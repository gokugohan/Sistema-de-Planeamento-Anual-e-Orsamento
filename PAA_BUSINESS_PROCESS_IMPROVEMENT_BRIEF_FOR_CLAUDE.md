---
date: 2026-10-17
purpose: Guide Claude in VS Code to audit and improve the existing PAA
  application safely
repository: "https://github.com/gokugohan/Sistema-de-Planeamento-Anual-e-Orsamento"
title: PAA Business Process Improvement --- Claude Code Project Brief
version: 1.0
---

# PAA Business Process Improvement --- Claude Code Project Brief

## 1. Purpose

You are assisting with the improvement of an existing Laravel
application for the **Plano de Ação Anual (PAA)** and budget planning
for public institutions in Timor-Leste.

Repository:

-   `https://github.com/gokugohan/Sistema-de-Planeamento-Anual-e-Orsamento`
-   Stack documented by the project: Laravel 12, PHP 8.3+, Livewire 3 /
    Volt, Tailwind CSS 3, Alpine.js, MySQL, Spatie Laravel Permission.
-   Main branch: `main`.

The objective is **not to rebuild the application**. Audit the existing
implementation, identify genuine business-process and technical gaps,
preserve working functionality, and propose safe incremental
improvements.

Use this document as the project brief. Work with the checked-out
repository available in VS Code. Do not assume the remote repository and
local working tree are identical: inspect the local branch, status, and
latest commit first.

## 2. Mandatory working rules

1.  **Inspect before editing.** Read relevant source files, migrations,
    tests, README and user manual before proposing code changes.
2.  **Do not rewrite existing workflows by default.** Reuse existing
    services, models, policies, pages and tests whenever possible.
3.  **Do not modify files, run destructive commands, drop tables,
    deploy, push, or commit without explicit user approval.** Begin with
    read-only inspection and documentation.
4.  **Never expose, copy, or request secrets.** Do not read `.env`
    values into reports. Do not print credentials, API keys, tokens,
    database passwords, private keys or personal data.
5.  **Do not run production migrations or commands against production.**
    Use a disposable local/test database. Before any schema change,
    assess data preservation and rollback.
6.  **Do not invent Timor-Leste legal requirements.** Mark rules as:
    -   `CONFIRMED`: supported by an applicable official source or
        explicitly confirmed by the user;
    -   `TO VALIDATE`: requires institutional/legal/finance
        confirmation;
    -   `RECOMMENDATION`: proposed process improvement, not a claim of
        legal obligation.
7.  **Distinguish the current software process from the legally required
    institutional process.** Code proves what the application
    implements, not what the law requires.
8.  **Avoid unsupported claims.** A source-code finding is a confirmed
    defect only when the code and tests establish it. Otherwise label it
    as a risk, inconsistency, or hypothesis and verify.
9.  **Protect historical records.** Approved versions, signatures, audit
    logs, verified reports and expenditure records must not be silently
    overwritten or deleted.
10. **Use small, testable increments.** Prefer a focused fix and
    regression tests over broad refactoring.
11. **Do not add dependencies or external integrations without
    explaining their purpose, cost, risks and alternatives.**
12. **Keep documentation in Markdown** unless the user asks for another
    format. Use clear, professional English. Preserve the application's
    existing Portuguese/Tetun terms where useful.

## 3. Project approach and deliverables

Work in this order. Do not skip ahead to implementation before the user
has reviewed the process and requirements.

### Step 1 --- Current-state audit (AS-IS)

Inspect the local repository and document how the software actually
works today.

Deliverable: `docs/improvement/01-AS-IS-CURRENT-STATE.md`

Include: - repository/branch/commit inspected; - application
architecture and important modules; - roles, permissions and
organisational scope; - current activity workflow and valid status
transitions; - planning-cycle windows and directorate-level
finalisation; - budget planning, budget confirmation and execution
reporting; - indicators, actions, strategic alignment, programme and
subprogramme; - activity revision/version behaviour; - monthly/quarterly
reports, verification and quarter compilation; - audit history,
notifications, imports/exports and security controls; - relevant model,
service, policy, Livewire page, route, migration and test paths; - known
limitations and questions requiring confirmation.

