<?php

namespace App\Modules\Sourcing\Actions;

use App\Models\User;
use App\Modules\Ppr\Jobs\CheckProjectRisks;
use App\Modules\Sourcing\Enums\QuoteStatus;
use App\Modules\Sourcing\Models\PackageSupplier;
use Illuminate\Support\Facades\Auth;

/**
 * A change to a supplier can change the risk of every package that chose it (SupplierAtRisk), so each of
 * their projects is checked like after any other save: the people told are the project managers and the
 * package owners, leaving out the person whose save it was.
 */
class CheckPackagesChoosingSuppliers
{
    /**
     * One query finds the projects, then one check is queued per project.
     *
     * @param  list<int>|null  $supplierIds  null: a change that affects every supplier, such as a newly required certificate
     */
    public function handle(?array $supplierIds): void
    {
        $projects = PackageSupplier::query()
            ->join('packages', 'packages.id', '=', 'package_suppliers.package_id')
            ->where('package_suppliers.status', QuoteStatus::Chosen)
            ->when($supplierIds !== null, fn ($chosen) => $chosen->whereIn('package_suppliers.supplier_id', $supplierIds))
            ->groupBy('packages.project_id')
            ->select('packages.project_id')
            ->selectRaw('json_agg(package_suppliers.package_id order by package_suppliers.package_id) as package_ids')
            ->toBase()
            ->get();

        $user = Auth::user();
        $changedById = $user instanceof User ? $user->id : null;

        foreach ($projects as $project) {
            CheckProjectRisks::dispatch(
                $project->project_id,
                $changedById,
                savedPackageIds: json_decode($project->package_ids, true, flags: JSON_THROW_ON_ERROR),
            );
        }
    }
}
