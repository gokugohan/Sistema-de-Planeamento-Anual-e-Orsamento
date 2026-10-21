# 01. AS-IS Current State of the PAA Application

*Step 1 of the business process improvement brief. Status: **baseline**. This is the audit of `main` at commit `17fe16c`, kept unchanged below so that every later change can be traced to it. Section 0a says what has changed since, on branch `improvement`, commit `b9d286a`. Nothing in this document has been validated with the institution.*

## 0a. Status after implementation (branch `improvement`, commit `b9d286a`)

The findings below are those of sections 7 and 8. "Fixed" means the code on the branch behaves as the target process asks and a test covers it; the test was confirmed to fail on the baseline code. Main (`17fe16c`) is unchanged until the branch is merged.

| Finding | Baseline | Now on the branch | Commit | Test |
|---|---|---|---|---|
| H1 report during a revision | activity disappears from the report | stays, shown as last approved from its stored version; total equals the plan in force | `76ededc` | `ApprovedPlanReportTest` |
| H2 submission completeness | only "an indicator exists" | also name, unit and target of every indicator; the official field matrix is still **to validate** | `a0fbcbf` | `ApprovalRulesTest` |
| H3 roll-forward | baseline, means, target text, due quarters, actions and line links lost | all copied; optional status filter; the default statuses are still **to decide** | `ba427d8` | `RollForwardFidelityTest` |
| H4 snapshot | subset of fields | every descriptive field, with a schema number; old snapshots untouched | `628f1ac` | `VersionSnapshotTest` |
| H5 planning completeness | silent units invisible; no nil return | optional rule, off by default: active directorates expected, nil return with a reason; which units are obliged stays **to validate** | `0659f97` | `OptionalPlanRulesTest` |
| H6 historical structure | reports read the live tree | snapshots record the unit path; the report can group by it (optional rule, off) | `0659f97` | `OptionalPlanRulesTest` |
| H7 irreversible migrations | old migrations irreversible | unchanged (not re-run); the three new migrations each have a working `down()` | — | — |
| H8 planning vs authorisation | "Approved budget", "Budget Proposal" | neutral wording ("Approved plan amount", "Planned resources"); what "approved" means is still **to validate** | `8963146` | `NeutralFinancialLabelsTest` |
| O1 Review bypass | direct status write, user-level check | goes through `ActivityWorkflow`; a guard test stops new direct writes | `6195b92`, `b9d286a` | `ReviewActionAuthorizationTest`, `NoDirectStatusUpdateTest` |
| O2 non-atomic approval | status and version in separate writes | one transaction with a row lock | `6195b92` | `ApprovalAtomicityTest` |
| O3 bulk actions not audited | no audit rows (inferred) | **confirmed by test**, then fixed: one audit row per figure | `1a486e2` | `BulkExecutionAuditTest` |
| O4 revision blocked after the window | only administrators could submit | revisions skip the plan window | `5743f92` | `PlanSubmissionWindowTest` |
| O5 line deleted in a revision | in-force amount lost at once | line set aside (soft delete) until the revision is approved | `e2a9abe` | `RevisionKeepsAmountsInForceTest` |
| O6 duplicate codes | count + 1, no index | highest number + 1; unique per unit and year | `6adc8cb` | `ActivityCodeUniquenessTest` |
| O7 segregation of duties | not recorded | preparer recorded; rule off / flag / block, default **off** until the institution decides | `96bff33` | `SegregationOfDutiesTest` |
| O8 corrected spending history | earlier values only in the audit log | listed on the spending page ("Earlier verified values"); the row is still edited in place | `d94f203` | `ExecutionHistoryTest` |
| O9 notifications | README said queued; lists and interim not told | README and manual corrected; holders of the next step told | `8963146`, `f1e20db` | `NextHolderNotificationTest` |
| O10 corrective actions | free text | tracked actions with person responsible and due date (optional rule, off) | `0659f97` | `OptionalPlanRulesTest` |

**New findings made while building**

