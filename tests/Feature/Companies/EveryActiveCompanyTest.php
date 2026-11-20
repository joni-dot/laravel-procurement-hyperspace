<?php

namespace Tests\Feature\Companies;

use App\Companies\CurrentCompany;
use App\Companies\EveryActiveCompany;
use App\Models\Company;
use Illuminate\Support\Facades\Exceptions;
use RuntimeException;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class EveryActiveCompanyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_work_runs_inside_each_active_company_and_the_company_before_is_restored()
    {
        $other = Company::factory()->create();
        Company::factory()->suspended()->create();
        $visited = [];

        $succeeded = app(EveryActiveCompany::class)->run(function (Company $company) use (&$visited) {
            $visited[] = [$company->id, app(CurrentCompany::class)->id()];
        });

        $this->assertTrue($succeeded);
        $this->assertEqualsCanonicalizing([[$this->company->id, $this->company->id], [$other->id, $other->id]], $visited);
        $this->assertSame($this->company->id, app(CurrentCompany::class)->id());
    }

    public function test_a_failure_in_one_company_is_reported_and_the_others_still_run()
    {
        Exceptions::fake();
        $failing = Company::factory()->create();
        $last = Company::factory()->create();
        $visited = [];

        $succeeded = app(EveryActiveCompany::class)->run(function (Company $company) use ($failing, &$visited) {
            throw_if($company->is($failing), new RuntimeException('Broken data'));
            $visited[] = $company->id;
        });

        $this->assertFalse($succeeded);
        $this->assertContains($last->id, $visited);
        Exceptions::assertReported(RuntimeException::class);
    }
}
