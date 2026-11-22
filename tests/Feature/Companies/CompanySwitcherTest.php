<?php

namespace Tests\Feature\Companies;

use App\Enums\Role;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

/**
 * The company switcher at the top of the menu, and which company opens when the person arrives.
 */
class CompanySwitcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_switcher_lists_the_companies_the_person_belongs_to()
    {
        $this->company->update(['name' => 'Beta Company']);
        $user = User::factory()->create();
        $alpha = $this->joinedCompany($user, 'Alpha Company');
        $alpha->forceFill(['is_demo' => true])->save();
        $suspended = $this->joinedCompany($user, 'Gamma Company');
        $suspended->forceFill(['suspended_at' => now()])->save();
        Membership::factory()->invited()->create(['user_id' => $user->id, 'company_id' => Company::factory()->create(['name' => 'Invited Company'])->id]);
        Membership::factory()->deactivated()->create(['user_id' => $user->id, 'company_id' => Company::factory()->create(['name' => 'Left Company'])->id]);
        Company::factory()->create(['name' => 'Stranger Company']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('companies', [
                ['name' => 'Alpha Company', 'slug' => $alpha->slug, 'suspended' => false, 'is_demo' => true],
                ['name' => 'Beta Company', 'slug' => $this->company->slug, 'suspended' => false, 'is_demo' => false],
                ['name' => 'Gamma Company', 'slug' => $suspended->slug, 'suspended' => true, 'is_demo' => false],
            ]));
    }

    public function test_pages_say_whether_the_open_company_is_a_demo()
    {
        $user = User::factory()->create();
        $demo = $this->joinedCompany($user, 'Demo Company');
        $demo->forceFill(['is_demo' => true])->save();

        $this->actingAs($user)->get("/{$demo->slug}/dashboard")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('company', ['name' => 'Demo Company', 'slug' => $demo->slug, 'is_demo' => true]));
        $this->get("/{$this->company->slug}/dashboard")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('company.is_demo', false));
    }

    public function test_the_list_is_also_there_on_account_pages_and_empty_for_guests()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('companies', 1)->where('companies.0.slug', $this->company->slug));

        auth()->logout();
        $this->get(route('login'))->assertInertia(fn (AssertableInertia $page) => $page->where('companies', []));
    }

    public function test_the_list_takes_one_query_however_many_companies_there_are()
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('profile.edit'));

        $forOne = $this->queriesFor(fn () => $this->get(route('profile.edit')));

        foreach (range(1, 5) as $index) {
            $this->joinedCompany($user, "Extra Company {$index}");
        }
        $forSix = $this->queriesFor(fn () => $this->get(route('profile.edit'))->assertInertia(fn (AssertableInertia $page) => $page->has('companies', 6)));

        $this->assertSame($forOne, $forSix);
    }

    public function test_each_company_in_the_list_opens_for_the_person()
    {
        $user = User::factory()->create();
        $other = $this->joinedCompany($user, 'Other Company');

        $this->actingAs($user)->get("/{$other->slug}/dashboard")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('company.slug', $other->slug));
        $this->get("/{$this->company->slug}/dashboard")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('company.slug', $this->company->slug));
    }

    public function test_arriving_without_a_company_opens_the_one_used_last()
    {
        $this->company->update(['name' => 'Alpha Company']);
        $user = User::factory()->create();
        $zulu = $this->joinedCompany($user, 'Zulu Company');

        $this->actingAs($user)->get(route('home'))->assertRedirect("/{$this->company->slug}");

        $this->get("/{$zulu->slug}/suppliers")->assertOk();
        $this->get(route('home'))->assertRedirect("/{$zulu->slug}");

        $this->get("/{$this->company->slug}/suppliers")->assertOk();
        $this->get(route('home'))->assertRedirect("/{$this->company->slug}");
    }

    public function test_signing_in_opens_the_company_used_last()
    {
        $this->company->update(['name' => 'Alpha Company']);
        $user = User::factory()->create();
        $zulu = $this->joinedCompany($user, 'Zulu Company');
        $user->forceFill(['last_company_id' => $zulu->id])->save();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/');

        $this->get('/')->assertRedirect("/{$zulu->slug}");
    }

    public function test_a_last_company_that_was_suspended_or_left_is_skipped()
    {
        $this->company->update(['name' => 'Alpha Company']);
        $user = User::factory()->create();
        $zulu = $this->joinedCompany($user, 'Zulu Company');
        $user->forceFill(['last_company_id' => $zulu->id])->save();

        $zulu->forceFill(['suspended_at' => now()])->save();
        $this->actingAs($user)->get(route('home'))->assertRedirect("/{$this->company->slug}");

        $zulu->forceFill(['suspended_at' => null])->save();
        $user->memberships()->where('company_id', $zulu->id)->update(['deactivated_at' => now()]);
        $this->get(route('home'))->assertRedirect("/{$this->company->slug}");
    }

    private function joinedCompany(User $user, string $name, Role $role = Role::Viewer): Company
    {
        $company = Company::factory()->create(['name' => $name]);
        Membership::factory()->create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => $role]);

        return $company;
    }

    private function queriesFor(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }
}