- **O11.** The Simulator (`resources/views/livewire/testing/simulation-list.blade.php`) approves and rejects by writing the status itself, and let the Minister approve. It works only on simulation records (`Activity::testData()`), which reports and the audit trail leave out, so it is allowed in the guard test. Since `b5ee79d` only directors and administrators approve there. **[CODE]**
- **O12.** Official budget-book activity codes repeat across units in the same year (sample data, for example `5100111`). An activity code is therefore unique only within a unit and a year. **[CODE]**, from the sample seeders.

**Tests.** latest full suite on `0659f97`: **439 passed, 0 failed** (1,839 assertions). Earlier: full suite on `819f84a` (same application code as `b9d286a`): **421 passed, 0 failed** (1,780 assertions); `vendor/bin/pint --test` passed on `094242b`, and the only PHP file added since (`NoDirectStatusUpdateTest`) was formatted with Pint.


## 0. Method, limits and labels

- Read-only inspection of the local repository. No application code was changed; no migration, test, style check, commit, push or deploy was run in this step. No test result is claimed here.
- `.env` values and secrets were not read or copied.
- The code shows what the software does, not what the law requires. No Timor-Leste legal requirement is asserted.

| Label | Meaning |
|---|---|
| **[CODE]** | Confirmed by reading the cited code |
| **[PROBE]** | Reproduced by a temporary probe test in an earlier session (probe deleted; not re-run in this step) |
| **[INFERRED]** | Reasoning from code, not demonstrated |
| **[VALIDATE]** | Depends on an institutional or legal rule that must be confirmed |

Hypothesis verdicts: `CONFIRMED GAP`, `NOT A GAP`, `NEEDS MORE EVIDENCE`, `REQUIRES INSTITUTIONAL DECISION`.

## 1. Repository state inspected

| Item | Value |
|---|---|
| Branch | `main` |
| Commit | `17fe16c` |
| Remote | `github.com/gokugohan/Sistema-de-Planeamento-Anual-e-Orsamento`, 0 ahead / 0 behind `origin/main` |
| Working tree | No modified tracked files. Untracked: the brief, `Sample_Plano.xlsx`, `docs/APPLICATION-ASSESSMENT.md`, `docs/AUDIT.md`, `public/logo.png` |
| Size | 79 migrations, 29 models, 24 services, 47 Livewire (Volt) pages, 56 feature-test files + 1 unit test |
| Documents inspected | `README.md`, `docs/MANUAL.md`, `.github/workflows/ci.yml` |

## 2. Architecture and modules

- **Stack [CODE]:** Laravel 12, Livewire 3 (Volt single-file pages in `resources/views/livewire/pages/**`), Tailwind, Alpine, MySQL, Spatie permissions, server-side PDF.
- **Layers:** routes in `routes/web.php`; rules in `app/Services/*` and `app/Policies/ActivityPolicy.php`; data in `app/Models/*` (audit hooks from `app/Models/Concerns/Auditable.php`); settings in `app/Support/*` (`ApprovalRules.php`, `FiscalYear.php`, `Navigation.php`).

| Module | Main code |
|---|---|
| Activity approval chain | `ActivityWorkflow.php`, `ApproverLists.php`, `ApprovalInbox.php`, `ActivitySignature.php`, `ApprovalDelegation.php` |
| Planning windows and finalisation | `ReportingWindows.php`, `PlanningWindow.php`, `PlanCycle.php`, `UnitPlanning.php` |
| Versions and revisions | `ActivityVersions.php`, `ActivityVersion.php` |
| Annual roll-forward | `ActivityRollForward.php` |
| Import and export | `ActivityCsvImporter.php`, list page export |
| Execution reporting | `execution-spending/progress/narrative.blade.php`, `BudgetExecution.php`, `IndicatorProgress.php`, `ActivityReport.php`, `QuarterCompilation.php` |
| Reports | `ApprovedPlanReport.php`, `ImplementationPlanReport.php`, `PerformanceReport.php`, `PlanBreakdown.php`, `ExecutionReport.php`, `DeliveryReport.php` |
| Home screens | `UnitWorkspace.php` (lines 53, 127, 200), `pages/work/*` |

## 3. Roles, permissions and organisational scope

