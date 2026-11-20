<?php

namespace App\Http\Middleware;

use App\Companies\CurrentCompany;
use App\Models\ApiClient;
use App\Models\Company;
use App\Models\Setting;
use App\Models\User;
use App\Modules\Srm\Models\Category;
use App\Modules\Srm\Models\CertificateType;
use App\Modules\Srm\Models\ContractType;
use App\Modules\Srm\Models\RatingCriterion;
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

    public function __construct(private readonly CurrentCompany $currentCompany) {}

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
            'company' => fn (): ?array => $this->navigationCompany($request)?->only(['name', 'slug']),
            'auth' => fn (): array => $this->insideNavigationCompany($request, fn (): array => [
                'user' => $request->user()?->only(['id', 'name', 'email', 'role']),
                'can' => [
                    'manageUsers' => (bool) $request->user()?->can('viewAny', User::class),
                    'manageCategories' => (bool) $request->user()?->can('manage', Category::class),
                    'manageCertificateTypes' => (bool) $request->user()?->can('manage', CertificateType::class),
                    'manageContractTypes' => (bool) $request->user()?->can('manage', ContractType::class),
                    'manageRatingCriteria' => (bool) $request->user()?->can('manage', RatingCriterion::class),
                    'manageSettings' => (bool) $request->user()?->can('manage', Setting::class),
                    'manageApiClients' => (bool) $request->user()?->can('manage', ApiClient::class),
                ],
            ]),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * The company the menu links point into: the open one, or on pages outside any company (such
     * as account settings) the one the person would land in.
     */
    private function navigationCompany(Request $request): ?Company
    {
        $user = $request->user();

        return $this->currentCompany->get() ?? ($user instanceof User ? $user->homeCompany() : null);
    }

    /**
     * Works out the menu permissions as they are in the navigation company, so account pages show
     * the same menu as the company pages. Only the person's own role is read, never company data.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    private function insideNavigationCompany(Request $request, callable $callback): mixed
    {
        $company = $this->navigationCompany($request);

        if ($company === null || $this->currentCompany->get() !== null) {
            return $callback();
        }

        return $this->currentCompany->runAs($company, fn () => $callback());
    }
}
