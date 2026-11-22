<?php

namespace App\Modules\Ppr\Demo;

use App\Companies\CurrentCompany;
use App\Companies\DemoRandom;
use App\Models\Company;
use App\Models\User;
use App\Modules\Ppr\Actions\Packages\CreatePackage;
use App\Modules\Ppr\Actions\Projects\CreateProject;
use App\Modules\Ppr\Enums\PackageStatus;
use App\Modules\Ppr\Jobs\CheckProjectRisks;
use App\Modules\Ppr\Models\Package;
use App\Modules\Ppr\Models\Project;
use App\Modules\Ppr\Models\ProjectCategory;
use App\Modules\Ppr\Models\ProjectMilestone;
use App\Modules\Ppr\Scheduling\Days;
use Illuminate\Support\Facades\DB;

/**
 * The made-up project of a demo company: about fifty packages in a small tree, whose milestone dates are set
 * around today, so some packages are done, some due soon, some late and some not started. The first admin
 * manages the project and owns and buys most packages; the rest have nobody yet. Runs inside the demo company.
 */
final class DemoProject
{
    private const int SEED = 60;

    /** Typical calendar days from the previous milestone, for each default milestone after the first. */
    private const array LEAD_DAYS = [1 => [7, 21], 2 => [14, 30], 3 => [7, 21], 4 => [30, 120], 5 => [3, 14], 6 => [7, 30]];

    /** How the top-level packages stand today; sub-packages follow the package they belong to. */
    private const array STORY_SHARES = ['done' => 4, 'late' => 4, 'due_soon' => 4, 'on_track' => 7, 'not_started' => 4, 'cancelled' => 1];

    private DemoRandom $random;

    /** "YYYY-MM-DD", the day the project pages count from. */
    private string $today;

    /** @var list<int> the project's milestone ids, in order */
    private array $milestoneIds = [];

    /** @var list<array<string, mixed>> */
    private array $milestoneRows = [];

    public function __construct(
        private readonly CreateProject $createProject,
        private readonly CreatePackage $createPackage,
    ) {}

    public function fill(Company $company, User $admin): void
    {
        $this->random = new DemoRandom(self::SEED);
        $this->today = now('UTC')->toDateString();
        $this->milestoneRows = [];

        $project = $this->createProject->handle(DemoProjectWords::NAME, DemoProjectWords::CODE, DemoProjectWords::DESCRIPTION, $admin);
        $this->milestoneIds = array_values($project->milestones()->orderBy('position')->get(['id'])->map(fn (ProjectMilestone $milestone) => $milestone->id)->all());
        $stories = $this->random->shuffledShares(self::STORY_SHARES);
        $index = 0;

        foreach (DemoProjectWords::PACKAGES as $code => $packages) {
            $category = $project->categories()->create(['code' => $code, 'name' => DemoProjectWords::CATEGORIES[$code]]);

            foreach ($packages as $title => $subPackages) {
                $this->addPackage($project, $category, $title, $subPackages, $stories[$index++ % count($stories)], $admin);
            }
        }

        // Plain inserts: the database fills in the open company.
        DB::table('package_milestones')->insert($this->milestoneRows);

        $this->checkRisksOnceSaved($company, $project);
    }

    /**
     * The first check only records each package's level, so nobody is told about made-up news. It is queued
     * once the demo company is saved, from inside the company: a job queued during the save would only be
     * sent after it, when the company is no longer open, and would run outside every company.
     */
    private function checkRisksOnceSaved(Company $company, Project $project): void
    {
        DB::afterCommit(fn () => app(CurrentCompany::class)->runAs($company, fn () => CheckProjectRisks::dispatch($project->id)));
    }

    /**
     * @param  array<string, mixed>  $subPackages  titles with their own sub-packages
     */
    private function addPackage(Project $project, ProjectCategory $category, string $title, array $subPackages, string $story, User $admin, ?Package $parent = null): void
    {
        $package = $this->createPackage->handle($project, $title, $category, $parent);
        $step = $this->currentStep($story);

        $package->forceFill([
            'status' => $this->status($story, $step),
            'purchase_type' => DemoProjectWords::PURCHASE_TYPES[$category->code] ?? null,
            'owner_id' => $this->random->chance(80) ? $admin->id : null,
            'buyer_id' => $this->random->chance(85) ? $admin->id : null,
        ])->save();

        if ($story !== 'cancelled') {
            $this->planMilestones($package, $story, $step);
        }

        foreach ($subPackages as $subTitle => $below) {
            $this->addPackage($project, $category, $subTitle, $below, $story, $admin, $package);
        }
    }

    /**
     * The first milestone not done yet; past the last one when everything is done.
     */
    private function currentStep(string $story): int
    {
        return match ($story) {
            'done' => count($this->milestoneIds),
            'late', 'due_soon' => $this->random->int(1, 5),
            'on_track' => $this->random->int(0, 4),
            default => 0,
        };
    }

    private function status(string $story, int $step): PackageStatus
    {
        return match (true) {
            $story === 'cancelled' => PackageStatus::Cancelled,
            $story === 'done' => PackageStatus::Closed,
            $step === 0 => $this->random->pick([PackageStatus::Draft, PackageStatus::Planned]),
            $step === 1 => PackageStatus::Planned,
            $step <= 3 => PackageStatus::Tendering,
            $step === 4 => PackageStatus::Ordered,
            default => PackageStatus::Delivered,
        };
    }

    /**
     * Plans the current milestone at the story's distance from today, the others one lead time apart before
     * and after it, and marks the earlier ones done around their planned dates.
     */
    private function planMilestones(Package $package, string $story, int $step): void
    {
        $last = count($this->milestoneIds) - 1;
        $anchor = min($step, $last);
        $leads = [0 => null];

        for ($position = 1; $position <= $last; $position++) {
            [$shortest, $longest] = self::LEAD_DAYS[$position] ?? [7, 30];
            $leads[$position] = $this->random->int($shortest, $longest);
        }

        $planned = [$anchor => Days::add($this->today, $this->daysFromToday($story))];

        for ($position = $anchor - 1; $position >= 0; $position--) {
            $planned[$position] = Days::add($planned[$position + 1], -(int) $leads[$position + 1]);
        }

        for ($position = $anchor + 1; $position <= $last; $position++) {
            $planned[$position] = Days::add($planned[$position - 1], (int) $leads[$position]);
        }

        $previousDone = null;

        foreach ($this->milestoneIds as $position => $milestoneId) {
            $done = $position < $step ? max($previousDone ?? '', min($this->today, Days::add($planned[$position], $this->random->int(-3, 4)))) : null;
            $previousDone = $done ?? $previousDone;

            $this->milestoneRows[] = [
                'package_id' => $package->id,
                'project_milestone_id' => $milestoneId,
                'planned_date' => $planned[$position],
                'forecast_date' => null,
                'actual_date' => $done,
                'lead_days' => $leads[$position],
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
    }

    /**
     * When the current milestone is due: the last one for a finished package, the next one otherwise.
     */
    private function daysFromToday(string $story): int
    {
        return match ($story) {
            'done' => -$this->random->int(10, 120),
            'late' => -$this->random->int(3, 25),
            'due_soon' => $this->random->int(1, 12),
            'on_track' => $this->random->int(20, 90),
            default => $this->random->int(40, 150),
        };
    }
}