**Roles [CODE]** (`database/seeders/RolesAndPermissionsSeeder.php`: Admin :64, Minister :70, Director :82, Planning :98 displayed as "Technical Officer", Finance :110, Viewer :123). Minister has `view_all_paa` and no approval permission. Technical Officer and Director have `submit_paa`. Finance has `confirm_budget` and `verify_spending`.

**Scope [CODE]:**
- `ActivityPolicy::inScope` (`ActivityPolicy.php:21-39`): own unit and below, units the user directs (`seats()`), units under an approver list the user is on, everything with `view_all_paa` or Admin.
- `update` (`:56-62`) needs `edit_activities`, scope, status Draft or Rejected, and not locked. `delete` (`:64-70`) needs Draft and `version === 0`.
- `Activity::isLocked()` (`Activity.php:38-41`) is always false. Nothing locks a year.

**Authority is computed live** from the unit tree, approver lists (`ApproverLists::listFor`, `ApproverLists.php:27-37`, nearest list above wins) and interim appointments (`ApprovalDelegation::active`). The head of a top-level unit is a seat with no parent (`ActivityWorkflow.php:499-504`).

**Route guards [CODE]** (`routes/web.php`): administration `role:Admin` (:141); approval lists and interim `role:Admin|Director` (:77, :81); `approvals.budget` needs `confirm_budget` (:73); the paa-approval page has only `auth,verified` and checks inside (:101).

**Tests:** `DepartmentScopeTest` (2), `UnitWorkspaceTest` (9), `FinanceOfficerTest` (4), `ApproverListsTest` (9), `DelegationTest` (6), `RestructuringTest` (7).

## 4. Process stage register

Each stage lists actor, trigger/input, action and rule, output, code, tests and open questions.

### S1. Create or edit a draft
- **Actor:** Technical Officer, Director or Admin, within scope.
- **Trigger/input:** activity form (programme, subprogramme, unit, dates, quarters, indicators with actions and months, budget lines).
- **Rules [CODE]:** validation `activity-form.blade.php:160-197` (programme exists in the year; description ≥ 5 characters; dates inside the fiscal year; at least one indicator and one budget line; line amount ≥ 0.01 at :181; indicator target required, numeric, ≥ 0 at :187). Planning must be open for the unit (:210); the plan window must be open for non-admins (:213). Code `UNIT-YY-NNN` is the count of existing rows + 1 (`nextCode`, :199).
- **Output:** Draft with lines; lines updated in place by id (keeps reported spending).
- **Tests:** `ActivityPlanningTest` (12), `PlanSubmissionWindowTest`, `PlanningOpenTest` (6).
- **Open:** mandatory-field matrix of the official template **[VALIDATE]**.

### S2. Submission checks
- **Rule [CODE]** (`ActivityWorkflow::problems`, :39-55): budget total > 0; at least one indicator exists (`isEmpty()` check); end date not before start. The message says "indicator with a target" (:48) but the check does not test the target.
- **Transition** (`target()`, :136-147): Draft/Rejected → Prepared; non-admins blocked when the plan window is closed (:139); a named list of preparers decides who may submit, otherwise anyone with `submit_paa`.

### S3. Budget confirmation (optional)
- **Actor:** Finance officer or Admin, in scope.
- **Rule [CODE]:** only if `ApprovalRules::budgetConfirmation` is on (default false, `ApprovalRules.php:21-24, 32-44`). Certification is blocked until `budget_confirmed_at` is set (:151-153). Any change to a line resets the confirmation (`LineItem` saved/deleted hook).
- **Code:** `ActivityWorkflow.php:189-214`, `approvals/budget-review.blade.php`. **Tests:** `BudgetConfirmationTest` (7).

### S4. Certification and parent validation
- **Rule [CODE]** (:149-173): Prepared → Verified if the unit has a parent and `parentValidation` is on, otherwise directly → Validated (shown "Certified"). Verified → Validated by the unit above.
- **Skip-level [CODE]:** `directsUnit` (:507-517) lets anyone whose directed units include the unit certify; Admin can do every step.
- **Tests:** `ApprovalRulesTest` (8), `ApprovalChainEndToEndTest` (3).

