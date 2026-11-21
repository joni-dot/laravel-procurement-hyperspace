<?php

namespace App\Http\Controllers\Settings;

use App\Enums\NotificationEmail;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\NotificationSettingsUpdateRequest;
use App\Pages\PageSections;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The person's own notification settings, the same in every company: whether notifications also come by
 * email, plus the modules' own settings as sections (such as the projects' reminder period).
 */
class NotificationSettingsController extends Controller
{
    public function edit(Request $request, PageSections $sections): Response
    {
        return Inertia::render('settings/Notifications', [
            'notificationEmail' => $request->user()->notification_email->value,
            'emailOptions' => NotificationEmail::options(),
            ...$sections->props('settings.notifications', $request->user()),
        ]);
    }

    public function update(NotificationSettingsUpdateRequest $request): RedirectResponse
    {
        $request->user()->notification_email = $request->enum('notification_email', NotificationEmail::class);
        $request->user()->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Notification settings saved.')]);

        return to_route('notification-settings.edit');
    }
}
