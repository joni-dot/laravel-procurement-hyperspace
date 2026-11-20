<?php

namespace App\Http\Controllers\Platform;

use App\Actions\Companies\InviteFirstAdmin;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\InviteFirstAdminRequest;
use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class InviteFirstAdminController extends Controller
{
    public function __invoke(InviteFirstAdminRequest $request, Company $company, InviteFirstAdmin $inviteFirstAdmin): RedirectResponse
    {
        $email = $request->validated('admin_email');

        $inviteFirstAdmin->handle($company, $request->validated('admin_name'), $email, $request->user());

        // The same message whether or not the address already had an account.
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':email was invited to be the admin of :company.', ['email' => $email, 'company' => $company->name]),
        ]);

        return back();
    }
}
