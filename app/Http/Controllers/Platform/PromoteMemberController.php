<?php

namespace App\Http\Controllers\Platform;

use App\Actions\Companies\PromoteMemberToAdmin;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\PromoteMemberRequest;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class PromoteMemberController extends Controller
{
    public function __invoke(PromoteMemberRequest $request, Company $company, PromoteMemberToAdmin $promoteMemberToAdmin): RedirectResponse
    {
        /** @var User $platformAdmin */
        $platformAdmin = $request->user();
        $membership = $promoteMemberToAdmin->handle($company, (int) $request->validated('membership_id'), $platformAdmin);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':name is now the admin of :company.', ['name' => $membership->user->name, 'company' => $company->name]),
        ]);

        return back();
    }
}
