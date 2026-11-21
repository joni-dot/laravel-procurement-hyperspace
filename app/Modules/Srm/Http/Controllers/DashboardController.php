<?php

namespace App\Modules\Srm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Srm\Dashboard\SupplierAttention;
use App\Modules\Srm\Enums\RiskLevel;
use App\Modules\Srm\Enums\SupplierStatus;
use App\Modules\Srm\Models\SupplierSpend;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every count here matches the supplier list the dashboard links to, so archived suppliers never count.
 * The attention tiles and the soonest dates are SupplierAttention's, which the home page shows too.
 */
class DashboardController extends Controller
{
    private const int TOP_SPEND_SHOWN = 5;

    public function __invoke(Request $request): Response
    {
        $attention = SupplierAttention::for($request->user());
        $canViewSpend = $request->user()->can('viewAny', SupplierSpend::class);
        $spendYear = today()->year;

        return Inertia::render('srm/Dashboard', [
            'attention' => $attention->counts(),
            'upcoming' => $attention->upcoming(),
            'riskLevels' => array_map(fn (RiskLevel $level) => [
                'level' => $level,
                'count' => SupplierAttention::suppliers()->withRiskLevel($level)->count(),
            ], [RiskLevel::High, RiskLevel::Medium, RiskLevel::Low]),
            'statuses' => $this->statusCounts(),
            'spendYear' => $spendYear,
            'topSpend' => $canViewSpend ? $this->topSpend($spendYear) : null,
        ]);
    }

    /**
     * @return list<array{status: SupplierStatus, count: int}>
     */
    private function statusCounts(): array
    {
        $counts = SupplierAttention::suppliers()
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return array_map(fn (SupplierStatus $status) => [
            'status' => $status,
            'count' => (int) $counts->get($status->value, 0),
        ], SupplierStatus::cases());
    }

    /**
     * The biggest suppliers per currency, because amounts in different currencies cannot be compared.
     *
     * @return list<array<string, mixed>>
     */
    private function topSpend(int $year): array
    {
        $rankedSpends = SupplierSpend::query()
            // company_id too: the outer query filters by company like every SupplierSpend query.
            ->select(['company_id', 'supplier_id', 'amount', 'currency_code'])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY currency_code ORDER BY amount DESC, supplier_id) AS spend_rank')
            ->where('year', $year)
            ->whereHas('supplier', fn (Builder $supplier) => $supplier->whereNull('archived_at'));

        return array_values(SupplierSpend::query()
            ->fromSub($rankedSpends, 'supplier_spends')
            ->where('spend_rank', '<=', self::TOP_SPEND_SHOWN)
            ->with('supplier:id,name')
            ->orderBy('currency_code')
            ->orderBy('spend_rank')
            ->get()
            ->groupBy(fn (SupplierSpend $spend) => $spend->currency_code->value)
            ->map(fn ($spends, string $currencyCode) => [
                'currency_code' => $currencyCode,
                'suppliers' => $spends->map(fn (SupplierSpend $spend) => [
                    'id' => $spend->supplier_id,
                    'name' => $spend->supplier->name,
                    'amount' => $spend->amount,
                ])->all(),
            ])
            ->all());
    }
}
