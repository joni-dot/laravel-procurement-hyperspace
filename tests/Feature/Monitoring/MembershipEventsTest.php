<?php

namespace Tests\Feature\Monitoring;

use App\Actions\Companies\AcceptInvitation;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\ReadsSecurityLog;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

/**
 * Who may work in a company, and with which rights: invitations, roles, leaving and coming back.
 */
class MembershipEventsTest extends TestCase
{
    use ReadsSecurityLog, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->admin = User::factory()->admin()->create();
        $this->captureLog();
    }

    public function test_an_invitation_is_logged_with_the_inviter_and_the_invited_account_by_id()
    {
        $this->actingAs($this->admin)
            ->post(route('admin.users.store'), ['name' => 'Nina New', 'email' => 'nina@example.com', 'role' => 'buyer'])
            ->assertSessionHasNoErrors();

        $invitation = Membership::query()->where('known_email', 'nina@example.com')->sole();
        $this->assertSame([
            'event' => 'membership.invited',
            'user_id' => $this->admin->id,
            'company_id' => $this->company->id,
            'membership_id' => $invitation->id,
            'member_user_id' => $invitation->user_id,
            'role' => 'buyer',
        ], $this->securityLine('membership.invited')['context']);
        $this->assertSecurityLogLeaves(['Nina']);

        app(AcceptInvitation::class)->handle($invitation);

        $this->assertSame($invitation->id, $this->securityLine('membership.joined')['context']['membership_id']);
    }

    public function test_a_role_change_is_logged_with_the_old_and_the_new_role()
    {
        $membership = User::factory()->create()->memberships()->sole();

        $this->actingAs($this->admin)
            ->patch(route('admin.users.update', $membership), ['role' => 'admin'])
            ->assertSessionHasNoErrors();

        $line = $this->securityLine('membership.role_changed');
        $this->assertSame('INFO', $line['level_name']);
        $this->assertSame([
            'event' => 'membership.role_changed',
            'user_id' => $this->admin->id,
            'company_id' => $this->company->id,
            'membership_id' => $membership->id,
            'member_user_id' => $membership->user_id,
            'role' => 'admin',
            'old_role' => 'viewer',
        ], $line['context']);
    }

    public function test_deactivating_and_reactivating_a_member_is_logged_with_who_did_it()
    {
        $membership = User::factory()->create()->memberships()->sole();

        $this->actingAs($this->admin)->post(route('admin.users.deactivate', $membership))->assertRedirect();
        $this->actingAs($this->admin)->post(route('admin.users.reactivate', $membership))->assertRedirect();

        $this->assertSame(['membership.deactivated', 'membership.reactivated'], $this->securityEvents());
        foreach ($this->securityLines() as $line) {
            $this->assertSame($this->admin->id, $line['context']['user_id']);
            $this->assertSame($membership->id, $line['context']['membership_id']);
            $this->assertSame($membership->user_id, $line['context']['member_user_id']);
            $this->assertSame($this->company->id, $line['context']['company_id']);
        }
    }
}
