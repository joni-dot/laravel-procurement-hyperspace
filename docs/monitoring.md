# Monitoring

What to watch on a running server, and what to do when something looks wrong. The setup itself is in
[hosting.md](hosting.md), backups and restores in [backups.md](backups.md).

| What                             | How                                                 | Set up              |
| -------------------------------- | --------------------------------------------------- | ------------------- |
| Is the service up and working?   | An uptime monitor calls `/health` every 1–5 minutes | Once, outside       |
| Errors people run into           | Error reports to Sentry or GlitchTip (optional)     | Paste a DSN in .env |
| What happened, in detail         | The containers' logs                                | Nothing to set up   |
| Is the disk filling up?          | A small timed check on the server                   | Once, on the server |
| Can we really get the data back? | The restore drill in [backups.md](backups.md)       | Every month         |

## Uptime monitor on /health

`https://your-address/health` (your `APP_URL`) answers **200** when everything works and **503** when
something doesn't, with a short list of what it checked:

```json
{
    "status": "ok",
    "checks": {
        "database": "ok",
        "backups": "ok",
        "redis": "ok",
        "queue": "ok",
        "scheduler": "ok"
    }
}
```

A failing check says `"failing"`. Anyone can call this address, so it never says more: no error
messages, versions, server names or timings. The details are in the logs.

**Maintenance mode** (`php artisan down`) doesn't change the answer: `/health` keeps saying whether
the parts work, while visitors see the maintenance page. Maintenance is planned by whoever would get
the alert, and the check must still answer when the maintenance flag can't be read (it lives in
Redis). Pause the monitor yourself if the work will make checks fail, for example while restoring the
database.

Point any uptime monitor at it: a hosted uptime service, or a self-hosted
[Uptime Kuma](https://github.com/louislam/uptime-kuma) on **another** server (a monitor on the same
server goes down with it).

- **Address:** the public address, exactly as in `APP_URL`. The app refuses every other host name,
  so calling the server's IP address gets 400.
- **Every 1–5 minutes,** expecting status 200. Each address may call it 60 times a minute.
- **Alert by email** (or chat) **after two failed checks in a row** (in Uptime Kuma: Retries 1). One
  failure can be a restart; two are a real problem.
- **Right after a fresh install** the background-job and scheduler checks fail for a minute or two,
  until they have run once. That is on purpose: a check that has never seen the scheduler run can't
  tell a slow start from a scheduler that was never started.

`/up` is a different check: it only says that the web server answers. Docker and load balancers use
it to decide whether to send visitors to a container. Don't give them `/health`: when the database is
down, every web container would be taken out and visitors would get the load balancer's error page
instead of the app's.

### What each failing check means

Run the commands in the folder with `compose.production.yaml` (with `-f compose.production.yaml`, as in
[hosting.md](hosting.md)). `docker compose ps` shows which services run; `docker compose logs --since 30m
<service>` shows what they said.

| Check       | It means                                                                                                                                                                                                  | What to do                                                                                                                                                                                                                                                                               |
| ----------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `database`  | The app can't run a query as its own database user.                                                                                                                                                       | Is `pgsql` running and healthy? Its logs often say why (disk full, out of memory). With a managed database, check the provider's status page and that the app's user and password in `.env` still work.                                                                                  |
| `backups`   | PostgreSQL's latest attempt to send a change file to the backup bucket failed, so changes since then would be lost with the server. (After a database failure it shows failing too, as it can't be read.) | `docker compose logs pgsql` shows `archive command failed` and why: often expired or changed keys, or a deleted or full bucket. Fix it, then `docker compose exec pgsql-backup db-backup check`. Until then the change files pile up on the server's disk. See [backups.md](backups.md). |
| `redis`     | The app can't reach Redis. Background jobs, the cache and rate limits stop, and the queue and scheduler checks usually fail too.                                                                          | Is `redis` running? Its logs; free memory on the server (`free -m`). With managed Redis, the provider's status page.                                                                                                                                                                     |
| `scheduler` | No sign of the scheduler for over 3 minutes. Reminders, daily checks and the queue's heartbeat stop, so `queue` fails soon after.                                                                         | Is `scheduler` running? Restart it: `docker compose up -d scheduler`. Fix this one first if both fail.                                                                                                                                                                                   |
| `queue`     | No queued job has run for over 5 minutes: emails and risk checks are waiting.                                                                                                                             | Is `worker` running? Its logs show a job that keeps failing or a crash. Restart it: `docker compose up -d worker`. If it runs but is busy, a very long job (a big import) may be in the way; it should catch up within minutes.                                                          |

