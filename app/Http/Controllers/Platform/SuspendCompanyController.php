<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Closes a company to its members. Nothing is deleted, and reopening it gives everything back.
 */
class SuspendCompanyController extends Controller
{
    public function __invoke(Company $company): RedirectResponse
    {
        if (! $company->isSuspended()) {
            $company->forceFill(['suspended_at' => now()])->save();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':company is suspended. Its data is kept.', ['company' => $company->name])]);

        return back();
    }
}
