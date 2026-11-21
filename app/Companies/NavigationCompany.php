<?php

namespace App\Companies;

use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The company the menu points into: the open one, or on pages outside every company (such as
 * account settings) the one the person would land in. Shared page data that depends on a company,
 * such as menu permissions or an unread count, is worked out inside it.
 */
final class NavigationCompany
{
    public function __construct(private readonly CurrentCompany $currentCompany, private readonly Request $request) {}

    public function for(?User $user): ?Company
    {
        if ($this->currentCompany->get() !== null || $user === null) {
            return $this->currentCompany->get();
        }

        // Kept on the request, so several pieces of shared page data look it up once.
        $key = self::class.':'.$user->id;

        if (! $this->request->attributes->has($key)) {
            $this->request->attributes->set($key, $user->homeCompany());
        }

        return $this->request->attributes->get($key);
    }

    /**
     * Runs the callback inside the person's navigation company. Without one it runs outside every
     * company, where company data reads as empty.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    public function runFor(?User $user, callable $callback): mixed
    {
        if ($this->currentCompany->get() !== null) {
            return $callback();
        }

        $company = $this->for($user);

        return $company === null ? $callback() : $this->currentCompany->runAs($company, fn () => $callback());
    }
}
