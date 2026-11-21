<?php

namespace Tests\Feature\Sourcing;

use App\Companies\CurrentCompany;
use App\Models\Company;
use App\Modules\Ppr\Enums\PackageStatus;
use App\Modules\Ppr\Models\Package;
use App\Modules\Ppr\Models\Project;
use App\Modules\Ppr\Risk\PackageRisk;
use App\Modules\Ppr\Risk\ProjectRisks;
use App\Modules\Ppr\Risk\RiskLevel;
use App\Modules\Ppr\Risk\RiskReason;
use App\Modules\Sourcing\Enums\QuoteStatus;
use App\Modules\Sourcing\Models\PackageSupplier;
use App\Modules\Srm\Enums\RiskLevel as SupplierRiskLevel;
use App\Modules\Srm\Enums\SupplierStatus;
use App\Modules\Srm\Models\CertificateType;
use App\Modules\Srm\Models\Supplier;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

/**
 * A package is amber when the supplier chosen for it is high risk or archived. Only the chosen supplier counts.
 */
class SupplierRiskOnPackagesTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create();
    }

    public function test_a_blocked_chosen_supplier_makes_the_package_amber()
    {
        $package = $this->packageChoosing(Supplier::factory()->create(['status' => SupplierStatus::Blocked]));

        $risk = $this->riskOf($package);

        $this->assertSame(RiskLevel::Amber, $risk->level);
        $this->assertTrue($risk->has(RiskReason::SupplierAtRisk));
    }

    public function test_a_poorly_rated_or_uncertified_chosen_supplier_is_high_risk_too()
    {
        $poorlyRated = $this->packageChoosing(Supplier::factory()->active()->create(['latest_rating_score' => 2.0]));
        CertificateType::factory()->required()->create();
        $uncertified = $this->packageChoosing(Supplier::factory()->active()->create());

        $this->assertTrue($this->riskOf($poorlyRated)->has(RiskReason::SupplierAtRisk));
        $this->assertTrue($this->riskOf($uncertified)->has(RiskReason::SupplierAtRisk));
    }

    public function test_an_override_decides_the_suppliers_level_but_blocked_always_counts()
    {
        $poorButOverriddenLow = $this->packageChoosing(Supplier::factory()->active()->create(['latest_rating_score' => 1.5, 'risk_override' => SupplierRiskLevel::Low]));
        $overriddenHigh = $this->packageChoosing(Supplier::factory()->active()->create(['risk_override' => SupplierRiskLevel::High]));
        $blockedButOverriddenLow = $this->packageChoosing(Supplier::factory()->create(['status' => SupplierStatus::Blocked, 'risk_override' => SupplierRiskLevel::Low]));

        $this->assertFalse($this->riskOf($poorButOverriddenLow)->has(RiskReason::SupplierAtRisk));
        $this->assertTrue($this->riskOf($overriddenHigh)->has(RiskReason::SupplierAtRisk));
        $this->assertTrue($this->riskOf($blockedButOverriddenLow)->has(RiskReason::SupplierAtRisk));
    }

    public function test_an_archived_chosen_supplier_makes_the_package_amber()
    {
        $package = $this->packageChoosing(Supplier::factory()->active()->archived()->create());

        $this->assertTrue($this->riskOf($package)->has(RiskReason::SupplierAtRisk));
    }

    public function test_a_medium_risk_or_good_supplier_is_no_reason()
    {
        $medium = $this->packageChoosing(Supplier::factory()->active()->create(['latest_rating_score' => 3.0]));
        $good = $this->packageChoosing(Supplier::factory()->active()->create(['latest_rating_score' => 4.5]));

        $this->assertFalse($this->riskOf($medium)->has(RiskReason::SupplierAtRisk));
        $this->assertFalse($this->riskOf($good)->has(RiskReason::SupplierAtRisk));
    }

    public function test_only_the_chosen_supplier_counts()
    {
        $package = $this->package();

        foreach ([QuoteStatus::Asked, QuoteStatus::OfferReceived, QuoteStatus::Declined] as $status) {
            PackageSupplier::factory()->for($package)->for(Supplier::factory()->create(['status' => SupplierStatus::Blocked]))->create(['status' => $status]);
        }

        $this->assertFalse($this->riskOf($package)->has(RiskReason::SupplierAtRisk));
    }

    public function test_a_finished_package_is_never_at_risk()
    {
        $package = $this->packageChoosing(Supplier::factory()->create(['status' => SupplierStatus::Blocked]));
        $package->forceFill(['status' => PackageStatus::Closed])->save();

        $this->assertSame(RiskLevel::None, $this->riskOf($package)->level);
    }

    public function test_a_package_has_at_most_one_chosen_supplier()
    {
        $package = $this->packageChoosing(Supplier::factory()->active()->create());

        $this->expectException(QueryException::class);

        PackageSupplier::factory()->for($package)->chosen()->create();
    }

    public function test_a_package_cannot_be_linked_to_another_companys_supplier()
    {
        $theirs = $this->inCompany(Company::factory()->create(), fn () => Supplier::factory()->active()->create());

        $this->expectException(QueryException::class);

        // Written directly, as a bug in the app might: the database itself refuses the link.
        DB::table('package_suppliers')->insert([
            'package_id' => $this->package()->id, 'supplier_id' => $theirs->id, 'status' => 'asked', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_supplier_check_adds_no_query()
    {
        Package::factory()->for($this->project)->count(3)->create();
        $before = $this->countQueries(fn () => ProjectRisks::for($this->project));

        foreach (range(1, 5) as $ignored) {
            $this->packageChoosing(Supplier::factory()->create(['status' => SupplierStatus::Blocked]));
        }

        $this->assertSame($before, $this->countQueries(fn () => ProjectRisks::for($this->project)));
    }

    private function packageChoosing(Supplier $supplier): Package
    {
        $package = $this->package();
        PackageSupplier::factory()->for($package)->for($supplier)->chosen()->create();

        return $package;
    }

    /**
     * Amber already for other reasons (no owner, buyer or need date), so tests look only at the supplier reason.
     */
    private function package(): Package
    {
        return Package::factory()->for($this->project)->create();
    }

    private function inCompany(Company $company, callable $callback): mixed
    {
        return app(CurrentCompany::class)->runAs($company, fn () => $callback());
    }

    private function riskOf(Package $package): PackageRisk
    {
        return ProjectRisks::for($this->project)[$package->id];
    }

    private function countQueries(callable $work): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $work();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
