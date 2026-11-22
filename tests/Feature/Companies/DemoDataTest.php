<?php

namespace Tests\Feature\Companies;

use App\Companies\CurrentCompany;
use App\Companies\DemoData;
use App\Enums\Role;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use InvalidArgumentException;
use RuntimeException;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class DemoDataTest extends TestCase
{
    use RefreshDatabase;

    private DemoData $demoData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->demoData = new DemoData;
    }

    public function test_fillers_run_in_order_inside_the_demo_company_with_its_admin()
    {
        $demo = Company::factory()->demo()->create();
        $admin = User::factory()->withoutCompany()->create();
        $calls = [];

        $this->demoData->add('second', 20, function (Company $company, User $user) use (&$calls) {
            $calls[] = ['second', $company->id, $user->id, app(CurrentCompany::class)->id()];
        });
        $this->demoData->add('first', 10, function (Company $company, User $user) use (&$calls) {
            $calls[] = ['first', $company->id, $user->id, app(CurrentCompany::class)->id()];
        });

        $this->demoData->fill($demo, $admin);

        $this->assertSame([
            ['first', $demo->id, $admin->id, $demo->id],
            ['second', $demo->id, $admin->id, $demo->id],
        ], $calls);
        $this->assertSame(['first', 'second'], $this->demoData->keys());
    }

    public function test_the_previous_company_is_open_again_afterwards_even_when_a_filler_fails()
    {
        $this->demoData->add('broken', 10, fn () => throw new RuntimeException('Filler failed.'));

        try {
            $this->demoData->fill(Company::factory()->create(), User::factory()->withoutCompany()->create());
            $this->fail('The failure was swallowed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Filler failed.', $exception->getMessage());
        }

        $this->assertSame($this->company->id, app(CurrentCompany::class)->id());
    }

    public function test_keys_must_be_unique_and_lower_case()
    {
        $this->demoData->add('srm_suppliers', 10, fn () => null);

        foreach (['srm_suppliers', 'Upper', 'with-dash', ''] as $key) {
            try {
                $this->demoData->add($key, 20, fn () => null);
                $this->fail("Key [{$key}] was accepted.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_filling_creates_no_user_accounts()
    {
        $demo = Company::factory()->demo()->create();
        $admin = User::factory()->withoutCompany()->create();
        // Demo data names its first admin, who must belong to the company: as an invitation until they accept.
        Membership::factory()->invited()->create(['company_id' => $demo->id, 'user_id' => $admin->id, 'role' => Role::Admin]);
        $usersBefore = User::query()->orderBy('id')->pluck('id')->all();

        app(DemoData::class)->fill($demo, $admin);

        $this->assertSame($usersBefore, User::query()->orderBy('id')->pluck('id')->all());
    }

    public function test_every_module_adds_its_demo_data_with_sourcing_after_the_modules_it_connects()
    {
        $this->assertSame(['srm_suppliers', 'ppr_project', 'sourcing_package_suppliers'], app(DemoData::class)->keys());
    }
}
