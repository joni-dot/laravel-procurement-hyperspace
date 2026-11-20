<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class PrepareBrowserTestsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_refuses_to_erase_any_other_database(): void
    {
        $user = User::factory()->create();
        config(['database.connections.pgsql.database' => 'hyperspace']);

        $this->artisan('app:prepare-browser-tests')->assertFailed();

        $this->assertModelExists($user);
    }

    public function test_it_refuses_when_the_owner_connection_points_at_another_database(): void
    {
        $user = User::factory()->create();
        // A database that doesn't exist, so nothing real could be erased even if the check failed.
        config(['database.connections.owner.url' => 'pgsql://nobody@127.0.0.1:1/not_the_testing_database']);

        $this->artisan('app:prepare-browser-tests')
            ->expectsOutputToContain('Refusing to continue')
            ->assertFailed();

        $this->assertModelExists($user);
    }

    public function test_it_refuses_to_run_outside_the_testing_environment(): void
    {
        $user = User::factory()->create();
        $this->app['env'] = 'local';

        $this->artisan('app:prepare-browser-tests')->assertFailed();

        $this->assertModelExists($user);
    }
}
