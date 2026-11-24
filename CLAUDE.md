# Procurement Hyperspace – instructions for Claude

Procurement Hyperspace: open-source software for procurement teams, built from modules: supplier relationship management (SRM) and the project procurement register (PPR). Laravel 13 (PHP 8.5), Inertia v3, Vue 3 + TypeScript, Tailwind 4, PostgreSQL 18 and Redis. It is a single-page app: no full page reloads. It is a multi-company service: one account per person, companies kept apart in the app and in PostgreSQL (README "Companies").

[README.md](README.md) explains setup, roles and business rules. Read the relevant section before changing a feature. This repository is public and licensed AGPL-3.0-only, so never put secrets or real company or supplier data in it, and never copy in code or assets whose licence doesn't allow that (record copied code in `THIRD-PARTY-NOTICES.md`).

## Commands

The host has only Docker and git. Run PHP and Node commands through Sail.

```bash
./vendor/bin/sail up -d                  # start everything
./vendor/bin/sail composer ci:check      # all checks; must pass before a pull request
./vendor/bin/sail artisan migrate --database=owner   # migrations run as the owner; the app's own user can't create tables
./vendor/bin/sail artisan test --filter=SupplierRiskTest   # one test class
./vendor/bin/sail composer lint          # fix PHP style
./vendor/bin/sail pnpm run check:fix     # fix frontend style and lint
./vendor/bin/sail restart queue scheduler  # after changing PHP that runs in the background
bin/image-smoke-test http://localhost:8080   # checks a running production setup (see docs/hosting.md)
tests/Browser/run.sh                     # real-browser tests, run from the host; see /browser-test
```

There is no `.env.testing`: `artisan ... --env=testing` still uses the development database. Only `artisan test` (phpunit.xml) and `bin/agent-test` reach the test databases, so never migrate or seed with `--env=testing`.

Helper scripts for several agents working at once, run from the host: `bin/agent-test <name>` (tests on the agent's own database), `bin/browser-test` (browser tests behind a lock), `bin/sync-check --fix` and `bin/lint-files <files>`.

`ci:check` runs the frontend checks, TypeScript, Pint, PHPStan and all tests. GitHub runs the same command and the browser tests on every pull request.

If a result makes no sense, Docker Desktop may have missed a quick file edit. Run `bin/sync-check --fix`, which compares every changed file on the host and in the container and rewrites the ones that differ.

## Modules

Core (sign-in, users, settings, API clients, layout) lives in Laravel's usual folders. Each module keeps its own code apart:

| What                         | Where (SRM as the example)                                                                                                |
| ---------------------------- | ------------------------------------------------------------------------------------------------------------------------- |
| PHP code                     | `app/Modules/Srm/...` (namespace `App\Modules\Srm`), plugged in by `SrmServiceProvider` in `bootstrap/providers.php`      |
| Routes, timed jobs, commands | `app/Modules/Srm/routes/*.php`; schedule and commands in `SrmServiceProvider`                                             |
| Migrations, factories        | `app/Modules/Srm/Database/{Migrations,Factories}`; models name their factory with `#[UseFactory]`, factories set `$model` |
| Pages, components, types     | `resources/js/pages/srm`, `resources/js/components/srm`, `@/types/srm`                                                    |
| Mail views                   | `resources/views/mail/srm`                                                                                                |
| Tests                        | `tests/Feature/Srm`, `tests/Unit/Srm`                                                                                     |

The projects module (PPR) uses the same layout under `Ppr`/`ppr`. Core never uses a module's code, and modules don't use each other's; `ModuleBoundariesTest` checks PHP code for this, with the exceptions listed in it. A module uses Core freely, and plugs into it through hooks: `PageSections` (sections on pages it doesn't own: `home`, `settings.notifications`, and the modules' own `ppr.package`, `srm.supplier`), `NotificationKinds` (its kinds of notification: who may see them, their message, where they lead), `OnboardingSteps` (steps of the getting-started checklist on Home, each with a one-query done check), `DemoData` (its part of a demo company's made-up data), Core events (`MembershipDeactivated`/`MembershipReactivated`), and `NavigationCompany` for data that needs a company on account pages. `Sourcing` is a connector module: it alone may use `Srm` and `Ppr` (`ModuleBoundariesTest::CONNECTORS`), and the projects module takes its supplier risk through `OutsideRiskReasons`. Known link the test can't see: `AppSidebar.vue` imports SRM and PPR route helpers. Seeders in `database/seeders` may fill data for every module.

## Git workflow

