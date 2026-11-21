<?php

namespace Tests\Feature\Companies;

use App\Actions\ApiClients\CreateApiClient;
use App\Companies\CurrentCompany;
use App\Enums\ApiScope;
use App\Enums\Role;
use App\Models\Company;
use App\Models\Membership;
use App\Models\Notification;
use App\Models\User;
use App\Modules\Ppr\Models\Package;
use App\Modules\Ppr\Models\Project;
use App\Modules\Ppr\Models\ProjectCategory;
use App\Modules\Ppr\Models\ProjectMember;
use App\Modules\Sourcing\Models\PackageSupplier;
use App\Modules\Srm\Models\Category;
use App\Modules\Srm\Models\Certificate;
use App\Modules\Srm\Models\CertificateType;
use App\Modules\Srm\Models\Comment;
use App\Modules\Srm\Models\Contact;
use App\Modules\Srm\Models\Contract;
use App\Modules\Srm\Models\ContractType;
use App\Modules\Srm\Models\Document;
use App\Modules\Srm\Models\RatingCriterion;
use App\Modules\Srm\Models\Supplier;
use App\Modules\Srm\Models\SupplierRating;
use App\Modules\Srm\Models\SupplierSpend;
use Illuminate\Routing\Route;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

/**
 * Nobody reaches another company's records: not through their own company's address with the other
 * company's ids, and not through the other company's address.
 *
 * The test goes through the app's real route list. A new route inside a company that takes a record
 * fails test_every_company_route_with_a_record_is_covered until it is listed below, and its record
 * gets a value in recordsOf().
 */
class CrossCompanyAccessTest extends TestCase
{
    use RefreshDatabase;

    private const array COVERED_ROUTES = [
        'admin.api-clients.revoke',
        'admin.api-clients.secret.regenerate',
        'admin.api-clients.update',
        'admin.categories.destroy',
        'admin.categories.update',
        'admin.certificate-types.destroy',
        'admin.certificate-types.update',
        'admin.contract-types.destroy',
        'admin.contract-types.update',
        'admin.rating-criteria.destroy',
        'admin.rating-criteria.restore',
        'admin.rating-criteria.update',
        'admin.users.deactivate',
        'admin.users.invitation.resend',
        'admin.users.reactivate',
        'admin.users.update',
        'notifications.show',
        'notifications.update',
        'projects.categories.destroy',
        'projects.categories.store',
        'projects.categories.update',
        'projects.edit',
        'projects.members.destroy',
        'projects.members.index',
        'projects.members.store',
        'projects.members.update',
        'projects.milestones.destroy',
        'projects.milestones.index',
        'projects.milestones.move',
        'projects.milestones.store',
        'projects.milestones.update',
        'projects.numbering.edit',
        'projects.numbering.update',
        'projects.packages.edit',
        'projects.packages.index',
        'projects.packages.risk-flag.destroy',
        'projects.packages.risk-flag.store',
        'projects.packages.schedule.edit',
        'projects.packages.schedule.update',
        'projects.packages.show',
        'projects.packages.store',
        'projects.packages.suppliers.destroy',
        'projects.packages.suppliers.store',
        'projects.packages.suppliers.update',
        'projects.packages.update',
        'projects.show',
        'projects.update',
        'suppliers.archive',
        'suppliers.certificates.destroy',
        'suppliers.certificates.download',
        'suppliers.certificates.store',
        'suppliers.certificates.update',
        'suppliers.comments.destroy',
        'suppliers.comments.store',
        'suppliers.comments.update',
        'suppliers.contacts.destroy',
        'suppliers.contacts.store',
        'suppliers.contacts.update',
        'suppliers.contracts.create',
        'suppliers.contracts.destroy',
        'suppliers.contracts.documents.store',
        'suppliers.contracts.edit',
        'suppliers.contracts.show',
        'suppliers.contracts.store',
        'suppliers.contracts.update',
        'suppliers.documents.destroy',
        'suppliers.documents.download',
        'suppliers.documents.store',
        'suppliers.edit',
        'suppliers.ratings.destroy',
        'suppliers.ratings.store',
        'suppliers.restore',
        'suppliers.risk.destroy',
        'suppliers.risk.update',
        'suppliers.show',
        'suppliers.spend.destroy',
        'suppliers.spend.store',
        'suppliers.update',
    ];

