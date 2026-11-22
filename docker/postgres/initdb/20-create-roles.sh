#!/bin/sh
# Runs once, on the first start with an empty data volume. Creates the app's database, its owner (who
# runs the migrations) and the restricted user the app connects as, with the same rights as in
# development (docker/pgsql/app-role.sql). Unlike development, there is no `testing` database.
#
# Neither user is a superuser: a leaked app or migration password must not unlock the whole server.
# The owner may create tables in its own database; the app user may only read and write rows.
set -eu

: "${POSTGRES_APP_DATABASE:?Set DB_DATABASE in .env}"
: "${POSTGRES_OWNER_USER:?Set DB_OWNER_USERNAME in .env}"
: "${POSTGRES_OWNER_PASSWORD:?Set DB_OWNER_PASSWORD in .env}"
: "${POSTGRES_APP_USER:?Set DB_USERNAME in .env}"
: "${POSTGRES_APP_PASSWORD:?Set DB_PASSWORD in .env}"

psql -v ON_ERROR_STOP=1 --username postgres --dbname postgres \
    -v database="$POSTGRES_APP_DATABASE" \
    -v owner="$POSTGRES_OWNER_USER" -v owner_password="$POSTGRES_OWNER_PASSWORD" \
    -v app_user="$POSTGRES_APP_USER" -v app_password="$POSTGRES_APP_PASSWORD" <<'SQL'
CREATE ROLE :"owner" LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS PASSWORD :'owner_password';
CREATE ROLE :"app_user" LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS PASSWORD :'app_password';
CREATE DATABASE :"database" OWNER :"owner";
REVOKE CONNECT ON DATABASE postgres FROM PUBLIC;
SQL

PGPASSWORD="$POSTGRES_OWNER_PASSWORD" psql -v ON_ERROR_STOP=1 \
    --username "$POSTGRES_OWNER_USER" --dbname "$POSTGRES_APP_DATABASE" \
    -v app_user="$POSTGRES_APP_USER" -v owner="$POSTGRES_OWNER_USER" \
    -f /usr/local/share/hyperspace/app-role.sql
