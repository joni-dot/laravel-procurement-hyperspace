<?php

namespace Tests\Feature;

use Database\Seeders\BrowserTestSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoCertificateSeeder;
use Database\Seeders\DemoContractSeeder;
use Database\Seeders\DemoProjectSeeder;
use Database\Seeders\DemoRatingSeeder;
use Database\Seeders\DemoSupplierSeeder;
use Illuminate\Database\Seeder;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class SeedersRefuseProductionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  class-string<Seeder>  $seeder
     */
    #[DataProvider('seeders')]
    public function test_the_seeder_adds_nothing_in_production(string $seeder): void
    {
        $this->app['env'] = 'production';

        $this->assertThrows(
            fn () => $this->app->call([$this->app->make($seeder), 'run']),
            RuntimeException::class,
            'production',
        );

        $this->assertDatabaseEmpty('users');
        $this->assertDatabaseEmpty('suppliers');
        $this->assertDatabaseEmpty('projects');
    }

    /**
     * @return array<string, array{class-string<Seeder>}>
     */
    public static function seeders(): array
    {
        return [
            'default admin' => [DatabaseSeeder::class],
            'demo suppliers' => [DemoSupplierSeeder::class],
            'demo certificates' => [DemoCertificateSeeder::class],
            'demo contracts' => [DemoContractSeeder::class],
            'demo ratings' => [DemoRatingSeeder::class],
            'demo project' => [DemoProjectSeeder::class],
            'browser test data' => [BrowserTestSeeder::class],
        ];
    }
}
