<?php

namespace App\Modules\Ppr\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Modules\Ppr\Actions\Projects\AddProjectMilestone;
use App\Modules\Ppr\Actions\Projects\MakeMilestoneTheNeedDate;
use App\Modules\Ppr\Actions\Projects\RemoveProjectMilestone;
use App\Modules\Ppr\Http\Presenters\ProjectHeader;
use App\Modules\Ppr\Http\Requests\Projects\StoreProjectMilestoneRequest;
use App\Modules\Ppr\Http\Requests\Projects\UpdateProjectMilestoneRequest;
use App\Modules\Ppr\Jobs\CheckProjectRisks;
use App\Modules\Ppr\Models\Project;
use App\Modules\Ppr\Models\ProjectMilestone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ProjectMilestoneController extends Controller
{
    public function index(Request $request, Project $project): Response
    {
        // A package has a row for a milestone only once something is filled in, so counting rows
        // counts the packages that would lose dates or a lead time if the milestone were removed.
        $milestones = $project->milestones()
            ->select(['id', 'name', 'is_need_date'])
            ->withCount('packageMilestones')
            ->get()
            ->map(fn (ProjectMilestone $milestone) => [
                'id' => $milestone->id,
                'name' => $milestone->name,
                'isNeedDate' => $milestone->is_need_date,
                'packagesWithDatesCount' => $milestone->package_milestones_count,
            ]);

        return Inertia::render('ppr/projects/Milestones', [
            'project' => ProjectHeader::for($project, $request->user()),
            'milestones' => $milestones,
            'maxMilestones' => AddProjectMilestone::MAX_PER_PROJECT,
        ]);
    }

    public function store(StoreProjectMilestoneRequest $request, Project $project, AddProjectMilestone $addMilestone): RedirectResponse
    {
        $addMilestone->handle($project, $request->validated('name'));
        CheckProjectRisks::dispatch($project->id, $request->user()->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Milestone added.')]);

        return back();
    }

    public function update(UpdateProjectMilestoneRequest $request, Project $project, ProjectMilestone $milestone, MakeMilestoneTheNeedDate $makeNeedDate): RedirectResponse
    {
        $becomesNeedDate = $request->boolean('is_need_date');

        DB::transaction(function () use ($request, $milestone, $makeNeedDate, $becomesNeedDate) {
            if ($request->has('name')) {
                $milestone->name = $request->validated('name');
                $milestone->save();
            }

            if ($becomesNeedDate) {
                $makeNeedDate->handle($milestone);
            }
        });

        if ($becomesNeedDate) {
            CheckProjectRisks::dispatch($project->id, $request->user()->id);
        }

        $message = $becomesNeedDate
            ? __(':name is now the need date.', ['name' => $milestone->name])
            : __('Milestone saved.');
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    public function destroy(Request $request, Project $project, ProjectMilestone $milestone, RemoveProjectMilestone $removeMilestone): RedirectResponse
    {
        $removeMilestone->handle($milestone);
        CheckProjectRisks::dispatch($project->id, $request->user()->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Milestone removed.')]);

        return back();
    }
}
