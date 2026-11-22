# Hosting

How to run Procurement Hyperspace for real people: on one Linux server with Docker (the reference setup), or with a hosting provider's managed database, Redis and file storage. Backups are in [backups.md](backups.md), health checks, error reports and logs in [monitoring.md](monitoring.md).

## How it fits together

Everything runs from **one Docker image** (a packaged copy of the app with everything it needs). The command a container starts with decides its role:

| Service     | What it does                                                                      | Command                                                         |
| ----------- | --------------------------------------------------------------------------------- | --------------------------------------------------------------- |
| `proxy`     | HTTPS with free certificates (Caddy), the only part reachable from the internet   | (Caddy's own image)                                             |
| `web`       | The web server (FrankenPHP), plain HTTP on port 8080 inside the server            | the image's default                                             |
| `worker`    | Background jobs: emails, risk checks                                              | `php artisan queue:work` (options in `compose.production.yaml`) |
| `scheduler` | Timed jobs: reminders, daily checks                                               | `php artisan schedule:work`                                     |
| `migrate`   | Brings the database up to date, then stops; runs before the others on each deploy | `php artisan migrate --database=owner --force --isolated`       |
| `redis`     | Queue, cache and sessions                                                         | (Redis's own image)                                             |
| `pgsql`     | PostgreSQL with continuous backups ([backups.md](backups.md))                     | (its own image)                                                 |

`compose.production.yaml` describes all of them. Every setting comes from `.env`; the image itself holds no secrets.

The proxy sits on a network of its own with `web`, so it can't reach the database or Redis. Redis runs as its own ordinary user too, and every container gives up the Linux rights it doesn't need.

The image runs as an ordinary user (not root), its code can't be changed while it runs (only `storage/` and `bootstrap/cache` are writable), and it refuses to start with `APP_DEBUG=true`, with `APP_ENV=local` or `testing`, or without `APP_KEY`. On start the web, worker and scheduler roles prepare their caches from the settings (`php artisan optimize`), which takes about a second.

## What you need

- **A Linux server** with Docker Engine 27 or newer and the Compose plugin 2.24.4 or newer. Start with 2 CPU cores, 4 GB of memory and 40 GB of SSD disk (see [Sizing](#sizing)).
- **A domain name**, for example `hyperspace.example.com`.
- **An SMTP service** for email (your email provider, or one such as Postmark, Mailgun, Amazon SES or Brevo).
- **S3-compatible storage**: one bucket for uploaded files (with versioning on) and one for the database backups. Most providers sell it (AWS S3, Hetzner, Scaleway, Backblaze B2, Cloudflare R2 and others).

## First install

Commands run on the server, in the folder you clone into.

1. **Firewall.** Open only SSH (22, ideally from your own addresses only), 80 and 443 (TCP, and UDP for HTTP/3). See the [Security checklist](#security-checklist).
2. **DNS.** Point your domain's A (and AAAA, if the server has IPv6) record at the server. Do this first: the proxy asks for a certificate on its first start, and that only works once the name leads to the server.
3. **Get the code** of the version you want:

    ```bash
    git clone https://github.com/joni-dot/laravel-procurement-hyperspace.git hyperspace
    cd hyperspace
    git checkout <latest release tag>
    ```

4. **Settings.** Copy the example and make it readable only by you:

    ```bash
    cp .env.production.example .env
    chmod 600 .env
    ```

    Fill in every empty value. The comments in the file explain each one. To make the secrets:

    ```bash
    echo "base64:$(openssl rand -base64 32)"   # APP_KEY
    openssl rand -hex 32                       # DB_PASSWORD, DB_OWNER_PASSWORD, REDIS_PASSWORD (a different one each)
    openssl rand -base64 48                    # BACKUP_ENCRYPTION_PASSPHRASE
    ```

    API token signing keys (Passport), as one line each with `\n` in place of the line breaks:

    ```bash
    openssl genrsa -out oauth-private.key 4096
    openssl rsa -in oauth-private.key -pubout -out oauth-public.key
    echo "PASSPORT_PRIVATE_KEY=\"$(awk '{printf "%s\\n", $0}' oauth-private.key)\"" >> .env
    echo "PASSPORT_PUBLIC_KEY=\"$(awk '{printf "%s\\n", $0}' oauth-public.key)\"" >> .env
    shred -u oauth-private.key oauth-public.key
    ```

    Then delete the empty `PASSPORT_PRIVATE_KEY=` and `PASSPORT_PUBLIC_KEY=` lines higher up. Keep a copy of `APP_KEY`, the Passport keys and `BACKUP_ENCRYPTION_PASSPHRASE` in a password manager: without `APP_KEY` encrypted data can't be read, and without the passphrase the backups can't.

5. **Database users.** The app uses two: the **owner** (`DB_OWNER_USERNAME`) creates the tables when migrations run, and the **app user** (`DB_USERNAME`) can only read and write rows, so it can never switch off the walls between companies. The `pgsql` service creates both on its first start. For a managed database, see [Managed PostgreSQL](#managed-postgresql).
6. **Build and start:**

    ```bash
    docker compose build
    docker compose up -d
    ```

    `migrate` runs first and creates the tables; `web`, `worker` and `scheduler` start once it has finished. `docker compose ps` shows the state, `docker compose logs -f` what happens.

7. **First platform admin.** There is no public sign-up:

    ```bash
    docker compose exec web php artisan app:create-user --platform-admin
    ```

8. **Check.** Open `https://<your domain>`, sign in, turn on two-factor sign-in, then create the first company (README, "Platform admins"). Point an uptime monitor at `https://<your domain>/health`, which checks the database, Redis, background jobs and the scheduler ([monitoring.md](monitoring.md)). `/up` only says that the web server answers; Docker uses it to check the web container.

To run any other command: `docker compose exec web php artisan <command>`.

**After changing `.env`**, run `docker compose up -d`. It replaces the containers whose settings changed; `docker compose restart` would keep the old settings, because a container reads `.env` only when it is created.

**Visitors' own addresses.** Rate limits (for example on sign-in and API tokens) count per visitor address, so the app must see the real one. Docker passes it on for IPv4. For IPv6 it does so only because the proxy's network has IPv6 switched on (`enable_ipv6` in `compose.production.yaml`, which needs Docker Engine 27 or newer); otherwise every IPv6 visitor would arrive from one internal address and share one limit. To check, from a computer with IPv6 and with IPv4:

```bash
curl -6 -s -d grant_type=client_credentials https://<your domain>/api/oauth/token   # then the same with -4
docker compose exec web sh -c 'tail -n 2 storage/logs/api-*.log'
```

Each "Failed token request" line should show that computer's own public address. If the IPv6 one shows an address such as `172.x.x.x` or `fd..`, turn off Docker's userland proxy (`"userland-proxy": false` in `/etc/docker/daemon.json`, then restart Docker), or remove the server's AAAA record until it's fixed.

## Updating to a new version

Database backups run all the time, so there is nothing to back up first. Read the release notes, then:

```bash
git fetch --tags
git checkout <new release tag>
APP_VERSION="$(git describe --tags --always)" docker compose build   # the old version keeps running meanwhile
docker compose run --rm migrate && docker compose up -d   # migrations first; the switch only if they worked
docker image prune -f                                     # removes the old image
```

- **Migrations run while the old version is still up.** `docker compose run --rm migrate` changes the database and stops. Only if it succeeds does `up -d` replace `web`, `worker` and `scheduler` with the new version (it runs `migrate` once more, which then has nothing to do).
- **The site is unreachable for about 10 seconds** (9 seconds measured on a small server) while `web` is replaced; the proxy answers "502 Bad Gateway" meanwhile. The worker gets 75 seconds to finish the job it is on, the scheduler 5 minutes for a running daily task. If the release notes say the old version can't work with the new database, or a migration takes long, switch on maintenance mode first with `docker compose exec web php artisan down` and off afterwards with `docker compose exec web php artisan up` (with `APP_MAINTENANCE_DRIVER=cache` every container sees it).

**If the migration fails**, the old version keeps running: nothing was switched. See why with the output of `run`, then go back to the code you came from so that a later `up -d` doesn't start the new image:

```bash
git checkout <previous release tag>
docker compose build
```

On PostgreSQL each migration runs in a transaction, so the one that failed left nothing half done. Migrations that finished before it in the same update stay; the release notes say if that matters. Going back to an older version after all its migrations have run needs a database restore to the moment before the update ([backups.md](backups.md)).

**Stay current.** The image's building blocks (PHP, the web server, the operating system) are pinned to exact versions, so the same code always builds the same image; rebuilding alone brings no security fixes. New releases pick up those fixes (Dependabot proposes them weekly), so update to the latest release at least once a month. You could build with unpinned, always-latest base images instead, but then two builds of the same version can differ.

There is no ready-made image to download yet; you build it on the server from the code as above.

## Behind a load balancer

A hosting provider's load balancer can do HTTPS instead of the `proxy` service:

- Leave out `proxy` (`docker compose up -d web worker scheduler`) and let the load balancer reach `web` on port 8080 over the private network only. Publish the port on the server's private address in a file of your own, for example `compose.lb.yaml`:

    ```yaml
    services:
        web:
            ports:
                - '10.0.0.5:8080:8080' # the server's private address
    ```

    and list it in `.env`: `COMPOSE_FILE=compose.production.yaml:compose.lb.yaml`. Never publish 8080 on a public address.

- Set `TRUSTED_PROXIES` to the load balancer's private addresses or range. The app then believes its `X-Forwarded-For` (the visitor's address, used for rate limits) and `X-Forwarded-Proto` (that the visitor used HTTPS). Anyone else could fake these headers, so never use `*` unless only the load balancer can reach the server.
- The load balancer must pass the original host name (`APP_URL`'s), send `X-Forwarded-For` and `X-Forwarded-Proto`, accept request bodies of at least 25 MB, and check health on `/up`.
- **Several web servers:** sessions are in Redis, so any server can answer any request. Uploaded files must then be on S3 (`UPLOADS_DISK=s3`). Run `worker` on as many servers as you like, but `scheduler` on one only.

## Managed services instead of containers

List what you leave out in a file of your own and add it to `COMPOSE_FILE` as above (this needs Compose 2.24.4 or newer, for `!reset` and `!override`). For example `compose.managed.yaml`, when both the database and Redis are managed:

```yaml
services:
    migrate:
        depends_on: !reset {}
    web:
        depends_on: !override
            migrate:
                condition: service_completed_successfully
    worker:
        depends_on: !override
            migrate:
                condition: service_completed_successfully
    scheduler:
        depends_on: !override
            migrate:
                condition: service_completed_successfully
```

Then start only what you still run yourself: `docker compose up -d web worker scheduler proxy`.

### Managed PostgreSQL

Use PostgreSQL 18. Create the two users and the database as the provider's admin user (passwords from `openssl rand -hex 32`):

```sql
CREATE ROLE hyperspace LOGIN PASSWORD '<DB_OWNER_PASSWORD>';
CREATE ROLE hyperspace_app LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS PASSWORD '<DB_PASSWORD>';
CREATE DATABASE hyperspace OWNER hyperspace;
```

Then give the app user its rights, as the owner, from a computer that has `psql`:

```bash
psql "host=<db host> dbname=hyperspace user=hyperspace sslmode=require" \
    -v app_user=hyperspace_app -v owner=hyperspace -f docker/pgsql/app-role.sql
```

In `.env` set `DB_HOST`, `DB_PORT` and `DB_SSLMODE=require` (or `verify-full`). The provider's point-in-time restore replaces the `pgsql` backups; switch it on and check how far back it goes.

### Managed Redis

Set `REDIS_URL=tls://<user>:<password>@<host>:<port>` (or `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD` without TLS). Choose a plan with persistence, and the eviction policy `noeviction`: other policies throw away queued emails when memory runs low.

### S3-compatible file storage

Set `UPLOADS_DISK=s3` and the `AWS_*` settings. The bucket must be private, with versioning on, so a deleted or overwritten file can be brought back. Give the app keys that can reach only that bucket, and never the backup bucket. With `UPLOADS_DISK=local` the files stay in the `uploads` volume on the server, outside the database backups.

## Email (SMTP)

Fill in the `MAIL_*` settings from your email service: host, port 587 (or 465 with `MAIL_SCHEME=smtps`), user name and password, and `MAIL_FROM_ADDRESS` on a domain you own. Set up SPF, DKIM and DMARC for that domain as your email service explains, or emails end up as spam. Emails go out from the background worker; to test, use "Forgot password" on the sign-in page and watch `docker compose logs worker`.

## Sizing

Measured on the reference setup with no load: `web` about 100 MB of memory, `worker` and `scheduler` about 40 MB each, `redis` about 10 MB, `proxy` about 15 MB, plus PostgreSQL. A sign-in page takes about 30 ms to make.

- **Up to about 50 active people:** 2 CPU cores, 4 GB of memory, 40 GB SSD.
- **Up to about 300:** 4 cores, 8 GB, or move the database to a managed service first.
- **More:** several web servers behind a load balancer, with managed PostgreSQL and Redis.

`web` handles two requests per CPU core at a time (FrankenPHP's PHP threads), each allowed up to 256 MB of memory (`memory_limit`). So its worst case is threads × 256 MB: 4 GB on an 8-core server, although normal pages use a few MB each. On a server with little memory and many cores, cap the threads in `.env`, for example `FRANKENPHP_CONFIG="num_threads 4"` (at most 1 GB), and run `docker compose up -d`. Container logs are kept up to 50 MB per service (10 MB × 5 files).

## Security checklist

- **Firewall:** only 22, 80 and 443 open. Docker opens the ports it publishes past `ufw` and similar firewalls, so never publish other ports (the database and Redis publish none) or bind them to `127.0.0.1` or a private address.
- **SSH:** keys only, no password sign-in, no root sign-in.
- **Updates:** automatic operating system security updates (`unattended-upgrades` on Debian and Ubuntu), Docker kept current, the app updated to the latest release at least monthly.
- **Secrets:** `.env` readable only by its owner (`chmod 600`), never in git, a copy of the irreplaceable keys in a password manager. Separate S3 keys for uploads and backups. The app containers never get the database owner's password or the backup keys; `compose.production.yaml` blanks them, so add any new secret meant for another service there too.
- **Settings:** `APP_ENV=production`, `APP_DEBUG=false` (the image refuses debug mode and `local`), `APP_URL` with `https://`, `SESSION_SECURE_COOKIE=true`.
- **People:** platform admins use two-factor sign-in.
- **Watch:** an uptime monitor on `/health` and error reports ([monitoring.md](monitoring.md)), and a restore drill now and then ([backups.md](backups.md)).

Found a security problem? See [SECURITY.md](../SECURITY.md).

## Running elsewhere (Kubernetes, Nomad, platform services)

The same image works on any container platform: run the commands from the table above as separate services, run `migrate` once as a job before each rollout (only one at a time; `--isolated` needs the Redis cache), give `web` port 8080 and its health check on `/up`, and pass the settings from `.env.production.example` as environment variables or secrets. The containers run as user 10001; only `/app/storage` and `/app/bootstrap/cache` need to be writable, and `/app/storage/app/private` needs a persistent volume only with `UPLOADS_DISK=local`.
