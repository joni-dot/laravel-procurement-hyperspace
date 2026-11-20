# Procurement Hyperspace

Open-source software for procurement teams, built from modules:

- **Supplier relationship management (SRM):** suppliers, contacts, certificates, contracts, ratings, risk and spend. Available now.
- **Project procurement register (PPR):** everything a project must buy, with milestones, lead times and risk. Joins as a module in a later step; until then it lives in [laravel-ppr](https://github.com/joni-dot/laravel-ppr).

It works as a single-page app: pages change without full reloads.

This repository continues the [laravel-srm](https://github.com/joni-dot/laravel-srm) and [laravel-ppr](https://github.com/joni-dot/laravel-ppr) apps, and both apps' git history is kept here. The goal is a cloud service that many companies can use, which people can also host themselves. Steps towards it:

1. One repository, with the supplier features as a module _(done)_
2. Companies: one account per person, invitations to several companies and a company switcher, each company's data kept apart _(done)_
3. The project procurement register joins as a module
4. The modules work together: packages linked to suppliers, one dashboard and one set of notifications
5. Getting started: a setup checklist, supplier import and a demo company
6. Production: Docker image, hosting guide, backups and monitoring

## Tech stack

| Part                    | Tool                               |
| ----------------------- | ---------------------------------- |
| Backend                 | Laravel 13 (PHP 8.5)               |
| Frontend                | Vue 3 + TypeScript, via Inertia.js |
| Styling                 | Tailwind CSS                       |
| Database                | PostgreSQL 18                      |
| Cache, sessions, queues | Redis                              |
| Local setup             | Docker (Laravel Sail)              |

## Getting started

You only need **Docker** and **git**. Everything else runs inside containers.

```bash
cp .env.example .env                 # then set DB_PASSWORD and DB_OWNER_PASSWORD to long random values (Docker will not start without them)
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app composer:latest composer install
./vendor/bin/sail build              # build the app image (first time only)
./vendor/bin/sail up -d              # start the app, database, Redis, Mailpit and the queue worker
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan passport:keys   # keys that sign API tokens; kept out of git
./vendor/bin/sail artisan migrate --database=owner   # migrations run as the database owner, see "Companies"
./vendor/bin/sail pnpm install
./vendor/bin/sail artisan app:create-user --platform-admin   # there is no public sign-up
./vendor/bin/sail pnpm run dev       # live-reloading frontend
```

Open http://localhost:8090. A platform admin turns on two-factor sign-in in their security settings, then creates companies on the **Companies** page; each company's first admin gets an invitation and invites the rest from the **Users** page.

Want something to click around in? `./vendor/bin/sail artisan db:seed` creates a **Demo Company** (`/demo`) with an admin, test@example.com with the password `password`. Fill it with about 2,000 made-up suppliers (these refuse to run in production):

```bash
./vendor/bin/sail artisan db:seed --class=DemoSupplierSeeder
./vendor/bin/sail artisan db:seed --class=DemoCertificateSeeder
./vendor/bin/sail artisan db:seed --class=DemoContractSeeder
./vendor/bin/sail artisan db:seed --class=DemoRatingSeeder
```

Emails (invitations, password resets, certificate reminders) are sent in the background by the `queue` container and show up in Mailpit at http://localhost:8027.

The ports differ from the old SRM and PPR apps', so all three can run at the same time. Change them in `.env` if needed.

The `scheduler` container runs timed jobs. Every morning at 06:00 (UTC) it emails certificate reminders: each person gets one email listing all their certificates that need renewing. To send due reminders right away while testing:

```bash
./vendor/bin/sail artisan app:send-certificate-reminders
```

After changing PHP code that runs in the background, restart the workers with `./vendor/bin/sail restart queue scheduler`.

## Companies

The service holds many companies, and each company's data is kept apart. A person has one account (their email address) and can belong to several companies, with a role in each.

- **Addresses:** a company opens at `/{its address}`, for example `/acme/suppliers`. People who aren't members get "not found", the same answer as for an address no company has, so outsiders can't tell which companies exist.
- **Switching companies:** the menu at the top of the sidebar shows the open company and every company the person belongs to (suspended ones are shown but can't be opened), plus their invitations and account settings. No new sign-in is needed. Signing in, or opening `/`, goes to the company used last.
- **Account and company:** name, email, password and two-factor sign-in belong to the account and work everywhere. Roles, settings (such as the reminder email) and API clients belong to one company.

### Roles in a company

| Role   | Can do                                                                          |
| ------ | ------------------------------------------------------------------------------- |
| Admin  | Everything, plus users, categories, certificate types, API clients and settings |
| Buyer  | Add, edit and archive suppliers and their contacts                              |
| Viewer | Look only                                                                       |

### Users and invitations

Each company's admins manage its people on **Users** (`/{company}/admin/users`). They invite someone by name, email and role, change roles, deactivate or reactivate members, and resend invitations. They can't edit a person's name or email, because those belong to the person's account, and nobody can change their own role or deactivate themselves, so a company always keeps an admin.

- **Inviting looks the same whether or not the address already has an account.** Until an invited person joins, the list shows only the name and address the inviter typed.
- **A new address** gets an email with a link to set a password, which joins the company and signs them in.
- **Someone with an account** signs in and chooses Accept or Decline, from the email link or from their **Invitations** page (`/invitations`). Nobody joins a company without accepting.
- Invitation links work for 3 days (`auth.invitations.expire`, in minutes) and only once; a new link replaces the old one. Unknown, used and expired links all get the same answer.
- **Deactivating** takes a person out of that one company; their account, their history there and their other companies stay. Deactivating a pending invitation cancels its link. People who belong to no company land on their Invitations page.

Users are deactivated, never deleted, so their history stays intact.

### Platform admins

Platform admins run the service at `/platform/companies`. They never see a company's data, only its name, address, status and number of members. They must turn on two-factor sign-in, and enter their password again if they haven't recently, before the platform pages open; everyone else gets "not found" there. Create the first one with `sail artisan app:create-user --platform-admin`.

- **Creating a company:** a name, an address (lowercase letters, digits and dashes, at most 40 characters) and the first admin's name and email. That person gets an invitation to be the company's admin. Self-hosters can do the same with `sail artisan app:create-company "Acme Oy" acme anna@example.com --admin-name="Anna"`; the command also prints the invitation link, to pass on if email isn't set up yet (treat it like a password). Addresses the app uses itself (`platform`, `settings`, `login`, `api` and so on) are reserved.
- **Changing an address:** old links keep working; they forward the company's members to the new address. An address that a company has ever had is never given to another company.
- **Suspending** a company stops its members (and its API clients) from opening it but keeps all its data; reopening gives everything back.

### Behind the scenes

- **The database keeps companies apart too.** Every company table has PostgreSQL row-level security: the database only shows and accepts rows of the company the request works for, and with no company it shows nothing. Links between rows, and to people, can't point into another company. The app connects as a database user that can only read and write rows, so it can't switch this off; a separate owner user runs the migrations (`DB_OWNER_USERNAME`, `artisan migrate --database=owner`). When hosting, create both users the way `docker/pgsql/app-role.sql` describes; the tests fail if the app's user is a superuser, can bypass row-level security or owns tables.
- **Background work:** queued jobs run in the company they were queued from. Timed jobs (contract renewal, contract and certificate reminders) go through every company that isn't suspended, one at a time; each email covers one company, names it in the subject, goes to that company's people (or its own reminder email) and links into its address, built from `APP_URL`. A failure in one company is logged and the others still run.
- **Files** are stored under `companies/{company id}/...` on the private disk.
- **A test tries every company page** that takes a record with another company's records and expects "not found" (`CrossCompanyAccessTest`); a new page of that kind fails the test until it is added there.

Suppliers are archived, never deleted, and can be restored. Contacts are people, so they are truly deleted when removed.

## Ratings, risk and spend

- **Ratings:** buyers and admins score a supplier from 1 to 5 on each criterion. The overall score is the weighted average, and the latest rating (by date) counts. Admins manage the criteria and their weights, which cannot total more than 100%; changing a weight recalculates all saved overall scores and risk levels. A criterion old ratings use is retired, not deleted.
- **Risk level** is calculated live, and buyers and admins can override it with a reason:
    - **High:** the supplier is blocked, the latest rating is below 2.5, or a valid required certificate is missing.
    - **Medium:** the latest rating is below 3.5, or a certificate has expired or expires within 30 days with no replacement.
    - **Low:** everything else, including suppliers with no rating yet.
- **Contracts are left out of risk on purpose:** viewers can see risk, but not contract details.
- **Spend** is one amount per supplier per year, visible only to buyers and admins. Amounts in different currencies are not added together.

The thresholds are in `app/Modules/Srm/Enums/RiskLevel.php`. The rules exist twice and must change together: `app/Modules/Srm/Compliance/SupplierRisk.php` (the page) and `Supplier::withRiskLevel()` (the list filter). `SupplierRiskTest` fails if they disagree.

## Contracts and documents

- **Who sees what:** everyone can see how many contracts a supplier has. Only buyers and admins can see contract details, values and files.
- **Key date:** reminders count back from the last day to cancel for auto-renewing contracts, and from the end date for the rest.
- **Auto-renewal:** every morning at 05:30 (UTC), active auto-renewing contracts past their end date move forward by their renewal term. Set a contract to Terminated to stop this.
- **Reminders** go to the contract owner, then the responsible buyer, then the reminder email under **Settings**. Emails never include prices.
- **Documents** (PDF, JPG, PNG, .docx, .xlsx, up to 20 MB) are stored privately. Macro-enabled Office files are rejected.

To run these jobs right away while testing:

```bash
./vendor/bin/sail artisan app:renew-contracts
./vendor/bin/sail artisan app:send-contract-reminders
```

## Certificates

- Each certificate has its own email reminders (for example 60 and 14 days before it expires). They go to the supplier's responsible buyer, or to the reminder email set by an admin under **Settings**.
- Admins can mark a certificate type as **required**. Active suppliers without a valid certificate of that type are flagged in the supplier list.
- Uploaded files (PDF, JPG, PNG, up to 10 MB) are stored privately and can only be downloaded by signed-in users.

## Comments

- **Who writes:** buyers and admins can comment on a supplier and on its contracts. Everyone can read supplier comments; contract comments are for buyers and admins only, like the rest of the contract.
- **Where they show:** each page shows its own comments, oldest first, with the box for a new comment underneath. Supplier comments are on the supplier page and contract comments on the contract page.
- **Changing them:** people can edit their own comments (shown as "edited") and delete them. Admins can delete anyone's comment, for example to remove personal data.
- **Kept with their record:** comments on archived suppliers are read-only. Deleting a contract deletes its comments too.

## API for other systems

Other systems, such as an ERP or a reporting tool, can read supplier data through an API. There are no public endpoints: every request needs a valid access token. Each API client belongs to one company and only ever sees that company's data; a suspended company's clients get no tokens, and tokens they already have are refused.

- **Who gets access:** an admin adds the system on the **API clients** page, ticks what it may read and names a responsible person. The page asks for the admin's password again first.
- **Signing in:** the system uses OAuth 2.0 client credentials. It sends its client ID and secret to `POST /api/oauth/token` and gets an access token that lasts 15 minutes. There are no refresh tokens; it simply asks again. No other OAuth flows are offered.
- **The secret is shown once.** The app keeps only a hash. If the secret is lost or may have leaked, create a new one: the old secret and its tokens stop working at once. Revoking a client is permanent; it stays in the list for the record.
- **What it can read** (scope `suppliers:read`): `GET /api/v1/suppliers` and `GET /api/v1/suppliers/{id}` return company details, categories, latest rating and risk level. Notes, contacts, the responsible buyer, contracts and spend are never included.
- **Paging and filters:** results come 100 at a time (`per_page`, up to 200). Pass the returned `next_cursor` as `cursor` for the next page. Filters: `status`, `updated_since` (changes to the supplier's own details) and `include_archived=true`.
- **Servers only:** web pages on other sites cannot call the API from a browser (CORS is off), and a login session never counts as API access.
- **Limits and records:** each client may make 120 requests a minute. An address that fails to get a token 10 times is blocked for 15 minutes. Every call is logged with the client, address and result in `storage/logs/api-*.log`, kept for 90 days.

```bash
curl -u "$CLIENT_ID:$CLIENT_SECRET" -d grant_type=client_credentials https://hyperspace.example.com/api/oauth/token
curl -H "Authorization: Bearer $ACCESS_TOKEN" "https://hyperspace.example.com/api/v1/suppliers?status=active"
```

On a server, create the signing keys once with `artisan passport:keys`, or put them in `PASSPORT_PRIVATE_KEY` and `PASSPORT_PUBLIC_KEY`. Keep the same keys across deployments; new keys log every system out (they simply fetch a new token).

## How the code is organised

The app has a **Core** (sign-in, users, settings, API clients and the layout) and **modules**. Each module keeps its code, database tables, pages and tests in its own folders, for example `app/Modules/Srm` and `resources/js/pages/srm`, and plugs itself into Core. Core's server code doesn't use a module's code, and a test checks this. A few links remain until modules get their own menus in a later step: the dashboard belongs to the supplier module for now, and the sidebar lists its pages, so a module can't be switched off yet. CLAUDE.md lists the folders.

## Everyday commands

```bash
./vendor/bin/sail artisan test            # run all tests
./vendor/bin/sail composer lint           # fix PHP code style
./vendor/bin/sail php vendor/bin/phpstan analyse   # find PHP type errors
./vendor/bin/sail pnpm run check:fix      # fix frontend style and lint
./vendor/bin/sail pnpm run types:check    # find TypeScript errors
```

Tip: add `alias sail='./vendor/bin/sail'` to your shell config to type less.

## Browser tests

The tests in `tests/Browser` use a real Chrome browser to sign in as each role, click through the main features and check that viewers never receive contract or spend data. Every test also fails on a JavaScript error or a blocked script.

```bash
tests/Browser/run.sh                 # run all of them (about 1.5 minutes)
tests/Browser/run.sh viewer          # run only the files matching "viewer"
```

They run on a separate copy of the app that erases and refills the `testing` database, so your development data, files and emails are not touched. Don't run them at the same time as `sail artisan test`, which uses the same database. Stop `pnpm run dev` first.

If a test fails, the screenshot and a step-by-step trace are in `tests/Browser/.output`. On GitHub, they are attached to the failed run.

The Playwright version is set in three places that must match: `package.json`, `compose.yaml` and `.github/workflows/tests.yml`.

## Security rules for developers

- **No inline JavaScript.** The Content Security Policy only lets the browser run scripts from this app's own files. `<script>` blocks, `onclick="..."` attributes and scripts from other websites are blocked. If a Blade view truly needs an inline script, give it `nonce="{{ Vite::cspNonce() }}"`.
- **Never show user input as HTML.** Vue's `{{ }}` and Blade's `{{ }}` escape text automatically. Do not use `v-html` or `{!! !!}` with anything a user typed.
- **Check permissions on the server.** Hiding a button is only for convenience; every route needs a `->can(...)` check.

### Keeping companies apart

- **A new company table** gets its company column, row-level security and company links in its migration with `App\Companies\CompanyIsolation` (see `2026_11_21_100100_add_companies_to_supplier_tables.php`), and its model uses `BelongsToCompany`. `CompanyIsolationTest` fails if a table with a `company_id` has no row-level security.
- **A new company page** goes under `/{company}/...` (the `company` middleware) and, if it takes a record, into `CrossCompanyAccessTest`. Pages outside companies (account, invitations, platform) never read company data; their first path segment must be in `App\Rules\CompanySlug::RESERVED`, which `CompanySlugTest` checks.
- **Work outside a request** (commands, timed jobs) opens each company with `CurrentCompany::runAs()` or `EveryActiveCompany`; without a company nothing is visible.
- **Tables without row-level security on purpose:** `memberships`, `company_slugs` and `oauth_clients` (the token endpoint looks clients up before any company is known). Queries on them always name the company.

### Account protection

- **Links in emails come from `APP_URL`, never from the request.** Outside local development and tests the app only accepts requests for `APP_URL`'s own host name; set it to the real address.
- **Forgot password** gives the same answer whether or not the address has an account, and a wrong reset link gets the same error for every address. Both answers always take at least one second (`AUTH_TIMEBOX_DURATION`), so their speed doesn't give it away either. Asking for links and resetting passwords are limited to 5 tries a minute per IP address. Reset and invitation emails are sent in the background, encrypted while they wait in the queue because they hold working links.
- **Changing the email address** asks for the current password, and the old address gets an email about the change. Whoever controls the address can reset the password, so this keeps a few minutes at someone's unlocked computer from becoming a lasting takeover.
- **Changing or resetting the password** signs out every other browser where the person was signed in (Laravel's `AuthenticateSession`). API clients are not affected; they sign in with their own credentials.
- **Signing out** (and every other way of landing on the sign-in page: an expired session, a password change elsewhere, a deactivated account) clears the pages the browser kept, so the Back button can't show them to the next person. The browser keeps them encrypted (Inertia's history encryption), which needs HTTPS or `localhost`.

## Ground rules

- **Security first.** Every change is reviewed for security and performance before it is merged.
- **Readable code.** Code should explain itself through clear names and small functions. Comments are for _why_, not _what_.
- **Tests.** New behaviour comes with tests.

## Contributing and security

See [CONTRIBUTING.md](CONTRIBUTING.md). Report security problems privately, as [SECURITY.md](SECURITY.md) explains, never in a public issue.

## Licence

Procurement Hyperspace is free software, licensed under the
[GNU Affero General Public License v3.0 only](LICENSE) (AGPL-3.0-only).
Copyright (C) 2026 joni-dot.

In plain words: you may use, change and host this software, including
for your own company, at no cost. If you share a copy (changed or not),
you must share its source code under the same licence. If you let other
people use a changed version over a network, for example as a hosted
service, you must also offer those users the source code of the exact
version they use. Every page has a **Source code** link for this; it points to this
repository unless `SOURCE_CODE_URL` says otherwise, so set it when you
run a changed copy. The software comes with no warranty. Parts come from other
open-source projects under their own permissive licences; see
[THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).
