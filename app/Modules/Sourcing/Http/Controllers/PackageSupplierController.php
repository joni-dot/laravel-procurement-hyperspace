<?php

namespace App\Modules\Sourcing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Ppr\Jobs\CheckProjectRisks;
use App\Modules\Ppr\Models\Package;
use App\Modules\Ppr\Models\Project;
use App\Modules\Sourcing\Actions\SavePackageSupplier;
use App\Modules\Sourcing\Enums\QuoteStatus;
use App\Modules\Sourcing\Http\Requests\StorePackageSupplierRequest;
use App\Modules\Sourcing\Http\Requests\UpdatePackageSupplierRequest;
use App\Modules\Sourcing\Models\PackageSupplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * The suppliers asked for a quote on a package, and the one chosen. Anyone who may edit the package manages them.
 */
class PackageSupplierController extends Controller
{
    public function store(StorePackageSupplierRequest $request, Project $project, Package $package, SavePackageSupplier $savePackageSupplier): RedirectResponse
    {
        $supplier = $request->supplier();
        $candidate = (new PackageSupplier)->forceFill(['package_id' => $package->id, 'supplier_id' => $supplier->id]);
        $status = $request->enum('status', QuoteStatus::class) ?? QuoteStatus::Asked;

        $savePackageSupplier->handle($candidate, $status, $request->validated('note'));
        $this->checkRisksIfChosenChanged($project, $package, $request->user(), [$status]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name added.', ['name' => $supplier->name])]);

        return to_route('projects.packages.show', [$project, $package]);
    }

    public function update(UpdatePackageSupplierRequest $request, Project $project, Package $package, PackageSupplier $packageSupplier, SavePackageSupplier $savePackageSupplier): RedirectResponse
    {
        $status = $request->enum('status', QuoteStatus::class) ?? $packageSupplier->status;

        // The status it had just before the save, read under the package's lock, not when the page was opened.
        $previousStatus = $savePackageSupplier->handle($packageSupplier, $status, $request->validated('note'));
        $this->checkRisksIfChosenChanged($project, $package, $request->user(), $status === $previousStatus ? [] : array_values(array_filter([$previousStatus, $status])));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Supplier saved.')]);

        return to_route('projects.packages.show', [$project, $package]);
    }

    public function destroy(Request $request, Project $project, Package $package, PackageSupplier $packageSupplier): RedirectResponse
    {
        // Read again under the package's lock, like a save: someone may have chosen it since it was loaded.
        $removedStatus = DB::transaction(function () use ($package, $packageSupplier): QuoteStatus {
            Package::query()->whereKey($package->id)->lockForUpdate()->value('id');
            $packageSupplier->refresh()->delete();

            return $packageSupplier->status;
        });
        $this->checkRisksIfChosenChanged($project, $package, $request->user(), [$removedStatus]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Supplier removed from the package.')]);

        return to_route('projects.packages.show', [$project, $package]);
    }

    /**
     * The chosen supplier is part of the package's risk (SupplierAtRisk), so a change to it is checked like any
     * other save of the package.
     *
     * @param  list<QuoteStatus>  $changedStatuses  the statuses the save took away or gave
     */
    private function checkRisksIfChosenChanged(Project $project, Package $package, User $savedBy, array $changedStatuses): void
    {
        if (in_array(QuoteStatus::Chosen, $changedStatuses, true)) {
            CheckProjectRisks::dispatch($project->id, $savedBy->id, savedPackageIds: [$package->id]);
        }
    }
}
