<?php

namespace Tests\Feature\Platform;

use App\Companies\CurrentCompany;
use App\Enums\Role;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use App\Modules\Srm\Models\Supplier;
use App\Notifications\UserInvitation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class CreateCompanyCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    public function test_it_creates_the_company_invites_its_admin_and_prints_a_working_link()
    {
        $this->artisan('app:create-company', ['name' => 'Acme Oy', 'slug' => 'acme', 'admin-email' => 'Anna@Example.com', '--admin-name' => 'Anna Admin'])
            ->expectsOutputToContain('Company Acme Oy created')
            ->expectsOutputToContain($this->invitationLinkStart())
            ->assertSuccessful();

        $company = Company::query()->where('slug', 'acme')->sole();
        $invitation = Membership::query()->where('company_id', $company->id)->sole();
        $admin = User::query()->where('email', 'anna@example.com')->sole();

        $this->assertSame('Anna Admin', $admin->name);
        $this->assertSame(Role::Admin, $invitation->role);
        $this->assertNull($invitation->joined_at);
        Notification::assertSentTo($admin, UserInvitation::class);
    }

    public function test_the_printed_link_opens_the_invitation()
    {
        $exitCode = Artisan::call('app:create-company', ['name' => 'Acme Oy', 'slug' => 'acme', 'admin-email' => 'anna@example.com']);

        $link = collect(explode("\n", Artisan::output()))
            ->map(fn (string $line) => trim($line))
            ->first(fn (string $line) => str_starts_with($line, $this->invitationLinkStart()));

        $this->assertSame(0, $exitCode);
        $this->assertNotNull($link);
        $this->get($link)->assertOk();
        $this->assertSame('anna', User::query()->where('email', 'anna@example.com')->sole()->name);
    }

    public function test_the_demo_option_fills_the_company_with_made_up_data_and_marks_it()
    {
        $this->artisan('app:create-company', ['name' => 'Demo Oy', 'slug' => 'demo-oy', 'admin-email' => 'anna@example.com', '--demo' => true])
            ->expectsOutputToContain('Demo company Demo Oy created')
            ->expectsOutputToContain($this->invitationLinkStart())
            ->assertSuccessful();

        $company = Company::query()->where('slug', 'demo-oy')->sole();

        $this->assertTrue($company->is_demo);
        $this->assertGreaterThan(0, app(CurrentCompany::class)->runAs($company, fn () => Supplier::query()->count()));
        Notification::assertSentTo(User::query()->where('email', 'anna@example.com')->sole(), UserInvitation::class);
    }

    public function test_without_the_demo_option_the_company_starts_empty()
    {
        $this->artisan('app:create-company', ['name' => 'Acme Oy', 'slug' => 'acme', 'admin-email' => 'anna@example.com'])->assertSuccessful();

        $company = Company::query()->where('slug', 'acme')->sole();

        $this->assertFalse($company->is_demo);
        $this->assertSame(0, app(CurrentCompany::class)->runAs($company, fn () => Supplier::query()->count()));
    }

    public function test_it_validates_like_the_form()
    {
        $oldSlug = $this->company->slug;
        $this->company->update(['slug' => 'renamed']);

        foreach (['platform', $oldSlug, 'Not valid!'] as $slug) {
            $this->artisan('app:create-company', ['name' => 'Acme Oy', 'slug' => $slug, 'admin-email' => 'anna@example.com'])
                ->assertFailed();
        }

        $this->artisan('app:create-company', ['name' => 'Acme Oy', 'slug' => 'acme', 'admin-email' => 'not-an-email'])
            ->assertFailed();

        $this->assertSame(1, Company::count());
        Notification::assertNothingSent();
    }

    private function invitationLinkStart(): string
    {
        return Str::before(route('invitation.show', ['token' => 'TOKEN']), 'TOKEN');
    }
}
