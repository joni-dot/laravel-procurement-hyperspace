<?php

namespace App\Modules\Sourcing\Demo;

use App\Companies\CurrentCompany;
use App\Companies\DemoRandom;
use App\Models\Company;
use App\Modules\Ppr\Enums\PackageStatus;
use App\Modules\Ppr\Jobs\CheckProjectRisks;
use App\Modules\Ppr\Models\Package;
use App\Modules\Sourcing\Enums\QuoteStatus;
use App\Modules\Srm\Enums\RiskLevel;
use App\Modules\Srm\Enums\SupplierStatus;
use App\Modules\Srm\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Suppliers on the demo project's packages: asked for a quote on open packages, chosen on most packages that
 * are ordered or done. A few open packages chose a blocked or high-risk supplier, so they turn amber and the
 * package page shows why. Runs inside the demo company, after its suppliers and project exist.
 */
final class DemoSourcing
{
    private const int SEED = 30;

    private const int AT_RISK_CHOICES = 3;

    public function fill(Company $company): void
    {
        $random = new DemoRandom(self::SEED);
        $packages = Package::query()->whereNull('parent_id')->orderBy('id')->get(['id', 'project_id', 'status']);
        $safe = $this->suppliers(fn (Builder $suppliers) => $suppliers->where('status', SupplierStatus::Active)->withRiskLevel(RiskLevel::Low));
        $atRisk = $random->some($this->suppliers(fn (Builder $suppliers) => $suppliers
            ->where(fn (Builder $atRisk) => $atRisk
                ->where('status', SupplierStatus::Blocked)
                ->orWhere(fn (Builder $active) => $active->where('status', SupplierStatus::Active)->withRiskLevel(RiskLevel::High)))), self::AT_RISK_CHOICES);

        if ($packages->isEmpty() || count($safe) < 4) {
            return;
        }

        $rows = [];

        foreach ($this->choices($random, $packages, $safe, $atRisk) as $packageId => $chosen) {
            $askedAlso = $random->some(array_diff($safe, [$chosen]), $random->int(1, 3));

            foreach ($askedAlso as $supplierId) {
                $rows[] = $this->row($packageId, $supplierId, $chosen === null
                    ? $random->pick([QuoteStatus::Asked, QuoteStatus::OfferReceived, QuoteStatus::OfferReceived, QuoteStatus::Declined])
                    : $random->pick([QuoteStatus::OfferReceived, QuoteStatus::Declined]));
            }

            if ($chosen !== null) {
                $rows[] = $this->row($packageId, $chosen, QuoteStatus::Chosen);
            }
        }

        // Plain inserts: the database fills in the open company. They fire no model events, so the projects'
        // risk is checked here, once the demo company is saved, from inside it (see DemoProject).
        DB::table('package_suppliers')->insert($rows);

        DB::afterCommit(fn () => app(CurrentCompany::class)->runAs($company, function () use ($packages): void {
            foreach ($packages->pluck('project_id')->unique() as $projectId) {
                CheckProjectRisks::dispatch($projectId);
            }
        }));
    }

    /**
     * The supplier each top-level package chose (null: still asking), for every package that has suppliers.
     * The at-risk suppliers go to open packages, where they make the package amber.
     *
     * @param  Collection<int, Package>  $packages
     * @param  list<int>  $safe
     * @param  list<int>  $atRisk
     * @return array<int, int|null> per package id
     */
    private function choices(DemoRandom $random, Collection $packages, array $safe, array $atRisk): array
    {
        $open = $packages->filter(fn (Package $package) => in_array($package->status, [PackageStatus::Planned, PackageStatus::Tendering, PackageStatus::Ordered, PackageStatus::Delivered], true));
        $choices = [];

        foreach ($random->shuffle($open->all()) as $index => $package) {
            $choices[$package->id] = match (true) {
                $index < count($atRisk) => $atRisk[$index],
                in_array($package->status, [PackageStatus::Ordered, PackageStatus::Delivered], true), $random->chance(30) => $random->pick($safe),
                default => null,
            };
        }

        foreach ($packages->where('status', PackageStatus::Closed) as $package) {
            $choices[$package->id] = $random->pick($safe);
        }

        return $choices;
    }

    /**
     * @param  callable(Builder<Supplier>): mixed  $filter
     * @return list<int>
     */
    private function suppliers(callable $filter): array
    {
        $suppliers = Supplier::query()->whereNull('archived_at')->orderBy('id');
        $filter($suppliers);

        return array_values($suppliers->get(['id'])->map(fn (Supplier $supplier) => $supplier->id)->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $packageId, int $supplierId, QuoteStatus $status): array
    {
        return ['package_id' => $packageId, 'supplier_id' => $supplierId, 'status' => $status->value, 'created_at' => now(), 'updated_at' => now()];
    }
}