For every process stage, record: actor, trigger/input, action,
decision/rule, output, responsible code location, tests, and unresolved
questions.

Do not claim to have executed tests unless you actually run them and
report the result.

### Step 2 --- Institutional requirements register

Deliverable:
`docs/improvement/02-INSTITUTIONAL-REQUIREMENTS-REGISTER.md`

Create a register with: - ID; - requirement/rule; - source/evidence; -
status (`CONFIRMED`, `TO VALIDATE`, `RECOMMENDATION`); - accountable
person/office to validate; - impact if unresolved; - related process
stage and software area.

Topics to validate include: - official annual planning calendar and
deadlines; - organisational units that must submit a plan, including
nil-return declarations; - approval and delegated authority; -
applicable OGE classifications and official codes; - distinction between
PAA approval and legal budget authorisation; - official forms, reporting
templates and required indicators; - directorate-level finalisation and
institution-wide consolidation; - amendment rules after approval; -
financial reporting and any future Ministry of Finance system
integration; - document retention, audit and language requirements.

Do not infer that the Minister or any other official must approve the
consolidated plan unless a relevant rule confirms it.

### Step 3 --- Target process (TO-BE)

Deliverable: `docs/improvement/03-TARGET-BUSINESS-PROCESS.md`

Design a practical target process for: 1. Annual cycle setup and
guidance. 2. Organisational-unit submission obligations. 3. Activity
preparation and completeness checks. 4. Unit review/certification. 5.
Parent-unit validation where applicable. 6. Finance review where
configured or required. 7. Final activity approval by the authorised
person. 8. Directorate plan finalisation. 9. Institutional
consolidation/review, **only if confirmed as required by the
institution**. 10. Baseline publication or formal institutional
decision, if applicable. 11. Implementation, monthly financial reporting
and quarterly indicator reporting. 12. Independent verification and
director review. 13. Corrective action tracking. 14. Revision/amendment
control. 15. Annual evaluation and next-cycle preparation.

For every stage, specify purpose, owner, inputs, actions, decision
rules, outputs/records, exceptions, audit requirements, and software
support.

Create Mermaid diagrams when useful. Use separate diagrams for activity
approval, annual plan governance, and monitoring/reporting so that the
workflows are not conflated.

### Step 4 --- Gap analysis

Deliverable: `docs/improvement/04-GAP-ANALYSIS.md`

Compare AS-IS against TO-BE. Each gap must include: - gap ID; - process
requirement; - current behaviour and code evidence; - target
behaviour; - user/business impact; - risk; - priority (`P0`, `P1`, `P2`,
`P3`); - proposed solution; - affected code/data; - required tests; -
dependency or decision needed.

Priority definitions: - `P0`: data integrity, security, destructive
migration, incorrect approved/financial reporting, or severe workflow
integrity risk. - `P1`: essential planning, approval, versioning, or
monitoring requirements. - `P2`: institutional maturity, usability,
reporting enhancements or integration preparation. - `P3`: optional
enhancements.

### Step 5 --- Product Requirements Document (PRD)

Deliverable: `docs/improvement/05-PRD.md`

For each functional requirement, use a unique ID, e.g. `PAA-FR-001`, and
include: - requirement statement ("The system shall..."); - rationale; -
actor/role; - preconditions; - main flow; - alternative/error flows; -
business rules; - data fields and audit events; - permissions; -
notifications and reports; - acceptance criteria written as testable
scenarios; - priority; - dependency; - affected existing code; -
migration/data compatibility considerations.

Also include non-functional requirements for security, performance,
availability, usability, accessibility, localisation, auditability,
backups/recovery and maintainability. Do not invent numeric
service-level targets: mark them for confirmation.

### Step 6 --- Technical design and implementation backlog

Deliverables: - `docs/improvement/06-TECHNICAL-DESIGN.md` -
`docs/improvement/07-IMPLEMENTATION-BACKLOG.md`

