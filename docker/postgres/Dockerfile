# PostgreSQL 18 for production, with pgBackRest (MIT licence) for continuous, encrypted backups to
# S3-compatible storage. See docs/backups.md. Built from the docker/ folder:
#   docker build -f docker/postgres/Dockerfile docker
FROM postgres:18-trixie@sha256:eb63372c1e05c755f55fa30cbd07aecc443676fc6c0fa90b9002d01537841e1a

# pgBackRest comes from the PostgreSQL project's own signed package repository, already set up in
# the official image. tini (MIT) runs as process 1: pgBackRest uploads change files from a background
# process, and if PostgreSQL itself were process 1 it would treat that process failing (for example
# while the bucket is unreachable) as a crash and restart every connection.
RUN apt-get update \
    && apt-get install -y --no-install-recommends pgbackrest tini \
    && rm -rf /var/lib/apt/lists/* \
    && install -d -o postgres -g postgres -m 0750 /var/spool/pgbackrest

# The builtin C.UTF-8 sort order doesn't depend on the operating system's locale library, so an image
# update can never silently change how text sorts (which would corrupt indexes). It also sorts the
# same way as the development database.
ENV POSTGRES_INITDB_ARGS="--locale-provider=builtin --locale=C.UTF-8 --encoding=UTF8"

COPY postgres/postgresql.conf postgres/pg_hba.conf /etc/postgresql/
COPY postgres/pgbackrest.conf /etc/pgbackrest/pgbackrest.conf
COPY pgsql/app-role.sql /usr/local/share/hyperspace/app-role.sql
COPY postgres/initdb/ /docker-entrypoint-initdb.d/
COPY --chmod=0755 postgres/bin/ /usr/local/bin/

ENTRYPOINT ["tini", "--", "hyperspace-postgres-entrypoint"]
CMD ["postgres", "-c", "config_file=/etc/postgresql/postgresql.conf"]