### S5. Final approval
- **Actor [CODE]** (:175-182, :296-301): head of the top-level unit, or the named approvers if a list exists, or Admin. The Minister has no approval right.
- **Output:** status Approved; `approved_at/by` set; version created (`ActivityVersions::approved`, called at :243 and :399). `advance()` and `approve()` use no transaction or lock (:220-256, :308-320); only `approveMany` does (:347-348).
- **Legacy [CODE]:** `Waiting Approval` can be approved (:288) but `target()` never produces it.

### S6. Return with a reason
- **Rule [CODE]:** reason required (`sendBack`, :268-274). `canReturn` allows Submitted, Reviewed, Validated, Waiting → Rejected for Admin, the head, a listed approver, a finance confirmer or whoever has the next step. Signatures of the round are voided.

### S7. Signing in order, interim approvers, bulk approval
- **In order [CODE]** (`signStage`, :93-122): on a certify/approve list of two or more with the `sequential` flag, each person signs in turn. Admin completes immediately. An interim signs in the next name's place; `signed_by` records the actual signer. Rounds are numbered; a return or revision voids earlier signatures (`ActivitySignature::close`); rows are never deleted.
- **Interim:** counts for certify and approve, not for prepare.
- **Bulk [CODE]:** `approveMany` (:332-393), limit 200, per-item lock and re-check, one audit batch row.
- **Tests:** `SequentialSigningTest` (7), `DelegationTest` (6), `BulkApprovalPageTest`.

### S8. Plan windows and directorate finalisation
- **Windows [CODE]** (`ReportingWindows.php:23-32`): no row means open; Admin always allowed; one plan window per year (period 1, :54-57) and one per month and quarter.
- **Finalisation [CODE]** (`PlanningWindow.php:238-262`): head of the directorate or Admin (:149-161); blocked while any subtree activity is Submitted, Reviewed, Validated or Waiting; blocked if there is no approved activity (:251-253); recorded in `unit_plannings`. Finalising stops new activities, imports and copies for that directorate (`isOpenFor`) but not revisions. The year counts as ended when every directorate that has activities has finalised (`endedBy`, :80-87).
- **Reopening:** head or Admin, reason required (:267-283).
- **Tests:** `PlanningWindowTest` (9), `PlanningOpenTest` (6), `ReportingWindowsTest` (5), `PlanCycleTest` (5).

### S9. Revisions and versions
- **Rule [CODE]:** each approval writes `activity_versions` with a JSON snapshot (`ActivityVersions.php:22-46, 48-70`) and sets amounts in force (`adjusted_budgets`). `reopen` (`ActivityWorkflow.php:476-486`) needs a reason ≥ 3 characters and is allowed to Admin, the final approver, a director of the unit, or preparers. The activity returns to Draft and **`approved_at/by` are nulled** (:483; the approver stays in the version row). The in-force version stays until the revision is approved. A line with reported spending, an indicator with reported progress and an activity approved once cannot be deleted.
- **Tests:** `ActivityRevisionTest` (8).

### S10. Roll-forward, import, export
- **Roll-forward [CODE]** (`ActivityRollForward.php:25-105`): copies all source-year activities of the chosen units (any status) as Drafts, shifting dates, skipping duplicates by description and closed directorates, creating the next programme if missing. See H3.
- **Import [CODE]:** CSV (type and size validated), one transaction, all rows validated first (`ActivityCsvImporter.php:43-131`); creates Drafts, one indicator per row (:256-283).
- **Tests:** `ActivityCsvImportTest` (8); roll-forward only in `ActivityPlanningTest.php:226-242` (counts only).

### S11. Execution reporting
- **Spending [CODE]** (`execution-spending.blade.php`): only lines with an in-force amount (:73); months only up to the current month (`latestMonth`, :34-41); month window checked (`mayReport`, :43-51); cumulative committed ≤ in-force amount and paid ≤ committed (:137-141). One row per line and month, overwritten in place (`updateOrCreate`, :143-146).
- **Indicator progress** (`execution-progress`): per quarter; cumulative or level indicators.
- **Narratives** (`execution-narrative`): results, constraints, reasons not met, corrective measures (free text), rating On track / Partly / Off track (`ActivityReport.php:17, 20-22`).

