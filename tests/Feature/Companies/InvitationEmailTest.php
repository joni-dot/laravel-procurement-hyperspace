<?php

namespace Tests\Feature\Companies;

use App\Actions\Companies\InviteMember;
use App\Enums\Role;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class InvitationEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_email_is_sent_only_after_the_invitation_and_a_new_account_are_saved()
    {
        $levelOutsideTheInvitation = DB::transactionLevel();
        $levelsWhenSent = [];
        Event::listen(NotificationSending::class, function () use (&$levelsWhenSent) {
            $levelsWhenSent[] = DB::transactionLevel();
        });

        app(InviteMember::class)->handle($this->company, 'New Person', 'new.person@example.com', Role::Viewer);

        // Sent inside the invitation's transaction, a queue worker could look for the new account
        // before it exists and drop the email.
        $this->assertSame([$levelOutsideTheInvitation], $levelsWhenSent);
    }
}
