# Backups and restoring

This guide is for the person who runs a Procurement Hyperspace server. It explains what is backed up,
how much data you could lose, how to get it back, and how to prove once a month that you can. The
server setup itself is in [hosting.md](hosting.md); watching that backups keep working is in
[monitoring.md](monitoring.md).

## What is backed up, and how often

| What                                     | How                                                                                                                                      | How often                                                         | Kept    |
| ---------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------- | ------- |
| The database (everything people enter)   | Every change is sent to a backup bucket, encrypted and compressed. A full copy is made every Sunday and a smaller one on the other days. | Changes: continuously, at most about a minute late. Copies: daily | 30 days |
| Uploaded files (certificates, documents) | They live in S3-compatible storage, never on the server. Versioning keeps every old and deleted version.                                 | At once, by the storage itself                                    | 30 days |
| The `.env` file                          | **You** keep a copy in a password manager or safe, away from the server.                                                                 | After every change                                                | Always  |

Not backed up, on purpose: Redis (queued emails and sign-in sessions: people may have to sign in
again, and an email queued in the last moments may not be sent) and the HTTPS certificates (they are
fetched again by themselves).

Words used below:

- **Bucket**: a storage area at an S3-compatible storage provider (AWS S3, Hetzner, Scaleway, OVH,
  Backblaze B2, Cloudflare R2, MinIO and many others).
- **Change files** (PostgreSQL calls them WAL, "write-ahead log"): PostgreSQL writes every change into
  these files before anything else. Replaying them on top of a copy brings the copy up to any later
  moment. This is called **point-in-time restore**.
- **pgBackRest**: the open-source backup tool (MIT licence) built into the database image. It sends
  the change files and copies to the bucket, encrypts them first, deletes old ones after the
  retention, and restores.

### How much can be lost, and how long a restore takes

- **Data lost when the server is destroyed: at most about one minute of changes.** PostgreSQL sends a
  change file as soon as it is full, and closes it after 60 seconds at the latest (`archive_timeout`),
  so on a quiet database too. Measured on a quiet test database: a change reached the bucket after
  38 to 50 seconds at random moments, and after 60 seconds in the worst case (a change made just
  after a file was closed). A busy database fills files faster, so its changes arrive sooner.
- **Data lost when a mistake is undone: none before the mistake.** You can restore any moment of the
  last 30 days, to the second.
- **Time to restore**, measured with the demo data (2,000 suppliers and 2,700 packages, a 23 MB
  database): about 15 seconds, of which 9 to copy the files back and 4 to replay the changes; the
  whole drill took 16 seconds. Add the minutes you need to notice the problem and run the steps. A
  bigger database takes longer, roughly in proportion to its size and the speed of the connection to
  the bucket. The monthly drill tells you your own number.

## Setting it up

### 1. Two buckets, two sets of keys

Use **two separate buckets**, ideally at the same provider:

- the **uploads bucket** for certificates and documents (`AWS_*` settings in `.env`), and
- the **backup bucket** for the database (`BACKUP_S3_*` settings).