### S12. Verification, correction, compilation
- **Verification [CODE]:** the verifier cannot be the submitter (spending :211, progress :151, narratives :140). Directors, Admin and (for spending) Finance may verify.
- **Correction:** the final approver or Admin can reopen a verified spending figure to Draft with a remark (:167-196).
- **Compile the quarter:** directors/Admin draft and save the quarter text (`QuarterCompilation.php:26-92`).
- **Tests:** `BudgetExecutionEntryTest` (6), `ExecutionCorrectionTest` (5), `ActivityNarrativeTest` (4), `QuarterCompilationTest` (7).

### S13. Reports and outputs
- Approved plan report, implementation plan per unit, performance report, CSV export, PDF.
- **Code:** `ApprovedPlanReport.php`, `ImplementationPlanReport.php:44`, `PerformanceReport.php:25`; routes `reports.*` (`routes/web.php:110-137`).
- **Tests:** `ApprovedPlanReportTest` (7), `ImplementationPlanReportTest` (4), `PerformanceReportTest` (5), `PlanBreakdownTest`, `ExecutionReportTest`.

### S14. Audit, notifications, security
- **Audit [CODE]:** `Auditable` (`Concerns/Auditable.php:24-68`) writes created/updated/deleted rows with old and new values, user, IP, agent, note. Records marked `is_test_data` are skipped. Eloquent model events only.
- **Notifications [CODE]:** `SystemNotification` uses `Queueable` but does **not** implement `ShouldQueue` (`SystemNotification.php:8-10`) and sends only to the `database` channel (:26-28): synchronous, in-app only. Routing in `ActivityWorkflow::notify` (:519-523) targets users with a role whose own `directorate_id` equals the unit, not the named approvers of a list nor an interim (except the next signer in `signStage`).
- **Security [CODE]:** login throttle 5 attempts (`LoginForm.php:49`), two-factor throttle (`two-factor-challenge.blade.php:38-47`), registration off by default (`config/auth.php:23`), password rules (`AppServiceProvider.php:44-46`), security headers (`SecurityHeaders.php:26-33`), forced password change, two-factor middleware.
- **Tests:** `TwoFactorTest` (12), `PlatformTest` (13), `AuditLogTest`.

## 5. Status transition table [CODE]

| From | To | Who | Conditions | Where |
|---|---|---|---|---|
| Draft / Rejected | Prepared | preparer list, or `submit_paa` | `problems()` empty; plan window open (non-admin) | `target` :136-147 |
| Prepared | Verified | certify list or director of the unit/above | budget confirmed if rule on; parent exists and `parentValidation` | :149-163 |
| Prepared | Certified | same | no parent, or `parentValidation` off | :163 |
| Verified | Certified | certify list of the unit above, or director above | | :165-173 |
| Certified (Waiting) | Approved | approve list, head of top-level unit, or Admin | in turn if signing in order | :175-182, 287-293 |
| Prepared / Verified / Certified / Waiting | Rejected | see S6 | reason required | :258-285 |
| Approved | Draft | see S9 | reason ≥ 3 characters | :455-486 |

A second path that bypasses `ActivityWorkflow` exists: see O1.

## 6. Data model summary [CODE]

