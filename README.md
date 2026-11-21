# Procurement Hyperspace

Open-source software for procurement teams, built from modules:

- **Supplier relationship management (SRM):** suppliers, contacts, certificates, contracts, ratings, risk and spend. Available now.
- **Project procurement register (PPR):** everything a project must buy (its _packages_), with numbering, milestones, lead times, risk levels and notifications. Available now.

It works as a single-page app: pages change without full reloads.

This repository continues the [laravel-srm](https://github.com/joni-dot/laravel-srm) and [laravel-ppr](https://github.com/joni-dot/laravel-ppr) apps, and both apps' git history is kept here. The goal is a cloud service that many companies can use, which people can also host themselves. Steps towards it:

1. One repository, with the supplier features as a module _(done)_
2. Companies: one account per person, invitations to several companies and a company switcher, each company's data kept apart _(done)_
3. The project procurement register joins as a module _(done)_
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

For projects, `./vendor/bin/sail artisan db:seed --class=DemoProjectSeeder` adds a made-up project with about 3,000 packages to the Demo Company, most with milestone dates so some are late and some have time to spare. It takes a few minutes and refuses to run in production. Its people join the company as viewers, so their project roles decide what they may do; the demo admin is its project manager.

Emails (invitations, password resets, certificate reminders) are sent in the background by the `queue` container and show up in Mailpit at http://localhost:8027.

The ports differ from the old SRM and PPR apps', so all three can run at the same time. Change them in `.env` if needed.

The `scheduler` container runs timed jobs. Every morning at 06:00 (UTC) it emails certificate reminders: each person gets one email listing all their certificates that need renewing. To send due reminders right away while testing:

```bash
./vendor/bin/sail artisan app:send-certificate-reminders
```

At 04:00 (UTC) it also checks every project's risk levels and sends date reminders as in-app notifications (`sail artisan packages:check-risks`, see "Notifications").

After changing PHP code that runs in the background, restart the workers with `./vendor/bin/sail restart queue scheduler`.

## Companies

The service holds many companies, and each company's data is kept apart. A person has one account (their email address) and can belong to several companies, with a role in each.

- **Addresses:** a company opens at `/{its address}`, for example `/acme/suppliers`. People who aren't members get "not found", the same answer as for an address no company has, so outsiders can't tell which companies exist.
- **Switching companies:** the menu at the top of the sidebar shows the open company and every company the person belongs to (suspended ones are shown but can't be opened), plus their invitations and account settings. No new sign-in is needed. Signing in, or opening `/`, goes to the company used last.
- **A company's home page:** `/{company}` opens the project dashboard for admins and for people who belong to at least one project there, and the supplier dashboard for everyone else. Each module offers its overview page (`App\Companies\CompanyHomePages`); Core doesn't know the modules' pages.
- **Account and company:** name, email, password and two-factor sign-in belong to the account and work everywhere. Roles, settings (such as the reminder email) and API clients belong to one company.

### Roles in a company

| Role   | Can do                                                                          |
| ------ | ------------------------------------------------------------------------------- |
| Admin  | Everything, plus users, categories, certificate types, API clients and settings |
| Buyer  | Add, edit and archive suppliers and their contacts                              |
| Viewer | Look only                                                                       |

These roles decide what people may do with suppliers. In projects, an admin can do everything in every project of the company; everyone else gets their rights from their role in each project (see "Projects"), so a company viewer can run a project.

### Users and invitations

Each company's admins manage its people on **Users** (`/{company}/admin/users`). They invite someone by name, email and role, change roles, deactivate or reactivate members, and resend invitations. They can't edit a person's name or email, because those belong to the person's account, and nobody can change their own role or deactivate themselves, so a company always keeps an admin.

- **Inviting looks the same whether or not the address already has an account.** Until an invited person joins, the list shows only the name and address the inviter typed.
- **A new address** gets an email with a link to set a password, which joins the company and signs them in.
- **Someone with an account** signs in and chooses Accept or Decline, from the email link or from their **Invitations** page (`/invitations`). Nobody joins a company without accepting.
- Invitation links work for 3 days (`auth.invitations.expire`, in minutes) and only once; a new link replaces the old one. Unknown, used and expired links all get the same answer.
- **Deactivating** takes a person out of that one company; their account, their history there and their other companies stay. Deactivating a pending invitation cancels its link. People who belong to no company land on their Invitations page.

Users are deactivated, never deleted, so their history stays intact.

**Former members keep the name they had.** Everywhere in the company (project members, package owners and buyers, risk flags, supplier comments, ratings, responsible people, risk overrides and contract owners), someone who has been deactivated is shown with the name and email they had when they left, never their account's later ones; active members are shown as they are now (`Membership::nameOnRecordsSql()`).

### Platform admins

Platform admins run the service at `/platform/companies`. They never see a company's data, only its name, address, status and number of members. They must turn on two-factor sign-in, and enter their password again if they haven't recently, before the platform pages open; everyone else gets "not found" there. Create the first one with `sail artisan app:create-user --platform-admin`.

- **Creating a company:** a name, an address (lowercase letters, digits and dashes, at most 40 characters) and the first admin's name and email. That person gets an invitation to be the company's admin. Self-hosters can do the same with `sail artisan app:create-company "Acme Oy" acme anna@example.com --admin-name="Anna"`; the command also prints the invitation link, to pass on if email isn't set up yet (treat it like a password). Addresses the app uses itself (`platform`, `settings`, `login`, `api` and so on) are reserved.
- **A company without an admin:** if the first admin's invitation expires or went to a mistyped address, nobody in the company can invite people. While a company has no active admin, a platform admin can send the invitation again or to a corrected address (the person button on the company's row); a link sent earlier to another address stops working. Once the company has an admin, its own admins invite people.
- **Changing an address:** old links keep working; they forward the company's members to the new address. An address that a company has ever had is never given to another company.
- **Suspending** a company stops its members (and its API clients) from opening it but keeps all its data; reopening gives everything back.

### Behind the scenes

- **The database keeps companies apart too.** Every company table has PostgreSQL row-level security: the database only shows and accepts rows of the company the request works for, and with no company it shows nothing. Links between rows, and to people, can't point into another company. The app connects as a database user that can only read and write rows, so it can't switch this off; a separate owner user runs the migrations (`DB_OWNER_USERNAME`, `artisan migrate --database=owner`). When hosting, create both users the way `docker/pgsql/app-role.sql` describes; the tests fail if the app's user is a superuser, can bypass row-level security or owns tables.
- **Background work:** queued jobs run in the company they were queued from. Timed jobs (contract renewal, contract and certificate reminders) go through every company that isn't suspended, one at a time; each email covers one company, names it in the subject, goes to that company's people (or its own reminder email) and links into its address, built from `APP_URL`. A failure in one company is logged and the others still run.
- **Notifications** belong to the company of their project. Someone in several companies sees each company's notifications only inside it; the bell counts the company the menu points into (on account pages, their home company), and platform pages never count them. The daily risk check queues one job per project of every active company, and each job runs inside its project's company.
- **"Deactivated" means "no longer in this company".** In projects, an owner or buyer whose membership in the company is deactivated (or whose account is) counts as deactivated there and turns their packages amber, even if they are active in another company.
- **Files** are stored under `companies/{company id}/...` on the private disk.
- **Known limit: supplier search in very large companies.** Row-level security stops PostgreSQL from using the fast text index for the supplier search (its `ILIKE` operator isn't "leakproof"), so the search reads all of the company's suppliers. That takes a few milliseconds at about 2,000 suppliers and about 70 ms at 30,000. Accepted for now; if a company grows far past about 10,000 suppliers, a separate search table is the planned fix.
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

## Projects

The project procurement register plans and tracks the items each project buys. Each purchasable item is a **package**. Projects live at `/{company}/projects`; the project dashboard at `/{company}/projects/dashboard`.

### Roles in a project

A company admin can do everything in every project of the company, and is the only one who creates projects and changes the company's risk warning defaults. Everyone else gets their rights from their role in each project, whatever their company role, so a person can lead one project and only look at another. People see only the projects they belong to; other projects don't show up anywhere, and their addresses answer "forbidden".

| Project role    | Can do                                                                   |
| --------------- | ------------------------------------------------------------------------ |
| Project manager | Runs the project: details, risk warnings, numbering, categories, members |
| Buyer           | Adds packages and edits every package in the project                     |
| Package owner   | Edits the packages they own, but can't hand them to someone else         |
| Viewer          | Looks at everything in the project but changes nothing                   |

Project managers can also add and edit every package. Only active members of the company (joined, with an active membership and account) can be chosen as project manager, project member, package owner or buyer. A package's owner must be a project manager, buyer or package owner in that project, and its responsible buyer a project manager or buyer. Removing someone from a project also removes them as owner or buyer of its packages. Each new project gets a project manager straight away, and a project can never lose its last project manager. Project codes are unique within a company, ignoring case; another company may use the same code. So a project manager who tries a code that a project hidden from them already uses learns that the code is taken, but nothing else about that project.

Colleagues see each other's work email in projects: a project manager adding people sees the name and email of every active member of the company, and project members see each other's on the Members tab. Only members of the same company are ever shown.

### Risk warnings

Company admins set the default **risk warnings** (Administration → Project risk warnings): a package turns amber or red when its spare time (days between expected delivery and the date it is needed) drops below these numbers. A new project gets a copy of them, and its project manager can change the project's own copy. Changing the defaults later doesn't change existing projects.

### Packages

Each project's **Packages** tab shows all its packages as a tree that can be folded and searched, and filtered by status, category, risk level (amber or red, amber only, red only) and what needs attention (overdue, due within 14 days, missing owner or buyer, no need date, flagged by hand). The filters are kept in the page address, such as `/acme/projects/9/packages?risk=red`, so a filtered list can be bookmarked, shared and reloaded. Changing a filter updates the address without asking the server and without adding a Back step.

- **Quick adding:** type a title, pick a category and press Enter. The package gets the next number straight away, and the form stays open for the next one. The other details (status, purchase type, quantity, owner, buyer, description, notes) are filled in later on the package's edit page.
- **Sub-packages** can be divided again without limit. A sub-package gets its parent's category unless another one, or none, is chosen.
- A top-level package needs a category when category codes are part of the project's numbers.
- Packages are never deleted, so their numbers stay unique; set the status to **Cancelled** instead. A category that packages use can't be removed.
- The list opens in two steps. The header, the add form and the filters arrive at once; the whole tree follows in a second request straight after (an Inertia _deferred prop_), with grey placeholder rows holding its place so nothing jumps. Once loaded, folding, searching and filtering need no server round trip, and the query count doesn't grow with the number of packages. Adding a package from the list reloads only the tree, so the table and an open add form stay on screen.
- The browser draws only the rows on screen and a few around them, so a list of thousands of packages opens and scrolls quickly. The browser's own find (Ctrl+F) therefore finds only drawn rows; the list's search box finds every package.

For trying lists with realistic amounts of data, `./vendor/bin/sail artisan db:seed --class=DemoProjectSeeder` adds a made-up project with about 3,000 packages, most with milestone dates so some are late and some have time to spare (it takes a couple of minutes, and refuses to run in production).

### Milestones and lead times

**Milestone list.** Each project has its own list of milestones: the steps every package goes through. New projects start with Specification ready, Request for quotation sent, Offers received, Order placed, Delivered from supplier, Required on site and Installed / commissioned. Admins and the project's managers change the list on the project's **Milestones** tab: add (at the end, at most 30), rename (names are unique in the project, ignoring case), move up or down, and remove.

- One milestone is the **need date**: when the project needs the package ("Required on site" at the start). To change it, make another milestone the need date; the need date itself can't be removed.
- Removing a milestone also removes every package's dates and lead time for it. The tab shows how many packages have dates for each milestone, and the confirmation says how many will lose them.
- A lead time is the number of days from the previous milestone, so moving a milestone changes what existing lead times mean.

**A package's dates.** For every milestone a package has a planned, forecast and actual date and a lead time in calendar days. **Edit dates** on the package page changes them.

- Anyone who may edit the package can change forecasts, actual dates and lead times, and can fill in an empty planned date. Once a planned date is set, only a project manager or admin can change or clear it, so the original plan stays visible.
- Actual dates can't be in the future. "Today" is today where the date is furthest ahead (UTC+14), because the app doesn't know each person's time zone.
- Dates must be between the years 2000 and 2100, and lead times between 0 and 3,650 days.

**What the app works out:**

- **Expected date** of each milestone: the actual date if it has happened, else the forecast, else the previous milestone's expected date plus the lead time, else the planned date. A milestone that hasn't happened is never expected before today: once its date has passed, it moves forward with each day until someone marks it done, so the float shrinks daily. So a late order pushes every later milestone back.
- **Due date:** the forecast, else the planned date: the date someone committed to. A milestone is overdue once its due date has passed without an actual date, and due soon, the date reminders and your next actions count from it too. The expected date is only an estimate, so a package ahead of plan is never overdue, and a milestone with only a lead time is never due. To move the date a milestone is due, change its forecast.
- **Latest date:** counting back from the need date with the lead times, the last day each earlier milestone can happen without making the package late. A missing lead time leaves the earlier latest dates unknown instead of guessing zero days.
- **Float:** the days between the expected need date and the need date. "12 days to spare", or "5 days late" when it is below zero.
- **Sub-packages:** a package isn't ready until its sub-packages are, so a parent also shows the latest expected date among itself and all its sub-packages, and the float that gives. Closed and cancelled sub-packages, and everything below them, are left out.

**Timeline.** Above the dates table, the package page draws its milestones on one line: a hollow ring for the planned date, a dot for the expected date and a green check for an actual date. A curve joins planned and expected when a milestone is later than planned, coloured like the package's risk (grey once the milestone is done). A dashed line marks today (UTC, as the risk rules count) and a blue line the need date. Hovering a milestone shows its dates, and screen readers hear every milestone with its dates. An overdue milestone's dot is red and sits on today's line, and hovering or a screen reader names the date it was due. A package without dates shows no timeline.

The package list shows each package's **next milestone** (the first one without an actual date) with its expected date, the need date, the expected need date including sub-packages, and the float ("−5 d" in red when late). The rules live in one place only, `app/Modules/Ppr/Scheduling/PackageSchedule.php`; pages and lists only show its results.

### Risk levels

Each package is **red**, **amber** or not at risk. The level is worked out every time a page asks, never stored, so it always matches the dates. It is the worst of these reasons:

| Reason                                                                                        | Level |
| --------------------------------------------------------------------------------------------- | ----- |
| Flagged as high risk by hand, with a reason                                                   | Red   |
| Overdue: a milestone without an actual date has passed its due date (UTC)                     | Red   |
| Float (counting sub-packages, as the list shows it) below the project's red warning           | Red   |
| Float below the project's amber warning                                                       | Amber |
| No planned need date, so the float can't be judged                                            | Amber |
| No package owner or responsible buyer, or theirs is no longer an active member of the company | Amber |

- "Below" means the number itself is still fine: with amber at 7 days, 7 days to spare is not at risk and 6 is amber. A package with only a planned need date is expected exactly then, so it has no days to spare and is amber under the company defaults (amber below 7 days, red below 0).
- Closed and cancelled packages are never at risk, and neither is any package below one, at any depth: it counts as finished, gets no date reminders and keeps its own status. Its page explains why.
- Only the float rolls up from sub-packages. A sub-package's overdue milestone or missing buyer makes the sub-package amber or red, not its parent.
- Anyone who may edit a package can flag it as high risk on its page (reason up to 500 characters), change the reason and clear the flag. The page shows who flagged it and when.

The package list has a **Risk** column (hover or a screen reader gives the reasons) and filters for the risk level and its reasons. The rules live in one place only, `app/Modules/Ppr/Risk/PackageRisk.php`; `App\Modules\Ppr\Risk\ProjectRisks::for($project)` works out every package of a project with three queries.

### Dashboard and project overview

The **Project dashboard** is personal. It covers only the projects you are a member of, admins included; an admin who belongs to no project gets a link to the Projects page.

- **My next actions:** milestones of the open packages you own or buy that are overdue, or due within your notification period (Settings → Notifications). It is the same rule as your date reminders; with 0 days, only overdue dates show. The most overdue come first. Up to 50 are listed, with the total.
- **My projects:** one row per project with its red, amber, overdue and due-soon packages, plus missing owner or buyer, no need date and flagged.

Each project's **Overview** tab shows how many packages are open and the same seven numbers, a count per status, and two charts:

- **Open packages by category:** one bar per category, split into red, amber and no risk. The red and amber parts open those packages, except in the "No category" bar: the package list can't filter for packages without a category.
- **Milestones due per week:** milestones not done yet, by due date, for this week and the 11 after it, labelled with each week's Monday. Overdue milestones are in the Overdue number instead.

Every tile and status count above zero opens the package list filtered to exactly the packages it counts, and a browser test checks this. The open-packages total and the weekly milestone counts aren't links. The tiles mean:

| Number                 | Packages it counts                                                   |
| ---------------------- | -------------------------------------------------------------------- |
| Red, amber             | Their risk level                                                     |
| Overdue                | A milestone not done has passed its due date                         |
| Due soon               | Open, and a milestone not done is due within 14 days, today included |
| Missing owner or buyer | No owner or buyer, or one no longer active                           |
| No need date           | The need-date milestone has no planned date                          |
| Flagged                | Flagged as high risk by hand                                         |

The numbers are worked out live on each visit by `app/Modules/Ppr/Dashboard/ProjectAttention.php`, the only place they are defined, and arrive just after the page with grey placeholders meanwhile. It takes a fixed number of queries per project, however many packages it has: about 0.2–0.3 s for a project of 2,600 packages. The list filter each number opens is in `resources/js/lib/ppr/packageFilters.ts`.

### Package numbers

Each project has its own numbering, set by its project manager on the project's **Numbering** page:

- A number is built from up to four parts, in this order: an optional **prefix**, the **project code**, the **category code** and a **running number**. The prefix, project code and category code can each be left out.
- The parts are joined with one **separator** (hyphen, dot, slash, underscore or none). The running number is padded with zeros to the chosen number of digits and starts at the chosen first number.
- Sub-packages add their own number after a separator, which can't be "none": `P100-ELE-0007.01`, and its own sub-package `P100-ELE-0007.01.01`. A sub-package's number always follows its parent's, whatever its own category.
- The running number counts across the whole project, not per category, so every number is unique.
- Numbers are never reused. Changing the numbering, a code or a package's category only affects packages added afterwards; existing numbers never change. Lowering the first number doesn't reuse numbers already given.
- Each package also stores its place in the tree as a list of numbers (`number_path`, for example `{7,1,1}`). The list is sorted by it, so `.100` comes after `.99`, and a unique index makes duplicate numbers impossible even when two people add packages at the same moment.
- Categories (for example `ELE Electrical`) belong to one project. Project and category codes use only letters and digits, so they can never be mistaken for a separator.

The rules live in `app/Modules/Ppr/Numbering/PackageNumberFormat.php`. The numbering page shows a live preview made by `resources/js/lib/ppr/packageNumber.ts`, and a browser test checks that both give the same result.

### Notifications

The bell at the top of every page shows how many unread notifications a person has and opens the **Notifications** page, newest first. Opening a notification goes to its package and marks it as read; **Mark as read** and **Mark all as read** do the same without leaving the page. Notifications are shown only in the app, not sent by email.

| Notification         | Who gets it                                                                                                      |
| -------------------- | ---------------------------------------------------------------------------------------------------------------- |
| You were assigned    | The person who becomes a package's owner or responsible buyer, unless they made the change themselves            |
| Date coming up       | The package's owner and buyer, when a milestone's due date is within their own notification period               |
| Date overdue         | The package's owner and buyer, the day after a milestone's due date has passed without an actual date            |
| Risk level changed   | The project's managers and the package owner, when the package's level changes either way, unless they caused it |
| Risk changes summary | Instead of the above, when one check would send a person more than 10 of them                                    |

- Each person sets on **Settings → Notifications** how many days ahead they want to hear about their packages' coming dates: 0 to 90, 7 at first, and 0 turns these reminders off. Overdue dates are still reported.
- **Risk changes** are noticed by a background check of the whole project, which runs right after every save that can change a package's risk (a package, its dates or risk flag, the risk warnings, the milestone list, a member's role, deactivating someone) and once a day for what time changes. The app remembers the level people were last told about (`packages.notified_risk_level`). The first check of a package, for example a new one, only stores its level, so nobody gets a flood of notifications.
- **Many changes at once:** when one check would send a person more than 10 risk-change notifications, they get one summary instead: how many packages in the project changed level, and how many became red, amber and not at risk. Opening it goes to the project's package list. Exactly 10 still arrive one by one.
- **Who caused it:** a save of one package (adding it, its details, dates or risk flag) leaves the person who saved out of the notifications for that package and the packages above and below it. Another package's change found by the same check, such as a date that passed overnight, is still told to them. A save that changes the whole project (risk warnings, the milestone list, a member's role, deactivating or reactivating someone) leaves them out of all of it. The daily check leaves nobody out.
- When overdue started counting from the due date, the release cleared `notified_risk_level`, so the first check after it only stored the new levels and recorded overdue dates as reminded without telling anyone. Coming dates people had already been reminded of were recorded again under their due dates, so only a coming date nobody had heard about arrives. To release such a change safely: stop the `queue` and `scheduler` containers, migrate, then start them, so no worker still running the old code makes that first check.
- **Date reminders** are sent by the daily check only, at 04:00 UTC (early morning in Finland), so nobody is reminded of a date they have just typed. "Today" is the UTC date. Finished packages (closed or cancelled, or below one), milestones with an actual date and milestones without a due date get none. Each reminder is sent once per person, package, milestone, kind and due date (`sent_reminders`); when the due date moves, the new date gets its own reminder. Dates that are already overdue when a package is first checked are recorded as reminded without telling anyone: the red risk badge already shows them.
- The daily check is `sail artisan packages:check-risks`, run by the `scheduler` container. It queues one job per project (`App\Modules\Ppr\Jobs\CheckProjectRisks`), which takes the same number of queries however many packages the project has.
- Only active people get notifications, and only about projects they can see. Someone who leaves a project no longer sees its notifications, and they don't count in the bell; admins see all of theirs.
- Notifications are private: nobody else, not even an admin, can open or mark someone's notifications.
- They are sent in the background by the `queue` container.
- **Companies:** notifications belong to the company of their project and are seen and counted only inside it. Opening or marking another company's notification answers "not found". The reminder period (Settings → Notifications) belongs to the person and is the same in every company.
- For developers: each kind is a class extending `app/Modules/Ppr/Notifications/PackageNotification.php` (about one package) or `ProjectNotification.php` (about the whole project), which describe how every notification is stored, plus one message line in `resources/js/components/ppr/notifications/notificationMessages.ts`.

