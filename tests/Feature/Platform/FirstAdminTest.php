<?php

namespace Tests\Feature\Platform;

use App\Actions\Companies\CreateCompany;
use App\Enums\Role;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use App\Notifications\UserInvitation;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

/**
 * A company whose first admin never accepted (or whose address was mistyped) can be got going again
 * by a platform admin, but only while it has no admin of its own.
 */
class FirstAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $platformAdmin;

    private Company $acme;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->platformAdmin = User::factory()->platformAdmin()->withTwoFactor()->create();
        $this->withSession(['auth.password_confirmed_at' => time()]);
        $this->acme = app(CreateCompany::class)->handle('Acme', 'acme', 'Anna Admin', 'ana@exmaple.com', $this->platformAdmin)->company;
    }

    public function test_the_list_offers_it_only_for_companies_without_an_admin()
    {
        $running = Company::factory()->create(['name' => 'Running Oy']);
        Membership::factory()->create(['company_id' => $running->id, 'role' => Role::Admin]);

        $this->actingAs($this->platformAdmin)
            ->get(route('platform.companies.index', ['search' => 'acme']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('companies.data.0.first_admin_invitation', ['name' => 'Anna Admin', 'email' => 'ana@exmaple.com']));

        $this->actingAs($this->platformAdmin)
            ->get(route('platform.companies.index', ['search' => 'running']))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('companies.data.0.first_admin_invitation', null));
    }

    public function test_sending_again_to_the_same_address_replaces_the_link()
    {
        $invitation = $this->acme->pendingAdminInvitation()->sole();
        $oldToken = $invitation->invitation_token;

        $this->sendFirstAdmin('Anna Admin', 'ana@exmaple.com')->assertSessionHasNoErrors();

        $this->assertNotSame($oldToken, $invitation->fresh()->invitation_token);
        $this->assertSame(1, $this->acme->memberships()->pendingInvitation()->count());
        Notification::assertSentToTimes($invitation->user, UserInvitation::class, 2);
    }

    public function test_a_corrected_address_gets_the_invitation_and_the_mistyped_ones_link_stops_working()
    {
        $mistyped = $this->acme->pendingAdminInvitation()->sole();

        $this->sendFirstAdmin('Anna Admin', 'anna@example.com')->assertSessionHasNoErrors();

        $mistyped->refresh();
        $this->assertNotNull($mistyped->deactivated_at);
        $this->assertNull($mistyped->invitation_token);
        $corrected = $this->acme->pendingAdminInvitation()->sole();
        $this->assertSame('anna@example.com', $corrected->known_email);
        $this->assertSame(Role::Admin, $corrected->role);
        Notification::assertSentTo($corrected->user, UserInvitation::class);
    }

    public function test_a_company_with_an_admin_is_left_to_its_own_admins()
    {
        Membership::factory()->create(['company_id' => $this->acme->id, 'role' => Role::Admin]);

        $this->sendFirstAdmin('Someone Else', 'someone@example.com')->assertSessionHasErrors('admin_email');

        $this->assertSame(0, Membership::query()->where('known_email', 'someone@example.com')->count());
        $this->assertFalse(User::query()->where('email', 'someone@example.com')->exists());
    }

    public function test_the_answer_is_the_same_whether_or_not_the_address_has_an_account()
    {
        User::factory()->withoutCompany()->create(['email' => 'existing@example.com', 'name' => 'Their Own Name']);

        $this->sendFirstAdmin('Typed Name', 'existing@example.com');
        $withAccount = session(SessionKey::FLASH_DATA)['toast']['message'] ?? null;
        $this->sendFirstAdmin('Typed Name', 'brand.new@example.com');
        $withoutAccount = session(SessionKey::FLASH_DATA)['toast']['message'] ?? null;

        $this->assertSame(
            str_replace('existing@example.com', 'X', (string) $withAccount),
            str_replace('brand.new@example.com', 'X', (string) $withoutAccount),
        );
        $this->assertSame('Their Own Name', User::query()->where('email', 'existing@example.com')->value('name'));
    }

    public function test_only_platform_admins_can_use_it()
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('platform.companies.first-admin', $this->acme), ['admin_name' => 'X', 'admin_email' => 'x@example.com'])
            ->assertNotFound();
    }

    private function sendFirstAdmin(string $name, string $email): TestResponse
    {
        return $this->actingAs($this->platformAdmin)
            ->from(route('platform.companies.index'))
            ->post(route('platform.companies.first-admin', $this->acme), ['admin_name' => $name, 'admin_email' => $email]);
    }
}
