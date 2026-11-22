<?php

namespace Tests\Feature\Onboarding;

use App\Enums\Role;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use App\Onboarding\OnboardingSteps;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Support\Header;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

/**
 * The getting-started checklist at the top of Home: for the company's admins, not in demo companies, until
 * every step is done or an admin hides it for the whole company.
 */
class OnboardingChecklistTest extends TestCase
{
    use RefreshDatabase;

    private const array STEP_KEYS = ['add_suppliers', 'choose_certificates', 'choose_reminder_email', 'create_project', 'invite_colleagues'];

    public function test_an_admin_gets_the_checklist_first_with_every_step_in_order_and_a_link_to_each()
    {
        $admin = User::factory()->admin()->create();

        $this->home($admin)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('sections.0', ['key' => 'onboarding_checklist', 'component' => 'onboarding/Checklist'])
            ->where('section_onboarding_checklist.doneCount', 0)
            ->where('section_onboarding_checklist.steps', fn ($steps) => collect($steps)->pluck('key')->all() === self::STEP_KEYS)
            ->where('section_onboarding_checklist.steps.0', [
                'key' => 'add_suppliers',
                'title' => 'Add your suppliers',
                'description' => 'Import them from a spreadsheet, or add them one by one.',
                'url' => route('suppliers.import.create', absolute: false),
                'done' => false,
            ])
            ->where('section_onboarding_checklist.steps.1.url', route('admin.certificate-types.index', absolute: false))
            ->where('section_onboarding_checklist.steps.2.url', route('admin.settings.edit', absolute: false))
            ->where('section_onboarding_checklist.steps.3.url', route('projects.index', absolute: false))
            ->where('section_onboarding_checklist.steps.4.url', route('admin.users.index', absolute: false))
            ->missing('section_onboarding_show_again'));
    }

    #[DataProvider('nonAdmins')]
    public function test_buyers_and_viewers_never_get_the_checklist(Role $role)
    {
        $person = $this->personWith($role);

        $this->assertNotContains('onboarding_checklist', $this->sectionKeys($person));
        $this->loadSections($person, ['section_onboarding_checklist', 'section_onboarding_show_again'])
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->missing('section_onboarding_checklist')
                ->missing('section_onboarding_show_again'));

        $this->company->forceFill(['onboarding_hidden_at' => now()])->save();
        $this->assertNotContains('onboarding_show_again', $this->sectionKeys($person));
    }

    /**
     * @return array<string, array{Role}>
     */
    public static function nonAdmins(): array
    {
        return ['buyer' => [Role::Buyer], 'viewer' => [Role::Viewer]];
    }

    public function test_a_demo_company_has_no_checklist()
    {
        $admin = User::factory()->admin()->create();
        $this->company->forceFill(['is_demo' => true])->save();

        $this->assertSame([], array_intersect(['onboarding_checklist', 'onboarding_show_again'], $this->sectionKeys($admin)));
    }

    public function test_a_hidden_checklist_leaves_admins_a_link_at_the_bottom_to_show_it_again()
    {
        $admin = User::factory()->admin()->create();
        $this->company->forceFill(['onboarding_hidden_at' => now()])->save();

        $keys = $this->sectionKeys($admin);

        $this->assertNotContains('onboarding_checklist', $keys);
        $this->assertSame('onboarding_show_again', end($keys));
        $this->home($admin)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('section_onboarding_show_again', ['doneCount' => 0, 'total' => count(self::STEP_KEYS)]));
    }

    public function test_once_every_step_is_done_the_checklist_and_its_link_are_gone()
    {
        $admin = User::factory()->admin()->create();
        $this->pretendEveryStepIsDone();

        $this->assertSame([], array_intersect(['onboarding_checklist', 'onboarding_show_again'], $this->sectionKeys($admin)));

        $this->company->forceFill(['onboarding_hidden_at' => now()])->save();
        $this->assertSame([], array_intersect(['onboarding_checklist', 'onboarding_show_again'], $this->sectionKeys($admin)));
    }

    public function test_a_finished_checklist_stays_away_even_when_a_step_opens_again_and_checks_nothing()
    {
        $admin = User::factory()->admin()->create();
        $this->pretendEveryStepIsDone();
        $this->sectionKeys($admin);
        $this->assertNotNull($this->company->fresh()->onboarding_completed_at);

        // Later a step looks open again, for example when the only colleague leaves.
        $checked = false;
        $steps = new OnboardingSteps;
        $steps->add('reopened_step', 10, fn () => 'Step', fn () => 'Open again.', fn () => '/', function () use (&$checked) {
            $checked = true;

            return false;
        });
        $this->app->instance(OnboardingSteps::class, $steps);

        $this->assertSame([], array_intersect(['onboarding_checklist', 'onboarding_show_again'], $this->sectionKeys($admin)));
        $this->assertFalse($checked);
    }

    public function test_done_steps_are_ticked_and_counted()
    {
        $admin = User::factory()->admin()->create();
        Membership::factory()->invited()->create(['company_id' => $this->company->id, 'role' => Role::Buyer]);

        $this->home($admin)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('section_onboarding_checklist.doneCount', 1)
            ->where('section_onboarding_checklist.steps.4.key', 'invite_colleagues')
            ->where('section_onboarding_checklist.steps.4.done', true)
            ->where('section_onboarding_checklist.steps.0.done', false));
    }

    public function test_inviting_a_colleague_ticks_the_invite_step()
    {
        User::factory()->admin()->create();
        $this->assertFalse($this->inviteStepDone());

        // Someone in another company, or a withdrawn invitation, doesn't count.
        Membership::factory()->create(['company_id' => Company::factory()->create()->id, 'role' => Role::Buyer]);
        Membership::factory()->invited()->deactivated()->create(['company_id' => $this->company->id]);
        $this->assertFalse($this->inviteStepDone());

        Membership::factory()->invited()->create(['company_id' => $this->company->id, 'role' => Role::Viewer]);
        $this->assertTrue($this->inviteStepDone());
    }

    public function test_an_admin_hides_and_shows_the_checklist_for_the_whole_company()
    {
        $admin = User::factory()->admin()->create();
        $otherAdmin = User::factory()->admin()->create();
        $this->travelTo('2027-03-10 12:00:00');

        $this->actingAs($admin)->post(route('onboarding.hide'))->assertRedirect(route('company.home'));

        $this->assertSame('2027-03-10 12:00:00', $this->company->refresh()->onboarding_hidden_at?->toDateTimeString());
        $this->assertNotContains('onboarding_checklist', $this->sectionKeys($otherAdmin));

        $this->actingAs($otherAdmin)->post(route('onboarding.show'))->assertRedirect(route('company.home'));

        $this->assertNull($this->company->refresh()->onboarding_hidden_at);
        $this->assertContains('onboarding_checklist', $this->sectionKeys($admin));
    }

    public function test_hiding_reloads_only_the_checklists_parts_of_home()
    {
        $admin = User::factory()->admin()->create();
        $only = ['sections', 'section_onboarding_checklist', 'section_onboarding_show_again'];

        // The browser sends the same headers again when it follows the redirect to Home.
        $props = $this->actingAs($admin)
            ->withHeaders([Header::PARTIAL_COMPONENT => 'Home', Header::PARTIAL_ONLY => implode(',', $only)])
            ->followingRedirects()
            ->post(route('onboarding.hide'))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame(['sections', 'section_onboarding_show_again'], array_values(array_intersect(array_keys($props), [...$only, 'section_srm_attention', 'section_ppr_projects'])));
        $this->assertSame(['doneCount' => 0, 'total' => count(self::STEP_KEYS)], $props['section_onboarding_show_again']);
    }

    #[DataProvider('nonAdmins')]
    public function test_only_admins_hide_or_show_the_checklist(Role $role)
    {
        $person = $this->personWith($role);

        $this->actingAs($person)->post(route('onboarding.hide'))->assertForbidden();
        $this->assertNull($this->company->refresh()->onboarding_hidden_at);

        $this->company->forceFill(['onboarding_hidden_at' => now()])->save();
        $this->actingAs($person)->post(route('onboarding.show'))->assertForbidden();
        $this->assertNotNull($this->company->refresh()->onboarding_hidden_at);
    }

    public function test_an_admin_of_another_company_cannot_touch_this_companys_checklist()
    {
        $other = Company::factory()->create();
        $outsider = User::factory()->withoutCompany()->create();
        Membership::factory()->create(['company_id' => $other->id, 'user_id' => $outsider->id, 'role' => Role::Admin]);

        $this->actingAs($outsider)->post(route('onboarding.hide', ['company' => $this->company->slug]))->assertNotFound();
        $this->assertNull($this->company->refresh()->onboarding_hidden_at);

        $this->company->forceFill(['onboarding_hidden_at' => now()])->save();
        $this->actingAs($outsider)->post(route('onboarding.show', ['company' => $this->company->slug]))->assertNotFound();
        $this->assertNotNull($this->company->refresh()->onboarding_hidden_at);
    }

    public function test_guests_are_sent_to_sign_in()
    {
        $this->post(route('onboarding.hide'))->assertRedirect(route('login'));
        $this->post(route('onboarding.show'))->assertRedirect(route('login'));
        $this->assertNull($this->company->refresh()->onboarding_hidden_at);
    }

    public function test_the_checklist_costs_home_one_query_per_step_and_nothing_for_anyone_else()
    {
        $admin = User::factory()->admin()->create();
        $buyer = User::factory()->buyer()->create();
        // The first visit to a company also records it as the person's last used one; later visits don't.
        $this->home($admin);
        $this->home($buyer);

        $withChecklist = $this->countQueries(fn () => $this->home($admin));
        $this->company->forceFill(['is_demo' => true])->save();
        $withoutChecklist = $this->countQueries(fn () => $this->home($admin));

        $this->assertSame(count(self::STEP_KEYS), $withChecklist - $withoutChecklist);

        // A buyer's Home reads nothing for the checklist, demo company or not.
        $buyerInDemo = $this->countQueries(fn () => $this->home($buyer));
        $this->company->forceFill(['is_demo' => false])->save();
        $this->assertSame($buyerInDemo, $this->countQueries(fn () => $this->home($buyer)));

        // Hidden, the steps are still checked once, for the link at the bottom.
        $this->company->forceFill(['onboarding_hidden_at' => now()])->save();
        $this->assertSame($withChecklist, $this->countQueries(fn () => $this->home($admin)));
    }

    // Viewer is the factory's default role.
    private function personWith(Role $role): User
    {
        return ($role === Role::Buyer ? User::factory()->buyer() : User::factory())->create();
    }

    private function inviteStepDone(): bool
    {
        $progress = app(OnboardingSteps::class)->progressOf($this->company);

        return collect($progress)->firstWhere('key', 'invite_colleagues')['done'];
    }

    private function pretendEveryStepIsDone(): void
    {
        $steps = new OnboardingSteps;
        $steps->add('only_step', 10, fn () => 'Only step', fn () => 'Done already.', fn () => '/', fn () => true);
        $this->app->instance(OnboardingSteps::class, $steps);
    }

    private function home(User $user): TestResponse
    {
        return $this->actingAs($user)->get(route('company.home'))->assertOk();
    }

    /**
     * @return list<string>
     */
    private function sectionKeys(User $user): array
    {
        return array_column($this->home($user)->viewData('page')['props']['sections'], 'key');
    }

    /**
     * @param  list<string>  $props
     */
    private function loadSections(User $user, array $props): TestResponse
    {
        return $this->actingAs($user)->get(route('company.home'), [
            Header::PARTIAL_COMPONENT => 'Home',
            Header::PARTIAL_ONLY => implode(',', $props),
        ])->assertOk();
    }

    private function countQueries(callable $work): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $work();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