| Concept | Table / model | Notes |
|---|---|---|
| Unit tree | `directorates` / `Directorate` | parent, type, `administration`, `active`, `established_year`, `dissolved_year` |
| Programme / subprogramme | `Program`, `Subprogram` | per fiscal year; official codes |
| Strategic alignment, result indicators | `Alignment` (SDG/PED), `ResultIndicator` | on programme and subprogramme; descriptive, not reported |
| Activity | `activities` | status, `version`, `revision_reason`, `submitted_at`, `reviewed_*`, `approved_*`, `budget_confirmed_*`. No `submitted_by` column |
| Indicator, actions | `Indicator`, `IndicatorAction` | target, baseline, means, `due_quarters`, `measure`; actions with months |
| Budget line | `LineItem` | economic category, funding source, amount, optional indicator link |
| Amount in force | `adjusted_budgets` | one row per line; FK `ON DELETE CASCADE` (`2026_01_22_232238_create_adjusted_budgets_table.php:15`) |
| Version, signatures | `ActivityVersion`, `ActivitySignature` | snapshot JSON; rounds, states |
| Finalisation | `UnitPlanning` | one row per directorate per year |
| Windows | `ReportingWindow` | plan, month, quarter |
| Execution | `BudgetExecution`, `IndicatorProgress`, `ActivityReport` | per line/month, per indicator/quarter, per period |

## 7. Hypotheses H1 to H8

| # | Verdict | Evidence |
|---|---|---|
| **H1** Report during a revision | **CONFIRMED GAP** | `ApprovedPlanReport.php:24-27` filters `status = Approved`; during a revision the activity is Draft and drops out of the report, while `PlanBreakdown`/dashboard sum in-force amounts (`PlanBreakdown.php:166`). The report shows "Revision in progress" as a count only (:112-115) and reads live lines, not the version snapshot. No test covers it. |
| **H2** Submission completeness | **NEEDS MORE EVIDENCE** (plus a confirmed small inconsistency) | `problems()` checks that an indicator exists, not that it has a target (`ActivityWorkflow.php:47-49`), although its message says so. Form and importer enforce a target (`activity-form.blade.php:187`, `ActivityCsvImporter.php:268-270`); zero is allowed. Official mandatory fields unknown **[VALIDATE]**. |
| **H3** Roll-forward | **CONFIRMED GAP** (copy rules need a decision) | Copied indicator fields: name, type, unit, measure, annual target (`ActivityRollForward.php:98`). Not copied: baseline, means of verification, target text, `due_quarters` (defaults to every quarter), actions and months, and the line-to-indicator link (:95). Any source status is copied. Only a count test exists (`ActivityPlanningTest.php:226-242`). |
| **H4** Snapshot completeness | **CONFIRMED GAP vs current fields**; required set needs a decision | `ActivityVersions::snapshot` (:48-70) omits activity `location`, `procurement_required`, `gender_responsive`, `period_type`, `quarter`; indicator `measure`, `target_text`; line `code`, `account_code`. Category/funding source stored by name, the rest by id. The migration backfill (`2026_11_04_100000…`) writes a similar subset. |
| **H5** Planning completeness | **CONFIRMED (behaviour)**; need is **REQUIRES INSTITUTIONAL DECISION** | `plannedRoots` derives from rows in `activities` (`PlanningWindow.php:66-69`): a directorate with no activity is invisible and cannot block "ended"; finalising requires an approved activity (:251-253), so a nil return is impossible. `Directorate` has `established_year`/`dissolved_year` (`Directorate.php:26-27, 40-48`) which this service ignores. |
| **H6** Historical structure | **REQUIRES INSTITUTIONAL DECISION** (no effective dating confirmed) | Activities store `directorate_id` only; reports eager-load the current parent chain (`ApprovedPlanReport.php:25-28`). Moving a unit changes how past years print. Moves are audited but not reconstructable per report. |
| **H7** Irreversible migrations | **CONFIRMED** (deployed state unknown) | Irreversible/empty `down()`: `2026_11_05_100000_drop_envelope_and_budget_cycle_tables.php:25-28`, `2026_11_04_110000_remove_fiscal_envelope_permissions.php:22-25`, earlier drops (`2026_01_24_145056…`, `2026_01_24_154820…`). `2026_11_04_100000_create_activity_versions.php:67-70` rewrites `adjusted_budgets` (sets amounts, deletes rows of non-approved activities) with no way back. **These migrations must not be run against any data without a backup.** |
| **H8** Planning vs financial authorisation | **NEEDS MORE EVIDENCE** (label review) | Mixed labels: "Approved plan in force", "Planned", "Paid (verified)" on workspace/dashboard; "Approved budget" (`execution-dashboard.blade.php:73`) and "Annual Activity Plan and Budget Proposal" / "Total budget proposal" on the report (`approved-plan.blade.php:94, 117`). README states there is no link to the Ministry of Finance system (`README.md:419-420`). Nothing in the app evidences an authorised allocation. |

