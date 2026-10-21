# 04. Gap Analysis (AS-IS vs TO-BE)

*Step 4 of the improvement brief. Compares `01-AS-IS-CURRENT-STATE.md` (baseline `17fe16c`) with `03-TARGET-BUSINESS-PROCESS.md`. The gap descriptions below are those of the baseline; the table gives each gap's status on branch `improvement`, commit `b9d286a`.*

## Status of the gaps (branch `improvement`, commit `b9d286a`)

| Gap | Priority | Status | Commit | Note |
|---|---|---|---|---|
| G-01 | P0 | **closed** | `6195b92`, `b9d286a` | plus a guard test |
| G-02 | P0 | **closed** | `6195b92` | concurrency tested with a stale copy, not two simultaneous requests |
| G-03 | P0 | **closed** | `e2a9abe` | soft deletes instead of an FK change; migration to run |
| G-04 | P0 | **closed** | `76ededc` | |
| G-05 | P0 | **closed** | `1a486e2` | absence of audit rows confirmed by test first |
| G-06 | P0 | **closed** | `8963146` | final wording waits for R-FIN-01; pt/tet to review |
| G-07 | P1 | **closed** (optional rule) | `0659f97` | built as an optional rule, **off by default**: expected directorates and nil returns; which units are obliged stays R-OBL-01 to R-OBL-04 |
| G-08 | P1 | **closed** | `5743f92` | a separate revision window waits for R-AMD-05 |
| G-09 | P1 | **closed** | `628f1ac` | |
| G-10 | P1 | **closed** | `ba427d8` | default statuses wait for R-FRM-06 |
| G-11 | P1 | **partly closed** | `a0fbcbf` | full field matrix waits for R-FRM-01 |
| G-12 | P1 | **closed** | `6adc8cb` | unique per unit and year (official codes repeat across units); migration to run |
| G-13 | P1 | **closed** (configurable) | `96bff33` | default off until R-APP-04; migration to run |
| G-14 | P1 | **closed** | `d94f203` | a view over the audit trail; no schema change |
| G-15 | P2 | **closed** | `f1e20db` | |
| G-16 | P2 | **closed** (optional rule) | `0659f97` | built as an optional rule, **off by default**: corrective actions with person responsible, due date, closing note |
| G-17 | P2 | **partly closed** (optional rule) | `0659f97` | baselines built as an optional rule, **off by default**; a consolidation **review decision is not built**: no rule-holder is known (R-APP-05, R-CON-03) and none is assumed |
| G-18 | P2 | **closed** | `0659f97` | guidance text per fiscal year on the activity form; the official calendar is still set as windows (R-CAL-01) |
| G-19 | P2 | **closed** (optional rule) | `0659f97` | built as an optional rule, **off by default**: finance officers see and verify every unit |
| G-20 | P2 | **closed** (optional rule) | `0659f97` | built as an optional rule, **off by default**: a revision marked as needing an outside approval waits for its reference |
| G-21 | P2 | **closed** (optional rule) | `0659f97` | snapshots record the unit path (schema 3); the report can group by it, built as an optional rule, **off by default** |
| G-22 | P3 | **partly closed** | `b437a0d` | `BACKUP_COPY_PATH` copies every verified backup to a second place; the retention period itself is an institutional rule (R-RET-01) |
| G-23 | P3 | **partly closed** | `094242b` | Pint passes; legacy "Waiting Approval", the `adjusted_budgets` name and static analysis remain |
| G-24 (new) | P3 | **closed** | `b5ee79d` | only directors and administrators approve or return simulation records; it still writes the status directly, on simulation records only |


**Priority definitions (from the brief):** P0 data integrity, security, destructive migration, incorrect approved or financial reporting, severe workflow integrity risk. P1 essential planning, approval, versioning or monitoring requirement. P2 institutional maturity, usability, reporting enhancement or integration preparation. P3 optional.

