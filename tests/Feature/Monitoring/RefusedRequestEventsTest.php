<?php

namespace Tests\Feature\Monitoring;

use App\Auth\StrongSignIn;
use App\Companies\CurrentCompany;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\ReadsSecurityLog;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

/**
 * Requests the app turned down: someone trying what their rights don't allow, or trying too often.
 */
class RefusedRequestEventsTest extends TestCase
{
    use ReadsSecurityLog, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->captureLog();
    }

    public function test_a_request_a_policy_refuses_is_logged_with_the_user_and_route()
    {
        $viewer = User::factory()->create();

        $this->actingAs($viewer)
            ->post(route('admin.users.store'), ['name' => 'X', 'email' => 'x@example.com', 'role' => 'admin'])
            ->assertForbidden();

        $line = $this->securityLine('authorization.denied');
        $this->assertSame('WARNING', $line['level_name']);
        $this->assertSame(['event' => 'authorization.denied', 'user_id' => $viewer->id, 'company_id' => $this->company->id, 'status' => 403], $line['context']);
        $this->assertSame('admin.users.store', $line['extra']['route']);
        $this->assertSame('POST', $line['extra']['method']);
    }

    public function test_someone_turned_away_from_the_platform_pages_is_logged_although_they_see_not_found()
    {
        $user = User::factory()->admin()->create();
        // Platform pages are outside every company.
        app(CurrentCompany::class)->clear();

        $this->actingAs($user)->get(route('platform.companies.index'))->assertNotFound();

        $this->assertSame(['event' => 'authorization.platform_denied', 'user_id' => $user->id, 'company_id' => null, 'status' => 404], $this->securityLine('authorization.platform_denied')['context']);
    }

    public function test_a_platform_admin_asking_for_a_missing_company_is_no_security_event()
    {
        $platformAdmin = User::factory()->platformAdmin()->withTwoFactor()->create();

        $this->actingAs($platformAdmin)
            ->withSession(['auth.password_confirmed_at' => time(), StrongSignIn::SESSION_KEY => $platformAdmin->id])
            ->post(route('platform.companies.suspend', ['company' => 999_999]))
            ->assertNotFound();

        $this->assertSame([], $this->securityEvents());
    }

    public function test_a_rate_limit_outside_sign_in_is_logged_as_such()
    {
        Route::middleware(['web', 'throttle:1,1'])->get('_test/limited', fn () => 'ok')->name('test.limited');

        $this->get('_test/limited')->assertOk();
        $this->get('_test/limited')->assertTooManyRequests();

        $this->assertSame('test.limited', $this->securityLine('request.rate_limited')['extra']['route']);
        $this->assertSame([], array_diff($this->securityEvents(), ['request.rate_limited']));
    }
}
