# Plano de Ação Anual (PAA)

A web application for public institutions to **prepare, approve and carry out their Annual Action Plan** (*Plano de Ação Anual*, PAA). The plan is the centre: each unit's activities, with their indicators, the actions and months that deliver them, and the budget lines that pay for them, are approved directorate by directorate. An approved activity is never overwritten: a change is a **revision** that makes a new **version** and goes through the approval again, while the approved version stays in force. The plan is reported on month by month and quarter by quarter. There is no separate fiscal envelope to register or distribute.

It is designed around the Timor-Leste State Budget (OGE) process, but it is **not tied to any one institution**. Your ministry, secretariat or agency is configured in the app (name, organisational structure, programmes). The Ministry of Transport and Communications (MTC) appears in this repository only as an **optional sample dataset** used for demos, training and development. See [Sample data](#sample-data-mtc).

Built with Laravel 12, Livewire 3 (Volt), Tailwind CSS 3, Alpine.js and MySQL. Interface languages: English, Português, Tetun.

---

**Documentation.** The complete user manual, for every role and with a step-by-step tutorial for a whole year, is in [docs/MANUAL.md](docs/MANUAL.md). The presentation deck is `docs/Sistema-PAA-apresentacao-v5.pptx` (older versions are kept beside it).

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

The app follows the cycle of the annual action plan. It is shown, for one year at a time, at *Plan → Plan cycle* and on the dashboard.

| # | Step | Where in the app | Who |
|---|---|---|---|
| 1 | **Plan submission**: units create, edit and submit their **activities**, each with indicators, an implementation plan and **budget lines**, inside the window set for it (no dates set means open) | *PAA Activities*, window at *System → Reporting windows* | Technical officer / Director |
| 2 | **Approval**: each activity moves through the chain of its unit (prepare, certify, approve; named lists and interim approvers where set; finance confirmation of the budget where asked for) until Approved | *Approvals* | Reviewers, final approver |
| 3 | **Plan finalized**: planning ends per directorate: the head of each top-level unit finalizes the plan of their own directorate (the year ends when every directorate that planned has); only they or an administrator can reopen it, with a remark. Finalizing stops new activities only | *PAA Activities*, *Approvals* | Final approver / Admin |
| 4 | **Reporting**: units report the executed amount and indicators inside their windows; someone else verifies; directors compile the quarter | *Report execution*, *Compile the quarter*, *Approvals* | Technical officer / Director / Finance |
| 5 | **Revision**: an approved activity changes only through a revision: a reason, the whole approval chain again, a new version; the version in force stays meanwhile | *Activity → Revise* | Preparers, director, final approver |

**Printed reports follow the Ministry of Finance template.** The final plan report and the monthly/quarterly performance report both print on A4 landscape in the formal table used by the ministry: objective bands (SDG/PED), a programme band and a subprogramme band with their totals, then each activity with its indicators, baseline, target, means of verification, unit, funding source, the quarters it runs in (blue cells) and the budget by economic category. The approved plan's summaries by category, programme and unit follow the table.

**Compiling the quarter.** At *Execute > Compile the quarter* the director of a unit (or an interim, or an administrator) turns what the unit reported in the three months and in the quarter into the text that goes in the ministry's report: a draft is built from the verified reports, edited, and saved beside the unit's own text, which is never changed. The printed quarterly report uses the compiled text once it exists; the director's inbox lists the activities still to compile for the quarter that has just ended.

**Funding sources.** Where the money comes from is a property of the **budget line**: each line picks a source (State Budget, a development partner, own revenue, ...) from a short list the administration keeps at *Administration > Funding sources*; new lines take the default (State Budget), a category may be paid from two sources, and the printed report's FF column is built from the lines.

**Approval lists.** At *Approvals > Approval lists* a director (for the units they direct) or an administrator names, per unit, the people who **prepare** (submit), **certify** (review and validate) and **approve** (final approval) its activities, in order. A unit's list applies to the units below it that have none of their own. Where a step has a list, only the people on it can act (administrators are never locked out) and the activity says who it is waiting for; where it has none, the default rule stays (the director of the unit, then of the unit above, and the Minister or head of the office for the final approval). By default anyone named may act on their own. A certify or approve list of two or more people can be set to **everyone signs, in the order listed**: each person signs in turn (the activity says who is next and how many have signed, and the next person is notified), the activity moves on only after the last signature, an interim of the unit may sign in the place of the next name (the record shows who actually signed), administrators are never held up, and returning or reopening the activity starts a new round. Signatures are never deleted: each belongs to a numbered round and is kept as completed or voided (with who voided it and why), and the activity's history lists every signature of every round, so who signed before a reset can always be told.

**Budget confirmation (the resource plan).** An institution can ask, in *Management > Settings*, that a finance officer confirms the budget lines of an activity before it is certified (off by default). The Finance role (`confirm_budget`) then finds the submitted activities of its unit at *Approvals > Budget review*, sees each line with its category and funding source, and confirms it with a short justification or sends it back to the unit with a reason. Until it is confirmed nobody can certify the activity; changing a budget line afterwards takes the confirmation away; the activity page says whether the budget is confirmed and by whom.

**Implementation plan, as the ministry's form has it.** Under each indicator (in *More details* of the activity form) the unit lists the numbered **actions** that carry it out, each with the **months** it takes place in; the quarters the indicator is reported in follow from those months, and the months must fall in the quarters the activity runs in. The **budget is allocated per indicator**: each budget line can say which indicator it pays for (or stay with the whole activity), and an indicator's budget is the sum of its lines. The plan shows on the activity page, to the unit on *Report execution* ("Planned for Q2") and in the printed reports. *Plan → Plan cycle → Implementation plan* prints the form per unit (A4 landscape, or as a PDF): programme and subprogramme bands, each activity with its indicators, the actions, a Jan-Dec grid grouped by quarter with the action's months shaded, the budget allocated per indicator, and the Prepared by / Approved by names taken from the unit's approval lists. By default it lists approved activities; activities not yet approved can be included.

**Final approval and restructuring.** The final approval of a plan is given by the head of the top-level unit (the directorate) for the plans of its own directorate and the units below it; the Minister sees every plan but does not approve plans. Because authority is worked out from the unit tree and the approval lists when someone acts (nothing is stored on the activity), a restructuring needs no clean-up of pending work: when a unit is moved to another parent, the heads and lists above it take over from the status where each activity stands. After a reshuffle, *Approvals → Approval lists* lets a director (for their own units) or an administrator replace one person by another in every list at once and tells when a list is left empty (the default rule then applies again); an administrator can also move an activity to another unit from its page, with a remark that is kept in the audit trail. An **interim** appointment counts for the steps the head decides (certify and approve) even where a unit's list names other people, but not for preparing, and it ends on its date or when revoked. Ending planning for a year is an administrator's, or the head's when every activity of the year is in their own directorate.

**Interim approvers.** When a director is away, the director (for their own unit) or an administrator (for any unit) appoints someone at *Approvals > Interim approvers* for a set time. For that time the person has the director's authority over the unit and the units below it: they see its activities, move them along the approval chain, verify figures, and can give the final approval where the head approves. It ends by date or when revoked, the appointment is audited, and the person is notified. Authority comes from a user's *seats* (`User::seats()`: their own unit with the Director role, plus units they stand in for), not from the role name alone.

**The reporting cycle.** Beside the figures, the unit writes a short **narrative**: for the month what was done and what got in the way, for the quarter also why targets were not met, the corrective measures and a rating (On track, Partly, Off track); it follows the same rule (the unit writes, someone else verifies). An administrator can open each month and quarter for a set time at *Administration > Reporting windows*; a period without a window is open, administrators can always report, and verifying is never blocked by a window. The **Finance Officer** role (`verify_spending`) verifies spending and nothing else. *Report execution > Performance report* opens the compiled monthly or quarterly report in the layout of the ministry's printed report (programme and subprogramme bands, activity indicators, budget, execution of the period, results and constraints; verified figures and narrative only), to print or download as a PDF (`App\Services\PerformanceReport`); each reader gets the units they may see; the PDF export is audited.

The sample data is aware of today's date: a finished year is executed to the end, the running year has its finished months verified and the current month part-way (some of it still waiting for verification), and a future year has nothing. The figures are illustrative, not taken from any execution report.

### The plan cycle

*Plan → Plan cycle* follows one fiscal year of the annual action plan: the window to submit it (an administrator sets its dates at *System → Reporting windows*; outside it only an administrator can create, edit or submit activities), how far the approval has got, whether planning has ended (and how many revisions are in progress), what is planned and what the approved versions allow, and the monthly and quarterly reporting with the window of each period and how much has been reported and verified. Nothing is done on that page: each step is done where it belongs, and the page shows where the year stands. The same steps run along the top of the dashboard.

### Roles

| Role | Typical use | Main permissions |
|---|---|---|
| **Admin** | System administrator | everything |
| **Minister** | Sees every plan and report; does not approve plans | view everything |
| **Director** | Unit head | create/edit activities, submit PAA, update indicator progress, view |
| **Planning** | Technical officer of a unit | create/edit activities, submit PAA, view |
| **Viewer** | Read-only | view PAA, budget, monitoring, reports |

Permissions are enforced on the server (route middleware and checks inside every Livewire action), not just by hiding buttons. Users only see activities of their own unit and its sub-units, unless they hold `view_all_paa` (Admin, Minister).

### Planning activities

An activity follows the structure of a Timor-Leste annual action plan: **programme** and **responsible unit**, the activity and its **objective**, **municipality or region** (`config/paa.php` lists them; edit the list for your institution), **start/end dates inside the fiscal year** and the **quarters** it runs in, whether it needs **procurement**, a **gender-responsive budgeting** marker, the **budget by economic category** (the five OGE categories) and **indicators** (output or outcome, unit, annual target). One form (*PAA Activities → New activity*, or *Edit* on a draft) captures all of it; the code (`UNIT-YY-NNN`) is generated.

- **Submitting.** A draft needs at least one budget line with an amount and one indicator before it can move on; the activity page lists what is missing. The chain is technical officer → unit director → (parent unit director) → top-level director → Minister (`ActivityWorkflow`).
- **Editing is locked** once an activity is submitted, and again when the planning cycle is closed. Only the unit's own users (and administrators) can change a draft.
- **The list** filters by status, unit, programme, location and text, groups by programme or unit, offers "Needs my action", moves several activities on at once, **exports CSV** (one amount column per category, opens in Excel) and, for a new year, **copies last year's plan** as drafts (once; duplicates are skipped).

### Who sees what

Visibility follows the unit tree. **Department users** (Director, Technical officer, Viewer) see and work on **their own unit and the units below it**, in activities and monitoring. The **dashboard** is the one ministry-wide page they can open; ministry-wide pages (plan cycle, reporting and the final plan report) are for users with *view all PAA* (**Admin** and **Minister**), and are hidden from the menu and refused on the server for everyone else. This is enforced by `ActivityPolicy` and route permissions, not just by hiding links.

### Navigation and the interface

- **One menu definition** (`config/navigation.php`) drives the sidebar, the breadcrumbs and the **Go to…** quick-jump (press `Ctrl K`, or `/`). Each user sees only the pages their role can open. To add a page, add it there, and add a `pages` entry if it is a detail screen, so its breadcrumb reads correctly.
- **Fiscal year is global.** The year picker in the top bar sets the year every page works in. The default is the current year while its plan is being carried out, otherwise the latest year with activities (never beyond next year).
- **Dashboard** (one fiscal year, filterable by unit and category): the plan-cycle tracker (submission → approval → finalized → reporting); key figures (planned, approved plan in force, paid, activities approved, delivery against targets); "Needs your attention" (what the signed-in user can act on) and "Worth a look" (units with no activities, approved activities without budget lines); charts by category, status and unit; a units table. Charts are bundled (no CDN), so they work on restricted networks.
- **Entities** (*Administration → Structure*) is the single page for units, programmes and economic categories: create, edit, delete, and see each one's approved plan and funded % for the selected year. (It replaces the former "Entities Registry"; `/admin/entities` redirects here.)
- **Sign-in screen:** two panes. The left pane says who and what: the institution (name and optional logo from *Settings*), the product tagline and description, the five-step annual cycle, and an "Implemented by" credit (it shows `public/logo.png` if the file exists). The right pane is the task: email, password with show/hide and a Caps Lock warning, remember-me, forgot-password, and a note to contact the administrator. It is institution-neutral and takes everything it displays from *System → Settings*.
- **Review pages.** *Activities to decide* (Admin, directors, named approvers) works through submitted activities in queues (To review → Ready for approval → Approved, plus Returned) with the amounts at stake in each. Both follow the fiscal year chosen in the top bar.
- **One visual language.** All screens use the same design system (sentence-case headings, indigo primary actions, the shared card, table, badge and KPI components); the legacy "black button" style is gone.
- Keyboard and screen-reader support: skip links, labelled landmarks, the current page marked in the menu and breadcrumb, visible focus.

**The menu follows the annual cycle:** *Plan* (cycle, activities), *Approvals*, *Execute* (executed amount and progress), *Insights* (performance, reports) and *Administration*. **Approvals → Waiting for you** is a plain server-rendered page (`App\Services\ApprovalInbox`) with only decisions and checks: activities to decide (each line opens its own queue), execution figures to verify (never your own), and the directorates ready to be finalized as one line. Drafts and reporting due are in *My work*. The dashboard's "Needs your attention" is the same list.

### Audit trail

Every create, update and delete on the core records (activities and their versions, line items, signatures, indicators, users, units, programmes, settings) is written to the audit log with the old and new values, the user and the IP address. Admins browse it at *System → Audit Log*. PDF exports of the final plan report and the performance report are logged too. Passwords are never recorded.

Bulk query-builder writes do not fire model events and are therefore not audited; use model saves for audited changes.

### Where things are

| | |
|---|---|
| Pages (Livewire Volt) | `resources/views/livewire/pages/…` |
| Business logic | `app/Services/` (`ActivityVersions`, `PlanCycle`, `ApprovedPlanReport`, `ActivityCsvImporter`, `SampleRegistry`) |
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
php artisan queue:listen      # optional: nothing in the application is queued today; see "Queue worker" below
```

`composer dev` starts the web server, queue worker, log viewer and Vite together.

Sign in with the administrator printed by the seeder. Without `ADMIN_PASSWORD` in `.env`, development installs use the password `password` (the seeder warns you). Set `ADMIN_NAME`, `ADMIN_EMAIL` and `ADMIN_PASSWORD` in `.env` before seeding to choose your own.

To explore with realistic data instead of an empty system, an administrator can load the [sample dataset](#sample-data-mtc) from **System → Sample Data** (and purge it again later).

---

## Setting it up for your institution

A fresh install contains no organisation, programmes or activities. If you tried the sample dataset first, **purge it** (*System → Sample Data*). Then do this once:

1. **Identity.** *System → Settings*: organisation name and abbreviation (per language), tagline and branding. These appear in the sidebar, login screen and printed documents.
2. **Organisational structure.** *Administration → Structure → Directorates*: create your general directorates, national directorates, departments, units and independent bodies, with their parent–child relationships and the year they were established. The `code` of each is used in reports and for CSV import.
3. **Programmes.** *Administration → Structure → Programs* (everyone can then see them under *Plan → Programmes*): the programmes activities are planned under. Use the **official codes** your Ministry of Finance prescribes.
4. **Economic categories.** The five Timor-Leste OGE categories are seeded: 10 Salaries & Wages, 20 Goods & Services, 30 Public Transfers, 40 Minor Capital, 50 Development Capital. If your jurisdiction differs, edit them in *Administration → Structure → Classifications* **before** entering budget lines.
5. **Users.** *Administration → System Users*: create accounts, assign a role and a directorate. Users see their own unit's data. There is no public sign-up: the sign-in page tells people to contact the administrator.
6. **Activities.** Technical officers enter them in the app, or an administrator **imports** them in bulk:
   *System → Import Activities* accepts a CSV (`;`, `,` or tab separated; UTF-8 or Windows-1252). Download the template from that page. Rows sharing a `ref` form one activity; each row can carry an indicator, a budget line, or both. The file is **validated first, with row numbers for every problem**, and nothing is written until it is clean. Everything imports as `Draft`, and re-importing the same file is rejected as a duplicate.
7. **Fiscal year.** Planning for a new year opens by itself once the years before it are finalized; the dates of each step are set in *System → Settings → Calendar*.
8. **Optional rules.** *System → Optional rules*: rules that are off until your institution needs them (nil returns, extra mandatory fields, planning guidance, revisions needing an outside approval, baselines, institution-wide finance officers, corrective actions, reports by the structure at approval). See the manual, Part 4.9.

---

## Sample data (MTC)

The repository ships an optional sample dataset based on the **Ministério dos Transportes e Comunicações (MTC)** of Timor-Leste. It exists so you can see the application with a realistic organisation, or train people, without typing anything. **The application does not depend on it**: a normal install never loads it, and it can be removed again with one click.

### Load and purge it from the app (recommended)

Sign in as an administrator and open **System → Sample Data**.

- **What to load**: *One directorate or office* (the default is the **Gabinete de Digitalização e Segurança Cibernética** with its units UDIG and USC; any other gabinete, direção-geral or autonomous body can be chosen) or *The whole ministry*. They are alternatives: load one, purge it, then load the other. An office gets only its own units, programmes, demo accounts (a head of office, plus a Technical officer and a Director per unit) and the activities the State Budget plans for it, and the app is branded as that office. The budget books give the Gabinete only one activity per unit, so it also gets an **illustrative** plan (`data/gdsc_illustrative.json`, not from any published document) with 2027 activities at every step of the approval workflow. Command line: `php artisan db:seed --class="Database\Seeders\Samples\MtcOfficeSampleDataSeeder"` (GDSC).
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
| `MtcDemoUsersSeeder` | `ministro@gdsc.tl` (Minister), plus `tecnico.<code>@gdsc.tl` and `director.<code>@gdsc.tl` for each ministry unit (for example `tecnico.dntt@gdsc.tl`). Password for all: `password`. |
| `MtcBudgetSeeder` | The **real annual plans of 2025, 2026 and 2027**: about 270 activities with their official codes, programme and sub-programme, owning division, indicator (starting point and target as printed) and the amount in each economic category; every approved activity is **version 1** of the plan, with its amounts in force. |

### The sample budget data is real

The activities and amounts are those printed in the State Budget books (*Livro Orçamental*, Volume II, "Planos Anuais dos Serviços e Entidades do Setor Público Administrativo", Anexo K), read from the published PDFs and checked against the budget tables: every programme and category total matches. Sources and method are in `database/seeders/Samples/data/README.md`.

| Year | Status | Ministry (entity 025) | Package with the autonomous bodies |
|---|---|---|---|
| 2025 | Lei n.º 8/2024, de 26 de novembro. Year over | 13 353 528 USD | 22 339 646 USD |
| 2026 | Lei n.º 8/2025, de 27 de novembro, **amended** by Lei n.º 8/2026, de 8 de junho.  | 12 800 471 → **12 674 262** USD | 21 372 171 plan |
| 2027 | **Proposta de Lei** delivered to Parliament on 1 October 2026 and **still being debated**: the plan is approved internally, but the budget is not decided  | 14 620 942 USD | 24 427 884 USD |

Things to know when reading the data:

- **The ministry's package.** The OGE lists APORTIL, TIC Timor, AACTL and ANC as entities with a budget of their own. Here they are part of one MTC package, as the ministry plans with them. ANATL, a public enterprise, has no appropriation in the OGE and therefore no activities.
- **The 2026 amendment** cut the ministry's *Bens e Serviços* by 126 209 USD. The law does not say which activities, so the sample keeps the plan as the books print it (21 372 171); in the application, such a change would be made by revising the activities concerned.
- **Activities printed without an amount** in the plan (for example four construction activities in 2026) are not loaded.
- **What the plans do not say** is not invented: every activity is an annual one (Q1–Q4) with the default priority.
- The 2025 and 2026 figures are **plans and appropriations, not execution**: no executed amount or progress is loaded.
- **Not for a real MTC system.** It is a dataset to demonstrate and train with; the codes of units that only exist as budget divisions are working abbreviations.

### How purging stays safe

Every record the sample creates is written to a registry table (`sample_records`), and purge deletes only registered records. In addition:

- **Real data is never modified or claimed.** If a record with the same code already exists (for example your own unit `DNTT`), loading leaves it untouched, reports it as skipped, and purge will never delete it.
- **Real data is never cascaded away.** A sample unit or programme that real data has since attached to (a real activity, a real user, a real child unit) is **kept** and becomes an ordinary record; the page lists what was kept. A kept unit also keeps its parents.
- **Administrators are never deleted**, even if one was registered by mistake.
- **Branding is reset only if you did not change it.** If you edited the organisation name after loading, your version stays.
- The purge runs in one transaction: it either completes or changes nothing. It is written to the audit log.

If units, programmes or accounts of the sample were loaded **before** tracking existed (an older database), register them once with `php artisan paa:adopt-samples`. It adopts only records identical to the sample (same code and same name or description); anything you changed is treated as real and left alone. The budget data is not adopted: purge and load again instead. It prints what it registered. Demo accounts whose name no longer matches (for example a `ministro@gdsc.tl` created long ago under another name) are not adopted; delete or rename them by hand.

### Using a different institution as the sample

Copy `database/seeders/Samples/` to a new folder, replace the structure and programmes in the `Mtc*Seeder` files, and write a seeder that calls them like `MtcSampleDataSeeder` does. Create records through `App\Services\SampleRegistry::upsert()` and they become purgeable automatically.

---

## Deployment (production)

### Server requirements

PHP 8.3+ with the extensions above, MySQL 5.7+ (8.0 recommended) using `utf8mb4` and the `mysql` command-line client (a new database is built from the schema baseline with it; see Development), a web server (Nginx or Apache) serving **`public/`** as the document root, HTTPS, and, only if you later add queued work, a process manager (systemd or Supervisor) for a queue worker. Redis is optional and recommended for large deployments.

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
6. Add a second service from the same repository for the queue worker. Give it the same database and application variables, use the same build command, and set its **Start Command** to `php artisan queue:work --sleep=3 --tries=3 --max-time=3600`. This worker is optional today: notifications are written to the database straight away and do not wait for it.

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

### Docker (recommended for production)

`compose.prod.yml` runs the whole stack: nginx, PHP-FPM, a queue worker, the scheduler, MySQL and Redis, with optional HTTPS through Caddy. For **CapRover** (one container per app) the repository has a `captain-definition` and a single-container image. Both are described step by step in the **[deployment manual](docs/DEPLOYMENT-MANUAL.md)** (also as `DEPLOYMENT-MANUAL.pdf` and `.docx`): choosing, installing, e-mail, backups and restore, updating, operating, security and troubleshooting.

### Notifications: in the application, by e-mail, and live

Notifications (approval requests, returned activities, calendar changes) are written to the database synchronously, in the same request, and appear in the bell. The bell checks for new ones every 20 seconds while the page is open and shows a short message when one arrives (no websocket server is needed). An administrator can also switch on **Management > Settings > Send notifications by e-mail**: every notification is then also mailed to the people who have not opted out in their profile. The mail is **queued**, so a slow mail server never holds up an action, and it needs a queue worker and the `MAIL_*` settings. With e-mail off, no worker is needed. Run a worker with (for example e-mail); then keep it running with a program such as this Supervisor example:

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
- Strong `ADMIN_PASSWORD`; **purge the sample data** (*System → Sample Data* or `php artisan paa:purge-samples`) so no demo accounts (`@gdsc.tl`, password `password`) remain. A red banner warns administrators while any are present.
- A dedicated MySQL user with rights on the app database only.
- `.env` not readable by the web server's public directory; never commit it.
- **Self-registration stays off** (`ALLOW_REGISTRATION=false`, the default): the `/register` route does not exist, and administrators create accounts in *Administration → System Users*. Sign-in is rate-limited (5 attempts, then a lockout).
- **No default passwords.** When an administrator creates a user or resets a password, the app generates a random temporary password, shows it **once** (it is never stored in clear text), and forces the user to choose their own at first sign-in; until then every page redirects to their profile. In production, passwords must be at least 12 characters with upper and lower case, a number and a symbol (`AppServiceProvider`).
- **Two-factor authentication (TOTP).** Anyone can turn it on at *Profile → Two-factor authentication*: scan the QR code with an authenticator app (Google Authenticator, Microsoft Authenticator, Authy…), confirm with a code, and save the **eight one-time recovery codes** (shown once). At sign-in, users with 2FA enter a 6-digit code (or a recovery code) after their password. The secret is stored encrypted and recovery codes hashed; a code cannot be reused; five wrong codes lock the challenge for a few minutes; enrolment, use of a recovery code and disabling are audited. **In production, `Admin` and `Minister` must set it up** before they can use the app (`TWO_FACTOR_REQUIRED_ROLES=Admin,Minister` in `.env`; list other roles, or leave it empty to make it optional; it is not enforced in local/testing). A required role cannot switch it off. If someone loses their device and recovery codes, an administrator resets it in *Administration → System Users* (the audit log records who did it); the user then enrols again. Turning it off needs the account password.
- **Browser protections.** Every response carries `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy` and `Cross-Origin-Opener-Policy`; HSTS is added over HTTPS in production; signed-in pages are sent `no-store`, so the Back button after signing out shows nothing. A Content-Security-Policy is not set yet: Alpine.js and Livewire need relaxed script rules, so it must be designed and tested against the whole UI first.
- **Sessions** expire after `SESSION_LIFETIME` minutes of inactivity (60 in `.env.example`); use `SESSION_SECURE_COOKIE=true` behind HTTPS.
- Keep dependencies patched: `composer audit` should report nothing.
- Review *System → Audit Log* regularly.

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
- **Copy the files off the server**: set `BACKUP_COPY_PATH` to another disk or a mounted network share and every verified backup is copied there too (and pruned the same way); otherwise copy them by other means. A backup on the same disk is not a backup. Restore with `gunzip -c file.sql.gz | mysql -u<user> -p paa_orcamento`, and test a restore periodically.

### Health check

- `GET /up` returns 200 when the application boots (Laravel's own check).
- `GET /health` returns JSON with the status of the **database, cache, storage, queue, disk space and latest backup**, and no secrets. HTTP 200 with `"status": "ok"`; `"degraded"` when something needs attention (failed or stuck queue jobs, under 10% free disk, no backup within `BACKUP_MAX_AGE_HOURS`); HTTP **503** with `"down"` when the database or storage fails. Point your uptime monitor at it and alert on anything but `ok`.

### Common tasks

| Task | Command |
|---|---|
| Add an administrator | `php artisan tinker` → create the user and `assignRole('Admin')` |
| Clear caches after a config change | `php artisan optimize:clear` |
| Remove all sample data | `php artisan paa:purge-samples` (or *System → Sample Data*) |
| Register old sample data so it can be purged | `php artisan paa:adopt-samples` |
| Watch logs | `php artisan pail` or `storage/logs/laravel.log` |

---

## Development

```bash
composer test:fast                    # the quick tests only (about 170): a few minutes; use this while working
php artisan test                      # the whole suite: about 460 end-to-end tests, 15 to 30 minutes (GitHub runs it on every push)
php artisan test tests/Feature/ApprovalRulesTest.php   # one file: seconds; use this while working
php artisan test --filter=SegregationOfDuties           # tests by name
composer lint                         # code style check (Laravel Pint); `composer lint:fix` repairs it
composer check                        # style + tests, what CI runs
```

The test database is wiped on each run by `RefreshDatabase`; never point `phpunit.xml` at the real database. GitHub Actions (`.github/workflows/ci.yml`) runs style, tests (on MySQL) and `composer audit` for every push and pull request. Tests cover versions and revisions, plan cycle and windows, final plan report, CSV import, audit trail, seeding, the sample-data load/purge safety rules, navigation, the dashboard, and the platform layer (security headers, password policy, temporary passwords, health endpoint, backups), two-factor authentication and the review pages. The suite (about 460 tests) passes in full.

**Why the suite is slow, and what helps.** Most tests load the sample office dataset and drive real pages, so each takes about two seconds, and the database is shared, so running them in parallel does not help. The 48 test classes that load the sample office dataset are tagged `#[Group('office')]`; `composer test:fast` leaves them out. While working, run `composer test:fast` and the files you touched; the complete suite runs on GitHub for every push, so you need not wait for it locally. The migrations up to 2026-11-12 were squashed into one baseline, `database/schema/mysql-schema.sql`, taken while the application was still in development; a new database loads it with the `mysql` client in seconds instead of replaying ninety migrations, and any migration added after it runs on top. The starting rows that old migrations used to insert (the three funding sources) now come from `2026_11_21_000000_add_the_default_funding_sources`, so a schema dump must always be paired with a migration or seeder for the data it cannot carry. Existing databases keep their `migrations` table and are unaffected. To refresh the baseline later, build a scratch database with `php artisan migrate`, run `DB_DATABASE=<scratch> php artisan schema:dump`, and compare it with the old one.

Conventions: business rules live in `app/Services`, not in Livewire components; every state-changing Livewire action checks permissions on the server; new user-facing strings go through `__()` and into all three `lang/*.json` files.

---

## Known limitations

- **The executed amount is reported, not booked.** Units report the amount each budget line executed in the month (one figure, as in the earlier Django PAA), and someone else verifies it; the application does not connect to the Ministry of Finance's financial system. A test fails the build if any view contains random or simulated figures.
- **No link to the Ministry of Finance's budget system.** Programme and activity codes are entered by hand.
- **No dark mode.**
- The Tetun translations were written without a native review and should be checked before official use.
- Major framework upgrades (Laravel 13, Livewire 4) are available and have not been applied.

## Versions and revisions

Every approval of an activity makes a version (table `activity_versions`: a JSON copy of the activity, its indicators with their actions and its budget lines, the total, the reason, who and when). The amounts of the latest version are what `adjusted_budgets` holds per budget line, and spending is reported against them. `App\Services\ActivityVersions` records approvals and compares a revision with the version in force; `ActivityWorkflow::reopen` starts a revision (a reason is required, the activity goes back to Draft and through the whole chain, signatures of the round in progress are voided and kept). A budget line with spending reported, an indicator with progress reported, and an activity approved once cannot be deleted. The tables of the former fiscal envelope are left untouched and unused.
