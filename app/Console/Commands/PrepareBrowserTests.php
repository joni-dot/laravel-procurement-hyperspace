<?php

namespace App\Console\Commands;

use Database\Seeders\BrowserTestSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\ConfigurationUrlParser;

class PrepareBrowserTests extends Command
{
    protected $signature = 'app:prepare-browser-tests';

    protected $description = 'Empty the testing database and fill it with the browser test data';

    public function handle(): int
    {
        if (! $this->isUsingTheTestingDatabase()) {
            $this->components->error('Refusing to continue: this erases the database, so it only runs in the testing environment on the "testing" database.');

            return self::FAILURE;
        }

        return $this->call('migrate:fresh', [
            '--database' => 'owner',
            '--force' => true,
            '--seed' => true,
            '--seeder' => BrowserTestSeeder::class,
        ]);
    }

    /**
     * The owner connection does the erasing and can have its own address (DB_OWNER_URL), so both
     * connections must really point at the "testing" database.
     */
    private function isUsingTheTestingDatabase(): bool
    {
        return app()->environment('testing')
            && $this->configuredDatabase((string) config('database.default')) === 'testing'
            && $this->configuredDatabase('owner') === 'testing';
    }

    /**
     * The database a connection is set up for, with a connection URL (which wins) taken into account.
     */
    private function configuredDatabase(string $connection): mixed
    {
        $config = (new ConfigurationUrlParser)->parseConfiguration((array) config("database.connections.{$connection}"));

        return $config['database'] ?? null;
    }
}
