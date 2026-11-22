<?php

namespace App\Monitoring;

use Illuminate\Support\Facades\Cache;

/**
 * Proof that the scheduler and the queue workers run, for the health check. Every minute the
 * scheduler records its heartbeat and queues RecordQueueHeartbeat, which records the workers'.
 *
 * The heartbeats live in the cache, which the web, worker and scheduler containers share (Redis).
 * A heartbeat that was never recorded counts as failing: right after a fresh install or a Redis
 * restart the check fails for a minute or two, and an uptime monitor that waits for a second
 * failed check before alerting stays quiet. Counting a missing heartbeat as fine would hide
 * exactly the mistake this check exists for: a scheduler or worker that never started.
 */
final class Heartbeats
{
    /**
     * The scheduler beats every minute. Three minutes allows a slow minute or a restart without
     * a false alarm, and still raises one within a few minutes of the scheduler stopping.
     */
    public const int SCHEDULER_MAX_AGE_SECONDS = 180;

    /**
     * The queue's job is queued every minute but may wait behind long jobs (a big import, the
     * daily risk check of every project), so the workers get five minutes.
     */
    public const int QUEUE_MAX_AGE_SECONDS = 300;

    private const string SCHEDULER_KEY = 'health:heartbeat:scheduler';

    private const string QUEUE_KEY = 'health:heartbeat:queue';

    /** Long past both limits; an older heartbeat would fail the check anyway. */
    private const int KEPT_SECONDS = 86_400;

    public function recordScheduler(): void
    {
        Cache::put(self::SCHEDULER_KEY, now()->getTimestamp(), self::KEPT_SECONDS);
    }

    public function recordQueue(): void
    {
        Cache::put(self::QUEUE_KEY, now()->getTimestamp(), self::KEPT_SECONDS);
    }

    /**
     * Reads both heartbeats in one round trip.
     *
     * @return array{scheduler: bool, queue: bool}
     */
    public function recent(): array
    {
        $beats = Cache::many([self::SCHEDULER_KEY, self::QUEUE_KEY]);

        return [
            'scheduler' => self::isRecent($beats[self::SCHEDULER_KEY], self::SCHEDULER_MAX_AGE_SECONDS),
            'queue' => self::isRecent($beats[self::QUEUE_KEY], self::QUEUE_MAX_AGE_SECONDS),
        ];
    }

    private static function isRecent(mixed $recordedAt, int $maxAgeSeconds): bool
    {
        return is_numeric($recordedAt) && now()->getTimestamp() - (int) $recordedAt <= $maxAgeSeconds;
    }
}
