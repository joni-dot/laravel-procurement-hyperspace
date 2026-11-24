<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Settings\RequestEmailChange;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request, RequestEmailChange $requestEmailChange): RedirectResponse
    {
        $user = $request->user();
        $user->update($request->safe()->only(['name']));

        if ($request->changesEmail()) {
            $email = (string) $request->validated('email');
            $requestEmailChange->handle($user, $email);

            Inertia::flash('toast', ['type' => 'success', 'message' => __('We sent a link to :email. Your email address changes when you open it.', ['email' => $email])]);

            return to_route('profile.edit');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }
}