The `backups` check reads PostgreSQL's own archiving statistics (`pg_stat_archiver`) in the same
query as the `database` check: it fails when the latest failure to send a change file is newer than
the latest success. Without continuous backups (a managed database whose provider makes them, or
archiving switched off) nothing ever fails there, so it passes; check the provider's backups in its
console instead. Right after the very first start it can fail for a few seconds, until the bucket is
prepared.

Behind the scenes: every minute the scheduler records "I ran" in Redis and queues a tiny job that
records "a worker ran me". `/health` reads both times. The limits (3 and 5 minutes) are in
`app/Monitoring/Heartbeats.php`.

## Error reports (Sentry or GlitchTip)

Off by default. To switch them on, create a project for a PHP / Laravel app in
[Sentry](https://sentry.io) or a self-hosted [GlitchTip](https://glitchtip.com) (free software that
understands the same reports), copy its **DSN** (an address with a key in it) into `.env`, and
restart the app:

```bash
SENTRY_LARAVEL_DSN=https://<key>@<your-sentry-or-glitchtip-host>/<project>
docker compose up -d
docker compose exec web php artisan sentry:test   # sends one test report
```

Each report names the release (`APP_VERSION` of the image) and the environment (`APP_ENV`), so you can
tell which version an error came from.

**What a report holds:** the error, the stack trace (with lines of our own open-source code), the
route (such as `/{company}/suppliers/{supplier}`), the user's **id** and the open company's **id**,
and the SQL of the queries before it, **without their values**. Look the ids up in the app when you
need to know who it was.

**What it never holds:** names, email addresses (they are cut out of error messages too), request
bodies and form fields, cookies, headers, the query string, IP addresses, values of SQL queries, the
contents of queued jobs, or log messages. A database error is reported by its SQLSTATE code and its
SQL with placeholders, as PostgreSQL's own message can quote the values ("Key (email)=(...) already
exists"); the full message is in the logs, which stay on your server. The rules are in
`config/sentry.php` and `app/Monitoring/ErrorReportPrivacy.php`.

**Not reported,** as they are answers to a person rather than faults: form errors, "not found",
"signed out", "not allowed", an expired form (CSRF) and "too many attempts". Failed background jobs
are reported.

**Optional:** `SENTRY_SAMPLE_RATE` (share of errors sent, default all). Performance tracing and
profiling are off; setting `SENTRY_TRACES_SAMPLE_RATE` (for example `0.05`) turns tracing on for that
share of requests: timings and SQL without values, at some extra work per request. Traces follow the
same rules as error reports: they name each page by its route (`/reset-password/{token}`,
`/{company}/...`), never by the address that was called.

Reports are sent while the request or job waits, with a 1-second limit to connect and 2 seconds in
all, so a report server that is down never holds up the app for long.

## Logs

Every container writes its log to its output, one JSON object per line (`LOG_CHANNEL=json`), with
the time, level (`level_name`), message and, for errors, the stack trace. Docker keeps them, rotated
at 5 × 10 MB per container (`compose.production.yaml`).

```bash
docker compose logs --since 1h web worker scheduler
# Errors only; lines that aren't the app's own (the web server's) are skipped.
docker compose logs --no-log-prefix web worker scheduler \
    | jq -cR 'fromjson? | select(.level_name? | IN("ERROR", "CRITICAL", "ALERT", "EMERGENCY"))'
```

`LOG_LEVEL=info` is the production setting; `debug` is too chatty to keep on. The API's call log
stays a file (`storage/logs/api-*.log`, kept 90 days, in the `app-logs` volume) because it is a record
of who called the API, not a message to read now.

The app's logs never hold passwords, tokens or request bodies. They can hold email addresses (for
example in the message of a failed database query), so treat them like the database: only people who
may see the data read them.

## Disk space

A full disk stops the database, which then can't write, and the logs. Check it now and then with
`df -h` and `docker system df`, and get an alert before it fills. Most uptime services can't see the
disk, so let the server report it. With Uptime Kuma, make a **Push** monitor and let the server call
its address every 5 minutes only while the disk is less than 80 % full; when the calls stop, you get
an email:

```bash
# crontab -e on the server; <uptime-kuma> and <token> come from the Push monitor
*/5 * * * * [ "$(df --output=pcent / | tail -n 1 | tr -dc '0-9')" -lt 80 ] && curl -fsS -m 10 -o /dev/null 'https://<uptime-kuma>/api/push/<token>'
```

What grows: the database volume, the database's change log before it is shipped to the backup
bucket (see [backups.md](backups.md)), container logs (capped as above) and old images. `docker image
prune` removes images no container uses.

## Backup drill

A backup is only proven by restoring it. Once a month, and after every change to the database or
backup setup, run the restore drill in [backups.md](backups.md) and check that the newest restored
change is only minutes old. Put it in the calendar; nothing reminds you otherwise.
