<?php

namespace Tests\Feature\Production;

use App\Http\Middleware\LimitHealthChecks;
use App\Jobs\RecordQueueHeartbeat;
use App\Monitoring\Heartbeats;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    protected function tearDown(): void
    {
        // Trusted hosts are kept on Symfony's Request class, outside the app.
        Request::setTrustedHosts([]);

        parent::tearDown();
    }

    public function test_everything_passing_answers_200_with_only_the_check_names()
    {
        $this->recordHeartbeats();

        $response = $this->getJson('/health');

        $response->assertOk()->assertExactJson([
            'status' => 'ok',
            'checks' => ['database' => 'ok', 'backups' => 'ok', 'redis' => 'ok', 'queue' => 'ok', 'scheduler' => 'ok'],
        ]);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_it_needs_no_signed_in_user_and_sets_no_cookie()
    {
        $this->recordHeartbeats();

        $response = $this->get('/health');

        $response->assertOk();
        $this->assertSame([], $response->headers->getCookies());
        $this->assertGuest();
    }

    public function test_a_database_that_cannot_be_reached_fails_the_check()
    {
        $this->recordHeartbeats();
        $connection = config('database.default');
        config(["database.connections.{$connection}.port" => 1]);
        DB::purge($connection);

        $this->getJson('/health')
            ->assertStatus(503)
            ->assertExactJson([
                'status' => 'failing',
                'checks' => ['database' => 'failing', 'backups' => 'failing', 'redis' => 'ok', 'queue' => 'ok', 'scheduler' => 'ok'],
            ]);
    }

    public function test_changes_that_stopped_reaching_the_backup_bucket_fail_the_backups_check()
    {
        $this->recordHeartbeats();

        $this->pretendArchiving(lastArchived: "now() - interval '10 minutes'", lastFailed: "now() - interval '1 minute'");
        $this->getJson('/health')
            ->assertStatus(503)
            ->assertJsonPath('checks.backups', 'failing')
            ->assertJsonPath('checks.database', 'ok');

        $this->pretendArchiving(lastArchived: 'null', lastFailed: 'now()');
        $this->getJson('/health')->assertStatus(503)->assertJsonPath('checks.backups', 'failing');
    }

    public function test_backups_pass_once_archiving_works_again_or_when_it_is_off()
    {
        $this->recordHeartbeats();

        $this->pretendArchiving(lastArchived: 'now()', lastFailed: "now() - interval '1 hour'");
        $this->getJson('/health')->assertOk()->assertJsonPath('checks.backups', 'ok');

        $this->pretendArchiving(lastArchived: 'null', lastFailed: 'null');
        $this->getJson('/health')->assertOk()->assertJsonPath('checks.backups', 'ok');
    }

    public function test_redis_that_cannot_be_reached_fails_the_check()
    {
        $this->recordHeartbeats();
        config(['database.redis.default.port' => 1]);
        Redis::purge('default');

        $this->getJson('/health')
            ->assertStatus(503)
            ->assertJsonPath('status', 'failing')
            ->assertJsonPath('checks.redis', 'failing')
            ->assertJsonPath('checks.database', 'ok');
    }

    public function test_it_still_answers_when_the_maintenance_flag_lives_in_a_redis_that_is_down()
    {
        $this->recordHeartbeats();
        config([
            'app.maintenance.driver' => 'cache',
            'app.maintenance.store' => 'redis',
            'database.redis.cache.port' => 1,
            'database.redis.default.port' => 1,
        ]);
        Redis::purge('cache');
        Redis::purge('default');

        $this->getJson('/health')
            ->assertStatus(503)
            ->assertJsonPath('checks.redis', 'failing')
            ->assertJsonPath('checks.database', 'ok');
    }

    public function test_maintenance_mode_does_not_change_the_answer()
    {
        $this->recordHeartbeats();
        $this->app->maintenanceMode()->activate(['status' => 503]);

        try {
            $this->getJson('/health')->assertOk()->assertJsonPath('status', 'ok');
            $this->get('/login')->assertServiceUnavailable();
        } finally {
            $this->app->maintenanceMode()->deactivate();
        }
    }

    public function test_a_stale_scheduler_heartbeat_fails_the_scheduler_check()
    {
        $this->recordHeartbeats();

        $this->travel(Heartbeats::SCHEDULER_MAX_AGE_SECONDS + 1)->seconds();
        app(Heartbeats::class)->recordQueue();

        $this->getJson('/health')
            ->assertStatus(503)
            ->assertJsonPath('checks.scheduler', 'failing')
            ->assertJsonPath('checks.queue', 'ok');
    }

    public function test_a_stale_queue_heartbeat_fails_the_queue_check()
    {
        $this->recordHeartbeats();

        $this->travel(Heartbeats::QUEUE_MAX_AGE_SECONDS + 1)->seconds();
        app(Heartbeats::class)->recordScheduler();

        $this->getJson('/health')
            ->assertStatus(503)
            ->assertJsonPath('checks.queue', 'failing')
            ->assertJsonPath('checks.scheduler', 'ok');
    }

    public function test_heartbeats_just_inside_their_limits_still_pass()
    {
        $this->recordHeartbeats();

        $this->travel(Heartbeats::SCHEDULER_MAX_AGE_SECONDS)->seconds();

        $this->getJson('/health')->assertOk();
    }

    public function test_missing_heartbeats_after_a_fresh_install_fail()
    {
        $this->getJson('/health')
            ->assertStatus(503)
            ->assertJsonPath('checks.queue', 'failing')
            ->assertJsonPath('checks.scheduler', 'failing');
    }

    public function test_the_scheduler_records_its_heartbeat_and_queues_the_queue_heartbeat_every_minute()
    {
        Queue::fake();
        $task = $this->heartbeatTask();

        $this->assertSame('* * * * *', $task->expression);

        $task->run($this->app);

        Queue::assertPushed(RecordQueueHeartbeat::class, 1);
        $this->assertSame(['scheduler' => true, 'queue' => false], app(Heartbeats::class)->recent());
    }

    public function test_the_queued_heartbeat_records_that_a_worker_runs_jobs()
    {
        RecordQueueHeartbeat::dispatch();

        $this->assertSame(['scheduler' => false, 'queue' => true], app(Heartbeats::class)->recent());
    }

    public function test_checks_are_limited_per_address()
    {
        $this->recordHeartbeats();

        for ($check = 0; $check < LimitHealthChecks::CHECKS_PER_MINUTE; $check++) {
            $this->getJson('/health')->assertOk();
        }

        $this->getJson('/health')->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_the_check_still_answers_when_the_rate_limiter_store_is_down()
    {
        $this->recordHeartbeats();
        RateLimiter::shouldReceive('attempt')->andThrow(new RuntimeException('Connection refused'));

        $this->getJson('/health')->assertOk();
    }

    public function test_a_monitor_calling_the_public_address_gets_through_the_trusted_hosts_check()
    {
        $this->recordHeartbeats();
        config(['app.url' => 'https://hyperspace.example.com']);
        // Laravel skips the trusted hosts check in tests; production doesn't.
        $this->app['env'] = 'production';
        TrustHosts::flushState();

        $this->get('https://hyperspace.example.com/health')->assertOk();
        $this->get('https://elsewhere.example.net/health')->assertBadRequest();
    }

    public function test_the_check_uses_one_database_query()
    {
        $this->recordHeartbeats();
        DB::enableQueryLog();

        $this->getJson('/health')->assertOk();

        $this->assertCount(1, DB::getQueryLog());
    }

    /**
     * PostgreSQL's archiver statistics can't be written, so a temporary view of the same name stands
     * in for them: this session looks in its temporary schema before the system catalog.
     */
    private function pretendArchiving(string $lastArchived, string $lastFailed): void
    {
        DB::statement('drop view if exists pg_temp.pg_stat_archiver');
        DB::statement("create temporary view pg_stat_archiver as select ({$lastArchived})::timestamptz as last_archived_time, ({$lastFailed})::timestamptz as last_failed_time");
        $this->beforeApplicationDestroyed(fn () => DB::statement('drop view if exists pg_temp.pg_stat_archiver'));
    }

    private function recordHeartbeats(): void
    {
        app(Heartbeats::class)->recordScheduler();
        app(Heartbeats::class)->recordQueue();
    }

    private function heartbeatTask(): CallbackEvent
    {
        $task = collect(app(Schedule::class)->events())
            ->first(fn ($event) => $event->description === 'health-heartbeats');

        $this->assertInstanceOf(CallbackEvent::class, $task);

        return $task;
    }
}
