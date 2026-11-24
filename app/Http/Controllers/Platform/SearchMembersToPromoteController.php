<?php

namespace App\Http\Controllers\Platform;

use App\Companies\ActiveMembers;
use App\Database\Like;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\SearchMembersToPromoteRequest;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * The members a platform admin can make the admin of a company whose admins have all left
 * (PromoteMemberToAdmin), searched by name or email. Asked for only when the dialog is open, and a
 * few at a time, so the companies list never carries a company's people.
 */
class SearchMembersToPromoteController extends Controller
{
    public const int MAX_RESULTS = 20;

    public function __invoke(SearchMembersToPromoteRequest $request, Company $company): JsonResponse
    {
        /** @var User $platformAdmin */
        $platformAdmin = $request->user();
        $search = $request->search();

        $members = ActiveMembers::only($company->memberships()->getQuery(), apartFrom: $platformAdmin)
            ->when($search !== '', fn (Builder $memberships) => $memberships->whereHas(
                'user',
                fn (Builder $users) => $users->whereAny(['users.name', 'users.email'], 'ilike', Like::containing($search)),
            ))
            ->with('user:id,name,email')
            ->orderBy(User::query()->select('name')->whereColumn('users.id', 'memberships.user_id'))
            ->orderBy('memberships.id')
            ->limit(self::MAX_RESULTS)
            ->get(['memberships.id', 'memberships.user_id']);

        return response()->json([
            'members' => $members->map(fn (Membership $member) => [
                'id' => $member->id,
                'name' => $member->user->name,
                'email' => $member->user->email,
            ])->all(),
        ]);
    }
}
