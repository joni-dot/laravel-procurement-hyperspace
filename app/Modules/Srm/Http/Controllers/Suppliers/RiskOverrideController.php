<?php

namespace App\Modules\Srm\Http\Controllers\Suppliers;

use App\Http\Controllers\Controller;
use App\Modules\Srm\Enums\RiskLevel;
use App\Modules\Srm\Http\Requests\Suppliers\RiskOverrideRequest;
use App\Modules\Srm\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class RiskOverrideController extends Controller
{
    public function update(RiskOverrideRequest $request, Supplier $supplier): RedirectResponse
    {
        $supplier->risk_override = $request->enum('level', RiskLevel::class);
        $supplier->risk_override_reason = $request->validated('reason');
        $supplier->risk_overridden_by_user_id = $request->user()->id;
        $supplier->risk_overridden_at = now();
        $supplier->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Risk level set.')]);

        return back();
    }

    public function destroy(Supplier $supplier): RedirectResponse
    {
        $supplier->risk_override = null;
        $supplier->risk_override_reason = null;
        $supplier->risk_overridden_by_user_id = null;
        $supplier->risk_overridden_at = null;
        $supplier->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Risk level is calculated again.')]);

        return back();
    }
}
