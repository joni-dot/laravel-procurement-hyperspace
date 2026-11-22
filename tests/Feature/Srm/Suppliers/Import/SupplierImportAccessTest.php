<?php

namespace Tests\Feature\Srm\Suppliers\Import;

use App\Companies\CurrentCompany;
use App\Enums\Role;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use App\Modules\Srm\Models\Contact;
use App\Modules\Srm\Models\Supplier;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class SupplierImportAccessTest extends TestCase
{
    use ImportsSupplierFiles;
    use RefreshDatabase;

    /**
     * @return array<string, array{string, string}>
     */
    public static function importRoutes(): array
    {
        return [
            'page' => ['get', 'suppliers.import.create'],
            'template' => ['get', 'suppliers.import.template'],
            'preview' => ['post', 'suppliers.import.preview'],
            'import' => ['post', 'suppliers.import.store'],
        ];
    }

    #[DataProvider('importRoutes')]
    public function test_guests_are_sent_to_sign_in(string $method, string $route)
    {
        $this->call($method, route($route))->assertRedirect(route('login'));
    }

    #[DataProvider('importRoutes')]
    public function test_viewers_cannot_import(string $method, string $route)
    {
        $this->actingAs(User::factory()->create())
            ->call($method, route($route), files: ['file' => $this->csvFile([['Name', 'Country'], ['Nordic Steel Oy', 'FI']])])
            ->assertForbidden();

        $this->assertSame(0, Supplier::count());
    }

    #[DataProvider('importRoutes')]
    public function test_members_of_another_company_get_not_found(string $method, string $route)
    {
        $outsider = app(CurrentCompany::class)->runAs(Company::factory()->create(), fn () => User::factory()->admin()->create());

        $this->actingAs($outsider)
            ->call($method, route($route), files: ['file' => $this->csvFile([['Name', 'Country'], ['Nordic Steel Oy', 'FI']])])
            ->assertNotFound();

        $this->assertSame(0, Supplier::query()->withoutGlobalScope('company')->count());
    }

    public function test_buyers_and_admins_open_the_import_page_and_see_the_import_button()
    {
        foreach ([User::factory()->buyer()->create(), User::factory()->admin()->create()] as $user) {
            $this->actingAs($user)->get(route('suppliers.import.create'))
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->component('srm/suppliers/Import')
                    ->where('maxRows', 5000)
                    ->where('canCreateCategories', $user->role === Role::Admin)
                    ->has('columns', 22));

            $this->get(route('suppliers.index'))
                ->assertInertia(fn (AssertableInertia $page) => $page->where('can.create', true));
        }
    }

    public function test_an_import_goes_into_the_current_company_only()
    {
        $person = User::factory()->admin()->create();
        Membership::factory()->for(Company::factory())->create(['user_id' => $person->id, 'role' => Role::Admin]);

        $this->actingAs($person)->import($this->csvFile([['Name', 'Country', 'Contact name'], ['Nordic Steel Oy', 'FI', 'Maija']]))
            ->assertSessionHasNoErrors();

        $this->assertSame([$this->company->id], Supplier::query()->withoutGlobalScope('company')->pluck('company_id')->all());
        $this->assertSame([$this->company->id], Contact::query()->withoutGlobalScope('company')->pluck('company_id')->all());
    }

    public function test_previews_and_imports_are_limited_per_person()
    {
        $this->actingAs(User::factory()->buyer()->create());
        $file = $this->csvFile([['Name', 'Country'], ['Nordic Steel Oy', 'XX']]);

        for ($i = 0; $i < 20; $i++) {
            $this->post(route('suppliers.import.preview'), ['file' => $file])->assertRedirect();
        }

        $this->post(route('suppliers.import.preview'), ['file' => $file])->assertTooManyRequests();
        $this->post(route('suppliers.import.store'), ['file' => $file])->assertTooManyRequests();

        $this->actingAs(User::factory()->buyer()->create())
            ->post(route('suppliers.import.preview'), ['file' => $file])->assertRedirect();
    }
}
