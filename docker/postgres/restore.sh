#!/usr/bin/env bash
# Restores the database from the backup bucket into an EMPTY data volume, then tells you what to do
# next. It never overwrites a database: a volume that already holds one is refused.
# Run on the server, from the folder with compose.production.yaml and .env. See docs/backups.md.
#
#   docker/postgres/restore.sh                    the newest data, into the volume PGSQL_VOLUME in .env
#                                                 names (a new server, or after deleting a bad volume)
#   docker/postgres/restore.sh --time '2026-11-22 14:05:00+00' --volume hyperspace-pgsql-20261122
#                                                 up to that moment (UTC), into a new volume next to
#                                                 the current one, to undo a mistake
#   --bucket-time '2026-11-22 03:00:00+00'        reads the bucket as it was then (versioned bucket
#                                                 whose backups were deleted or damaged)
set -euo pipefail

usage() {
    sed -n '2,13p' "$0" | sed 's/^# \{0,1\}//' >&2
    exit 2
}

restore_args=()
volume=''
while [ "$#" -gt 0 ]; do
    case "$1" in
        --time | --bucket-time)
            [ "$#" -ge 2 ] || usage
            restore_args+=("$1" "$2")
            shift 2
            ;;
        --volume)
            [ "$#" -ge 2 ] || usage
            volume="$2"
            shift 2
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

# The volume the database runs on: PGSQL_VOLUME in .env, or the default in compose.production.yaml.
# Read from the resolved setup, which only this script sees (it holds secrets).
current="$(docker compose config 2>/dev/null | awk '
    /^volumes:/ { in_volumes = 1; next }
    in_volumes && /^[^ ]/ { in_volumes = 0 }
    in_volumes && /^  pgsql-data:/ { in_data = 1; next }
    in_data && /^    name:/ { print $2; exit }
    in_data && /^  [^ ]/ { in_data = 0 }')"
current="${current:-hyperspace-pgsql-data}"
volume="${volume:-$current}"
export RESTORE_VOLUME="$volume"

holds_database() {
    docker compose --profile restore run --rm --no-deps -T --entrypoint sh pgsql-restore \
        -c '[ -n "$(ls -A /var/lib/postgresql/18/docker 2>/dev/null)" ]' > /dev/null 2>&1
}

if holds_database; then
    echo "The volume $volume already holds a database, and a restore never overwrites one." >&2
    if [ "$volume" = "$current" ]; then
        echo "It is the one the server uses (PGSQL_VOLUME). To restore next to it, name a new volume:" >&2
        hint="$0"
        for argument in "${restore_args[@]}"; do
            hint+=" $(printf '%q' "$argument")"
        done
        echo "  $hint --volume hyperspace-pgsql-$(date -u +%Y%m%d-%H%M)" >&2
        echo "If it is a new, empty database started by mistake, see \"The whole server is lost\" in docs/backups.md." >&2
    fi
    exit 1
fi

echo "Restoring into the volume $volume ..."
started="$(date +%s)"
docker compose --profile restore run --rm --no-deps -T pgsql-restore "${restore_args[@]}"
echo "Done in $(($(date +%s) - started)) seconds."

echo
echo 'Next steps:'
if [ "$volume" = "$current" ]; then
    cat <<STEPS
  1. Start the database alone. It first replays the changes from the bucket; wait until it shows
     "healthy":
       docker compose up -d pgsql && docker compose ps pgsql
STEPS
else
    cat <<STEPS
  1. Stop and remove the containers, so nothing writes to the old database any more and the
     database container is made anew on the new volume (all data stays in the volumes):
       docker compose down
     In .env, set:
       PGSQL_VOLUME=$volume
     Start the database alone. It first replays the changes up to the chosen moment; wait until
     it shows "healthy":
       docker compose up -d pgsql && docker compose ps pgsql
STEPS
fi
cat <<STEPS
  2. Check how far it got; this fails if nothing after the backup itself was replayed:
       docker compose exec pgsql db-restore-check
  3. Start everything else:
       docker compose up -d
  4. Take a full backup of the restored database:
       docker compose exec pgsql-backup db-backup now full
STEPS
if [ "$volume" != "$current" ]; then
    echo "  5. When you are sure the restore is right, delete the old volume: docker volume rm $current"
fi