    public function test_every_company_route_with_a_record_is_covered()
    {
        $routes = $this->companyRoutesWithRecords()->map(fn (Route $route) => (string) $route->getName())->sort()->values()->all();

        $this->assertSame(
            self::COVERED_ROUTES,
            $routes,
            'A route inside a company takes a record. Add it to COVERED_ROUTES, and its record to recordsOf(), so this test proves other companies cannot reach it.',
        );
    }

    public function test_a_member_of_one_company_reaches_no_record_of_another()
    {
        Storage::fake('local');
        $ours = $this->company;
        $member = User::factory()->admin()->create();
        $member->forceFill(['last_company_id' => $ours->id])->save();

        $theirs = Company::factory()->create();
        $records = $this->recordsOf($theirs, $member);
        $before = $this->databaseState([$ours, $theirs]);

        $this->actingAs($member)->withSession(['auth.password_confirmed_at' => time()]);

        foreach ($this->companyRoutesWithRecords() as $route) {
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                foreach (['their records through our address' => $ours, 'their address' => $theirs] as $way => $company) {
                    $url = $this->urlFor($route, $company, $records);

                    $this->assertSame(404, $this->call($method, $url)->getStatusCode(), "{$method} {$url} ({$way}) must be not found.");
                }
            }
        }

        $this->assertSame($before, $this->databaseState([$ours, $theirs]), 'Nothing may change in either company.');
        Storage::disk('local')->assertExists([$records['_documentFile'], $records['_certificateFile']]);
    }

    public function test_someone_in_both_companies_has_each_companys_role_on_its_pages()
    {
        $person = User::factory()->admin()->create();
        $other = Company::factory()->create();
        Membership::factory()->create(['company_id' => $other->id, 'user_id' => $person->id, 'role' => Role::Viewer]);
        $ourContract = Contract::factory()->create();
        $theirContract = app(CurrentCompany::class)->runAs($other, fn () => Contract::factory()->create());

        $this->actingAs($person);

        $this->get(route('admin.categories.index', ['company' => $this->company->slug]))->assertOk();
        $this->get(route('suppliers.contracts.show', ['company' => $this->company->slug, 'supplier' => $ourContract->supplier_id, 'contract' => $ourContract->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('auth.user.role', Role::Admin->value)
                ->where('auth.can.manageCategories', true));

        $this->get(route('admin.categories.index', ['company' => $other->slug]))->assertForbidden();
        $this->get(route('suppliers.contracts.show', ['company' => $other->slug, 'supplier' => $theirContract->supplier_id, 'contract' => $theirContract->id]))
            ->assertForbidden();
        $this->get(route('suppliers.show', ['company' => $other->slug, 'supplier' => $theirContract->supplier_id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('auth.user.role', Role::Viewer->value)
                ->where('auth.can.manageCategories', false)
                ->where('auth.can.manageApiClients', false)
                ->missing('spend'));

        // And back again: the role from the other company doesn't stick.
        $this->get(route('admin.categories.index', ['company' => $this->company->slug]))->assertOk();
    }

    /**
     * @return Collection<int, Route>
     */
    private function companyRoutesWithRecords(): Collection
    {
        return collect(Router::getRoutes()->getRoutes())
            ->filter(fn (Route $route) => str_starts_with($route->uri(), '{company}/'))
            ->filter(fn (Route $route) => array_diff($route->parameterNames(), ['company']) !== [])
            ->values();
    }

    /**
     * One record of the company for every kind of record in a route, each belonging to the same
     * supplier or project where it has one, so only the company stands in the way. Keys starting with _
     * are not route parameters; projectCategory is {category} in the projects' routes (recordKey()).
     *
     * @return array<string, int|string>
     */
    private function recordsOf(Company $company, User $member): array
    {
        return app(CurrentCompany::class)->runAs($company, function () use ($company, $member) {
            $supplier = Supplier::factory()->create();
            $contract = Contract::factory()->for($supplier)->create();
            $document = Document::factory()->for($supplier)->create(['file_path' => "companies/{$company->id}/documents/{$supplier->id}/file.pdf"]);
            $certificate = Certificate::factory()->for($supplier)->create([
                'file_path' => "companies/{$company->id}/certificates/{$supplier->id}/file.pdf",
                'file_name' => 'file.pdf',
            ]);
            Storage::disk('local')->put($document->file_path, 'their document');
            Storage::disk('local')->put((string) $certificate->file_path, 'their certificate');
            $project = Project::factory()->withDefaultMilestones()->create();
            $projectCategory = ProjectCategory::factory()->for($project)->create();
            $package = Package::factory()->for($project)->create(['category_id' => $projectCategory->id]);

            return [
                'supplier' => $supplier->id,
                'contact' => Contact::factory()->for($supplier)->create()->id,
                'certificate' => $certificate->id,
                'contract' => $contract->id,
                'document' => $document->id,
                'rating' => SupplierRating::factory()->create(['supplier_id' => $supplier->id])->id,
                'spend' => SupplierSpend::factory()->create(['supplier_id' => $supplier->id])->id,
                'comment' => Comment::factory()->for($supplier)->create()->id,
                'category' => Category::factory()->create()->id,
                'certificateType' => CertificateType::factory()->create()->id,
                'contractType' => ContractType::factory()->create()->id,
                'ratingCriterion' => RatingCriterion::factory()->create()->id,
                'apiClient' => app(CreateApiClient::class)->handle($company, 'Their integration', [ApiScope::SuppliersRead->value], User::factory()->admin()->create()->id)->id,
                'membership' => Membership::factory()->invited()->create(['company_id' => $company->id, 'role' => Role::Buyer])->id,
                'project' => $project->id,
                'projectCategory' => $projectCategory->id,
                'member' => ProjectMember::factory()->for($project)->create()->id,
                'milestone' => $project->milestones()->firstOrFail()->id,
                'package' => $package->id,
                'packageSupplier' => PackageSupplier::factory()->for($package)->for($supplier)->create()->id,
                // Addressed to our member, so only the company stands between them and it.
                'notification' => $this->notificationOf($member, $project),
                '_documentFile' => $document->file_path,
                '_certificateFile' => (string) $certificate->file_path,
            ];
        });
    }

    /**
     * @param  array<string, int|string>  $records
     */
    private function urlFor(Route $route, Company $company, array $records): string
    {
        $parameters = ['company' => $company->slug];

        foreach (array_diff($route->parameterNames(), ['company']) as $name) {
            $key = $this->recordKey($route, $name);
            $this->assertArrayHasKey($key, $records, "Add a record for {{$name}} to recordsOf().");
            $parameters[$name] = $records[$key];
        }

        return route((string) $route->getName(), $parameters);
    }

    /**
     * The modules both call some records {category}: the supplier module's are company-wide, the projects' belong to a project.
     */
    private function recordKey(Route $route, string $parameter): string
    {
        return $parameter === 'category' && str_starts_with((string) $route->getName(), 'projects.') ? 'projectCategory' : $parameter;
    }

    /**
     * Notifications only go to people of their company, so our member has a deactivated membership there:
     * the row can exist, but the company no longer opens for them.
     */
    private function notificationOf(User $person, Project $project): string
    {
        Membership::factory()->deactivated()->create(['company_id' => $project->company_id, 'user_id' => $person->id]);
        $notification = new Notification;
        $notification->forceFill([
            'id' => (string) Str::uuid(),
            'type' => 'package_assigned',
            'notifiable_type' => $person->getMorphClass(),
            'notifiable_id' => $person->id,
            'project_id' => $project->id,
            'data' => ['params' => []],
        ])->save();

        return $notification->id;
    }

    /**
     * A fingerprint of every row each company can see, plus the shared tables, to prove that
     * refused requests changed nothing.
     *
     * @param  list<Company>  $companies
     * @return array<string, string>
     */
    private function databaseState(array $companies): array
    {
        $tables = collect(DB::select("SELECT table_name FROM information_schema.columns WHERE table_schema = 'public' AND column_name = 'company_id'"))
            ->pluck('table_name')
            ->merge(['companies', 'company_slugs', 'users'])
            ->unique()
            ->sort()
            ->values();

        $state = [];

        foreach ($companies as $company) {
            app(CurrentCompany::class)->runAs($company, function () use ($tables, $company, &$state) {
                foreach ($tables as $table) {
                    $state["{$company->slug}.{$table}"] = (string) DB::scalar(
                        "SELECT md5(coalesce(string_agg(t::text, '|' ORDER BY t::text), '')) FROM \"{$table}\" t",
                    );
                }
            });
        }

        return $state;
    }
}
