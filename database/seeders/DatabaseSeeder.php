<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    // Model events stay on: a new company records its address and gets its starting lists through them.

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The default admin must never be added to production.');
        }

        DemoCompany::enter();
    }
}
