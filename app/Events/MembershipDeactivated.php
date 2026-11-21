<?php

namespace App\Events;

use App\Models\Membership;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Someone was taken out of a company. Modules listen when the people they work with matter to
 * their own rules (a project's risk, for example). Dispatched inside the company.
 */
final class MembershipDeactivated
{
    use Dispatchable;

    public function __construct(public readonly Membership $membership, public readonly User $changedBy) {}
}