**Reading notes:** "Evidence" cites the AS-IS document. Gaps marked *(decision)* cannot be built until the named institutional question is answered; the technical part may still be prepared. File and line references are as of commit `17fe16c`.

## Summary

| Priority | Gaps |
|---|---|
| P0 | G-01, G-02, G-03, G-04, G-05, G-06 |
| P1 | G-07, G-08, G-09, G-10, G-11, G-12, G-13, G-14 |
| P2 | G-15, G-16, G-17, G-18, G-19, G-20, G-21 |
| P3 | G-22, G-23 |

---

## P0

### G-01. Approval review bypasses the workflow service
- **Process requirement:** one authority check for every approval step (stages 4, 5; R-APP-08).
- **Current behaviour:** `paa-approval.blade.php:193-209` (`review($id)`) sets status directly with a user-level check only (`mayApprove`, :28-31). No scope check, no approver lists, no signing in order, no budget-confirmation gate, no tests. (AS-IS O1)
- **Target behaviour:** `review()` calls `ActivityWorkflow` and inherits all its rules; out-of-scope ids are refused.
- **Impact:** a user may advance an activity they have no authority over.
- **Risk:** high; exploit not executed.
- **Proposed solution:** replace the direct update by the service call; add a feature test for a foreign-scope Director and for an unmet budget gate.
- **Affected:** `paa-approval.blade.php`, `ActivityWorkflow.php`.
- **Tests:** foreign-scope refusal; list-governed unit; budget-confirmation gate; audit row.
- **Dependency:** none.

### G-02. Single approval is not atomic
- **Requirement:** approval and version creation succeed or fail together (stage 7).
- **Current:** `advance()`/`approve()` update the status, then create the version, with no transaction or lock (`ActivityWorkflow.php:220-256, 308-320, 395-400`). (O2)
- **Target:** one transaction with a row lock; a repeat approval is a clear no-op.
- **Impact:** Approved without a version; second concurrent approver errors after the status changed.
- **Risk:** medium-high.
- **Solution:** wrap in `DB::transaction` with `lockForUpdate` and re-check, as `approveMany` does (:347-348).
- **Affected:** `ActivityWorkflow.php`.
- **Tests:** simulated failure after status change leaves no Approved-without-version; double approval.
- **Dependency:** none.

### G-03. Deleting a line in a revision removes the amount in force
- **Requirement:** the version in force and its amounts stay until the revision is approved (R-AMD-03, stage 14).
- **Current:** FK `ON DELETE CASCADE` on `adjusted_budgets` (`2026_01_22_232238_create_adjusted_budgets_table.php:15`); probe showed 50,477 becoming 31,841 while still version 1. (O5)
- **Target:** amounts in force change only on approval.
- **Impact:** dashboards, spending limits and performance reports change before approval; a rejected revision cannot restore the amount.
- **Risk:** high (financial reporting).
- **Solution:** keep the in-force row when a line is removed in a revision (mark "removed in revision"); reconcile on approval. Requires a migration changing the FK behaviour; **take a backup before any run; no production run without approval**.
- **Affected:** `adjusted_budgets` migration, `LineItem`, `ActivityVersions`, execution pages.
- **Tests:** delete line in revision, totals unchanged; approve revision, totals updated; reject revision, totals unchanged.
- **Dependency:** backup and migration approval.

### G-04. Report drops activities under revision
- **Requirement:** report totals stay stable during revisions and tie to a baseline or the version in force (R-CON-04, stages 10, 14).
- **Current:** `ApprovedPlanReport.php:24-27` filters `status = Approved`; dashboards use in-force amounts (`PlanBreakdown.php:166`); the report shows only a count of revisions (:112-115) and reads live lines. (H1)
- **Target:** the report reads the version in force (snapshot) and shows pending revisions beside it.
- **Impact:** the final plan report and the dashboard disagree.
- **Risk:** high (incorrect approved-plan reporting).
- **Solution:** build the report from `activity_versions` for the version in force; show the pending revision separately.
- **Affected:** `ApprovedPlanReport.php`, `approved-plan.blade.php`, `ActivityVersions`.
- **Tests:** report total equals in-force total during a revision; revision shown separately.
- **Dependency:** G-09 (complete snapshot) for full fidelity.

