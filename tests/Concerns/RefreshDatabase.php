<?php

namespace Tests\Concerns;

use Illuminate\Foundation\Testing\RefreshDatabase as LaravelRefreshDatabase;

/**
 * Laravel's RefreshDatabase, with the migrations run as the database owner. The tests themselves
 * use the app's restricted user, so the companies' row-level security applies to them as in
 * production.
 */
trait RefreshDatabase
{
    use LaravelRefreshDatabase {
        migrateFreshUsing as laravelMigrateFreshUsing;
    }

    /**
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing(): array
    {
        return [...$this->laravelMigrateFreshUsing(), '--database' => 'owner'];
    }
}