Give each bucket **its own access keys**, allowed to use only that bucket. The app never gets the
backup keys; only the database containers do. If the app is ever broken into, the intruder can't
read, overwrite or delete the database backups, and a mistake in the app can't touch them either.
(The backups are also encrypted, so the storage provider can't read them.) Set the buckets up with a
third, separate key that never goes on the server.

Keep both buckets private (no public access).

### 2. The uploads bucket: versioning and a clean-up rule

Switch on **versioning**: a changed or deleted file keeps its earlier version. Add a **lifecycle rule**
that deletes old versions after the same 30 days as the database backups, so the two stay in step.
In your provider's console these are usually called "Versioning" and "Lifecycle rules". With the AWS
command line tool (other providers accept the same commands with `--endpoint-url`):

```bash
aws s3api put-bucket-versioning --bucket YOUR-UPLOADS-BUCKET \
    --versioning-configuration Status=Enabled

aws s3api put-bucket-lifecycle-configuration --bucket YOUR-UPLOADS-BUCKET \
    --lifecycle-configuration '{"Rules": [{
        "ID": "keep-old-versions-30-days",
        "Status": "Enabled",
        "Filter": {},
        "NoncurrentVersionExpiration": {"NoncurrentDays": 30},
        "Expiration": {"ExpiredObjectDeleteMarker": true},
        "AbortIncompleteMultipartUpload": {"DaysAfterInitiation": 7}
    }]}'
```

To get a file back, show the bucket's versions in the provider's console and restore (or download)
the version from before the change.

### 3. The backup bucket: safe even from the server

pgBackRest deletes old backups itself after `BACKUP_RETENTION_DAYS`, so the backup keys on the
server may delete. Without more protection, anyone who takes over the server (ransomware, for
example) could delete the database **and** every backup. Protect the bucket in ways the server
can't undo:

1. **Versioning, with a rule that removes old versions only after 14 days.** A deletion, by
   pgBackRest or by an intruder, then only hides a file: its last version stays for 14 days, and a
   restore can read the bucket as it was before (see "The backups were deleted" below). The rule
   must only expire _old_ (noncurrent) versions, never current files:

    ```bash
    aws s3api put-bucket-versioning --bucket YOUR-BACKUP-BUCKET \
        --versioning-configuration Status=Enabled

    aws s3api put-bucket-lifecycle-configuration --bucket YOUR-BACKUP-BUCKET \
        --lifecycle-configuration '{"Rules": [{
            "ID": "keep-deleted-backups-14-days",
            "Status": "Enabled",
            "Filter": {},
            "NoncurrentVersionExpiration": {"NoncurrentDays": 14},
            "Expiration": {"ExpiredObjectDeleteMarker": true},
            "AbortIncompleteMultipartUpload": {"DaysAfterInitiation": 7}
        }]}'
    ```

    The bucket then holds about two weeks more of backups than the retention, which costs a little
    more storage.

2. **Backup keys that can't undo that.** The keys on the server may list, read, write and delete
   files, and read old versions (for a restore), but **not** delete versions or change the bucket's
   settings. On AWS, give them only this policy (other providers have similar key settings; choose
   read and write without "delete versions" and without bucket administration):

    ```json
    {
        "Version": "2012-10-17",
        "Statement": [
            {
                "Effect": "Allow",
                "Action": [
                    "s3:ListBucket",
                    "s3:ListBucketVersions",
                    "s3:GetBucketLocation"
                ],
                "Resource": "arn:aws:s3:::YOUR-BACKUP-BUCKET"
            },
            {
                "Effect": "Allow",
                "Action": [
                    "s3:GetObject",
                    "s3:GetObjectVersion",
                    "s3:PutObject",
                    "s3:DeleteObject"
                ],
                "Resource": "arn:aws:s3:::YOUR-BACKUP-BUCKET/*"
            }
        ]
    }
    ```

    Everything not listed, such as `s3:DeleteObjectVersion`, `s3:PutBucketVersioning`,
    `s3:PutLifecycleConfiguration`, `s3:PutBucketPolicy` and `s3:DeleteBucket`, is refused.

3. **Stronger, if your provider offers it: Object Lock** in compliance mode with a default retention
   of 14 days. Then nobody, not even with full keys, can delete a version before it is 14 days old.
   Most providers only allow it when the bucket is created. (Not tested with this setup yet; test
   it with a drill before you rely on it.)

If your provider offers none of these, copy the backup bucket regularly to a second provider with
keys the server doesn't have.

Don't use the uploads bucket's 30-day rule here and don't expire current files: pgBackRest decides
which backups are still needed.

### 4. The settings

Fill in the "Database backups" part of `.env` (the example file explains each one):

- `BACKUP_S3_ENDPOINT` (host name only, no `https://`), `BACKUP_S3_REGION`, `BACKUP_S3_BUCKET`,
  `BACKUP_S3_ACCESS_KEY_ID`, `BACKUP_S3_SECRET_ACCESS_KEY`, and `BACKUP_S3_URI_STYLE=path` if your
  provider needs path-style addresses (MinIO, Garage and some others).
- `BACKUP_ENCRYPTION_PASSPHRASE`: make one with `openssl rand -base64 48`. **Store it with your copy
  of `.env`, away from the server. Without it nobody, including you, can read the backups.** Never
  change it once backups exist.
- `BACKUP_RETENTION_DAYS` (30), `BACKUP_TIME` (02:30, UTC) and `BACKUP_FULL_WEEKDAY` (7 = Sunday).

The database refuses to start while a backup setting is missing, so a server never runs unprotected
by accident.

### 5. First start, and checking it works

`docker compose up -d` starts the database (`pgsql`) and its backup service (`pgsql-backup`). On the
very first start the database creates the app's database and its two users (from `DB_DATABASE`,
`DB_OWNER_USERNAME`, `DB_USERNAME` and their passwords), and the backup service prepares the bucket
and makes the first full backup at once. In the first seconds the database log shows a few
`archive.info ... missing` errors until the bucket is prepared; that is expected.

