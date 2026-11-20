<?php

namespace App\Http\Controllers\Platform;

use App\Actions\Companies\CreateCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StoreCompanyRequest;
use App\Http\Requests\Platform\UpdateCompanyRequest;
use App\Models\Company;
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
        $search = $request->string('search')->trim()->limit(100, '')->toString();

        $companies = Company::query()
            ->select(['id', 'name', 'slug', 'suspended_at', 'created_at'])
            ->withCount([
                'memberships as active_members_count' => fn ($memberships) => $memberships->active(),
                'memberships as pending_invitations_count' => fn ($memberships) => $memberships->pendingInvitation(),
            ])
            ->when($search !== '', fn ($query) => $query->whereAny(['name', 'slug'], 'ilike', "%{$search}%"))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(self::COMPANIES_PER_PAGE)
            ->withQueryString()
            ->through(fn (Company $company) => [
                'id' => $company->id,
                'name' => $company->name,
                'slug' => $company->slug,
                'is_suspended' => $company->isSuspended(),
                'active_members_count' => $company->getAttribute('active_members_count'),
                'pending_invitations_count' => $company->getAttribute('pending_invitations_count'),
                'created_at' => $company->created_at?->toDateString(),
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
