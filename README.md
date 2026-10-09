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

### Navigation and the interface

- **One menu definition** (`config/navigation.php`) drives the sidebar, the breadcrumbs and the **Go to…** quick-jump (press `Ctrl K`, or `/`). Each user sees only the pages their role can open. To add a page, add it there, and add a `pages` entry if it is a detail screen, so its breadcrumb reads correctly.
- **Fiscal year is global.** The year picker in the top bar sets the year every page works in. Between budget cycles the default is the year being planned (the latest year with an envelope or activities, never beyond next year), not an empty calendar year.
- **Dashboard** (one fiscal year, filterable by unit and category): the budget-cycle tracker (ceiling → CRO → budget law → envelope → distributed); key figures (envelope, approved plan, distributed, activities approved, delivery against targets); "Needs your attention" (what the signed-in user can act on) and "Worth a look" (plan over the CRO decision, undistributed envelope, units with no activities, approved activities without budget lines); charts by category, status and unit; a units table. Charts are bundled (no CDN), so they work on restricted networks.
- **Budget Allocation** shows how the envelope was shared: approved plan, distributed and funded % by unit (with sub-units), economic category and programme.
- **Entities** (*Administration → Entities*) is the single page for units, programmes and economic categories: create, edit, delete, and see each one's approved plan and funded % for the selected year. (It replaces the former "Entities Registry"; `/admin/entities` redirects here.)
- **Sign-in screen:** two panes. The left pane says who and what: the institution (name and optional logo from *Settings*), the product tagline and description, the five-step annual cycle, and an "Implemented by" credit (it shows `public/logo.png` if the file exists). The right pane is the task: email, password with show/hide and a Caps Lock warning, remember-me, forgot-password, and a note to contact the administrator. It is institution-neutral and takes everything it displays from *Management → Settings*.
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

**Requirements:** PHP 8.2+ with `pdo_mysql`, `mbstring`, `intl`, `gd`, `zip`, `fileinfo`; Composer 2; Node 20+; MySQL 5.7+ (8.0 recommended). [Laragon](https://laragon.org) on Windows has all of it.

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
| `MtcDirectorateSeeder` | The 28 MTC entities from Decreto-Lei 75/2023 as amended by Decreto-Lei 12/2026: 3 general directorates (Transportes, Comunicações, Administração e Finanças), their 10 national directorates, 5 gabinetes, 5 units, and the 5 indirect-administration bodies. The law defines the structure only down to national directorates and units, so **no departments are invented**. |
| `MtcProgramSeeder` | 7 working programmes with codes `MTC-001` … `MTC-007`. **These are placeholders, not official OGE codes**, and are prefixed so they can never collide with a real programme code. |
| `MtcBrandingSeeder` | Sets the organisation name and abbreviation to MTC. |
| `MtcDemoUsersSeeder` | `ministro@goku.tl` (Minister), plus `planner.<code>@goku.tl` and `director.<code>@goku.tl` for each unit (for example `planner.dntt@goku.tl`). Password for all: `password`. |
| `MtcSampleActivitiesSeeder` | 19 illustrative activities for **2027**, with indicators and budget lines, status `Draft`. |

### The sample activities are not real plans

Their descriptions start with **`[EXEMPLO]`** and their codes with **`SAMPLE-`**. Each is loosely based on the mandate the organic law gives its unit, but **every amount, target and date is invented**. Never present them as MTC's actual plan.

### How purging stays safe

Every record the sample creates is written to a registry table (`sample_records`), and purge deletes only registered records. In addition:

- **Real data is never modified or claimed.** If a record with the same code already exists (for example your own unit `DNTT`), loading leaves it untouched, reports it as skipped, and purge will never delete it.
- **Real data is never cascaded away.** A sample unit or programme that real data has since attached to (a real activity, a real user, a real child unit) is **kept** and becomes an ordinary record; the page lists what was kept. A kept unit also keeps its parents.
- **Administrators are never deleted**, even if one was registered by mistake.
- **Branding is reset only if you did not change it.** If you edited the organisation name after loading, your version stays.
- The purge runs in one transaction: it either completes or changes nothing. It is written to the audit log.

If the sample was loaded **before** tracking existed (an older database), register it once with `php artisan paa:adopt-samples`. It adopts only records identical to the sample (same code and same name or description); anything you changed is treated as real and left alone. It prints what it registered. Demo accounts whose name no longer matches (for example a `ministro@goku.tl` created long ago under another name) are not adopted; delete or rename them by hand.

### Using a different institution as the sample

Copy `database/seeders/Samples/` to a new folder, replace the structure and programmes in the `Mtc*Seeder` files, and write a seeder that calls them like `MtcSampleDataSeeder` does. Create records through `App\Services\SampleRegistry::upsert()` and they become purgeable automatically.

---

## Deployment (production)

### Server requirements

PHP 8.2+ with the extensions above, MySQL 5.7+ (8.0 recommended) using `utf8mb4`, a web server (Nginx or Apache) serving **`public/`** as the document root, HTTPS, and a process manager (systemd or Supervisor) for the queue worker. Redis is optional and recommended for large deployments.

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

The test database is wiped on each run by `RefreshDatabase`; never point `phpunit.xml` at the real database. GitHub Actions (`.github/workflows/ci.yml`) runs style, tests (on MySQL) and `composer audit` for every push and pull request. Tests cover the distribution rules, budget cycle, CRO report, CSV import, audit trail, seeding, the sample-data load/purge safety rules, navigation, the dashboard, and the platform layer (security headers, password policy, temporary passwords, health endpoint, backups). The suite currently has **4 pre-existing failures** (a missing `profile` route in the authentication tests and the default `ExampleTest`); the rest pass.

Conventions: business rules live in `app/Services`, not in Livewire components; every state-changing Livewire action checks permissions on the server; new user-facing strings go through `__()` and into all three `lang/*.json` files.

---

## Known limitations

- **No expenditure tracking.** The app plans and distributes money; it does not record commitments or payments. Screens therefore never show "spent", "used" or "remaining" amounts, and a test fails the build if any view contains random or simulated figures.
- **No link to the Ministry of Finance's budget system.** Programme and activity codes are entered by hand.
- **CRO decisions are recorded at ministry-total level**, not per activity.
- **No dark mode.**
- The Tetun translations were written without a native review and should be checked before official use.
- Major framework upgrades (Laravel 13, Livewire 4) are available and have not been applied.