Then check:

```bash
docker compose exec pgsql-backup db-backup info    # lists the backups and the restorable range
docker compose exec pgsql-backup db-backup check   # proves change files reach the bucket
docker compose logs pgsql-backup                   # the nightly backups, and any errors
```

`db-backup now full` (or `diff`) makes an extra backup at any time, for example before a big update.

The database passwords in `.env` are used on the first start only. To change one later, change it in
the database too: `docker compose exec pgsql gosu postgres psql -c '\password hyperspace_app'`
(use your user's name), then in `.env`, then run `docker compose up -d` (a plain `restart` would
keep the old settings: only `up` reads `.env` again).

## Restoring

Times are in UTC (the database's clock). All commands run on the server, in the folder with `.env`,
whose `COMPOSE_FILE` points Compose at `compose.production.yaml` (see [hosting.md](hosting.md)). A
restore **never overwrites** a database: it only goes into an empty data volume, and refuses one
that already holds a database. `PGSQL_VOLUME` in `.env` names the volume the server uses.

### One mistake to undo

Example: someone deleted a supplier's contacts by mistake at about 14:05 today.

First find the moment just **before** the mistake, for example `2026-11-22 14:04:00+00`. Then choose:

**A. Copy back only what was lost** (recommended when other people kept working after the mistake;
their work stays). Start a throwaway copy of the database as it was at that moment, next to the real
one:

```bash
docker/postgres/restore-drill.sh --time '2026-11-22 14:04:00+00' --keep
docker compose --profile restore exec -u postgres pgsql-drill psql -d hyperspace
```

Look up the lost rows in the copy and write them back into the real database. This needs someone who
knows SQL and the tables, because the copied rows must still fit the data around them. Remove the
copy when done (the drill prints the command).

**B. Put the whole database back to that moment.** Everything entered after it, by everyone, is
lost. Restore into a new volume next to the current one and follow the steps the script prints:

```bash
docker/postgres/restore.sh --time '2026-11-22 14:04:00+00' --volume hyperspace-pgsql-20261122
```

The steps are: stop and remove the containers (`docker compose down`; the data stays in its
volumes, and the containers must be made anew to use another volume), set `PGSQL_VOLUME=` to the
new volume in `.env`, start the database alone and wait for "healthy" (it first replays the changes
up to the moment), check it with `docker compose exec pgsql db-restore-check`, start everything,
and make a fresh full backup. The old volume stays until you delete it.

### The whole server is lost

1. Set up a new server as in [hosting.md](hosting.md), with the same `.env` (from your safe copy).
   **Don't start it yet**: a first start without a restore would create a new, empty database.
2. Build or pull the images: `docker compose build pgsql` (and the app image as hosting.md says).
3. Restore the newest data. Without `--volume`, it goes into the volume `PGSQL_VOLUME` in `.env`
   names, which is the one the server will start on:

    ```bash
    docker/postgres/restore.sh
    ```

4. Start the database alone and check how far the restore got:

    ```bash
    docker compose up -d --wait pgsql
    docker compose exec pgsql db-restore-check
    ```

    It shows the moment of the newest change it restored, which should be shortly before the server
    was lost. It **fails** if no change after the backup itself was replayed, usually because the
    bucket couldn't be read during the replay. Then run `docker compose down`, delete the volume
    (`docker volume rm` with the name from `PGSQL_VOLUME`), check the bucket settings and restore
    again. (Run the check before the database is next restarted: a restart forgets the answer.)

5. Start everything: `docker compose up -d`. Uploaded files are already in their bucket.
6. Make a fresh full backup: `docker compose exec pgsql-backup db-backup now full`.

If the new server was started by mistake before the restore, nothing is lost: the backup service
sees that the new, empty database is not the one in the bucket and refuses to touch the backups,
and `restore.sh` refuses to restore over it. Run `docker compose down`, delete that empty volume
(`docker volume rm` with the name from `PGSQL_VOLUME`) and restore.

### The backups were deleted or damaged

If someone deleted or damaged the backups (`db-backup info` shows no backups, or an error, and the
`backups` check of the health page fails), a versioned backup bucket (step 3 of the setup) still
holds the earlier versions for 14 days. Find a moment shortly before the damage, for example
`2026-11-22 03:00:00+00`, and add `--bucket-time` to read the bucket as it was then:

```bash
docker/postgres/restore-drill.sh --bucket-time '2026-11-22 03:00:00+00'     # check what is there
docker/postgres/restore.sh --bucket-time '2026-11-22 03:00:00+00'           # if the database is gone too
```

If the database itself is still fine, you don't need to restore: the backup service prepares the
bucket again by itself within 15 minutes and makes a new full backup. Either way, the intruder had
the server: change every key and password in `.env`, starting with the backup keys, and find out
how they got in. Tested: after every file was deleted with the server's own backup keys, the drill
restored from the bucket's earlier versions up to the last change before the deletion.

## The monthly restore drill

A backup you have never restored is only a hope. Once a month, run:

```bash
docker/postgres/restore-drill.sh
```

It restores the newest backup into a throwaway volume, starts a throwaway database on it (it can't
send anything to the bucket, and the app can't reach it), and checks that it finished replaying
changes, has companies, keeps companies apart (row-level security is in place), and that the app's
restricted user can sign in and sees only the company it opens. It then deletes the copy, prints
the newest change it found and how long it all took. The live system keeps running.

Write down the date, "passed" and the time it took. If it fails, fix that first: the backups are not
working.

## Keeping an eye on it

- **The health page's `backups` check** fails when the database's latest attempt to send a change
  file to the bucket failed (it reads PostgreSQL's own record of this). An uptime monitor on the
  health page warns you within minutes; see [monitoring.md](monitoring.md).
- **Change files that can't be sent pile up on the server's disk** until the bucket is reachable
  again; nothing is lost, but the disk fills. The database log (`docker compose logs pgsql`) then
  shows `archive command failed`. Alert on the server's free disk space (see
  [monitoring.md](monitoring.md)).
- A failed nightly backup shows `ERROR` in `docker compose logs pgsql-backup`; it is tried again the
  next night, and the change files keep flowing in the meantime.
- `db-backup info` shows the newest backup and change file at a glance.

## Using a managed database instead

If your provider runs PostgreSQL for you (a "managed database"), you don't need the `pgsql` and
`pgsql-backup` services, this image or the `BACKUP_*` settings. Instead:

- Use PostgreSQL 18, and create the database, the owner and the app's restricted user as
  [hosting.md](hosting.md) explains.
- Switch on the provider's **point-in-time restore** (sometimes called "PITR" or "continuous
  backup") and check that it keeps **at least 30 days**. Check how far back it can go and how
  restoring works (usually into a new database you then point the app at).
- Run the provider's restore into a new database once a month, as your drill.

The uploads bucket needs versioning and the lifecycle rule either way.

## Privacy: deleted data stays in backups for a while

Contacts are truly deleted from the database for privacy, and uploaded files are deleted when people
delete them. **They remain in the backups until the 30 days of retention have passed, and then for
up to 14 more days as old versions in the versioned backup bucket; old versions of uploaded files
stay 30 days. After that they are gone for good.** The backups are encrypted, and only the
database's own keys and passphrase can reach them. Say so in your privacy notice, for example:
"Deleted data is removed from our backups within 45 days."

After a restore to an earlier moment, anything deleted since that moment comes back. Keep a list of
deletion requests (for example from people asking to be forgotten) and delete those again after a
restore.

## Settings for a bigger server

The database is tuned for a small server (2 to 4 CPU cores, 4 GB memory shared with the app):
`docker/postgres/postgresql.conf`. On a server with more memory, raise `shared_buffers` to about a
quarter of the memory and `effective_cache_size` to about half, then rebuild and restart:
`docker compose build pgsql && docker compose up -d pgsql`. The database logs statements slower than
2 seconds, without their values, because values can be personal data.
