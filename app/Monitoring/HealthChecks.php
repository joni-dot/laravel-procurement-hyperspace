<?php

namespace App\Monitoring;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * What the health check (/health) looks at. Outsiders can call it, so each check is a constant,
 * tiny piece of work: one query (database and backups), one Redis PING and one read of both
 * heartbeats.
 *
 * The uploads disk is left out on purpose: with S3 every check would be a paid call to the
 * storage provider that anyone could trigger, a write test would pile up file versions in the
 * versioned bucket, and a read test would not prove that uploads work. A failing upload is
 * reported as an error instead (the s3 disk throws).
 *
 * Nothing is logged when a check fails: outsiders can call the check, and the app's own errors
 * already show what is wrong.
 */
final class HealthChecks
{
    /**
     * Runs as the app's own database user, the one requests use, and also asks PostgreSQL whether
     * its changes still reach the backup bucket: the latest failure to ship one must not be newer
     * than the latest success. Without continuous backups (archiving off, as with most managed
     * databases) nothing ever fails, so the check passes. The view's name is left unqualified on
     * purpose, so a test can stand in for it with a temporary view.
     */
    private const string DATABASE_QUERY = 'select coalesce(last_failed_time is null or last_failed_time <= last_archived_time, false) as backups_ok from pg_stat_archiver';

    public function __construct(private readonly Heartbeats $heartbeats) {}

    /**
     * @return array{database: bool, backups: bool, redis: bool, queue: bool, scheduler: bool}
     */
    public function run(): array
    {
        $database = $this->database();
        $heartbeats = $this->heartbeats();

        return [
            'database' => $database['database'],
            'backups' => $database['backups'],
            'redis' => $this->passes(fn (): bool => Redis::connection()->command('ping') !== false),
            'queue' => $heartbeats['queue'],
            'scheduler' => $heartbeats['scheduler'],
        ];
    }

    /**
     * Backups can't be confirmed without the database, so they fail along with it.
     *
     * @return array{database: bool, backups: bool}
     */
    private function database(): array
    {
        try {
            $row = DB::selectOne(self::DATABASE_QUERY);
        } catch (Throwable) {
            return ['database' => false, 'backups' => false];
        }

        return ['database' => $row !== null, 'backups' => ($row->backups_ok ?? false) === true];
    }

    /**
     * @return array{scheduler: bool, queue: bool}
     */
    private function heartbeats(): array
    {
        try {
            return $this->heartbeats->recent();
        } catch (Throwable) {
            return ['scheduler' => false, 'queue' => false];
        }
    }

    /**
     * @param  callable(): bool  $check
     */
    private function passes(callable $check): bool
    {
        try {
            return $check();
        } catch (Throwable) {
            return false;
        }
    }
}
