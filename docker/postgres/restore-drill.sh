#!/usr/bin/env bash
# Restore drill: proves that the backups can be restored. Restores the newest backup (or the moment
# given with --time) into a throwaway volume, starts a throwaway database on it and checks it, then
# deletes both. Production keeps running and is never touched; the copy uploads nothing.
# Run it once a month on the server, from the folder with compose.production.yaml and .env.
#
#   docker/postgres/restore-drill.sh
#   docker/postgres/restore-drill.sh --time '2026-11-22 14:05:00+00'
#   docker/postgres/restore-drill.sh --keep     leaves the copy running to look at or copy rows from:
#                                               docker compose --profile restore exec -u postgres pgsql-drill psql -d <database>
set -euo pipefail

usage() {
    sed -n '2,11p' "$0" | sed 's/^# \{0,1\}//' >&2
    exit 2
}

restore_args=()
keep=false
while [ "$#" -gt 0 ]; do
    case "$1" in
        --time)
            [ "$#" -ge 2 ] || usage
            restore_args+=(--time "$2")
            shift 2
            ;;
        --bucket-time)
            [ "$#" -ge 2 ] || usage
            restore_args+=(--bucket-time "$2")
            shift 2
            ;;
        --keep)
            keep=true
            shift
            ;;
        *) usage ;;
    esac
done

# compose.production.yaml comes from COMPOSE_FILE in .env (see docs/hosting.md).
services="$(docker compose --profile restore config --services 2>/dev/null || true)"
if ! grep -qx pgsql-restore <<<"$services"; then
    echo 'Run this from the folder with compose.production.yaml and .env (with COMPOSE_FILE=compose.production.yaml).' >&2
    exit 1
fi
RESTORE_VOLUME="hyperspace-restore-drill-$(date -u +%Y%m%d-%H%M%S)"
export RESTORE_VOLUME

clean_up() {
    docker compose --profile restore rm --stop --force pgsql-drill >/dev/null 2>&1 || true
    docker volume rm --force "$RESTORE_VOLUME" >/dev/null 2>&1 || true
}

if [ "$keep" = false ]; then
    trap clean_up EXIT
fi

started="$(date +%s)"
echo "1/3 Restoring the backup into the throwaway volume $RESTORE_VOLUME ..."
docker compose --profile restore run --rm -T pgsql-restore --drill "${restore_args[@]}"

echo "2/3 Starting a throwaway database on it ..."
docker compose --profile restore up --detach --no-deps --force-recreate pgsql-drill

echo "3/3 Checking it ..."
docker compose --profile restore exec -T pgsql-drill db-drill-check

echo "Restore drill passed in $(($(date +%s) - started)) seconds in all."
if [ "$keep" = true ]; then
    echo "The copy is still running. Remove it when done:"
    echo "  RESTORE_VOLUME=$RESTORE_VOLUME docker compose --profile restore rm --stop --force pgsql-drill && docker volume rm $RESTORE_VOLUME"
fi
