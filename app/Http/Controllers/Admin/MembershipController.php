<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Companies\InviteMember;
use App\Companies\CurrentCompany;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\InviteMemberRequest;
use App\Http\Requests\Admin\UpdateMembershipRequest;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The company's Users page: everyone who is, was or is invited to be a member.
 */
class MembershipController extends Controller
{
    private const int MEMBERS_PER_PAGE = 25;

    /**
     * Until someone joins, the company knows them only by what the inviter typed. The account's own
     * name and address stay out of the list, its search and its order, so the list never tells
     * whether an address already had an account.
     */
    private const string NAME = 'CASE WHEN memberships.joined_at IS NULL THEN COALESCE(memberships.invited_name, memberships.invited_email) ELSE users.name END';

    private const string EMAIL = 'CASE WHEN memberships.joined_at IS NULL THEN memberships.invited_email ELSE users.email END';

    public function __construct(private readonly CurrentCompany $currentCompany) {}

    public function index(Request $request): Response
    {
        /** @var User $actor */
        $actor = $request->user();
        $search = $request->string('search')->trim()->limit(100, '')->toString();

        $members = Membership::query()
            ->join('users', 'users.id', '=', 'memberships.user_id')
            ->where('memberships.company_id', $this->currentCompany->id())
            ->select([
                'memberships.id', 'memberships.company_id', 'memberships.user_id', 'memberships.role',
                'memberships.invitation_token', 'memberships.invitation_sent_at',
                'memberships.joined_at', 'memberships.deactivated_at',
            ])
            ->selectRaw(self::NAME.' AS display_name')
            ->selectRaw(self::EMAIL.' AS display_email')
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereRaw(self::NAME.' ILIKE ?', ["%{$search}%"])
                ->orWhereRaw(self::EMAIL.' ILIKE ?', ["%{$search}%"])))
            ->orderByRaw(self::NAME)
            ->orderBy('memberships.id')
            ->paginate(self::MEMBERS_PER_PAGE)
            ->withQueryString()
            ->through(fn (Membership $membership) => [
                'id' => $membership->id,
                'name' => $membership->getAttribute('display_name'),
                'email' => $membership->getAttribute('display_email'),
                'role' => $membership->role,
                'status' => $membership->status(),
                'isYou' => $membership->user_id === $actor->id,
                'can' => [
                    'update' => $actor->can('update', $membership),
                    'deactivate' => $actor->can('deactivate', $membership),
                    'reactivate' => $actor->can('reactivate', $membership),
                    'resendInvitation' => $actor->can('resendInvitation', $membership),
                ],
            ]);

        return Inertia::render('admin/users/Index', [
            'users' => $members,
            'filters' => ['search' => $search],
            'roles' => Role::options(),
        ]);
    }

    public function store(InviteMemberRequest $request, InviteMember $inviteMember): RedirectResponse
    {
        $email = $request->validated('email');
        $inviteMember->handle(
            $this->currentCompany->get() ?? abort(404),
            $request->validated('name'),
            $email,
            $request->enum('role', Role::class) ?? Role::Viewer,
            $request->user(),
        );

        // The same answer whether or not the address had an account, or already had an invitation.
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation sent to :email.', ['email' => $email])]);

        return to_route('admin.users.index');
    }

    public function update(UpdateMembershipRequest $request, Membership $membership): RedirectResponse
    {
        $membership->update(['role' => $request->enum('role', Role::class)]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role changed.')]);

        return back();
    }
}
