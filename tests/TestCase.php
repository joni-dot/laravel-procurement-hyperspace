<?php

namespace Tests;

use App\Companies\CurrentCompany;
use App\Models\Company;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use Tests\Concerns\RefreshDatabase;

abstract class TestCase extends BaseTestCase
{
    /**
     * The company each database test starts in, so factories and requests work as they would for
     * someone inside one company. Tests about several companies create more with Company::factory().
     */
    protected ?Company $company = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (in_array(RefreshDatabase::class, class_uses_recursive($this), true)) {
            $this->enterCompany(Company::factory()->create());
        }
    }

    protected function enterCompany(Company $company): Company
    {
        app(CurrentCompany::class)->set($company);

        return $this->company = $company;
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