## Page loading

Procurement Hyperspace is a single-page app, so a click never reloads the whole page. These cues show that the next page is on its way:

- **Loading on hover.** Links in the sidebar, the project tabs and the settings menu load their page while the pointer rests on them (Inertia _prefetch_), so a click usually shows the page at once. A page loaded this way is kept for 10 seconds, and every save (any form that posts, changes or deletes) forgets all of them, so they never show data from before the save. The server checks permissions on these requests exactly as for a click. Only pages whose opening changes nothing are loaded this way: the Security settings page (it may ask for the password again), notifications (opening one marks it read) and the company switcher (opening a company records it as the last one used) are excluded; set `prefetch: false` on a nav item for any such page.
- **Instant highlight.** The clicked menu item or tab is highlighted the moment it is clicked. If the page takes longer than a quarter of a second, a small spinner appears next to it.
- **Slow pages.** After a quarter of a second, a 3 px bar in the app's main colour runs along the top, and the current page fades to 60 %, can't be clicked or tabbed into, and is marked busy for screen readers until the new page arrives. Faster pages show none of this, so quick clicks never flicker.
- **New pages fade in** over 150 ms; search filters, page numbers and parts that load later don't replay it. With "reduce motion" turned on in the system settings there is no fading; the page still dims, but instantly.

