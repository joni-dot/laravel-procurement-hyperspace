#!/bin/sh
# Runs once, when the database volume is first created. POSTGRES_USER owns the tables and runs the
# migrations; APP_DB_USERNAME is the restricted user the app connects as (see app-role.sql).
set -eu

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" \
    -v app_user="$APP_DB_USERNAME" -v app_password="$APP_DB_PASSWORD" <<'SQL'
CREATE ROLE :"app_user" LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS PASSWORD :'app_password';
SQL

for database in "$POSTGRES_DB" testing; do
    psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$database" \
        -v app_user="$APP_DB_USERNAME" -v owner="$POSTGRES_USER" -f /docker-entrypoint-initdb.d/app-role.sql.inc
done
