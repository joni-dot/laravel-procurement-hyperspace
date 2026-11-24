<?php

namespace Tests\Feature\Monitoring;

use App\Auth\StrongSignIn;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\ReadsSecurityLog;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

/**
 * What the people who run the service do to companies.
 */
class PlatformEventsTest extends TestCase
{
    use ReadsSecurityLog, RefreshDatabase;

    private User $platformAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdmin = User::factory()->platformAdmin()->withTwoFactor()->create();
        $this->withSession(['auth.password_confirmed_at' => time(), StrongSignIn::SESSION_KEY => $this->platformAdmin->id]);
        Notification::fake();
        Mail::fake();
        $this->captureLog();
    }

    public function test_creating_a_company_is_logged_with_its_first_admins_invitation()
    {
        $this->actingAs($this->platformAdmin)
            ->post(route('platform.companies.store'), $this->companyInput('acme'))
            ->assertSessionHasNoErrors();

        $company = Company::query()->where('slug', 'acme')->sole();
        $invitation = Membership::query()->where('company_id', $company->id)->sole();
        $this->assertSame(['platform.company_created', 'platform.first_admin_invited'], $this->securityEvents());
        $this->assertSame(['event' => 'platform.company_created', 'user_id' => $this->platformAdmin->id, 'company_id' => $company->id], $this->securityLine('platform.company_created')['context']);
        $this->assertSame([
            'event' => 'platform.first_admin_invited',
            'user_id' => $this->platformAdmin->id,
            'company_id' => $company->id,
            'membership_id' => $invitation->id,
            'member_user_id' => $invitation->user_id,
            'role' => 'admin',
        ], $this->securityLine('platform.first_admin_invited')['context']);
        $this->assertSecurityLogLeaves(['Anna']);
    }

    public function test_creating_a_demo_company_is_logged_as_one()
    {
        $this->actingAs($this->platformAdmin)
            ->post(route('platform.companies.demo.store'), $this->companyInput('demo-oy'))
            ->assertSessionHasNoErrors();

        $company = Company::query()->where('slug', 'demo-oy')->sole();
        $this->assertSame($company->id, $this->securityLine('platform.demo_company_created')['context']['company_id']);
        $this->assertSame('platform.companies.demo.store', $this->securityLine('platform.demo_company_created')['extra']['route']);
    }

    public function test_suspending_reopening_and_inviting_a_first_admin_again_are_logged()
    {
        $company = Company::factory()->create();
        $this->captureLog();

        $this->actingAs($this->platformAdmin)->post(route('platform.companies.suspend', $company))->assertRedirect();
        $this->actingAs($this->platformAdmin)->post(route('platform.companies.reopen', $company))->assertRedirect();
        $this->actingAs($this->platformAdmin)
            ->post(route('platform.companies.first-admin', $company), ['admin_name' => 'Bob Admin', 'admin_email' => 'bob@example.com'])
            ->assertSessionHasNoErrors();

        $this->assertSame(['platform.company_suspended', 'platform.company_reopened', 'platform.first_admin_invited'], $this->securityEvents());
        foreach ($this->securityLines() as $line) {
            $this->assertSame($this->platformAdmin->id, $line['context']['user_id']);
            $this->assertSame($company->id, $line['context']['company_id']);
        }
    }

    /**
     * @return array<string, string>
     */
    private function companyInput(string $slug): array
    {
        return ['name' => 'Acme Oy', 'slug' => $slug, 'admin_name' => 'Anna Admin', 'admin_email' => 'anna@example.com'];
    }
}
