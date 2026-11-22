<?php

namespace App\Modules\Sourcing;

use App\Companies\DemoData;
use App\Models\Company;
use App\Models\User;
use App\Modules\Ppr\Models\Package;
use App\Modules\Ppr\Risk\OutsideRiskReasons;
use App\Modules\Ppr\Risk\RiskReason;
use App\Modules\Sourcing\Actions\CheckPackagesChoosingSuppliers;
use App\Modules\Sourcing\Demo\DemoSourcing;
use App\Modules\Sourcing\Enums\QuoteStatus;
use App\Modules\Sourcing\Models\PackageSupplier;
use App\Modules\Sourcing\Sections\PackageSuppliers;
use App\Modules\Sourcing\Sections\SupplierPackages;
use App\Modules\Srm\Enums\RiskLevel;
use App\Modules\Srm\Enums\SupplierStatus;
use App\Modules\Srm\Models\Certificate;
use App\Modules\Srm\Models\CertificateType;
use App\Modules\Srm\Models\RatingCriterion;
use App\Modules\Srm\Models\Supplier;
use App\Modules\Srm\Models\SupplierRating;
use App\Pages\PageSections;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Connects the two modules: project packages (Ppr) are bought from suppliers (Srm). Neither module knows
 * the other; this one uses both and plugs into their pages and rules. Registered after both.
 */
class SourcingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->linkPackagesToTheirSuppliers();
        $this->addSupplierRiskToPackages();
        $this->checkPackagesWhenSuppliersChange();
        $this->addPageSections();
        $this->addDemoData();
        $this->registerRoutes();
    }

    /**
     * Ppr's Package doesn't know suppliers; the relation lets the routes find a package's supplier entries
     * only through that package (scoped route binding).
     */
    private function linkPackagesToTheirSuppliers(): void
    {
        Package::resolveRelationUsing('packageSuppliers', fn (Package $package): HasMany => $package->hasMany(PackageSupplier::class));
    }

    /**
     * A package whose chosen supplier is archived, blocked (whatever its risk override) or high risk (override
     * respected) is amber. Supplier risk is worked out live by Supplier::withRiskLevel(), so the package follows it
     * without storing anything. PackageSuppliers::makesPackageAtRisk() is the same rule in PHP, for the package
     * page's warning.
     *
     * The supplier is found by the id the package chose. Joined the other way round, PostgreSQL checked every
     * supplier of the company for each package: seconds for a project of thousands of packages.
     */
    private function addSupplierRiskToPackages(): void
    {
        $this->app->make(OutsideRiskReasons::class)->add(RiskReason::SupplierAtRisk, fn () => Supplier::query()
            ->where('suppliers.id', fn (QueryBuilder $chosen) => $chosen
                ->from('package_suppliers')
                ->select('package_suppliers.supplier_id')
                ->whereColumn('package_suppliers.package_id', 'packages.id')
                ->where('package_suppliers.status', QuoteStatus::Chosen->value)
                ->limit(1))
            ->where(fn (Builder $atRisk) => $atRisk
                ->whereNotNull('suppliers.archived_at')
                ->orWhere('suppliers.status', SupplierStatus::Blocked)
                ->orWhere(fn (Builder $highRisk) => $highRisk->withRiskLevel(RiskLevel::High)))
            ->toBase());
    }

    /**
     * Package risk follows the chosen supplier's risk, so every save in the supplier module that can change a
     * supplier's level queues a check of the projects that chose it, and people hear about it like after any
     * other save. Saves that change no model (RecalculateOverallScores updates scores in one statement) are
     * covered by the rating or weight saved with them; certificates expiring with time by the daily check.
     */
    private function checkPackagesWhenSuppliersChange(): void
    {
        $check = $this->checkPackagesChoosing(...);

        Supplier::updated(function (Supplier $supplier) use ($check): void {
            if ($supplier->wasChanged(['status', 'archived_at', 'risk_override', 'latest_rating_score'])) {
                $check([$supplier->id]);
            }
        });

        // A required certificate missing or lapsing makes an active supplier high risk.
        Certificate::created(fn (Certificate $certificate) => $check([$certificate->supplier_id]));
        Certificate::updated(function (Certificate $certificate) use ($check): void {
            if ($certificate->wasChanged(['certificate_type_id', 'expires_on'])) {
                $check([$certificate->supplier_id]);
            }
        });
        Certificate::deleted(fn (Certificate $certificate) => $check([$certificate->supplier_id]));

        // The new score is written after the rating, in the same transaction; the check runs after it commits.
        SupplierRating::created(fn (SupplierRating $rating) => $check([$rating->supplier_id]));

        // Every supplier's score is calculated again with the new weights, in the same transaction.
        RatingCriterion::updated(function (RatingCriterion $criterion) use ($check): void {
            if ($criterion->wasChanged('weight')) {
                $check(null);
            }
        });

        CertificateType::created(function (CertificateType $type) use ($check): void {
            if ($type->is_required) {
                $check(null);
            }
        });
        CertificateType::updated(function (CertificateType $type) use ($check): void {
            if ($type->wasChanged('is_required')) {
                $check(null);
            }
        });
        CertificateType::deleted(function (CertificateType $type) use ($check): void {
            if ($type->is_required) {
                $check(null);
            }
        });
    }

    /**
     * @param  list<int>|null  $supplierIds  null: every chosen supplier
     */
    private function checkPackagesChoosing(?array $supplierIds): void
    {
        $this->app->make(CheckPackagesChoosingSuppliers::class)->handle($supplierIds);
    }

    private function addPageSections(): void
    {
        $sections = $this->app->make(PageSections::class);

        $sections->add('ppr.package', 'sourcing_suppliers', 10, 'sourcing/PackageSuppliers',
            fn (User $user, array $context): bool => $user->can('view', $context['project']),
            fn (User $user, array $context): array => $this->app->make(PackageSuppliers::class)->for(
                $user,
                $context['project'],
                $context['package'],
                request()->string('supplier_search')->trim()->limit(PackageSuppliers::MAX_SEARCH_LENGTH, '')->toString(),
            ));

        // Everyone may see suppliers; the packages listed are only those in projects the person can see.
        $sections->add('srm.supplier', 'sourcing_packages', 10, 'sourcing/SupplierPackages',
            fn (User $user, array $context): bool => $user->can('view', $context['supplier']),
            fn (User $user, array $context): array => $this->app->make(SupplierPackages::class)->for($user, $context['supplier']));
    }

    /** Suppliers on the demo project's packages: after the suppliers and the project exist. */
    private function addDemoData(): void
    {
        $this->callAfterResolving(DemoData::class, function (DemoData $demoData): void {
            $demoData->add('sourcing_package_suppliers', 30, fn (Company $company) => $this->app->make(DemoSourcing::class)->fill($company));
        });
    }

    private function registerRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        Route::middleware(['web', 'company'])
            ->prefix('{company}')
            ->where(['company' => Company::routePattern()])
            ->group(__DIR__.'/routes/web.php');
    }
}
