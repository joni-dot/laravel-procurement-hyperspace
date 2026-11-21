<?php

namespace App\Http\Middleware;

use App\Companies\NavigationCompany;
use App\Models\ApiClient;
use App\Models\Company;
use App\Models\Membership;
use App\Models\Notification;
use App\Models\Setting;
use App\Models\User;
use App\Modules\Srm\Models\Category;
use App\Modules\Srm\Models\CertificateType;
use App\Modules\Srm\Models\ContractType;
use App\Modules\Srm\Models\RatingCriterion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    public function __construct(private readonly NavigationCompany $navigationCompany) {}

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'sourceCodeUrl' => config('app.source_code_url'),
            // Closures run when the page renders, after EnterCompany has opened the company.
            'company' => fn (): ?array => $this->navigationCompany->for($request->user())?->only(['name', 'slug']),
            // For the company switcher: every company the person may open, and suspended ones marked.
            'companies' => fn (): array => $this->memberCompanies($request),
            // Menu permissions as they are in the navigation company, so account pages show the same
            // menu as the company pages. Only the person's own role is read, never company data.
            'auth' => fn (): array => $this->navigationCompany->runFor($request->user(), fn (): array => [
                'user' => $request->user()?->only(['id', 'name', 'email', 'role']),
                'isPlatformAdmin' => (bool) $request->user()?->is_platform_admin,
                'can' => [
                    'manageUsers' => (bool) $request->user()?->can('viewAny', Membership::class),
                    'manageCategories' => (bool) $request->user()?->can('manage', Category::class),
                    'manageCertificateTypes' => (bool) $request->user()?->can('manage', CertificateType::class),
                    'manageContractTypes' => (bool) $request->user()?->can('manage', ContractType::class),
                    'manageRatingCriteria' => (bool) $request->user()?->can('manage', RatingCriterion::class),
                    'manageSettings' => (bool) $request->user()?->can('manage', Setting::class),
                    'manageApiClients' => (bool) $request->user()?->can('manage', ApiClient::class),
                ],
            ]),
            // Invitations the person can still accept, for the company switcher's badge.
            'invitationCount' => fn (): int => $request->user() === null
                ? 0
                : Membership::query()->where('user_id', $request->user()->id)->openInvitation()->count(),
            // For the bell on every page. A closure, so partial reloads that don't ask for it skip the query.
            'unreadNotificationCount' => fn (): int => $this->unreadNotificationCount($request),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * The person's unread notifications they may still see, counted in the navigation company, so account
     * pages show the count too. One query.
     */
    private function unreadNotificationCount(Request $request): int
    {
        $user = $request->user();

        // Platform pages never read company data, not even a person's own notifications.
        if (! $user instanceof User || $request->routeIs('platform.*')) {
            return 0;
        }

        // Outside every company (a platform admin, someone not yet in one) there is nothing to count.
        if ($this->navigationCompany->for($user) === null) {
            return 0;
        }

        return $this->navigationCompany->runFor($user, fn (): int => Notification::query()
            ->visibleTo($user)
            ->whereNull('read_at')
            ->count());
    }

    /**
     * One query, only names and addresses: nothing from inside the companies.
     *
     * @return list<array{name: string, slug: string, suspended: bool}>
     */
    private function memberCompanies(Request $request): array
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return [];
        }

        return array_values(Company::query()
            ->whereHas('memberships', fn (Builder $memberships) => $memberships->where('user_id', $user->id)->active())
            ->orderBy('name')
            ->get(['name', 'slug', 'suspended_at'])
            ->map(fn (Company $company): array => [
                'name' => $company->name,
                'slug' => $company->slug,
                'suspended' => $company->isSuspended(),
            ])
            ->all());
    }
}
