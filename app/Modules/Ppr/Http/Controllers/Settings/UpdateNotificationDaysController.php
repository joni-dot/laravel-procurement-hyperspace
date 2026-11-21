<?php

namespace App\Modules\Ppr\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Modules\Ppr\Http\Requests\Settings\NotificationDaysUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * How many days ahead the person is reminded of their packages' dates: the projects' part of
 * Settings → Notifications, which Core's page shows as a section.
 */
class UpdateNotificationDaysController extends Controller
{
    public function __invoke(NotificationDaysUpdateRequest $request): RedirectResponse
    {
        $request->user()->notification_days = $request->integer('notification_days');
        $request->user()->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Notification settings saved.')]);

        return to_route('notification-settings.edit');
    }
}
