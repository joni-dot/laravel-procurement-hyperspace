#!/usr/bin/env bash
# Writes the settings for the backup drill (.github/workflows/backups.yml) from .env.production.example,
# with throwaway random secrets and a local test S3 server. The values are never printed.
#
#   .github/backup-drill/write-env.sh [file]     default .env; BACKUP_DRILL_PROJECT names the Compose project
set -euo pipefail

cd "$(dirname "$0")/../.."
umask 077

file="${1:-.env}"
project="${BACKUP_DRILL_PROJECT:-hyperspace-backup-drill}"
random() { openssl rand -hex 24; }

sed -e "s#^COMPOSE_FILE=.*#COMPOSE_FILE=compose.production.yaml:.github/backup-drill/compose.yaml#" \
    -e "s#^APP_KEY=.*#APP_KEY=base64:$(openssl rand -base64 32)#" \
    -e 's#^APP_URL=.*#APP_URL=http://localhost#' \
    -e 's#^ACME_EMAIL=.*#ACME_EMAIL=backup-drill@example.com#' \
    -e "s#^DB_PASSWORD=.*#DB_PASSWORD=$(random)#" \
    -e "s#^DB_OWNER_PASSWORD=.*#DB_OWNER_PASSWORD=$(random)#" \
    -e "s#^REDIS_PASSWORD=.*#REDIS_PASSWORD=$(random)#" \
    -e 's#^UPLOADS_DISK=.*#UPLOADS_DISK=local#' \
    -e 's#^MAIL_MAILER=.*#MAIL_MAILER=log#' \
    -e 's#^MAIL_FROM_ADDRESS=.*#MAIL_FROM_ADDRESS=backup-drill@example.com#' \
    -e 's#^QUEUE_CONNECTION=.*#QUEUE_CONNECTION=sync#' \
    -e 's#^CACHE_STORE=.*#CACHE_STORE=array#' \
    -e 's#^SESSION_DRIVER=.*#SESSION_DRIVER=array#' \
    -e 's#^LOG_CHANNEL=.*#LOG_CHANNEL=stderr#' \
    -e 's#^BACKUP_S3_ENDPOINT=.*#BACKUP_S3_ENDPOINT=s3#' \
    -e 's#^BACKUP_S3_PORT=.*#BACKUP_S3_PORT=8443#' \
    -e 's#^BACKUP_S3_REGION=.*#BACKUP_S3_REGION=us-east-1#' \
    -e 's#^BACKUP_S3_BUCKET=.*#BACKUP_S3_BUCKET=backup-drill#' \
    -e 's#^BACKUP_S3_URI_STYLE=.*#BACKUP_S3_URI_STYLE=path#' \
    -e "s#^BACKUP_S3_ACCESS_KEY_ID=.*#BACKUP_S3_ACCESS_KEY_ID=$(openssl rand -hex 10)#" \
    -e "s#^BACKUP_S3_SECRET_ACCESS_KEY=.*#BACKUP_S3_SECRET_ACCESS_KEY=$(random)#" \
    -e "s#^BACKUP_ENCRYPTION_PASSPHRASE=.*#BACKUP_ENCRYPTION_PASSPHRASE=$(openssl rand -base64 48)#" \
    -e "s#^PGSQL_VOLUME=.*#PGSQL_VOLUME=$project-pgsql-data#" \
    -e "s#^APP_IMAGE=.*#APP_IMAGE=procurement-hyperspace:backup-drill#" \
    .env.production.example > "$file"

path="$(realpath "$file")"
{
    echo "COMPOSE_PROJECT_NAME=$project"
    echo "DB_IMAGE=$project-postgres"
    # Read by .github/backup-drill/compose.yaml, so the app's one-off commands get these settings too.
    echo "BACKUP_DRILL_ENV_FILE=$path"
} >> "$file"
