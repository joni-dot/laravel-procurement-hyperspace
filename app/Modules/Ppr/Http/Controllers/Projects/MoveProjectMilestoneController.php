<?php

namespace App\Modules\Ppr\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Modules\Ppr\Actions\Projects\MoveProjectMilestone;
use App\Modules\Ppr\Enums\MoveDirection;
use App\Modules\Ppr\Http\Requests\Projects\MoveProjectMilestoneRequest;
use App\Modules\Ppr\Jobs\CheckProjectRisks;
use App\Modules\Ppr\Models\Project;
use App\Modules\Ppr\Models\ProjectMilestone;
use Illuminate\Http\RedirectResponse;

class MoveProjectMilestoneController extends Controller
{
    public function __invoke(MoveProjectMilestoneRequest $request, Project $project, ProjectMilestone $milestone, MoveProjectMilestone $moveMilestone): RedirectResponse
    {
        $moveMilestone->handle($milestone, $request->enum('direction', MoveDirection::class));
        // Lead times count from the previous milestone, so moving one changes expected dates.
        CheckProjectRisks::dispatch($project->id, $request->user()->id);

        return back();
    }
}
