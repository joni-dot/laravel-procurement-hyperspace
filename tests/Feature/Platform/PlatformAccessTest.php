<?php

namespace Tests\Feature\Platform;

use App\Auth\StrongSignIn;
use App\Models\Company;
use App\Models\User;
use Inertia\Support\SessionKey;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class PlatformAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_the_sign_in_page()
    {
        $this->get(route('platform.companies.index'))->assertRedirect(route('login'));
        $this->post(route('platform.companies.suspend', ['company' => $this->company->id]))->assertRedirect(route('login'));

        $this->assertFalse($this->company->fresh()->isSuspended());
    }

    public function test_everyone_else_is_told_the_area_does_not_exist()
    {
        foreach ([User::factory()->admin()->withTwoFactor()->create(), User::factory()->withoutCompany()->create()] as $user) {
            $this->actingAs($user);

            $this->get(route('platform.companies.index'))->assertNotFound();
            $this->post(route('platform.companies.store'), [
                'name' => 'Acme', 'slug' => 'acme', 'admin_name' => 'Anna', 'admin_email' => 'anna@example.com',
            ])->assertNotFound();
            $this->put(route('platform.companies.update', ['company' => $this->company->id]), ['name' => 'Renamed', 'slug' => 'renamed'])->assertNotFound();
            $this->post(route('platform.companies.suspend', ['company' => $this->company->id]))->assertNotFound();
            $this->post(route('platform.companies.reopen', ['company' => $this->company->id]))->assertNotFound();
            $this->getJson(route('platform.companies.members-to-promote', ['company' => $this->company->id]))->assertNotFound();
        }

        $this->assertSame(1, Company::count());
        $this->assertNotSame('renamed', $this->company->fresh()->slug);
        $this->assertFalse($this->company->fresh()->isSuspended());
    }

    public function test_a_platform_admin_without_two_factor_sign_in_is_asked_to_turn_it_on_first()
    {
        $this->actingAs(User::factory()->platformAdmin()->create());

        $this->get(route('platform.companies.index'))->assertRedirect(route('security.edit'));
        $this->assertSame('warning', session(SessionKey::FLASH_DATA)['toast']['type']);

        $this->post(route('platform.companies.suspend', ['company' => $this->company->id]))->assertRedirect(route('security.edit'));
        $this->assertFalse($this->company->fresh()->isSuspended());

        $this->getJson(route('platform.companies.members-to-promote', ['company' => $this->company->id]))->assertRedirect(route('security.edit'));
    }

    public function test_the_security_page_itself_tells_a_platform_admin_to_turn_on_two_factor_sign_in()
    {
        $this->actingAs(User::factory()->platformAdmin()->create())
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit'))
            ->assertInertia(fn ($page) => $page->where('platformNeedsTwoFactor', true));

        $this->actingAs(User::factory()->create())
            ->get(route('security.edit'))
            ->assertInertia(fn ($page) => $page->where('platformNeedsTwoFactor', false));
    }

    public function test_a_session_that_never_passed_two_factor_sign_in_must_sign_in_again()
    {
        $platformAdmin = User::factory()->platformAdmin()->withTwoFactor()->create();
        $other = User::factory()->create();

        // Signed in with the password alone (before two-factor sign-in was turned on), or a second
        // factor proved by another account in this browser.
        foreach ([[], [StrongSignIn::SESSION_KEY => $other->id]] as $session) {
            $this->actingAs($platformAdmin)
                ->withSession(['auth.password_confirmed_at' => time(), ...$session])
                ->get(route('platform.companies.index'))
                ->assertRedirect(route('login'));

            $this->assertGuest();
        }
    }

    public function test_a_two_factor_code_or_passkey_in_this_session_opens_the_platform_pages()
    {
        $platformAdmin = User::factory()->platformAdmin()->withTwoFactor()->create();

        foreach ([
            new ValidTwoFactorAuthenticationCodeProvided($platformAdmin),
            new TwoFactorAuthenticationConfirmed($platformAdmin),
        ] as $event) {
            $this->actingAs($platformAdmin)->withSession(['auth.password_confirmed_at' => time()]);
            session()->flush();
            event($event);

            $this->assertTrue(StrongSignIn::passedBy($platformAdmin));
        }
    }

    public function test_a_platform_admin_must_have_entered_the_password_recently()
    {
        $platformAdmin = User::factory()->platformAdmin()->withTwoFactor()->create();

        $this->actingAs($platformAdmin)
            ->withSession([StrongSignIn::SESSION_KEY => $platformAdmin->id])
            ->get(route('platform.companies.index'))
            ->assertRedirect(route('password.confirm'));
    }

    public function test_a_platform_admin_with_two_factor_sign_in_opens_the_companies()
    {
        $platformAdmin = User::factory()->platformAdmin()->withTwoFactor()->create();

        $this->actingAs($platformAdmin)
            ->withSession(['auth.password_confirmed_at' => time(), StrongSignIn::SESSION_KEY => $platformAdmin->id])
            ->get(route('platform.companies.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('platform/companies/Index')
                ->where('auth.isPlatformAdmin', true));
    }

    public function test_others_are_not_told_they_are_platform_admins()
    {
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('auth.isPlatformAdmin', false));
    }

    public function test_a_platform_admin_without_a_company_starts_on_the_platform_pages()
    {
        $this->actingAs(User::factory()->platformAdmin()->withTwoFactor()->create())
            ->get(route('home'))
            ->assertRedirect(route('platform.companies.index'));
    }
}
