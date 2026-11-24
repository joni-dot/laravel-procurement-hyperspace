<?php

namespace Tests\Feature\Platform;

use App\Auth\StrongSignIn;
use App\Enums\Role;
use App\Http\Controllers\Platform\SearchMembersToPromoteController;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

/**
 * The platform admin chooses a new admin for a company whose admins have all left by searching its
 * members, a few at a time, only while the choice is being made.
 */
class SearchMembersToPromoteTest extends TestCase
{
    use RefreshDatabase;

    private User $platformAdmin;

    private Company $acme;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdmin = User::factory()->platformAdmin()->withTwoFactor()->create();
        $this->withSession(['auth.password_confirmed_at' => time(), StrongSignIn::SESSION_KEY => $this->platformAdmin->id]);
        $this->acme = Company::factory()->create(['name' => 'Acme']);
    }

    public function test_it_finds_at_most_twenty_members_by_name_ordered_by_name()
    {
        // Added last name first, so the order can't come from the order they were added in.
        $members = collect(range(25, 1))->mapWithKeys(fn (int $number) => [$number => $this->addMember(['name' => sprintf('Member %02d', $number)])]);

        $found = $this->search()->assertOk()->json('members');

        $this->assertCount(SearchMembersToPromoteController::MAX_RESULTS, $found);
        $this->assertSame(
            collect(range(1, 20))->map(fn (int $number) => $members[$number]->id)->all(),
            array_column($found, 'id'),
        );
        $this->assertSame(['id', 'name', 'email'], array_keys($found[0]));
    }

    public function test_it_searches_names_and_emails_and_takes_the_typed_text_literally()
    {
        $byName = $this->addMember(['name' => 'Ville Koskinen', 'email' => 'v1@example.com']);
        $byEmail = $this->addMember(['name' => 'Someone', 'email' => 'kaisa.virtanen@example.com']);
        $this->addMember(['name' => 'Other Person', 'email' => 'other@example.com']);
        $percent = $this->addMember(['name' => '100% Sure', 'email' => 'sure@example.com']);

        $this->assertSame([$byName->id], $this->search('KOSKI')->json('members.*.id'));
        $this->assertSame([$byEmail->id], $this->search('virtanen@')->json('members.*.id'));
        $this->assertSame([$percent->id], $this->search('%')->json('members.*.id'));
    }

    public function test_it_finds_only_active_members_with_active_accounts_of_that_company()
    {
        $active = $this->addMember(['name' => 'Wanted Active']);
        Membership::factory()->deactivated()->create(['company_id' => $this->acme->id, 'user_id' => User::factory()->withoutCompany()->create(['name' => 'Wanted Left'])->id]);
        Membership::factory()->invited()->create(['company_id' => $this->acme->id, 'user_id' => User::factory()->withoutCompany()->create(['name' => 'Wanted Invited'])->id]);
        Membership::factory()->create(['company_id' => $this->acme->id, 'user_id' => User::factory()->withoutCompany()->deactivated()->create(['name' => 'Wanted Deactivated'])->id]);
        Membership::factory()->create(['company_id' => Company::factory()->create()->id, 'user_id' => User::factory()->withoutCompany()->create(['name' => 'Wanted Elsewhere'])->id]);
        Membership::factory()->create(['company_id' => $this->acme->id, 'user_id' => $this->platformAdmin->id]);
        $this->platformAdmin->forceFill(['name' => 'Wanted Platform'])->save();

        $this->assertSame([$active->id], $this->search('wanted')->json('members.*.id'));
    }

    public function test_a_company_with_an_admin_is_refused()
    {
        $this->addMember();
        Membership::factory()->create(['company_id' => $this->acme->id, 'role' => Role::Admin]);

        $this->search()->assertForbidden();
    }

    public function test_only_platform_admins_can_search()
    {
        $this->addMember();

        foreach ([User::factory()->admin()->withTwoFactor()->create(), User::factory()->withoutCompany()->create()] as $user) {
            $this->actingAs($user)
                ->getJson(route('platform.companies.members-to-promote', $this->acme))
                ->assertNotFound();
        }
    }

    public function test_it_has_a_limit_of_its_own()
    {
        $middleware = Route::getRoutes()->getByName('platform.companies.members-to-promote')?->gatherMiddleware() ?? [];

        $this->assertContains('throttle:60,1,platform-member-search', $middleware);
        $this->assertContains('platform', $middleware);
    }

    public function test_the_number_of_queries_does_not_grow_with_the_members()
    {
        $this->addMembers(2);
        $few = $this->countQueriesOfTheSearch();

        $this->addMembers(30);

        $this->assertSame($few, $this->countQueriesOfTheSearch());
    }

    private function search(string $search = ''): TestResponse
    {
        return $this->actingAs($this->platformAdmin)
            ->getJson(route('platform.companies.members-to-promote', ['company' => $this->acme, 'search' => $search]));
    }

    /**
     * @param  array<string, string>  $user
     */
    private function addMember(array $user = []): Membership
    {
        return Membership::factory()->create([
            'company_id' => $this->acme->id,
            'user_id' => User::factory()->withoutCompany()->create($user)->id,
        ]);
    }

    private function addMembers(int $count): void
    {
        foreach (range(1, $count) as $_) {
            $this->addMember();
        }
    }

    private function countQueriesOfTheSearch(): int
    {
        // Each request then starts the same way: inside a company, which the platform pages leave.
        $this->enterCompany($this->company);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->search()->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }
}
