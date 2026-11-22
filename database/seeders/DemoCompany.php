<?php

namespace Database\Seeders;

use App\Companies\CurrentCompany;
use App\Enums\Role;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;

/**
 * The made-up company the demo seeders fill, marked as a demo, with an admin to sign in as
 * (test@example.com, password "password"). Created the first time a demo seeder runs.
 */
final class DemoCompany
{
    public const string SLUG = 'demo';

    public static function enter(): Company
    {
        $company = Company::query()->where('slug', self::SLUG)->first()
            ?? Company::factory()->demo()->create(['name' => 'Demo Company', 'slug' => self::SLUG]);
        // Made-up data, so it is marked "Demo" wherever it is named, also in databases seeded before the mark existed.
        $company->forceFill(['is_demo' => true])->save();

        app(CurrentCompany::class)->set($company);

        $admin = User::query()->where('email', 'test@example.com')->first()
            ?? User::factory()->withoutCompany()->create(['name' => 'Test User', 'email' => 'test@example.com']);

        Membership::query()->firstOrCreate(
            ['company_id' => $company->id, 'user_id' => $admin->id],
            ['role' => Role::Admin, 'joined_at' => now()],
        );

        return $company;
    }
}
