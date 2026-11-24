<?php

namespace App\Companies;

use App\Enums\Role;
use App\Models\Membership;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Runs an admin's change to another member while holding the company's admin rows. Nobody may change
 * their own role, so a company keeps an admin only if the acting admin is still one when the change is
 * saved: two admins demoting each other at the same moment would otherwise both pass the policy and
 * leave the company with none.
 */
final class AdminLock
{
    public function __construct(private readonly CurrentCompany $currentCompany) {}

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $change
     * @return TResult
     */
    public function run(User $actor, Closure $change): mixed
    {
        return DB::transaction(function () use ($actor, $change): mixed {
            $admins = Membership::query()
                ->where('company_id', $this->currentCompany->id())
                ->active()
                ->where('role', Role::Admin)
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('user_id');

            abort_unless($admins->contains($actor->id), 403);

            return $change();
        });
    }
}