### G-05. Bulk verification leaves no audit rows
- **Requirement:** every state change is audited (stage 12, R-EXE-05).
- **Current:** query-builder `update()` bypasses model events in `verifyAll` (`execution-spending.blade.php:231-233`), progress submit (`execution-progress.blade.php:113`) and narrative submit (`execution-narrative.blade.php:118`). (O3; absence **inferred**)
- **Target:** one audit row per verified figure, or a batch row listing ids.
- **Impact:** verification, which controls what counts as real, is invisible in the audit trail.
- **Risk:** medium-high.
- **Solution:** iterate with models or write explicit audit entries. First confirm the absence with a test.
- **Affected:** the three Volt pages, `Auditable`.
- **Tests:** audit row exists after bulk verify.
- **Dependency:** none.

### G-06. Documentation and labels misstate behaviour
- **Requirement:** documents match the system (stage 14 notifications; R-FIN-02).
- **Current:** README says notifications are queued (`README.md:145, 315-317`); they are synchronous, database-only (O9). Labels "Approved budget" (`execution-dashboard.blade.php:73`) and "Budget Proposal" (`approved-plan.blade.php:94, 117`) imply budget authorisation (H8).
- **Target:** accurate text; neutral wording unless a validated rule says otherwise.
- **Impact:** operators wait for a worker that is not needed; readers treat planned amounts as authorised.
- **Risk:** medium; listed P0 because H8 concerns incorrectly framed approved/financial reporting.
- **Solution:** correct the README; neutral labels ("Planned", "Approved plan in force") pending R-FIN-01.
- **Affected:** `README.md`, two Blade views, `lang/*.json`.
- **Tests:** guard test for `lang/en.json` keys already exists; add label assertions.
- **Dependency:** R-FIN-01 for final wording.

---

## P1

### G-07. Unit submission obligations and nil returns are not modelled
- **Requirement:** expected-submitter list; nil return as a recorded outcome (stages 2, 8; R-OBL-01 to R-OBL-04).
- **Current:** `plannedRoots` comes from rows in `activities` (`PlanningWindow.php:66-69`); finalising requires an approved activity (:251-253); `established_year`/`dissolved_year` ignored. (H5)
- **Target:** per-cycle obligation list from the unit tree; nil return recorded and signed.
- **Impact:** a silent unit cannot be seen or block year-end; a unit with nothing to plan cannot finalise.
- **Risk:** medium-high.
- **Solution:** a per-cycle obligations table and a nil-return flag on `unit_plannings`; checks in `PlanningWindow`. Schema change.
- **Affected:** `PlanningWindow.php`, `UnitPlanning`, new migration, dashboards.
- **Tests:** unit without activities listed; nil return allows finalisation; dissolved unit excluded.
- **Dependency:** *(decision)* R-OBL-01 to R-OBL-03.

### G-08. Revisions blocked for unit staff after the plan window closes
- **Requirement:** revisions follow their own window rule (R-AMD-05, stage 14).
- **Current:** a revision returns to Draft; `target()` blocks non-admins when the plan window is closed (`ActivityWorkflow.php:139`). (O4)
- **Target:** a revision (`version > 0`) uses a revision rule, not the plan window.
- **Impact:** every post-season change becomes an administrator task.
- **Risk:** medium.
- **Solution:** exempt `version > 0` from the plan window, or add a revision window.
- **Affected:** `ActivityWorkflow.php`, `ReportingWindows.php`.
- **Tests:** revision submittable after the window closes; new activity still blocked.
- **Dependency:** *(decision)* R-AMD-05.

