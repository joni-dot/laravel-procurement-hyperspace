<?php

namespace App\Modules\Sourcing\Sections;

use App\Models\User;
use App\Modules\Ppr\Models\Package;
use App\Modules\Ppr\Models\Project;
use App\Modules\Sourcing\Enums\QuoteStatus;
use App\Modules\Sourcing\Models\PackageSupplier;
use App\Modules\Srm\Compliance\SupplierRisk;
use App\Modules\Srm\Enums\RiskLevel;
use App\Modules\Srm\Enums\SupplierStatus;
use App\Modules\Srm\Models\Supplier;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The suppliers on a package, for the section on the package's page: the candidates with their risk, and for
 * those who may manage them, the company's suppliers matching a search, so the page never loads all of them.
 * The same number of queries however many candidates there are.
 */
class PackageSuppliers
{
    public const int MOST_SEARCH_MATCHES = 20;

    public function __construct(private readonly SupplierRisk $supplierRisk) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $user, Project $project, Package $package, string $search): array
    {
        $canManage = $user->can('update', $package);

        return [
            'projectId' => $project->id,
            'packageId' => $package->id,
            'candidates' => $this->candidates($package),
            'statuses' => QuoteStatus::options(),
            'canManage' => $canManage,
            'search' => $canManage && $search !== '' ? ['term' => $search, 'matches' => $this->matches($package, $search)] : null,
        ];
    }

    /**
     * The chosen one first, then by name.
     *
     * @return list<array<string, mixed>>
     */
    private function candidates(Package $package): array
    {
        $candidates = PackageSupplier::query()
            ->where('package_id', $package->id)
            ->with(['supplier' => fn (BelongsTo $supplier) => $supplier->select(['id', 'name', 'supplier_number', 'status', 'archived_at', 'latest_rating_score', 'risk_override'])])
            ->get()
            ->sortBy([
                fn (PackageSupplier $a, PackageSupplier $b) => ($b->status === QuoteStatus::Chosen) <=> ($a->status === QuoteStatus::Chosen),
                fn (PackageSupplier $a, PackageSupplier $b) => strnatcasecmp($a->supplier->name, $b->supplier->name),
            ]);
        $risks = $this->supplierRisk->assess($candidates->pluck('supplier'));

        return array_values($candidates
            ->map(function (PackageSupplier $candidate) use ($risks): array {
                $supplier = $candidate->supplier;
                $riskLevel = $risks[$supplier->id]['level'];

                return [
                    'id' => $candidate->id,
                    'status' => $candidate->status,
                    'statusLabel' => $candidate->status->label(),
                    'note' => $candidate->note,
                    'supplier' => [
                        'id' => $supplier->id,
                        'name' => $supplier->name,
                        'number' => $supplier->supplier_number,
                        'status' => $supplier->status,
                        'statusLabel' => $supplier->status->label(),
                        'riskLevel' => $riskLevel,
                        'isArchived' => $supplier->isArchived(),
                    ],
                    'putsPackageAtRisk' => $candidate->status === QuoteStatus::Chosen && self::makesPackageAtRisk($riskLevel, $supplier->isArchived(), $supplier->status === SupplierStatus::Blocked),
                ];
            })
            ->all());
    }

    /**
     * The PHP version of the package risk rule in SourcingServiceProvider::addSupplierRiskToPackages(), so the
     * warning here says the same as the package's risk. PackageSuppliersSectionTest fails if they disagree.
     */
    public static function makesPackageAtRisk(RiskLevel $riskLevel, bool $isArchived, bool $isBlocked): bool
    {
        // Blocked counts even when an override lowers the supplier's level: nothing should be bought from it.
        return $isArchived || $isBlocked || $riskLevel === RiskLevel::High;
    }

    /**
     * Suppliers that may be added: the company's own, neither archived nor blocked, not on the package yet.
     *
     * @return list<array{id: int, name: string, number: string|null}>
     */
    private function matches(Package $package, string $search): array
    {
        return array_values(Supplier::query()
            ->whereNull('archived_at')
            ->where('status', '!=', SupplierStatus::Blocked)
            ->search($search)
            ->whereNotIn('id', PackageSupplier::query()->where('package_id', $package->id)->select('supplier_id'))
            ->orderBy('name')
            ->orderBy('id')
            ->limit(self::MOST_SEARCH_MATCHES)
            ->get(['id', 'name', 'supplier_number'])
            ->map(fn (Supplier $supplier) => ['id' => $supplier->id, 'name' => $supplier->name, 'number' => $supplier->supplier_number])
            ->all());
    }
}