- Never commit to `main`. GitHub refuses direct pushes, and the `ci` check must pass before a merge.
- Use one branch per change, from an up-to-date `main`, named `<type>/<short-name>` (for example `feat/audit-log` or `fix/reminder-date`).
- Use Conventional Commits: `type(optional scope): short imperative summary`, signed off with `git commit -s` (the DCO in CONTRIBUTING.md).
- Commit, push and open pull requests only when asked. The user merges pull requests.
- Before committing, check that no secrets are staged.

## Rules for every change

- **Security must be flawless.** Follow the security rules in the README.
- **Performance is a top priority.** Avoid repeated queries in lists, and check new lists and filters against the demo data (about 2,000 suppliers, a project of about 3,000 packages). Long lists draw only the rows on screen (see `PackageTreeTable.vue`). Don't use CSS `:has()` rules such as Tailwind's `has-*` and `group-has-*` on elements that wrap the page: the browser re-checks them after every change, which made scrolling the package list stutter. Turn Xdebug off (`XDEBUG_MODE=off`) when timing pages.
- **Code explains itself** through clear names and small functions. Comments are for why, not what.
- **Controllers follow CRUD where it makes sense.** Use only `index`, `create`, `store`, `show`, `edit`, `update` and `destroy`, and leave out the ones a feature doesn't need. An action that doesn't fit CRUD, or a controller that would have only one method, becomes a single-action controller named verb + noun (for example `ArchiveSupplierController`).
- **Action classes are single-action.** Each class in an `Actions` folder (`app/Actions`, `app/Modules/*/Actions`) is named verb + noun and has one public `handle()` method, except where a framework interface requires another name (Fortify's `reset()`). An action that needs another action receives it through its constructor.
- **Ready for more languages.** Every text a person sees goes through `__()`/`trans_choice()` in PHP or `$t()`/`trans()` in Vue, and is listed in `lang/en.json`; see Languages in the README. `TranslationsTest` checks it.
- **New behaviour comes with tests.** Add a browser test in `tests/Browser` when a feature adds a page or a user flow.
- **Report the result.** Every change summary includes a short security and performance check.
- **Explain in plain language.** Keep it brief, define unavoidable technical terms and give the reason for each decision. Ask when a choice is really the user's to make.

## Things that are easy to break

- **Companies are kept apart twice: in the app and in PostgreSQL.** Read README "Keeping companies apart" before adding a table, a page or background work.
    - The app connects as a restricted database user; never give it more rights, and run migrations with `--database=owner`. `CompanyIsolationTest` checks the user and the row-level security of every table with `company_id`.
    - Company pages live under `/{company}` with the `company` middleware (`EnterCompany`), which opens the company before route binding and permission checks. Non-members get 404, the same as for an unknown company.
    - `$user->role` is the role in the current company (a membership). It must never be cached across companies (`withoutObjectCaching()` in `User::role()`).
    - New pages with a record parameter go into `CrossCompanyAccessTest`; new top-level paths outside companies go into `CompanySlug::RESERVED`. Company routes use `Company::routePattern()`, never the bare `SLUG_PATTERN`.
    - "Active" means an active account AND an active membership in this company (`User::activeInCurrentCompany()`, `isActiveIn()`, rule `ActiveMember`). Joins to `memberships` (which has no row-level security) must match `company_id`.
    - Shared page data that needs a company works inside `NavigationCompany` (the open company, or the home company on account pages) and reads nothing on platform pages.
    - Per-request caches must not outlive the request: tests and long-running servers reuse the app. `ProjectRoles` is forgotten after every request; `NavigationCompany` keeps its lookup on the request.
    - Platform admins never see company data; platform pages run with no company open.
    - Invitations never reveal whether an address has an account, and members lists never show an invited person's account details before they join.
    - `ApiClient` deliberately has no company global scope (the token endpoint needs every client); query it with the company named.
    - Tests use `Tests\Concerns\RefreshDatabase` (it migrates as the owner), and each database test starts inside its own company (`$this->company`, `enterCompany()`).
- **Viewers must not get restricted data.** Only buyers and admins see contract details (`ContractPolicy::viewDetails`), including comments about contracts, and spend (`SupplierSpendPolicy::viewAny`). Enforce this on the server by leaving the data out of the response, not by hiding it in Vue.
- **Some rules exist twice, in PHP for a page and in SQL for a list filter, and must change together.** Tests fail if they disagree:
    - Risk: `app/Modules/Srm/Compliance/SupplierRisk.php` and `Supplier::withRiskLevel()`
    - Required certificates: `app/Modules/Srm/Compliance/RequiredCertificates.php` and `Supplier::missingRequiredCertificates()`
    - Contract health: `Contract::health()` and `Contract::needingActionSoon()`
    - Chosen supplier at risk: the SQL check in `SourcingServiceProvider::addSupplierRiskToPackages()` and `PackageSuppliers::makesPackageAtRisk()` (the package page's warning)
    - Risk reason wording: `app/Modules/Ppr/Notifications/RiskReasonWords.php` (notifications and emails) and `resources/js/lib/ppr/packageRisk.ts` (pages); same `lang/en.json` texts
- **Dashboard tile counts must equal the supplier list each tile links to,** on the supplier dashboard and on Home. Archived suppliers never count. They are defined only in `app/Modules/Srm/Dashboard/SupplierAttention.php`; the project dashboard and Home share `app/Modules/Ppr/Dashboard/MyProjects.php`.
- **Overall rating scores are calculated in one place only:** `app/Modules/Srm/Actions/Ratings/RecalculateOverallScores.php`.
- **Links in emails come from `APP_URL`,** never from the request's host name (`ResetPasswordLink`, trusted hosts in `bootstrap/app.php`). A new email with a link must do the same.
- **Account forms must not reveal who has an account.** Forgot password and reset password give the same answer for every address (`app/Http/Responses`); keep any new sign-in or reset message the same for known and unknown addresses, and as fast (failed sign-ins run in a `Timebox`; the profile form accepts a taken address with the same answer and sends no link).
- **Account takeover guards (README "Account protection").**
    - An account's email changes only through `RequestEmailChange` → link → `ConfirmEmailChange`; never write `users.email` from a form. Invitations are tied to the account that holds the address.
    - Platform pages and changes to how someone signs in need a second factor proved in this session (`App\Auth\StrongSignIn`), not just the account's settings. A new way to change sign-in methods goes into `RequireSecondFactorForSignInChanges`, and must raise the account's `SignInVersion` (other sessions end).
    - Every form that checks the current password goes into `LimitPasswordConfirmations::checkedField()`, so none of them can be used to guess it.
    - A change to another member's role or status runs inside `AdminLock`.
    - The platform never joins a company that has members (active membership and active account, `App\Companies\ActiveMembers`, never counting the acting platform admin): `InviteFirstAdmin` only while there is nobody to promote, `PromoteMemberToAdmin` otherwise. The members to choose from are searched on demand (`SearchMembersToPromoteController`), never sent with the companies list.
- **Security events are logged by id only** (`App\Monitoring\SecurityEvents`): never an email address, name, typed text, token or code, and a failed sign-in names nobody. New sign-in, account, membership or platform actions get an event there (`tests/Feature/Monitoring`).
- **Every rate limit has its own name** (`throttle:30,1,uploads`); unnamed limits share one counter per person or address. Limits per visitor address use `App\Http\VisitorAddress` (IPv6 per /64).
- **A company's daily limits count only work that was done** (`App\Companies\DailyLimits`): a new way to send invitations or store files wraps its work in `sendingInvitation()` or `storingUpload()` inside the controller, after the permission and form checks, never as route middleware (that runs before the company and permission checks).
- **Records are kept.** Suppliers are archived, packages are never deleted (cancel them), and users and memberships are deactivated, never deleted. Contacts are truly deleted for privacy.
- **Projects: people see only their own projects.** A company admin can do everything in every project; everyone else gets rights from their role in each project (`App\Modules\Ppr\Access\ProjectRoles`, policies `ProjectPolicy`/`PackagePolicy`), whatever their company role. The package list checks thousands of rows through `PackagePolicy::updateOwnedBy()` directly, so change the rule there. The project dashboard shows only projects the person is a member of, admins included.
- **Package numbers are never reused.** Only `AssignPackageNumber` gives numbers, under a project lock. The rules exist twice and must change together: `app/Modules/Ppr/Numbering/PackageNumberFormat.php` and `resources/js/lib/ppr/packageNumber.ts` (`tests/Browser/admin/projects.spec.ts` fails if they disagree).
- **Project dashboard numbers are defined only in `app/Modules/Ppr/Dashboard/ProjectAttention.php`,** and each has a package list filter in `resources/js/lib/ppr/packageFilters.ts` (`TILE_FILTERS`) that must show exactly the packages it counts (`tests/Browser/viewer/dashboard-numbers.spec.ts`). Never count packages in a controller or in Vue.
- **Project dates, risk and "finished" live in one place each.** Milestone dates: `app/Modules/Ppr/Scheduling` (`PackageSchedule`, `SubPackageRollUp`). Overdue, due soon and reminders count from the due date (`ScheduledMilestone::due()`, `isOverdue()`), never the expected date. "Finished" (closed or cancelled, itself or above) only through `ProjectSchedules::isFinished()`. Risk: `app/Modules/Ppr/Risk` (`PackageRisk`, `ProjectRisks`), calculated live, never stored for display. Read "today" once per request and pass it into `ProjectSchedules::for()` (`TodayAcrossMidnightTest`). Planned dates, once set, change only by project managers and admins (`PackagePolicy::changePlannedDates`). A project always has exactly one need-date milestone and keeps a project manager. Merging and dividing packages (later) must stay traceable.
- **Notifications are private and per company.** Read them only through `App\Models\Notification::visibleTo()`; each module registers its kinds in `App\Notifications\NotificationKinds` with a SQL rule for who may still see them (projects they can see; contract details for contract reminders), and a kind nobody registers is never shown. Messages are written once, in PHP, for the page and the summary email. Project kinds extend `ProjectNotification` (`PackageNotification` for one package). Risk and date notifications come only from `CheckProjectRisks`, whose atomic `UPDATE … RETURNING` / `INSERT … ON CONFLICT DO NOTHING RETURNING` stop double notifications; every save that can change a package's risk dispatches it (with the saver and `savedPackageIds`), including membership changes through the Core events.
- **Page loading.** Only `useNavigationState.ts` decides whether a page is loading. Prefetch only pages whose GET changes nothing: never notification links, Security settings or the company switcher (opening a company records it as the last one); `lib/prefetchCache.ts` forgets prefetched pages after each save. The package tree is a deferred prop; a form on the package list must reload it with `only: ['packages', 'today']`, and the list's filters live in the page address (`router.replace` only after the tree has loaded).
- **A package's chosen supplier is part of its risk.** Any save that can change which supplier is chosen, or a chosen supplier's risk, must queue `CheckProjectRisks` (`PackageSupplierController`, `CheckPackagesChoosingSuppliers` and the model listeners in `SourcingServiceProvider`). A bulk SQL update of suppliers fires no model events: queue the check yourself.
- **Demo data runs in production.** Fillers registered in `App\Companies\DemoData` use fixed word lists, never `fake()` or factories (`DemoDataSourceTest`), never create user accounts, and name only the demo company's first admin. A job queued from a filler must be queued with `DB::afterCommit` while the company is open (see `DemoProject`), or it runs in no company.
- **Supplier and contact fields are checked in one place,** `SupplierFields` and `ContactFields`, for the forms and the file import. A new supplier field goes there and into `ImportColumn`. `ImportSuppliers` inserts suppliers and contacts many rows at a time, without model events: a new listener on `Supplier` or `Contact` needs its work done there too (`SupplierImportRowsTest` fails until then).
- **The getting-started checklist runs its done checks on every admin Home visit** while it is shown, or hidden with steps still open: keep each check one indexed `exists`/`count` query (`OnboardingChecklistTest` counts them).
- **Production setup (README "Running it for real", docs/).** The image copies only the folders the `Dockerfile` lists, and the app services in `compose.production.yaml` blank every secret they don't need (`x-app` environment): a new top-level folder the app needs goes into the `Dockerfile`, and a new secret for another service goes into that blank list. `bin/image-smoke-test` (workflow `image`) checks that the image starts and that the app never gets those secrets; workflow `backups` checks a real backup and restore.
    - Uploaded files go through `App\Files\Uploads` only (`UPLOADS_DISK`), never `Storage::disk('local')`.
    - Error reports leave our servers: `App\Monitoring\ErrorReportPrivacy` keeps only ids, never emails, names, typed text or query values (`ErrorReportsTest`). New context data for reports goes through it.
    - `/health` answers anyone, so it shows only check names and ok/failing; it must stay a few constant queries (`HealthCheckTest`).
- **Seeders must refuse to run in production.** `SeedersRefuseProductionTest` covers this, so add any new seeder to it.
- **Every app page links to the source code** (`SOURCE_CODE_URL`, shared as `sourceCodeUrl`, shown in the sidebar and on the sign-in pages). The AGPL licence needs it; keep it on new Inertia layouts. Laravel's plain error pages don't have it yet.
- **Generated code:** `resources/js/actions`, `resources/js/routes` and `resources/js/wayfinder` are generated and ignored by git. Don't edit them.
