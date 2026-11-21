<?php

namespace App\Modules\Srm\Dashboard;

use App\Models\User;
use App\Modules\Srm\Enums\RiskLevel;
use App\Modules\Srm\Enums\SupplierStatus;
use App\Modules\Srm\Models\Certificate;
use App\Modules\Srm\Models\Contract;
use App\Modules\Srm\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;

/**
 * What needs attention among the company's suppliers, for the supplier dashboard and the home page: the
 * only place these numbers are defined. Every count matches the supplier list its tile links to, so
 * archived suppliers never count. Contracts are only read for people allowed to see their details.
 */
final readonly class SupplierAttention
{
    public const int UPCOMING_SHOWN = 10;

    private function __construct(private bool $canViewContracts) {}

    public static function for(User $user): self
    {
        return new self($user->can('viewDetails', Contract::class));
    }

    /**
     * @return array{contracts_action_soon: int|null, missing_certificates: int, certificates_expiring: int, high_risk: int, rating_overdue: int}
     */
    public function counts(): array
    {
        return [
            'contracts_action_soon' => $this->canViewContracts
                ? self::suppliers()->whereHas('contracts', fn (Builder $contracts) => $contracts->needingActionSoon())->count()
                : null,
            'missing_certificates' => self::suppliers()->missingRequiredCertificates()->count(),
            'certificates_expiring' => self::activeSuppliers()->withExpiringCertificateNotReplaced()->count(),
            'high_risk' => self::activeSuppliers()->withRiskLevel(RiskLevel::High)->count(),
            'rating_overdue' => self::suppliers()->ratingOverdue()->count(),
        ];
    }

    /**
     * The soonest dates across contracts and certificates.
     *
     * @return list<array<string, mixed>>
     */
    public function upcoming(): array
    {
        $contracts = ! $this->canViewContracts ? collect() : Contract::query()
            ->needingActionSoon()
            ->whereHas('supplier', fn (Builder $supplier) => $supplier->whereNull('archived_at'))
            ->with('supplier:id,name')
            ->orderBy('action_by')
            ->limit(self::UPCOMING_SHOWN)
            ->get(['id', 'supplier_id', 'title', 'auto_renews', 'action_by'])
            ->map(fn (Contract $contract) => [
                'key' => "contract-{$contract->id}",
                'kind' => $contract->auto_renews ? 'contract_cancel_by' : 'contract_ends',
                'date' => $contract->action_by->toDateString(),
                'title' => $contract->title,
                'contract_id' => $contract->id,
                'supplier_id' => $contract->supplier_id,
                'supplier_name' => $contract->supplier->name,
            ]);

        $certificates = Certificate::query()
            ->expiringWithoutReplacement()
            ->where('expires_on', '>=', today())
            ->whereHas('supplier', fn (Builder $supplier) => $supplier->whereNull('archived_at')->where('status', SupplierStatus::Active))
            ->with(['supplier:id,name', 'type:id,name'])
            ->orderBy('expires_on')
            ->limit(self::UPCOMING_SHOWN)
            ->get(['id', 'supplier_id', 'certificate_type_id', 'expires_on'])
            ->map(fn (Certificate $certificate) => [
                'key' => "certificate-{$certificate->id}",
                'kind' => 'certificate_expires',
                'date' => $certificate->expires_on->toDateString(),
                'title' => $certificate->type->name,
                'contract_id' => null,
                'supplier_id' => $certificate->supplier_id,
                'supplier_name' => $certificate->supplier->name,
            ]);

        return array_values($contracts->concat($certificates)
            ->sortBy('date')
            ->take(self::UPCOMING_SHOWN)
            ->all());
    }

    /**
     * The suppliers every dashboard number counts from.
     *
     * @return Builder<Supplier>
     */
    public static function suppliers(): Builder
    {
        return Supplier::query()->whereNull('archived_at');
    }

    /**
     * @return Builder<Supplier>
     */
    private static function activeSuppliers(): Builder
    {
        return self::suppliers()->where('status', SupplierStatus::Active);
    }
}