### G-09. Version snapshot is incomplete
- **Requirement:** a snapshot holds every field that affects meaning (R-AMD-04, stage 14).
- **Current:** `ActivityVersions::snapshot` (:48-70) omits `location`, `procurement_required`, `gender_responsive`, `period_type`, `quarter`; indicator `measure`, `target_text`; line `code`, `account_code`. (H4)
- **Target:** full snapshot; comparison view shows all fields.
- **Impact:** a past version cannot be reproduced faithfully.
- **Risk:** medium.
- **Solution:** extend the snapshot and add a snapshot schema version number; do not rewrite old snapshots.
- **Affected:** `ActivityVersions.php`, version comparison view.
- **Tests:** snapshot contains each listed field; old snapshots still display.
- **Dependency:** *(decision)* required set (R-OGE-03).

### G-10. Roll-forward loses indicator and action detail
- **Requirement:** copy a defined field set and only chosen statuses (R-FRM-06, stage 15).
- **Current:** copies name, type, unit, measure, annual target only; baseline, means, target text, `due_quarters`, actions and months, line-to-indicator link are lost; copies any status (`ActivityRollForward.php:95-98`). (H3)
- **Target:** documented copy rules; link and actions preserved.
- **Impact:** silent loss of data; every indicator due in every quarter by default.
- **Risk:** medium.
- **Solution:** extend the copy; add a preview that lists what will be copied; status filter option.
- **Affected:** `ActivityRollForward.php`, roll-forward page.
- **Tests:** field-by-field fidelity; link preserved; status filter.
- **Dependency:** *(decision)* R-FRM-06.

### G-11. Submission check does not match its message
- **Requirement:** completeness matches the official form (R-FRM-01, R-FRM-02).
- **Current:** `problems()` checks that an indicator exists, not a target (`ActivityWorkflow.php:47-49`), message says "with a target"; form and importer enforce it. (H2)
- **Target:** one completeness rule used by form, importer and workflow.
- **Impact:** activities can reach Prepared with an empty indicator via other paths.
- **Risk:** low-medium.
- **Solution:** central completeness method; matrix driven by the validated field list.
- **Affected:** `ActivityWorkflow.php`, `activity-form.blade.php`, `ActivityCsvImporter.php`.
- **Tests:** each mandatory field blocks submission from every entry path.
- **Dependency:** *(decision)* R-FRM-01 (field matrix).

### G-12. Duplicate activity codes possible
- **Requirement:** activity code unique within the year (stage 3).
- **Current:** `count + 1` (`activity-form.blade.php:199`; roll-forward the same); no unique index. (O6)
- **Target:** unique code; generation tolerant of gaps.
- **Impact:** two activities with the same code in reports and imports.
- **Risk:** medium.
- **Solution:** next code from the highest suffix; unique index on `(fiscal_year, code)` after a duplicate check. **Existing duplicates must be listed before the index migration; no production run without approval.**
- **Affected:** form, roll-forward, importer, new migration.
- **Tests:** delete a middle code then create; importer collisions.
- **Dependency:** data check of existing duplicates.

### G-13. No segregation of duties inside the activity chain
- **Requirement:** preparer, certifier, approver distinct where required (R-APP-04, stage 4).
- **Current:** no `submitted_by`; `target()` never compares actors; a head or Admin can do all steps. (O7)
- **Target:** `submitted_by` recorded; configurable rule (block or flag).
- **Impact:** self-approval is possible without a signal.
- **Risk:** medium.
- **Solution:** add `submitted_by`; rule setting in `ApprovalRules`; show an exception flag when overridden.
- **Affected:** `activities` migration, `ActivityWorkflow.php`, `ApprovalRules.php`.
- **Tests:** blocked and flagged modes; override recorded.
- **Dependency:** *(decision)* R-APP-04, R-APP-06.

### G-14. Corrections overwrite verified spending history
- **Requirement:** earlier verified values stay visible (R-EXE-03, stage 12).
- **Current:** one row per line and month overwritten via `updateOrCreate` (`execution-spending.blade.php:143-146`); earlier values only in `audit_logs.old_values`. (O8)
- **Target:** a visible correction history per figure.
- **Impact:** an auditor cannot easily see what was verified before.
- **Risk:** medium.
- **Solution:** a correction history table or a view over audit entries on the figure's page.
- **Affected:** `BudgetExecution`, spending page.
- **Tests:** correction shows earlier value, who and when.
- **Dependency:** none (view over audit is the cheapest option).

