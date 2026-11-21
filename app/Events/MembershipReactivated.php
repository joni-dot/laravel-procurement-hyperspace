<?php

namespace App\Events;

use App\Models\Membership;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Someone was let into a company again. Modules listen when the people they work with matter to
 * their own rules (a project's risk, for example). Dispatched inside the company.
 */
final class MembershipReactivated
{
    use Dispatchable;

    public function __construct(public readonly Membership $membership, public readonly User $changedBy) {}
}
