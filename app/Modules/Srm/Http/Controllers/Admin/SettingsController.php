<?php

namespace App\Modules\Srm\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Modules\Srm\Enums\SettingKey;
use App\Modules\Srm\Http\Requests\Admin\SettingsRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('srm/admin/Settings', [
            'reminderEmail' => Setting::get(SettingKey::ReminderEmail),
        ]);
    }

    public function update(SettingsRequest $request): RedirectResponse
    {
        Setting::put(SettingKey::ReminderEmail, $request->validated('reminder_email'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Settings saved.')]);

        return back();
    }
}
