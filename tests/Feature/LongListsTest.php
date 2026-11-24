<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Ppr\Actions\Packages\CreatePackage;
use App\Modules\Ppr\Enums\ProjectRole;
use App\Modules\Ppr\Models\Project;
use App\Modules\Ppr\Models\ProjectCategory;
use App\Modules\Srm\Models\Category;
use App\Modules\Srm\Models\CertificateType;
use App\Modules\Srm\Models\ContractType;
use App\Modules\Srm\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

/**
 * Lists in forms are limited before their items are checked, so a long list is refused at once
 * instead of keeping the server busy checking every item against every other.
 */
class LongListsTest extends TestCase
{
    use RefreshDatabase;

    private const int LONG = 10_000;

    public function test_certificate_reminders_are_limited_before_their_items_are_checked()
    {
        $supplier = Supplier::factory()->create();
        $this->actingAs(User::factory()->buyer()->create());

        $response = $this->timed(fn () => $this->post(route('suppliers.certificates.store', $supplier), [
            'certificate_type_id' => CertificateType::factory()->create()->id,
            'reminder_days' => $this->longList(),
        ]));

        $this->assertRefusedWithoutCheckingEveryItem($response, 'reminder_days');
    }

    public function test_contract_reminders_are_limited_before_their_items_are_checked()
    {
        $supplier = Supplier::factory()->create();
        $this->actingAs(User::factory()->buyer()->create());

        $response = $this->timed(fn () => $this->post(route('suppliers.contracts.store', $supplier), [
            'title' => 'Steel supply',
            'contract_type_id' => ContractType::query()->value('id'),
            'status' => 'active',
            'reminder_days' => $this->longList(),
        ]));

        $this->assertRefusedWithoutCheckingEveryItem($response, 'reminder_days');
    }

    public function test_supplier_categories_are_limited_before_their_items_are_checked()
    {
        $this->actingAs(User::factory()->buyer()->create());

        $response = $this->timed(fn () => $this->post(route('suppliers.store'), [
            'name' => 'Nordic Steel Oy',
            'status' => 'active',
            'country_code' => 'SE',
            'category_ids' => $this->longList(),
        ]));

        $this->assertRefusedWithoutCheckingEveryItem($response, 'category_ids');
    }

    public function test_supplier_categories_are_checked_with_one_query()
    {
        $this->actingAs(User::factory()->buyer()->create());
        $categories = Category::factory()->count(5)->create();
        $input = ['name' => 'Nordic Steel Oy', 'status' => 'active', 'country_code' => 'SE'];

        $categoryQueries = $this->countCategoryQueries(fn () => $this->post(route('suppliers.store'), [
            ...$input,
            'category_ids' => $categories->pluck('id')->all(),
        ])->assertSessionHasNoErrors());

        $this->assertSame(1, $categoryQueries);

        $this->post(route('suppliers.store'), [...$input, 'name' => 'Other Oy', 'category_ids' => [$categories[0]->id, 999999]])
            ->assertSessionHasErrors('category_ids');
    }

    public function test_api_client_scopes_are_limited_before_their_items_are_checked()
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()]);

        $response = $this->timed(fn () => $this->post(route('admin.api-clients.store'), [
            'name' => 'ERP integration',
            'scopes' => array_fill(0, self::LONG, 'suppliers:read'),
            'responsible_user_id' => $admin->id,
        ]));

        $this->assertRefusedWithoutCheckingEveryItem($response, 'scopes');
    }

    public function test_package_dates_are_limited_before_their_items_are_checked()
    {
        $manager = User::factory()->create();
        $project = Project::factory()->withDefaultMilestones()->withMember($manager, ProjectRole::ProjectManager)->create();
        $category = ProjectCategory::factory()->for($project)->create(['code' => 'ELE']);
        $package = app(CreatePackage::class)->handle($project, 'Transformer', $category, null);
        $row = ['id' => $project->milestones()->value('id'), 'planned_date' => null, 'forecast_date' => null, 'actual_date' => null, 'lead_days' => null];

        $response = $this->timed(fn () => $this->actingAs($manager)->put(
            route('projects.packages.schedule.update', [$project, $package]),
            ['milestones' => array_fill(0, self::LONG, $row)],
        ));

        $this->assertRefusedWithoutCheckingEveryItem($response, 'milestones');
    }

    /**
     * The same value over and over, so that checking the items one by one would also find them repeated.
     *
     * @return list<int>
     */
    private function longList(): array
    {
        return array_fill(0, self::LONG, 1);
    }

    /**
     * @param  callable(): TestResponse<Response>  $request
     * @return TestResponse<Response>
     */
    private function timed(callable $request): TestResponse
    {
        $started = hrtime(true);
        $response = $request();

        // Loose on purpose: a slow test machine must not fail it, a check of every item would.
        $this->assertLessThan(1.0, (hrtime(true) - $started) / 1e9, 'The long list took too long to refuse.');

        return $response;
    }

    /**
     * @param  TestResponse<Response>  $response
     */
    private function assertRefusedWithoutCheckingEveryItem(TestResponse $response, string $field): void
    {
        $response->assertSessionHasErrors($field);

        $lastItem = $field.'.'.(self::LONG - 1);
        $errorFields = array_keys(session('errors')->getBag('default')->messages());
        $this->assertSame([], preg_grep('/^'.preg_quote($lastItem, '/').'(\\.|$)/', $errorFields), 'Items past the limit must not be checked.');
    }

    private function countCategoryQueries(callable $request): int
    {
        $count = 0;
        DB::listen(function ($query) use (&$count) {
            if (str_contains($query->sql, '"categories"') && str_starts_with(strtolower($query->sql), 'select')) {
                $count++;
            }
        });

        $request();

        return $count;
    }
}
