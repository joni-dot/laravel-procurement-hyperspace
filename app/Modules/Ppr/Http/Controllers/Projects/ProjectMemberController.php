<?php

namespace App\Modules\Ppr\Http\Controllers\Projects;

use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\User;
use App\Modules\Ppr\Actions\Projects\ChangeProjectMemberRole;
use App\Modules\Ppr\Actions\Projects\RemoveProjectMember;
use App\Modules\Ppr\Enums\ProjectRole;
use App\Modules\Ppr\Http\Presenters\ProjectHeader;
use App\Modules\Ppr\Http\Requests\Projects\StoreProjectMemberRequest;
use App\Modules\Ppr\Http\Requests\Projects\UpdateProjectMemberRequest;
use App\Modules\Ppr\Jobs\CheckProjectRisks;
use App\Modules\Ppr\Models\Project;
use App\Modules\Ppr\Models\ProjectMember;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class ProjectMemberController extends Controller
{
    public function index(Request $request, Project $project): Response
    {
        $canManage = $request->user()->can('manageMembers', $project);

        // A member stays on the project after leaving the company, shown as deactivated with the name and address
        // they had then.
        $members = $project->members()
            ->join('users', 'users.id', '=', 'project_members.user_id')
            ->join('memberships', fn (JoinClause $join) => $join
                ->on('memberships.company_id', '=', 'project_members.company_id')
                ->on('memberships.user_id', '=', 'project_members.user_id'))
            ->select(['project_members.id', 'project_members.user_id', 'project_members.role'])
            ->selectRaw(Membership::nameOnRecordsSql().' as name')
            ->selectRaw(Membership::emailOnRecordsSql().' as email')
            ->orderBy('name')
            ->selectRaw('coalesce(users.deactivated_at, memberships.deactivated_at) is not null as is_deactivated')
            ->get()
            ->map(fn (ProjectMember $member) => [
                'id' => $member->id,
                'name' => $member->getAttribute('name'),
                'email' => $member->getAttribute('email'),
                'status' => $member->getAttribute('is_deactivated') ? MembershipStatus::Deactivated : MembershipStatus::Active,
                'role' => $member->role,
            ]);

        return Inertia::render('ppr/projects/Members', [
            'project' => ProjectHeader::for($project, $request->user()),
            'members' => $members,
            'roles' => ProjectRole::options(),
            // Only loaded when the "Add member" dialog opens, and only for people who may add members.
            ...($canManage ? ['userOptions' => Inertia::optional(fn () => $this->peopleNotInProject($project))] : []),
        ]);
    }

    public function store(StoreProjectMemberRequest $request, Project $project): RedirectResponse
    {
        $member = $project->members()->make(['role' => $request->enum('role', ProjectRole::class)]);
        $member->user_id = $request->integer('user_id');
        $member->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member added.')]);

        return back();
    }

    public function update(UpdateProjectMemberRequest $request, Project $project, ProjectMember $member, ChangeProjectMemberRole $changeRole): RedirectResponse
    {
        $changeRole->handle($member, $request->enum('role', ProjectRole::class));
        // A new role can take the person off packages they owned or bought.
        CheckProjectRisks::dispatch($project->id, $request->user()->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role changed.')]);

        return back();
    }

    public function destroy(Request $request, Project $project, ProjectMember $member, RemoveProjectMember $removeMember): RedirectResponse
    {
        $removeMember->handle($member);
        // Removing someone takes them off the packages they owned or bought.
        CheckProjectRisks::dispatch($project->id, $request->user()->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member removed.')]);

        return back();
    }

    /**
     * @return Collection<int, array{id: int, name: string, email: string}>
     */
    private function peopleNotInProject(Project $project): Collection
    {
        return User::query()
            ->activeInCurrentCompany()
            ->whereNotIn('id', $project->members()->select('user_id'))
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email]);
    }
}
