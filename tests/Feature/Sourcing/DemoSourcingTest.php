<?php

namespace Tests\Feature\Sourcing;

use App\Actions\Companies\CreateDemoCompany;
use App\Modules\Ppr\Models\Project;
use App\Modules\Ppr\Risk\ProjectRisks;
use App\Modules\Ppr\Risk\RiskLevel;
use App\Modules\Ppr\Risk\RiskReason;
use App\Modules\Ppr\Scheduling\ProjectSchedules;
use App\Modules\Sourcing\Enums\QuoteStatus;
use App\Modules\Sourcing\Models\PackageSupplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class DemoSourcingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->enterCompany(app(CreateDemoCompany::class)->handle('Demo Oy', 'demo-oy', 'Anna Admin', 'anna@example.com')->company);
    }

    public function test_some_packages_ask_for_quotes_and_some_chose_a_supplier()
    {
        $this->assertTrue(PackageSupplier::query()->where('status', QuoteStatus::Chosen)->exists());
        $this->assertTrue(PackageSupplier::query()->where('status', '!=', QuoteStatus::Chosen)->exists());
        $this->assertSame(0, PackageSupplier::query()->where('status', QuoteStatus::Chosen)->groupBy('package_id')->havingRaw('count(*) > 1')->count('package_id'));
    }

    public function test_a_few_open_packages_are_amber_because_their_chosen_supplier_is_at_risk()
    {
        $project = Project::query()->sole();
        $rows = ProjectRisks::packageRows($project)->get();
        $risks = ProjectRisks::fromRows($project, $rows, ProjectSchedules::for($project, $rows, now('UTC')->toDateString()));

        $atRisk = array_filter($risks, fn ($risk) => $risk->has(RiskReason::SupplierAtRisk));

        $this->assertGreaterThanOrEqual(2, count($atRisk));
        foreach ($atRisk as $risk) {
            $this->assertNotSame(RiskLevel::None, $risk->level);
        }
    }

    public function test_the_first_risk_check_only_records_the_levels()
    {
        $this->assertSame(0, DB::table('packages')->whereNull('notified_risk_level')->count());
        $this->assertSame(0, DB::table('notifications')->count());
        // The first admin's invitation is the only message.
        Notification::assertCount(1);
    }
}