`resources/js/composables/useNavigationState.ts` alone decides what counts as a page change and when it is slow.

## Languages

The interface is in English, built so that other languages can be added later without changing the code.

- Write every text in English and pass it through `__('Text')` in PHP (`trans_choice('One|Many', $n)` for counts), `$t('Text')` in Vue templates or `trans('Text')` in Vue scripts (from `@/lib/i18n`). The English text is the key, so PHP and Vue share one language file per language.
- Fill in changing values with placeholders, never by joining texts together: `$t('Invitation sent to :email.', { email })`. Word order differs between languages.
- A text that is set before a page runs, such as a title in a page's `defineOptions`, is a function: `title: () => trans('Users')`. It is then translated when it is shown.
- Translations are shown as plain text. Never put them in `v-html` or `{!! !!}`.
- `lang/en.json` lists every text. `TranslationsTest` fails if a text is missing from it, if it lists a text the code no longer uses, or if a Vue template contains text without `$t()`. Country and currency names come from Symfony Intl in the app's language.
- To add a language, copy `lang/en.json` to, for example, `lang/fi.json` and translate the values. The same test checks that the new file has every text and keeps every placeholder. Laravel's own messages (validation, password reset) come from separate files, created with `sail artisan lang:publish`.
- Not built yet: choosing the language per user (today the whole app uses `APP_LOCALE`), plural forms in Vue, and translating the starting lists a new company gets (contract types, rating criteria), which are saved as data its admins edit.

