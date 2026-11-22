<?php

namespace App\Http\Controllers\Platform;

use App\Actions\Companies\CreateDemoCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StoreCompanyRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * A company filled with made-up data, for demos and trials. The platform admin only names it and its first
 * admin, like any company; the data inside stays out of their sight.
 */
class CreateDemoCompanyController extends Controller
{
    public function __invoke(StoreCompanyRequest $request, CreateDemoCompany $createDemoCompany): RedirectResponse
    {
        $email = $request->validated('admin_email');

        $createDemoCompany->handle(
            $request->validated('name'),
            $request->validated('slug'),
            $request->validated('admin_name'),
            $email,
            $request->user(),
        );

        // The same message whether or not the address already had an account.
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Demo company created. :email was invited to be its admin.', ['email' => $email]),
        ]);

        return to_route('platform.companies.index');
    }
}
