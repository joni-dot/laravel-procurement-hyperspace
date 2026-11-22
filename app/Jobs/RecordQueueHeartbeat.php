<?php

namespace App\Jobs;

use App\Monitoring\Heartbeats;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Queued by the scheduler every minute, outside every company. Running it proves to the health
 * check that a queue worker takes jobs.
 */
class RecordQueueHeartbeat implements ShouldQueue
{
    use Queueable;

    /** A heartbeat that fails is simply late; the next one is a minute away. */
    public int $tries = 1;

    public function handle(Heartbeats $heartbeats): void
    {
        $heartbeats->recordQueue();
    }
}
