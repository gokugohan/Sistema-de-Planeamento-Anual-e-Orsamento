# Annual Activity Plan & Budget System (PAA)

A web application for public institutions to **plan their annual activities, build the budget proposal behind them, follow it through the State Budget cycle, and distribute the approved envelope** across their organisational units.

It is designed around the Timor-Leste State Budget (OGE) process, but it is **not tied to any one institution**. Your ministry, secretariat or agency is configured in the app (name, organisational structure, programmes). The Ministry of Transport and Communications (MTC) appears in this repository only as an **optional sample dataset** used for demos, training and development. See [Sample data](#sample-data-mtc).

Built with Laravel 12, Livewire 3 (Volt), Tailwind CSS 3, Alpine.js and MySQL. Interface languages: English, Português, Tetun.

---

## Contents

1. [How the application works](#how-the-application-works)
2. [Quick start (local development)](#quick-start-local-development)
3. [Setting it up for your institution](#setting-it-up-for-your-institution)
4. [Sample data (MTC)](#sample-data-mtc)
5. [Deployment (production)](#deployment-production)
6. [Operations](#operations)
7. [Development](#development)
8. [Known limitations](#known-limitations)

---

## How the application works

### The annual cycle

The app follows the order in which things happen in practice. The first three steps are done by your institution inside the app; the next ones happen outside it, and the app records their result.

| # | Step | Where in the app | Who |
|---|---|---|---|
| 1 | Units plan their **activities**, each with indicators (physical targets) and **budget lines** by economic category | *PAA Activities* | Planner / Director |
| 2 | Plans move through internal review until **Approved** | *PAA Activities*, *Planning → PAA Approval* | Reviewers, Minister |
| 3 | The approved plan is printed or exported as a PDF for the **Budget Review Committee (CRO)** | *Reporting → Approved plan for CRO (PDF)* | Admin / Minister |
| 4 | The submission to the CRO is **frozen**, then the CRO's ministry total is recorded | *Budget Planning → Budget Cycle* | Admin / Minister |
| 5 | Parliament approves the State Budget and the President promulgates it *(outside the app)* | recorded as law reference + date on the envelope | Admin |
| 6 | The ministry **registers the envelope** it received, split by economic category | *Fiscal Envelope* | Admin → Minister approves |
| 7 | The envelope is **distributed** over the approved plan | *Fiscal Envelope → Redistribute OGE* | Admin / Minister |
| 8 | Directorates report quarterly **progress** on indicators; progress is verified | *Monitoring* | Directors, reviewers |

### Activities

Each activity belongs to one organisational unit, one programme and one fiscal year. It has a priority, a quarter and planned dates, optional procurement flag, **indicators** (Output or Outcome, with unit and annual target) and **budget lines** (an economic category and an estimated amount).

Statuses: `Draft` → `Submitted` → `Reviewed` / `Validated` → `Waiting Approval` → `Approved`. A rejected activity goes back to `Draft` for correction. Approved activities of a closed year are locked.

### Fiscal envelope and distribution

The **envelope** is the amount the institution actually receives for a fiscal year, split across economic categories. It moves `Draft` → `Pending Approval` → `Approved` → `Closed`, strictly in that order, and cannot be submitted until the **State Budget law reference and promulgation date** are recorded.

Distribution rules (`app/Services/EnvelopeDistributor.php`):

- Only an **Approved** envelope can be distributed.
- Only activities with status **Approved** take part. Drafts, submissions and rejected plans receive nothing.
- Each category is shared **pro rata** to the approved requests in it.
- **Nobody receives more than they planned.** If the envelope is larger than the approved plan, the surplus is shown as *Unallocated*.
- Amounts are exact to the cent, and the whole operation is one transaction.
- The preview you confirm is computed by the same code that writes the result.

**Autonomous bodies.** Public bodies under the ministry that have their own legal personality and financial autonomy (units marked *Autonomous* in *Administration → Entities*) are part of the ministry's package: their approved plans are inside the totals sent to the CRO and are distributed from the same envelope once the ministry has received it. They are presented as a separate section, "Transfers to autonomous bodies", in the CRO report and in Budget Allocation, so the ministry's own services and the transfers can be read apart. If such a body runs its own planning system, its plan can be entered or imported here for its unit by ministry staff.

### How indicators are measured and verified

An indicator is either **cumulative** (a count: each quarter adds to the total) or a **level** (a rate such as a percentage reached: the latest quarter is where it stands; adding the quarters up would overstate it). A unit of `%` is a level by default. Delivery against targets is the average, over indicators, of standing ÷ annual target (capped at 100%), from verified figures only (`App\Services\DeliveryReport`). Verified execution figures, financial and physical, follow one rule: **someone other than the person who reported them verifies them**, the screen shows who verified and when, and only the final approver or an administrator can **reopen** a verified figure for correction, with a remark kept in the audit trail.

### When planning ends

New activities can only be planned in a year whose plan is open. Planning ends with the **final approval of the plan**: the final approver (the Minister of a ministry, the director or chefe do gabinete of a directorate or office) or an administrator **finalizes the plan** once no activity is waiting for a decision (card on *PAA Tech Review* and on the activities list). Only they can **reopen** it, and they must give a remark; both steps are in the audit trail. **Once the budget envelope is received, the plan is final for good**: planning cannot be reopened, approved activities cannot be reopened, and only the distribution of the envelope (and then its execution) changes. A year whose envelope was received without the plan ever being finalized counts as ended too. Creating an activity, copying a plan forward and importing are all refused for an ended year (`App\Services\PlanningWindow`).

### Programmes belong to a fiscal year

Programmes can differ from one year to the next, so each one is registered **for a fiscal year** (the same OGE code can exist in several years, but only once in each). At *Management > Entities > Programs* the list is the working year's. While registering a new programme, typing a code or name suggests programmes of other years that this year does not have yet; picking one fills in the form. *Copy programs from another year* registers a whole year's programmes at once, and rolling a plan forward carries each activity's programme to the new year (registering it if needed). An activity can only use a programme of its own year. The dashboard shows the plan, funding and payment of each programme and can be filtered by one.

### Budget execution

Once a plan is approved and the envelope distributed, execution is reported per **budget line and quarter** at *Monitoring > Budget Execution*: what was **committed** (compromisso) and what was **paid** (pagamento). The unit reports it (Draft, then Submitted); a different person verifies it (or returns it), and **only verified figures count**. The headline rate is paid ÷ approved budget, read against how much of the year has passed; the schedule is the approved amount spread over the quarters the activity runs in. The dashboard and *Monitoring > Performance* show it by quarter, by programme and the activities furthest behind schedule (`App\Services\ExecutionReport`). Editing an approved activity is not possible without reopening it (which leaves a reason in the approval trail).

The sample data is aware of today's date: a finished year is executed to the end, the running year has its finished quarters verified and the current quarter part-way (some of it still waiting for verification), and a future year has nothing. The figures are illustrative, not taken from any execution report.

### Budget cycle and the CRO

*Budget Planning → Budget Cycle* tracks one fiscal year through: indicative ceiling → submission to the CRO → CRO decision → budget law promulgated → envelope approved. It shows the differences between stages and warns when the approved plan is **above the CRO amount** (revise the plan before the budget goes to Parliament, otherwise the final distribution cuts every activity pro rata).

The submission is stored as a **frozen copy**. Later edits to activities never change what was sent to the CRO. The PDF carries a document reference and a content fingerprint, so a printed copy can be checked against the system.

### Roles

| Role | Typical use | Main permissions |
|---|---|---|
| **Admin** | System administrator | everything |
| **Minister** | Approves plans, envelopes and progress | approve PAA / envelope / progress, view everything |
| **Director** | Unit head | create/edit activities, submit PAA, update indicator progress, view |
| **Planning** | Unit planner | create/edit activities, submit PAA, view |
| **Viewer** | Read-only | view PAA, budget, monitoring, reports |

Permissions are enforced on the server (route middleware and checks inside every Livewire action), not just by hiding buttons. Users only see activities of their own unit and its sub-units, unless they hold `view_all_paa` (Admin, Minister).

### Planning activities

An activity follows the structure of a Timor-Leste annual action plan: **programme** and **responsible unit**, the activity and its **objective**, **municipality or region** (`config/paa.php` lists them; edit the list for your institution), **start/end dates inside the fiscal year** and the **quarters** it runs in, whether it needs **procurement**, a **gender-responsive budgeting** marker, the **budget by economic category** (the five OGE categories) and **indicators** (output or outcome, unit, annual target). One form (*PAA Activities → New activity*, or *Edit* on a draft) captures all of it; the code (`UNIT-YY-NNN`) is generated.

- **Submitting.** A draft needs at least one budget line with an amount and one indicator before it can move on; the activity page lists what is missing. The chain is planner → unit director → (parent unit director) → top-level director → Minister (`ActivityWorkflow`).
- **Editing is locked** once an activity is submitted, and again when the planning cycle is closed. Only the unit's own users (and administrators) can change a draft.
- **The list** filters by status, unit, programme, location and text, groups by programme or unit, offers "Needs my action", moves several activities on at once, **exports CSV** (one amount column per category, opens in Excel) and, for a new year, **copies last year's plan** as drafts (once; duplicates are skipped).

### Who sees what

Visibility follows the unit tree. **Department users** (Director, Planner, Viewer) see and work on **their own unit and the units below it**, in activities, monitoring and allocation review. The **dashboard** is the one ministry-wide page they can open; ministry-wide pages (fiscal envelope, budget allocation, budget cycle, reporting and the CRO report) are for users with *view all PAA* (**Admin** and **Minister**), and are hidden from the menu and refused on the server for everyone else. This is enforced by `ActivityPolicy` and route permissions, not just by hiding links.

### Navigation and the interface

- **One menu definition** (`config/navigation.php`) drives the sidebar, the breadcrumbs and the **Go to…** quick-jump (press `Ctrl K`, or `/`). Each user sees only the pages their role can open. To add a page, add it there, and add a `pages` entry if it is a detail screen, so its breadcrumb reads correctly.
- **Fiscal year is global.** The year picker in the top bar sets the year every page works in. Between budget cycles the default is the year being planned (the latest year with an envelope or activities, never beyond next year), not an empty calendar year.
- **Dashboard** (one fiscal year, filterable by unit and category): the budget-cycle tracker (ceiling → CRO → budget law → envelope → distributed); key figures (envelope, approved plan, distributed, activities approved, delivery against targets); "Needs your attention" (what the signed-in user can act on) and "Worth a look" (plan over the CRO decision, undistributed envelope, units with no activities, approved activities without budget lines); charts by category, status and unit; a units table. Charts are bundled (no CDN), so they work on restricted networks.
- **Budget Allocation** shows how the envelope was shared: approved plan, distributed and funded % by unit (with sub-units), economic category and programme.
- **Entities** (*Administration → Entities*) is the single page for units, programmes and economic categories: create, edit, delete, and see each one's approved plan and funded % for the selected year. (It replaces the former "Entities Registry"; `/admin/entities` redirects here.)
- **Sign-in screen:** two panes. The left pane says who and what: the institution (name and optional logo from *Settings*), the product tagline and description, the five-step annual cycle, and an "Implemented by" credit (it shows `public/logo.png` if the file exists). The right pane is the task: email, password with show/hide and a Caps Lock warning, remember-me, forgot-password, and a note to contact the administrator. It is institution-neutral and takes everything it displays from *Management → Settings*.
- **Review pages.** *PAA Tech Review* (Admin, Minister) works through submitted activities in queues (To review → Ready for approval → Approved, plus Returned) with the amounts at stake in each. *Allocation Review* lets a unit compare what it was allocated with what its approved plan asked for, then accept it or ask for an adjustment; directors and planners act for their own unit only, administrators and ministers can look at any unit. Both follow the fiscal year chosen in the top bar.
- **One visual language.** All screens use the same design system (sentence-case headings, indigo primary actions, the shared card, table, badge and KPI components); the legacy "black button" style is gone.
- Keyboard and screen-reader support: skip links, labelled landmarks, the current page marked in the menu and breadcrumb, visible focus.

### Audit trail

Every create, update and delete on the core records (activities, line items, budgets, envelopes, indicators, users, units, programmes, settings) is written to the audit log with the old and new values, the user and the IP address. Admins browse it at *Management → Audit Log*. PDF exports of the CRO document are logged too. Passwords are never recorded.

Bulk query-builder writes do not fire model events and are therefore not audited; use model saves for audited changes.

### Where things are

| | |
|---|---|
| Pages (Livewire Volt) | `resources/views/livewire/pages/…` |
| Business logic | `app/Services/` (`EnvelopeDistributor`, `BudgetCycleService`, `ApprovedPlanReport`, `ActivityCsvImporter`, `SampleRegistry`) |
| Models | `app/Models/` (audit via `Concerns/Auditable`) |
| Routes and permissions | `routes/web.php` |
| Seeders | `database/seeders/` (base) and `database/seeders/Samples/` (MTC sample) |
| Design tokens (TailAdmin style) | `tailwind.config.js`, `resources/css/app.css` |
| Navigation (sidebar, breadcrumbs, quick-jump) | `config/navigation.php`, `app/Support/Navigation.php` |
| Sign-in and other auth screens | `resources/views/layouts/guest.blade.php`, `resources/views/livewire/pages/auth/` |
| Translations | `lang/en.json`, `lang/pt.json`, `lang/tet.json` |

---

## Quick start (local development)

**Requirements:** PHP 8.3+ with `pdo_mysql`, `mbstring`, `intl`, `gd`, `zip`, `fileinfo`; Composer 2; Node 20+; MySQL 5.7+ (8.0 recommended). [Laragon](https://laragon.org) on Windows has all of it.

```bash
# 1. Create two empty databases (app and tests)
mysql -uroot -e "CREATE DATABASE paa_orcamento CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
                 CREATE DATABASE paa_orcamento_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 2. Install
composer install
cp .env.example .env          # then check the DB_* lines
php artisan key:generate
npm install

# 3. Create the schema and the neutral base data
php artisan migrate
php artisan db:seed           # roles, economic categories, default settings, first admin

# 4. Run
npm run build                 # or `npm run dev` for hot reload
php artisan serve
php artisan queue:listen      # notifications are queued: keep this running
```

`composer dev` starts the web server, queue worker, log viewer and Vite together.

Sign in with the administrator printed by the seeder. Without `ADMIN_PASSWORD` in `.env`, development installs use the password `password` (the seeder warns you). Set `ADMIN_NAME`, `ADMIN_EMAIL` and `ADMIN_PASSWORD` in `.env` before seeding to choose your own.

To explore with realistic data instead of an empty system, an administrator can load the [sample dataset](#sample-data-mtc) from **Management → Sample Data** (and purge it again later).

---

## Setting it up for your institution

A fresh install contains no organisation, programmes or activities. If you tried the sample dataset first, **purge it** (*Management → Sample Data*). Then do this once:

1. **Identity.** *Management → Settings*: organisation name and abbreviation (per language), tagline and branding. These appear in the sidebar, login screen and printed documents.
2. **Organisational structure.** *Management → Entities → Directorates*: create your general directorates, national directorates, departments, units and independent bodies, with their parent–child relationships and the year they were established. The `code` of each is used in reports and for CSV import.
3. **Programmes.** *Management → Entities → Programs*: the programmes activities are planned under. Use the **official codes** your Ministry of Finance prescribes.
4. **Economic categories.** The five Timor-Leste OGE categories are seeded: 10 Salaries & Wages, 20 Goods & Services, 30 Public Transfers, 40 Minor Capital, 50 Development Capital. If your jurisdiction differs, edit them in *Management → Entities → Classifications* **before** entering budget lines.
5. **Users.** *Management → System Users*: create accounts, assign a role and a directorate. Users see their own unit's data. There is no public sign-up: the sign-in page tells people to contact the administrator.
6. **Activities.** Planners enter them in the app, or an administrator **imports** them in bulk:
   *Management → Import Activities* accepts a CSV (`;`, `,` or tab separated; UTF-8 or Windows-1252). Download the template from that page. Rows sharing a `ref` form one activity; each row can carry an indicator, a budget line, or both. The file is **validated first, with row numbers for every problem**, and nothing is written until it is clean. Everything imports as `Draft`, and re-importing the same file is rejected as a duplicate.
7. **Fiscal year.** Turn on *enable next year planning* in Settings when you start planning the next year.

---

## Sample data (MTC)

The repository ships an optional sample dataset based on the **Ministério dos Transportes e Comunicações (MTC)** of Timor-Leste. It exists so you can see the application with a realistic organisation, or train people, without typing anything. **The application does not depend on it**: a normal install never loads it, and it can be removed again with one click.

### Load and purge it from the app (recommended)

Sign in as an administrator and open **Management → Sample Data**.

- **What to load**: *One directorate or office* (the default is the **Gabinete de Digitalização e Segurança Cibernética** with its units UDIG and USC; any other gabinete, direção-geral or autonomous body can be chosen) or *The whole ministry*. They are alternatives: load one, purge it, then load the other. An office gets only its own units, programmes, demo accounts (a head of office, plus a Planner and a Director per unit) and the activities the State Budget plans for it, and the app is branded as that office. The budget books give the Gabinete only one activity per unit, so it also gets an **illustrative** plan (`data/gdsc_illustrative.json`, not from any published document) with 2027 activities at every step of the approval workflow. Command line: `php artisan db:seed --class="Database\Seeders\Samples\MtcOfficeSampleDataSeeder"` (GDSC).
- **Load sample data** adds the dataset below. It is **disabled when `APP_ENV=production`**, because it creates demo accounts with a known password.
- **Purge sample data** deletes exactly what the sample created and nothing else. It works in every environment, and it is the step to run **before you go live**.

If sample data is still present in a production system, every administrator sees a red banner on every page until it is purged.

The same operations exist on the command line:

```bash
php artisan db:seed --class="Database\Seeders\Samples\MtcSampleDataSeeder"   # load (refused in production)
php artisan paa:purge-samples                                                  # purge (asks first; --force skips)
```

### What it adds

| Seeder | Adds |
|---|---|
| `MtcDirectorateSeeder` | The MTC structure from Decreto-Lei 75/2023 as amended by Decreto-Lei 12/2026 (3 general directorates, their national directorates, gabinetes and units), the 5 **indirect-administration bodies** of art. 7 (ANATL E.P., APORTIL I.P., AACTL I.P., ANC I.P., TIC Timor I.P.) flagged *Autonomous*, and the **divisions the State Budget itself prints** (the Minister's office, 11 municipal directorates, AACTL's 8 directorates, APORTIL's ferry operations). Each carries its budget division code in its description. |
| `MtcProgramSeeder` | The 6 programmes of the State Budget the ministry uses, with their **official OGE codes**: 502 Transportes, 510 Funcionamento e Desenvolvimento Institucional, 558 Serviços Postais, 980 Inclusão Social, A08 Tecnologia de Informação e Comunicação, A38 Meteorologia e Geofísica. A programme with the same code that already exists (510 is shared by every ministry) is left as it is. |
| `MtcBrandingSeeder` | Sets the organisation name and abbreviation to MTC. |
| `MtcDemoUsersSeeder` | `ministro@goku.tl` (Minister), plus `planner.<code>@goku.tl` and `director.<code>@goku.tl` for each ministry unit (for example `planner.dntt@goku.tl`). Password for all: `password`. |
| `MtcBudgetSeeder` | The **real annual plans of 2025, 2026 and 2027**: about 270 activities with their official codes, programme and sub-programme, owning division, indicator (starting point and target as printed) and the amount in each economic category; the **2025 and 2026 fiscal envelopes**, distributed over the plan; and the **2027 budget cycle**. |

### The sample budget data is real

The activities and amounts are those printed in the State Budget books (*Livro Orçamental*, Volume II, "Planos Anuais dos Serviços e Entidades do Setor Público Administrativo", Anexo K), read from the published PDFs and checked against the budget tables: every programme and category total matches. Sources and method are in `database/seeders/Samples/data/README.md`.

| Year | Status | Ministry (entity 025) | Package with the autonomous bodies |
|---|---|---|---|
| 2025 | Lei n.º 8/2024, de 26 de novembro. Year over: envelope **closed** | 13 353 528 USD | 22 339 646 USD |
| 2026 | Lei n.º 8/2025, de 27 de novembro, **amended** by Lei n.º 8/2026, de 8 de junho. Envelope approved and distributed | 12 800 471 → **12 674 262** USD | 21 372 171 plan; **21 245 962** envelope |
| 2027 | **Proposta de Lei** delivered to Parliament on 1 October 2026 and **still being debated**: the plan is approved internally and the CRO decision is recorded, but there is no budget law and **no envelope** | 14 620 942 USD | 24 427 884 USD |

Things to know when reading the data:

- **The ministry's package.** The OGE lists APORTIL, TIC Timor, AACTL and ANC as entities with a budget of their own. Here they are part of one MTC package, as the ministry plans with them and passes their share on once it has received its envelope. ANATL, a public enterprise, has no appropriation in the OGE and therefore no activities.
- **The 2026 amendment** cut the ministry's *Bens e Serviços* by 126 209 USD. The law does not say which activities, so the application's own distribution applies the cut pro rata: the 2026 plan is 21 372 171 and the envelope 21 245 962.
- **Activities printed without an amount** in the plan (for example four construction activities in 2026) are not loaded.
- **What the plans do not say** is not invented: every activity is an annual one (Q1–Q4) with the default priority.
- The 2025 and 2026 figures are **plans and appropriations, not execution**: no spending or progress is loaded.
- **Not for a real MTC system.** It is a dataset to demonstrate and train with; the codes of units that only exist as budget divisions are working abbreviations.

### How purging stays safe

Every record the sample creates is written to a registry table (`sample_records`), and purge deletes only registered records. In addition:

- **Real data is never modified or claimed.** If a record with the same code already exists (for example your own unit `DNTT`), loading leaves it untouched, reports it as skipped, and purge will never delete it.
- **Real data is never cascaded away.** A sample unit or programme that real data has since attached to (a real activity, a real user, a real child unit) is **kept** and becomes an ordinary record; the page lists what was kept. A kept unit also keeps its parents.
- **Administrators are never deleted**, even if one was registered by mistake.
- **Branding is reset only if you did not change it.** If you edited the organisation name after loading, your version stays.
- The purge runs in one transaction: it either completes or changes nothing. It is written to the audit log.

If units, programmes or accounts of the sample were loaded **before** tracking existed (an older database), register them once with `php artisan paa:adopt-samples`. It adopts only records identical to the sample (same code and same name or description); anything you changed is treated as real and left alone. The budget data is not adopted: purge and load again instead. It prints what it registered. Demo accounts whose name no longer matches (for example a `ministro@goku.tl` created long ago under another name) are not adopted; delete or rename them by hand.

### Using a different institution as the sample

Copy `database/seeders/Samples/` to a new folder, replace the structure and programmes in the `Mtc*Seeder` files, and write a seeder that calls them like `MtcSampleDataSeeder` does. Create records through `App\Services\SampleRegistry::upsert()` and they become purgeable automatically.

---

## Deployment (production)

### Server requirements

PHP 8.3+ with the extensions above, MySQL 5.7+ (8.0 recommended) using `utf8mb4`, a web server (Nginx or Apache) serving **`public/`** as the document root, HTTPS, and a process manager (systemd or Supervisor) for the queue worker. Redis is optional and recommended for large deployments.

### Deploying on Railway

1. Push this repository to GitHub. In Railway, create a project from the repository and add a **MySQL** service. Railway should detect the Laravel app from `composer.json`.
2. In the app service's **Variables**, set the following. Replace `MySQL` in the references if you named the database service differently. Generate `APP_KEY` locally with `php artisan key:generate --show` and paste the result; do not commit it.

   ```ini
   APP_ENV=production
   APP_DEBUG=false
   LOG_LEVEL=warning
   APP_KEY=<generated key>
   APP_URL=https://<your Railway domain>
   DB_CONNECTION=mysql
   DB_HOST=${{ MySQL.MYSQLHOST }}
   DB_PORT=${{ MySQL.MYSQLPORT }}
   DB_DATABASE=${{ MySQL.MYSQLDATABASE }}
   DB_USERNAME=${{ MySQL.MYSQLUSER }}
   DB_PASSWORD=${{ MySQL.MYSQLPASSWORD }}
   SESSION_SECURE_COOKIE=true
   ADMIN_NAME=System Administrator
   ADMIN_EMAIL=<your administrator email>
   ADMIN_PASSWORD=<strong unique password>
   ```

   Keep `QUEUE_CONNECTION=database`. For speed, add a Railway **Redis** service and also set `SESSION_DRIVER=redis`, `CACHE_STORE=redis`, `REDIS_CLIENT=predis`, `REDIS_HOST=${{ Redis.REDISHOST }}`, `REDIS_PORT=${{ Redis.REDISPORT }}` and `REDIS_PASSWORD=${{ Redis.REDISPASSWORD }}`; with the database drivers every request makes extra round-trips to the separate MySQL service. Deploy the services in the Railway region closest to your users. Configure SMTP variables if the app needs to send email; `MAIL_MAILER=log` does not deliver it.
3. In the app service's **Build** settings, set the build command to `composer install --no-dev --optimize-autoloader --no-interaction && npm ci && npm run build`. **Leave the web service's Start Command empty** so Railpack runs Laravel on FrankenPHP (a multi-worker production server that listens on Railway's port). Never use `php artisan serve`: it is PHP's single-request development server and makes the application feel slow. Add `&& php artisan optimize` to the build command if your Railway build does not already cache config, routes and views. Railpack installs Composer development dependencies while building, so this project requires PHP 8.3+; do not override its PHP version with 8.2. The `--no-dev` flag still keeps production-only dependencies in the final deployment.
4. Set the service's **Pre-deploy Command** to `php artisan migrate --force`. This runs schema migrations before each release. Deploy, then generate a Railway domain in the service's **Networking** settings and update `APP_URL` to that HTTPS URL.
5. In the app service's Railway shell, run `php artisan db:seed --force` once to create the base records and first administrator. Do not run this as a deploy command, and do not load the optional sample dataset in production. Sign in with the administrator credentials you set above.
6. Add a second service from the same repository for the queue worker. Give it the same database and application variables, use the same build command, and set its **Start Command** to `php artisan queue:work --sleep=3 --tries=3 --max-time=3600`. Notifications will remain queued if this worker is not running.

Railway's container filesystem is ephemeral. If you store uploaded files locally, attach a Railway Volume at the Laravel storage path or use persistent object storage; keep database backups outside the app container. Railway's managed MySQL backups are preferable to the local `paa:backup` command unless `mysqldump` and persistent backup storage have also been configured. Check `/health` after deployment.

### First deployment

```bash
git clone <repository> /var/www/paa && cd /var/www/paa
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

Edit `.env`:

```ini
APP_ENV=production
APP_DEBUG=false                 # never true in production
APP_URL=https://paa.example.org
DB_HOST=127.0.0.1
DB_DATABASE=paa_orcamento
DB_USERNAME=paa_app             # a dedicated user, not root
DB_PASSWORD=<strong password>
SESSION_SECURE_COOKIE=true
ADMIN_NAME="Your Name"
ADMIN_EMAIL=you@example.org
ADMIN_PASSWORD=<strong password>   # mandatory in production: there is no default
ALLOW_REGISTRATION=false        # accounts are created by an administrator (the default)
MAIL_MAILER=smtp                # plus MAIL_HOST / PORT / USERNAME / PASSWORD / FROM
```

Then:

```bash
php artisan migrate --force
php artisan db:seed --force     # base data + first admin only; never the sample data (it is refused in production)
php artisan storage:link
php artisan optimize            # caches config, routes, views, events
chown -R www-data:www-data storage bootstrap/cache
```

The compiled front-end (`public/build`) is committed. If you change CSS or views, rebuild with `npm ci && npm run build` and commit the result, or build in your pipeline.

### Queue worker (required)

Notifications (approval requests, returned activities) are queued. **Without a running worker they are never delivered.** Example Supervisor program:

```ini
[program:paa-queue]
command=php /var/www/paa/artisan queue:work --sleep=3 --tries=3 --max-time=3600
user=www-data
autostart=true
autorestart=true
stopwaitsecs=3600
```

Restart it on every deployment: `php artisan queue:restart`.

### Updating an existing deployment

```bash
php artisan down
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize:clear && php artisan optimize
php artisan queue:restart
php artisan up
```

**Back up the database first** (see below). Never run `migrate:fresh` on a live system, and never load sample data on one.

### Security checklist

- `APP_DEBUG=false`, `APP_ENV=production`, HTTPS only (the app forces `https` URLs in production).
- Strong `ADMIN_PASSWORD`; **purge the sample data** (*Management → Sample Data* or `php artisan paa:purge-samples`) so no demo accounts (`@goku.tl`, password `password`) remain. A red banner warns administrators while any are present.
- A dedicated MySQL user with rights on the app database only.
- `.env` not readable by the web server's public directory; never commit it.
- **Self-registration stays off** (`ALLOW_REGISTRATION=false`, the default): the `/register` route does not exist, and administrators create accounts in *Management → System Users*. Sign-in is rate-limited (5 attempts, then a lockout).
- **No default passwords.** When an administrator creates a user or resets a password, the app generates a random temporary password, shows it **once** (it is never stored in clear text), and forces the user to choose their own at first sign-in; until then every page redirects to their profile. In production, passwords must be at least 12 characters with upper and lower case, a number and a symbol (`AppServiceProvider`).
- **Two-factor authentication (TOTP).** Anyone can turn it on at *Profile → Two-factor authentication*: scan the QR code with an authenticator app (Google Authenticator, Microsoft Authenticator, Authy…), confirm with a code, and save the **eight one-time recovery codes** (shown once). At sign-in, users with 2FA enter a 6-digit code (or a recovery code) after their password. The secret is stored encrypted and recovery codes hashed; a code cannot be reused; five wrong codes lock the challenge for a few minutes; enrolment, use of a recovery code and disabling are audited. **In production, `Admin` and `Minister` must set it up** before they can use the app (`TWO_FACTOR_REQUIRED_ROLES=Admin,Minister` in `.env`; list other roles, or leave it empty to make it optional; it is not enforced in local/testing). A required role cannot switch it off. If someone loses their device and recovery codes, an administrator resets it in *Management → System Users* (the audit log records who did it); the user then enrols again. Turning it off needs the account password.
- **Browser protections.** Every response carries `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy` and `Cross-Origin-Opener-Policy`; HSTS is added over HTTPS in production; signed-in pages are sent `no-store`, so the Back button after signing out shows nothing. A Content-Security-Policy is not set yet: Alpine.js and Livewire need relaxed script rules, so it must be designed and tested against the whole UI first.
- **Sessions** expire after `SESSION_LIFETIME` minutes of inactivity (60 in `.env.example`); use `SESSION_SECURE_COOKIE=true` behind HTTPS.
- Keep dependencies patched: `composer audit` should report nothing.
- Review *Management → Audit Log* regularly.

### Scaling

The defaults (database sessions, cache and queue) work for a ministry-sized user base. For heavier use, move sessions and cache to Redis, run several queue workers, and put MySQL on its own server. Indexes on the hot paths already exist.

---

## Operations

### Scheduler (required for backups)

Add one cron entry on the server; Laravel decides what runs when:

```cron
* * * * * cd /var/www/paa && php artisan schedule:run >> /dev/null 2>&1
```

It runs `paa:backup` at 02:00 and prunes failed jobs and expired password-reset tokens daily. On Windows use Task Scheduler to run `php artisan schedule:run` every minute.

### Backups

`php artisan paa:backup` writes a gzip-compressed `mysqldump` to `storage/app/backups/paa_YYYYMMDD_HHMMSS.sql.gz`.

- The dump is streamed to a `.partial` file, **read back and checked for mysqldump's completion marker**, and only then renamed, so a failed or truncated run never looks like a good backup (and never replaces one).
- The database password is passed to `mysqldump` through the environment, not on the command line.
- Backups older than `BACKUP_KEEP_DAYS` (default 30) are deleted after each successful run (`--prune-only` just prunes). Every backup is recorded in the audit log.
- If `mysqldump` is not on `PATH`, set `BACKUP_MYSQLDUMP` to its full path.
- **Copy the files off the server** (another machine or object storage): a backup on the same disk is not a backup. Restore with `gunzip -c file.sql.gz | mysql -u<user> -p paa_orcamento`, and test a restore periodically.

### Health check

- `GET /up` returns 200 when the application boots (Laravel's own check).
- `GET /health` returns JSON with the status of the **database, cache, storage, queue, disk space and latest backup**, and no secrets. HTTP 200 with `"status": "ok"`; `"degraded"` when something needs attention (failed or stuck queue jobs, under 10% free disk, no backup within `BACKUP_MAX_AGE_HOURS`); HTTP **503** with `"down"` when the database or storage fails. Point your uptime monitor at it and alert on anything but `ok`.

### Common tasks

| Task | Command |
|---|---|
| Add an administrator | `php artisan tinker` → create the user and `assignRole('Admin')` |
| Clear caches after a config change | `php artisan optimize:clear` |
| Remove all sample data | `php artisan paa:purge-samples` (or *Management → Sample Data*) |
| Register old sample data so it can be purged | `php artisan paa:adopt-samples` |
| Watch logs | `php artisan pail` or `storage/logs/laravel.log` |

---

## Development

```bash
php artisan test                      # uses the paa_orcamento_test database
composer lint                         # code style check (Laravel Pint); `composer lint:fix` repairs it
composer check                        # style + tests, what CI runs
```

The test database is wiped on each run by `RefreshDatabase`; never point `phpunit.xml` at the real database. GitHub Actions (`.github/workflows/ci.yml`) runs style, tests (on MySQL) and `composer audit` for every push and pull request. Tests cover the distribution rules, budget cycle, CRO report, CSV import, audit trail, seeding, the sample-data load/purge safety rules, navigation, the dashboard, and the platform layer (security headers, password policy, temporary passwords, health endpoint, backups), two-factor authentication and the review pages. The suite (about 130 tests) passes in full.

Conventions: business rules live in `app/Services`, not in Livewire components; every state-changing Livewire action checks permissions on the server; new user-facing strings go through `__()` and into all three `lang/*.json` files.

---

## Known limitations

- **No expenditure tracking.** The app plans and distributes money; it does not record commitments or payments. Screens therefore never show "spent", "used" or "remaining" amounts, and a test fails the build if any view contains random or simulated figures.
- **No link to the Ministry of Finance's budget system.** Programme and activity codes are entered by hand.
- **CRO decisions are recorded at ministry-total level**, not per activity.
- **No dark mode.**
- The Tetun translations were written without a native review and should be checked before official use.
- Major framework upgrades (Laravel 13, Livewire 4) are available and have not been applied.