## 8. Additional findings

| ID | Finding | Label |
|---|---|---|
| **O1** | `paa-approval.blade.php:193-209` (`review($id)`, button :353) moves any Submitted/Reviewed activity to Reviewed/Validated via `Activity::findOrFail($id)` with a **user-level** check only (`mayApprove` :28-31: Admin, any Director, or anyone on any list). It does not call `ActivityWorkflow`: no scope check on the activity, no `parentValidation` setting, no approver lists, no signing in order, no budget-confirmation gate, no tests. A Director of another unit could in principle act on a foreign id. | **[CODE]**; exploit **not executed**. Candidate **P0** |
| **O2** | `advance()`/`approve()` update the status, then create the version, outside a transaction (:220-256, :308-320, :395-400). A failure between them leaves Approved with no version; no lock against double approval. | **[CODE]** |
| **O3** | Query-builder bulk `->update()` bypasses model events, so no audit rows: `verifyAll` (`execution-spending.blade.php:231-233`), progress submit (`execution-progress.blade.php:113`), narrative submit (`execution-narrative.blade.php:118`). `verified_by/at` remain on the row. | **[CODE]**; absence of audit rows **[INFERRED]** from hook design |
| **O4** | After the plan window closes, a revision (Draft) cannot be submitted by non-admins (:139). | **[PROBE]** + code |
| **O5** | Deleting a line during a revision cascades away its in-force amount (FK cascade): 50,477 → 31,841 while still version 1 in the probe. | **[PROBE]** + FK **[CODE]** |
| **O6** | Duplicate activity codes: count + 1 (`activity-form.blade.php:199`; roll-forward uses the same pattern); no unique index. | **[PROBE]** + code |
| **O7** | No segregation of duties inside the activity chain: no `submitted_by`; `target()` never compares actor with preparer. A head or Admin can prepare, certify and approve the same activity. Whether this is acceptable is **[VALIDATE]**. | **[CODE]** |
| **O8** | Verified spending is one overwritten row per line/month. After a correction the earlier verified values survive only in `audit_logs.old_values`. | **[CODE]** |
| **O9** | Notifications are in-app and synchronous, but README says queued with a worker (`README.md:145, 315-317`). Routing ignores approver lists and interim. | **[CODE]** |
| **O10** | Corrective actions are free text only (`reasons_not_met`, `corrective_measures`); no owner, due date or status. | **[CODE]** |

## 9. Test coverage map and gaps

- **Covered:** workflow rules, lists, signing, interim, restructuring, windows, finalisation, revision basics, execution entry and correction, narratives, compilation, imports, reports, scope, two-factor, platform (counts above). 376 tests existed at the last full run in a previous session; none were run in this step.
- **No test found for:** O1, report totals during a revision (H1), roll-forward field fidelity (H3), snapshot completeness (H4), atomicity/concurrency (O2), audit rows for bulk verification (O3), segregation of duties in the chain (O7), nil returns or empty units (H5).

## 10. Questions for institutional validation

1. The official annual planning calendar; which units must submit, including nil returns.
2. Who prepares, certifies and approves in each type of unit; whether skip-level certification and administrator override are acceptable; whether preparer and approver must differ.
3. Mandatory fields of the official template and the official codes (division, funding source, programme outcomes and outputs).
4. How amendments after approval are authorised; what a report must show while a revision is pending.
5. Whether past reports must show the organisational structure at approval time.
6. Which financial figures may be called "approved" or "budget"; any reconciliation with the financial system.
7. Retention, audit and language requirements, including competent Tetun review.

## 11. Documentation drift

- `README.md:145, 315-317` says notifications are queued; they are not (O9).
- `docs/AUDIT.md` (untracked, written earlier) said every data-changing Livewire action authorizes and that notifications need a queue worker; both are corrected in that file as part of this step.
- The manual presents approval as one chain and does not mention `review()`.