## How the code is organised

The app has a **Core** (sign-in, companies, users, settings, API clients and the layout) and two **modules**: suppliers (`app/Modules/Srm`, `resources/js/pages/srm`) and projects (`app/Modules/Ppr`, `resources/js/pages/ppr`). Each module keeps its code, database tables, pages and tests in its own folders and plugs itself into Core: its routes, timed jobs, its offer for the company's home page and the data it shares with every page. Core's server code doesn't use a module's code, and a test checks this. A few links remain: the sidebar lists both modules' pages, the top bar shows the projects module's notification bell, and the account settings link to its notification settings, so a module can't be switched off yet. CLAUDE.md lists the folders.

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

The tests in `tests/Browser` use a real Chrome browser to sign in as each role, click through the main features of both modules and check that viewers never receive contract or spend data and never see projects they don't belong to. Every test also fails on a JavaScript error or a blocked script.

```bash
tests/Browser/run.sh                 # run all of them (about 5 minutes)
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

- **A new company table** gets its company column, row-level security and company links in its migration with `App\Companies\CompanyIsolation` (see `2026_11_21_100100_add_companies_to_supplier_tables.php` and `2026_11_21_100200_add_companies_to_project_tables.php`), and its model uses `BelongsToCompany`. `CompanyIsolationTest` fails if a table with a `company_id` has no row-level security.
- **A new company page** goes under `/{company}/...` (the `company` middleware) and, if it takes a record, into `CrossCompanyAccessTest`. Pages outside companies (account, invitations, platform) never read company data, except a person's own counts in the menu worked out in their home company (`App\Companies\NavigationCompany`); their first path segment must be in `App\Rules\CompanySlug::RESERVED`, which `CompanySlugTest` checks. Company routes use `Company::routePattern()`, which never matches a reserved word, so `/settings/notifications` can't be taken for a company called "settings".
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
