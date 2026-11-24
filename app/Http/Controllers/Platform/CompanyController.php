<?php

namespace App\Http\Controllers\Platform;

use App\Actions\Companies\CreateCompany;
use App\Companies\ActiveMembers;
use App\Database\Like;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\ListFilters;
use App\Http\Requests\Platform\StoreCompanyRequest;
use App\Http\Requests\Platform\UpdateCompanyRequest;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The companies using the service, for platform admins. Only the companies themselves and how many
 * people they have are shown; their data stays out of reach.
 */
class CompanyController extends Controller
{
    private const int COMPANIES_PER_PAGE = 25;

    public function index(Request $request): Response
    {
        $search = ListFilters::search($request);

        /** @var User $platformAdmin */
        $platformAdmin = $request->user();

        $companies = Company::query()
            ->select(['id', 'name', 'slug', 'suspended_at', 'is_demo', 'created_at'])
            ->withCount([
                'memberships as active_members_count' => fn (Builder $memberships) => ActiveMembers::only($memberships),
                'memberships as pending_invitations_count' => fn ($memberships) => $memberships->pendingInvitation(),
                'memberships as active_admins_count' => fn (Builder $memberships) => ActiveMembers::only($memberships)->where('role', Role::Admin),
                // Who could be made the admin of a company without one; the platform admin never is.
                'memberships as members_to_promote_count' => fn (Builder $memberships) => ActiveMembers::only($memberships, apartFrom: $platformAdmin),
            ])
            ->with('pendingAdminInvitation:id,memberships.company_id,known_name,known_email')
            ->when($search !== '', fn ($query) => $query->whereAny(['name', 'slug'], 'ilike', Like::containing($search)))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(self::COMPANIES_PER_PAGE)
            ->withQueryString();

        $companies->through(fn (Company $company) => [
            'id' => $company->id,
            'name' => $company->name,
            'slug' => $company->slug,
            'is_suspended' => $company->isSuspended(),
            'is_demo' => $company->is_demo,
            'active_members_count' => $company->getAttribute('active_members_count'),
            'pending_invitations_count' => $company->getAttribute('pending_invitations_count'),
            'created_at' => $company->created_at?->toDateString(),
            // Only for a company nobody can run yet: whom the waiting admin invitation went to,
            // as the platform (or the company) typed it.
            'first_admin_invitation' => $company->getAttribute('active_admins_count') === 0
                ? ['name' => $company->pendingAdminInvitation?->known_name, 'email' => $company->pendingAdminInvitation?->known_email]
                : null,
            // The members themselves are searched only when the platform admin chooses one
            // (SearchMembersToPromoteController).
            'members_to_promote_count' => $company->getAttribute('active_admins_count') === 0
                ? $company->getAttribute('members_to_promote_count')
                : 0,
        ]);

        return Inertia::render('platform/companies/Index', [
            'companies' => $companies,
            'filters' => ['search' => $search],
        ]);
    }

    public function store(StoreCompanyRequest $request, CreateCompany $createCompany): RedirectResponse
    {
        $email = $request->validated('admin_email');

        $createCompany->handle(
            $request->validated('name'),
            $request->validated('slug'),
            $request->validated('admin_name'),
            $email,
            $request->user(),
        );

        // The same message whether or not the address already had an account.
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Company created. :email was invited to be its admin.', ['email' => $email]),
        ]);

        return to_route('platform.companies.index');
    }

    public function update(UpdateCompanyRequest $request, Company $company): RedirectResponse
    {
        $oldSlug = $company->slug;
        $company->update($request->validated());

        $message = $company->slug === $oldSlug
            ? __('Company updated.')
            : __('Company updated. Links to the old address /:old keep working: they forward to /:new.', ['old' => $oldSlug, 'new' => $company->slug]);

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
