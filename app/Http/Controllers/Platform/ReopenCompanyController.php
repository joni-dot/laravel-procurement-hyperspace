<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ReopenCompanyController extends Controller
{
    public function __invoke(Company $company): RedirectResponse
    {
        $company->forceFill(['suspended_at' => null])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':company is open again.', ['company' => $company->name])]);

        return back();
    }
}
