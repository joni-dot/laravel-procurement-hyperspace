# Procurement Hyperspace – instructions for Claude

Procurement Hyperspace: open-source software for procurement teams, built from modules (supplier relationship management now, the project procurement register later). Laravel 13 (PHP 8.5), Inertia v3, Vue 3 + TypeScript, Tailwind 4, PostgreSQL 18 and Redis. It is a single-page app: no full page reloads. It is still a single-company app; support for many companies comes in a later step (see the README's step list).

[README.md](README.md) explains setup, roles and business rules. Read the relevant section before changing a feature. This repository is public and licensed AGPL-3.0-only, so never put secrets or real company or supplier data in it, and never copy in code or assets whose licence doesn't allow that (record copied code in `THIRD-PARTY-NOTICES.md`).

## Commands

The host has only Docker and git. Run PHP and Node commands through Sail.

```bash
./vendor/bin/sail up -d                  # start everything
./vendor/bin/sail composer ci:check      # all checks; must pass before a pull request
./vendor/bin/sail artisan test --filter=SupplierRiskTest   # one test class
./vendor/bin/sail composer lint          # fix PHP style
./vendor/bin/sail pnpm run check:fix     # fix frontend style and lint
./vendor/bin/sail restart queue scheduler  # after changing PHP that runs in the background
tests/Browser/run.sh                     # real-browser tests, run from the host; see /browser-test
```

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

Core never uses a module's code, and modules don't use each other's; `ModuleBoundariesTest` checks this, with the exceptions listed in it. A module uses Core freely. Seeders in `database/seeders` may fill data for every module.

## Git workflow

- Never commit to `main`. GitHub refuses direct pushes, and the `ci` check must pass before a merge.
- Use one branch per change, from an up-to-date `main`, named `<type>/<short-name>` (for example `feat/audit-log` or `fix/reminder-date`).
- Use Conventional Commits: `type(optional scope): short imperative summary`, signed off with `git commit -s` (the DCO in CONTRIBUTING.md).
- Commit, push and open pull requests only when asked. The user merges pull requests.
- Before committing, check that no secrets are staged.

## Rules for every change

- **Security must be flawless.** Follow the security rules in the README.
- **Performance is a top priority.** Avoid repeated queries in lists, and check new filters against the demo data (about 2,000 suppliers).
- **Code explains itself** through clear names and small functions. Comments are for why, not what.
- **Controllers follow CRUD where it makes sense.** Use only `index`, `create`, `store`, `show`, `edit`, `update` and `destroy`, and leave out the ones a feature doesn't need. An action that doesn't fit CRUD, or a controller that would have only one method, becomes a single-action controller named verb + noun (for example `ArchiveSupplierController`).
- **Action classes are single-action.** Each class in an `Actions` folder (`app/Actions`, `app/Modules/*/Actions`) is named verb + noun and has one public `handle()` method, except where a framework interface requires another name (Fortify's `reset()`). An action that needs another action receives it through its constructor.
- **New behaviour comes with tests.** Add a browser test in `tests/Browser` when a feature adds a page or a user flow.
- **Report the result.** Every change summary includes a short security and performance check.
- **Explain in plain language.** Keep it brief, define unavoidable technical terms and give the reason for each decision. Ask when a choice is really the user's to make.

## Things that are easy to break

- **Viewers must not get restricted data.** Only buyers and admins see contract details (`ContractPolicy::viewDetails`), including comments about contracts, and spend (`SupplierSpendPolicy::viewAny`). Enforce this on the server by leaving the data out of the response, not by hiding it in Vue.
- **Some rules exist twice, in PHP for a page and in SQL for a list filter, and must change together.** Tests fail if they disagree:
    - Risk: `app/Modules/Srm/Compliance/SupplierRisk.php` and `Supplier::withRiskLevel()`
    - Required certificates: `app/Modules/Srm/Compliance/RequiredCertificates.php` and `Supplier::missingRequiredCertificates()`
    - Contract health: `Contract::health()` and `Contract::needingActionSoon()`
- **Dashboard tile counts must equal the supplier list each tile links to.** Archived suppliers never count.
- **Overall rating scores are calculated in one place only:** `app/Modules/Srm/Actions/Ratings/RecalculateOverallScores.php`.
- **Links in emails come from `APP_URL`,** never from the request's host name (`ResetPasswordLink`, trusted hosts in `bootstrap/app.php`). A new email with a link must do the same.
- **Account forms must not reveal who has an account.** Forgot password and reset password give the same answer for every address (`app/Http/Responses`); keep any new sign-in or reset message the same for known and unknown addresses.
- **Records are kept.** Suppliers are archived and users are deactivated, never deleted. Contacts are truly deleted for privacy.
- **Seeders must refuse to run in production.** `SeedersRefuseProductionTest` covers this, so add any new seeder to it.
- **Every app page links to the source code** (`SOURCE_CODE_URL`, shared as `sourceCodeUrl`, shown in the sidebar and on the sign-in pages). The AGPL licence needs it; keep it on new Inertia layouts. Laravel's plain error pages don't have it yet.
- **Generated code:** `resources/js/actions`, `resources/js/routes` and `resources/js/wayfinder` are generated and ignored by git. Don't edit them.
