<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Settings\ConfirmEmailChange;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The link from the email sent to a new address (RequestEmailChange). Only the account that asked for
 * the change can use it: the address is in the signed link, and it must still be that account's
 * pending address.
 */
class ConfirmEmailChangeController extends Controller
{
    public function __invoke(Request $request, ConfirmEmailChange $confirmEmailChange): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        // The link names the account that asked: another account with the same address waiting can't use it.
        $forThisAccount = (string) $request->query('user') === (string) $user->id;

        $toast = $forThisAccount && $confirmEmailChange->handle($user, (string) $request->query('email'))
            ? ['type' => 'success', 'message' => __('Your email address is now :email.', ['email' => $user->email])]
            : ['type' => 'error', 'message' => __('This link no longer works. Change your email address again to get a new one.')];

        Inertia::flash('toast', $toast);

        return to_route('profile.edit');
    }
}