Map each PRD requirement to existing Laravel files and classify the work
as: - `REUSE`; - `MODIFY`; - `ADD`; - `DEPRECATE` (only with an approved
migration/deprecation plan).

Include proposed schema changes only where justified. For each backlog
item define scope, files, tests, dependencies, rollout/rollback plan and
acceptance criteria. Group work into small pull requests.

### Step 7 --- Incremental implementation

Only begin after the user explicitly approves the relevant requirements
and implementation task.

For each approved task: 1. Confirm branch and working-tree state. 2.
Make a small, focused change. 3. Add or update regression tests. 4. Run
the relevant tests and style checks if the environment permits. 5.
Report exact commands and actual results; clearly state any checks not
run. 6. Review migration safety, access control, audit trail and
backward compatibility. 7. Summarise changed files and risks. 8. Wait
for user approval before committing, pushing, deploying or running
production commands.

## 4. Initial source-code map

Use these as starting points; verify they exist and inspect the current
local versions.

### Core workflow and planning

-   `app/Services/ActivityWorkflow.php`
-   `app/Services/ActivityVersions.php`
-   `app/Services/PlanCycle.php`
-   `app/Services/PlanningWindow.php`
-   `app/Services/ApproverLists.php`
-   `app/Services/ApprovalInbox.php`
-   `app/Services/QuarterCompilation.php`
-   `app/Services/ActivityRollForward.php`
-   `app/Support/ApprovalRules.php`
-   `app/Support/FiscalYear.php`

### Models and policies

-   `app/Models/Activity.php`
-   `app/Models/ActivityVersion.php`
-   `app/Models/ActivitySignature.php`
-   `app/Models/Directorate.php`
-   `app/Models/UnitPlanning.php`
-   `app/Models/Program.php`
-   `app/Models/Subprogram.php`
-   `app/Models/ResultIndicator.php`
-   `app/Models/Alignment.php`
-   `app/Models/Indicator.php`
-   `app/Models/IndicatorAction.php`
-   `app/Models/LineItem.php`
-   `app/Models/AdjustedBudget.php`
-   `app/Models/BudgetExecution.php`
-   `app/Models/ActivityReport.php`
-   `app/Policies/ActivityPolicy.php`

### Reporting, routes, migrations and tests

-   `app/Services/ApprovedPlanReport.php`
-   `app/Services/PlanBreakdown.php`
-   `app/Services/ExecutionReport.php`
-   `app/Services/PerformanceReport.php`
-   `app/Services/ImplementationPlanReport.php`
-   `routes/web.php`
-   `database/migrations/`
-   `tests/Feature/`
-   `docs/MANUAL.md`
-   `README.md`
-   `.github/workflows/ci.yml`

Search the repository for other related files before concluding the list
is complete.

## 5. Initial review hypotheses to verify

These are starting hypotheses from a prior source inspection, not
permission to change code and not all are confirmed defects. Recheck
each against the local working tree, relevant callers, migrations and
tests.

### H1 --- Approved-plan reporting during revision

`ActivityVersions` documents that the last approved version remains in
force while a revision is in progress. Inspect whether
`ApprovedPlanReport` and all relevant totals/reports continue to include
the approved version while the activity's current status is not
`Approved`. If not, propose a version-aware reporting design and
regression tests.

### H2 --- Submission completeness

Inspect `ActivityWorkflow::problems()` and form validation. Confirm
whether the application validates targets and all required fields
consistently, and whether the validation message accurately describes
the actual checks. Build the mandatory-field matrix from confirmed
institutional requirements, not assumptions.

### H3 --- Annual roll-forward

Inspect `ActivityRollForward` and all child models. Determine which
fields, indicator actions, months, baseline/target details, and
budget-line-to-indicator links are copied or omitted. Define the
intended copy rules and tests. Do not carry forward old approvals or
execution results as if they belonged to the new year.

### H4 --- Completeness of approved snapshots

Compare every field required by the approved planning template with
`ActivityVersions::snapshot()` and any migration backfill. Ensure
approved versions preserve all information necessary for historical
reporting and comparison.

