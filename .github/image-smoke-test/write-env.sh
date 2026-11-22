#!/usr/bin/env bash
# Writes .env for the image smoke test from .env.production.example, with throwaway random secrets.
# The values are never printed.
set -euo pipefail

cd "$(dirname "$0")/../.."
umask 077

random() { openssl rand -hex 24; }
one_line() { awk '{printf "%s\\n", $0}' "$1"; }

keys=$(mktemp -d)
trap 'rm -rf "$keys"' EXIT
openssl genrsa -out "$keys/private.key" 2048 2> /dev/null
openssl rsa -in "$keys/private.key" -pubout -out "$keys/public.key" 2> /dev/null

sed -e "s#^APP_KEY=.*#APP_KEY=base64:$(openssl rand -base64 32)#" \
    -e "s#^APP_URL=.*#APP_URL=${SMOKE_TEST_URL:-http://localhost:8080}#" \
    -e 's#^ACME_EMAIL=.*#ACME_EMAIL=smoke-test@example.com#' \
    -e "s#^DB_PASSWORD=.*#DB_PASSWORD=$(random)#" \
    -e "s#^DB_OWNER_PASSWORD=.*#DB_OWNER_PASSWORD=$(random)#" \
    -e "s#^REDIS_PASSWORD=.*#REDIS_PASSWORD=$(random)#" \
    -e 's#^UPLOADS_DISK=.*#UPLOADS_DISK=local#' \
    -e 's#^MAIL_MAILER=.*#MAIL_MAILER=log#' \
    -e 's#^MAIL_FROM_ADDRESS=.*#MAIL_FROM_ADDRESS=smoke-test@example.com#' \
    -e "s#^BACKUP_S3_SECRET_ACCESS_KEY=.*#BACKUP_S3_SECRET_ACCESS_KEY=$(random)#" \
    -e "s#^BACKUP_ENCRYPTION_PASSPHRASE=.*#BACKUP_ENCRYPTION_PASSPHRASE=$(random)#" \
    -e "s#^APP_IMAGE=.*#APP_IMAGE=${APP_IMAGE:-procurement-hyperspace:smoke-test}#" \
    -e '/^PASSPORT_PRIVATE_KEY=/d' -e '/^PASSPORT_PUBLIC_KEY=/d' \
    .env.production.example > "${1:-.env}"

{
    printf 'PASSPORT_PRIVATE_KEY="%s"\n' "$(one_line "$keys/private.key")"
    printf 'PASSPORT_PUBLIC_KEY="%s"\n' "$(one_line "$keys/public.key")"
} >> "${1:-.env}"
