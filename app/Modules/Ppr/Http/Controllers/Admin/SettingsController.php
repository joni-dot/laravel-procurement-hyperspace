<?php

namespace App\Modules\Ppr\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Modules\Ppr\Enums\SettingKey;
use App\Modules\Ppr\Http\Requests\Admin\SettingsRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('ppr/admin/RiskWarnings', [
            'riskAmberDays' => SettingKey::RiskAmberDays->savedOrDefault(),
            'riskRedDays' => SettingKey::RiskRedDays->savedOrDefault(),
        ]);
    }

    public function update(SettingsRequest $request): RedirectResponse
    {
        Setting::put(SettingKey::RiskAmberDays, (string) $request->integer('risk_amber_days'));
        Setting::put(SettingKey::RiskRedDays, (string) $request->integer('risk_red_days'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Settings saved.')]);

        return back();
    }
}