### H5 --- Planning completeness

Inspect `PlanningWindow::plannedRoots()` and related cycle calculations.
Confirm whether the cycle accounts for all units that are required to
plan, including units with no activities, inactive units and units
created or dissolved during a fiscal year. Propose a submission register
or nil-return declaration only if needed.

### H6 --- Historical organisational structure

Determine whether past reports should reflect the organisational
structure at approval time or the current structure. If historical
fidelity is required, propose effective-dated assignments or a snapshot.
Do not change current behaviour until the rule is agreed.

### H7 --- Destructive/irreversible migrations

Inspect all migrations, especially migrations that drop or rename
tables/columns and `down()` methods that cannot restore data. Assess
migration history and current database expectations. Never run a
destructive migration against production or recommend doing so without a
verified backup and explicit approval.

### H8 --- Planning vs financial authorisation

Confirm that labels and reports distinguish planned estimates,
finance-reviewed amounts, amounts in an approved PAA version, official
authorised allocations (if the system can evidence them), commitments
and payments. The application currently documents that it does not
integrate with the Ministry of Finance's financial system; do not imply
that reported figures are booked or officially authorised.

For every hypothesis, output one of: - `CONFIRMED GAP`; - `NOT A GAP`; -
`NEEDS MORE EVIDENCE`; - `REQUIRES INSTITUTIONAL DECISION`.

Cite file paths and relevant line numbers from the local repository when
possible.

## 6. Required business-process principles

The target process should observe these principles unless the
institution confirms an exception:

-   **Separate decisions:** activity approval, directorate plan
    finalisation and institution-wide consolidation are different
    concepts.
-   **Authority is explicit:** approval authority must follow validated
    rules and delegations.
-   **Segregation of duties:** reporting and verification should not be
    performed by the same person where the applicable rules require
    independent verification.
-   **Approved baseline is preserved:** revisions must not erase the
    last approved version.
-   **Financial concepts are distinct:** PAA planning, budget review,
    official allocation, commitment and payment are not interchangeable.
-   **No silent data loss:** important approved, verified and audit
    records are retained.
-   **Every decision is traceable:** record who acted, when, what they
    decided and why when a justification is required.
-   **Unit accountability is visible:** the system tracks every required
    unit's submission or accepted nil return.
-   **Performance drives action:** missed targets and execution problems
    should have accountable follow-up where appropriate.
-   **Historical reporting is deliberate:** define which organisational
    structure and approved data version a report represents.
-   **Accessibility and localisation:** retain and test the project's
    supported English, Portuguese and Tetun interfaces; arrange
    competent Tetun review before official use.

## 7. How to start in Claude Code

Begin with read-only analysis. Do not generate or modify application
code yet.

Use the following first task:

> Read `PAA_BUSINESS_PROCESS_IMPROVEMENT_BRIEF_FOR_CLAUDE.md` in full.
> Inspect the local Git repository and report the branch, commit, and
> working-tree state without revealing secrets. Review the README, user
> manual, workflow services, models, policies, routes, migrations and
> tests listed in the brief. Then draft
> `docs/improvement/01-AS-IS-CURRENT-STATE.md` as a proposal in the chat
> first, unless I explicitly ask you to write the file. Include source
> paths, line references, current behaviour, test coverage, risks and
> unresolved questions. Label claims as confirmed by code, inferred, or
> requiring institutional validation. Do not edit application code, run
> migrations, commit, push or deploy.

After the user reviews Step 1, proceed to Step 2. Do not automatically
create every document in one pass without review.

## 8. Definition of success

This improvement programme is successful when: - the current software
process is accurately documented; - legal/institutional assumptions are
explicitly marked and validated; - the target process is approved by the
relevant stakeholders; - every software change traces back to a
requirement; - approved-version, financial and audit data remain
consistent; - each implemented requirement has automated
acceptance/regression tests where practical; - changes are incremental,
reviewable and safely deployable; - users have clear operating
procedures and reporting outputs.

**Immediate next action: complete Step 1 (AS-IS) only.**