---

## P2

### G-15. Notification routing ignores lists and interim appointees
- **Requirement:** notify whoever holds the next step (R-LAN-02).
- **Current:** `ActivityWorkflow::notify` (:519-523) targets role plus `directorate_id`; only `signStage` notifies the next signer. (O9)
- **Solution:** resolve recipients through `ApproverLists` and active delegations. **Tests:** list member and interim notified. **Dependency:** none.

### G-16. No corrective-action tracking
- **Requirement:** actions with owner, due date, status (R-FRM-04, stage 13).
- **Current:** free text only (`reasons_not_met`, `corrective_measures`). (O10)
- **Solution:** a corrective-action table linked to the report period; open items shown in the next review. Schema change.
- **Tests:** create, close, overdue listing. **Dependency:** *(decision)* R-FRM-04.

### G-17. No institution-wide consolidation or baseline
- **Requirement:** conditional consolidation and a fixed baseline (R-CON-03, R-CON-04, stages 9-10).
- **Current:** not modelled.
- **Solution:** baseline marker (date plus version ids) with a configurable rule-holder review. Built only after validation. Schema change.
- **Tests:** baseline fixes totals; off by default.
- **Dependency:** *(decision)* R-APP-05, R-CON-03. No approving official is assumed.

### G-18. Cycle guidance and calendar not in the system
- **Requirement:** guidance text and calendar per cycle (R-CAL-01, stage 1).
- **Current:** free windows, open by default (`ReportingWindows.php:23-32`).
- **Solution:** cycle record with calendar dates and guidance text; windows created from it.
- **Tests:** windows follow the calendar. **Dependency:** *(decision)* R-CAL-01.

### G-19. Finance scope limited to own unit
- **Requirement:** ministry-wide finance function where it exists (R-FIN-06).
- **Current:** scope follows the unit tree (`ActivityPolicy.php:21-39`).
- **Solution:** scoped "all units" permission for Finance. **Tests:** finance verifies across directorates; others unchanged. **Dependency:** *(decision)* R-FIN-06.

### G-20. Amendments needing outside approval are not flagged
- **Requirement:** flag and reference for amendments needing outside approval (R-AMD-02, stage 14).
- **Current:** none.
- **Solution:** a flag with an external reference field on the revision. **Dependency:** *(decision)* R-AMD-02.

### G-21. Historical organisational structure not reconstructable
- **Requirement:** past reports show structure at approval time if required (H6).
- **Current:** activities store `directorate_id` only; reports read the current tree (`ApprovedPlanReport.php:25-28`).
- **Solution:** store the unit path in the version snapshot (cheap), or effective-dated structure (larger). **Dependency:** *(decision)* H6 requirement.

---

## P3

### G-22. Backups and retention
- Off-server backup with restore test (R-OPS-01); retention rule (R-RET-01). Operations, not code. Dependency: IT unit, archives office.

### G-23. Code hygiene
- Pint fails on 45 files; legacy `Waiting Approval`; table name `adjusted_budgets`; no static analysis. Low risk, improves delivery. Do after P0/P1.

---

## Irreversible migrations (H7)
Not a gap to fix but a **deployment constraint**: migrations listed in AS-IS H7 are irreversible. No migration in this plan should run against live data without a verified backup and explicit approval. Gaps G-03, G-07, G-12, G-13, G-16, G-17 add schema changes and each needs a rollout and rollback plan in Step 6.

## Suggested order
1. **G-01, G-02, G-05** (no schema change, high risk).
2. **G-03, G-04, G-06**.
3. **G-08, G-11, G-12, G-09, G-14**.
4. Decision-dependent items (**G-07, G-10, G-13**) as answers arrive.
5. P2 and P3.
