# 06. Technical Design

*Step 6 (part 1) of the improvement brief. Status: **draft for review**. Maps each requirement in `05-PRD.md` to existing Laravel files. File and line references are as of commit `17fe16c` and were read in earlier steps; they must be re-checked at the start of each implementation task. No dependency is added by this design.*

**Work classes:** `REUSE` existing code as is; `MODIFY` existing code; `ADD` new code or schema; `DEPRECATE` only with an approved migration plan (none proposed).

## 1. Requirement-to-code map

| Req | Class | Existing files | Design |
|---|---|---|---|
| FR-001 | MODIFY | `resources/views/livewire/pages/planning/paa-approval.blade.php:193-209`, `ActivityWorkflow.php` | `review($id)` calls the service transition (certify/validate path) instead of `$activity->update()`. Use the service's own authority and scope methods; drop the page-local `mayApprove` for the decision (keep it only to show or hide buttons). If the service has no method for "review one step", expose the existing `advance()` through a small public method rather than copying rules. |
| FR-002 | MODIFY | `ActivityWorkflow.php:220-256, 308-324, 395-400` | Wrap `advance()`/`approve()` in `DB::transaction`, `lockForUpdate()` the activity, re-read status inside the lock, create the version inside the same transaction. Reuse the pattern of `approveMany` (:347-348). |
| FR-003 | MODIFY + ADD (schema) | `LineItem` (soft deletes), `ActivityVersions.php`, planned-total queries in `PlanBreakdown`, `PlanCycle`, `UnitWorkspace`, `PerformanceReport`, dashboard and entities pages | **As built (PR-05):** `LineItem` uses Laravel soft deletes. A line that has an amount in force is set aside (not deleted) when a revision removes it; other lines are deleted for good. `ActivityVersions::approved` force-deletes the set-aside lines. Planned totals leave out set-aside lines; in-force totals keep them. The foreign key is unchanged. Replaces the earlier plan (FK change + `removed_in_revision` marker). |
| FR-004 | MODIFY | `ApprovedPlanReport.php:24-45, 101-115`, `approved-plan.blade.php`, `ActivityVersion` model | Read each activity's in-force `ActivityVersion` snapshot (or in-force amounts) instead of filtering `status = Approved` and loading live lines; add a "revisions pending" section. Keep the existing totals method name to avoid touching callers. |
| FR-005 | MODIFY | `execution-spending.blade.php:231-233`, `execution-progress.blade.php:113`, `execution-narrative.blade.php:118`, `Concerns/Auditable.php` | Replace builder `->update()` with a loop that saves each model (fires `Auditable`) inside one transaction, or add an explicit batch audit row listing ids. Prefer the loop for per-figure rows; limit already bounded by page size. |
| FR-006 | MODIFY | `README.md:145, 315-317`, `execution-dashboard.blade.php:73`, `approved-plan.blade.php:94, 117`, `lang/*.json` | Text changes only. New keys added to all three language files; existing guard test must pass. |
| FR-007 | MODIFY | `ActivityVersions.php:48-70`, version comparison view | Add the missing fields and a `schema` key in the snapshot JSON. Comparison code treats missing keys as "not recorded" so old versions render. No migration (JSON column). |
| FR-008 | MODIFY | `ActivityWorkflow.php:139`, `ReportingWindows.php:23-57` | In `target()`, apply the plan-window check only when `version === 0`. Optional later: a `revision` window type in `ReportingWindow` (`ADD` a constant, no schema change if `type` is a string). |
| FR-009 | MODIFY | `ActivityWorkflow::problems` (:39-55), `activity-form.blade.php:160-197`, `ActivityCsvImporter.php:256-283` | Move the completeness rules into one method (for example `ActivityWorkflow::problems` extended) and have the form and importer call it after building the model. Rules table-driven from a config array so the validated field matrix can change it without code edits. |
| FR-010 | MODIFY + ADD (schema) | `activity-form.blade.php:199`, `ActivityRollForward.php:25-110`, `ActivityCsvImporter.php`, new migration | Shared `Activity::nextCode()` using max numeric suffix; retry on unique violation. Migration: first a command that reports duplicates; then a unique index on `(fiscal_year, code)`. The migration aborts with a clear message if duplicates exist. |
| FR-011 | MODIFY | `ActivityRollForward.php:95-98` | Copy the additional indicator fields; copy `IndicatorAction` rows; re-link lines to copied indicators through an id map; add an optional status filter and a result summary object returned to the page. |
| FR-012 | ADD (schema) + MODIFY | `activities` migration, `ActivityWorkflow.php` (`target()`), `Support/ApprovalRules.php` | New nullable `submitted_by` (FK users, null on delete). Set on submit. `ApprovalRules::segregation()` returns `off|flag|block` from `SystemSetting`. `target()` checks the actor against `submitted_by`. Flag mode writes an audit note. Existing rows keep null (rule skipped when null). |
| FR-013 | MODIFY | `BudgetExecution` model, `execution-spending.blade.php:143-196` | First option: a read-only "history" panel built from `audit_logs` rows for the figure (no schema). Second option, only if the audit-based view is judged weak: a `budget_execution_revisions` table. Start with the first. |
| FR-014 | ADD (schema) **[HOLD]** | `PlanningWindow.php:66-87, 238-283`, `UnitPlanning` | New table `cycle_obligations (fiscal_year, directorate_id, status, nil_return_by, nil_return_at)` or columns on `unit_plannings`; rules per answers. Not designed in detail until the questions are answered. |
| FR-015 | MODIFY | `ActivityWorkflow::notify` (:519-523), `ApproverLists`, `ApprovalDelegation` | Resolve recipients with `ApproverLists::listFor` and active delegations; fall back to the current role+unit rule. |
| FR-016 | ADD **[HOLD]** | `ActivityReport.php` | New `corrective_actions` table. Deferred. |
| FR-017 | ADD **[HOLD]** | `ActivityVersions`, `ApprovedPlanReport` | New `plan_baselines` (date, set of version ids). Deferred; off by default. |

## 2. Schema changes (only where justified)

| Change | Reason | Reversible? | Risk and safeguard |
|---|---|---|---|
| `adjusted_budgets` FK: cascade → restrict; `line_items.removed_in_revision` nullable timestamp (FR-003) | stop silent loss of amounts in force | Yes: `down()` restores cascade and drops the column | Backup first; run on a copy and compare in-force totals before and after |
| Unique index `activities (fiscal_year, code)` (FR-010) | uniqueness | Yes: drop index | Duplicate report must be empty; migration aborts otherwise |
| `activities.submitted_by` nullable FK (FR-012) | segregation of duties | Yes: drop column | None for existing rows (null) |
| Obligation and nil-return structure (FR-014) | **[HOLD]** | n/a | Designed after decisions |
| `corrective_actions`, `plan_baselines` (FR-016/017) | **[HOLD]** | n/a | Designed after decisions |

Every migration must have a real `down()`. The previous irreversible migrations (AS-IS H7) are not touched, and none is re-run.

## 3. Cross-cutting design choices

- **Service-first authority.** No page may change `status` directly. A small test scans `resources/views` for `'status' =>` updates outside services and fails if a new one appears (guard against regressions of FR-001).
- **Transactions.** Each state transition that touches several tables is one transaction.
- **Audit.** Prefer model saves over builder updates. If a bulk builder update is unavoidable, write an explicit audit entry.
- **Configuration.** New behaviour switches (`segregation`, optional stages) are `SystemSetting` values read through `ApprovalRules`, default to the current behaviour so deployments stay unchanged until switched.
- **Backward compatibility.** Old snapshots, signatures and audit rows are never rewritten. New snapshot keys are additive.
- **Localisation.** All new text goes through `__()` with keys in `en`, `pt`, `tet`.

## 4. Test strategy

- Each requirement gets feature tests that mirror its Given/When/Then scenarios. Existing test files to extend: `ApprovalChainEndToEndTest`, `ActivityRevisionTest`, `ApprovedPlanReportTest`, `BudgetExecutionEntryTest`, `ActivityPlanningTest`, `AuditLogTest`.
- New tests: `ReviewActionAuthorizationTest` (FR-001), `ApprovalAtomicityTest` (FR-002), `RollForwardFidelityTest` (FR-011), `ActivityCodeUniquenessTest` (FR-010), `NoDirectStatusUpdateTest` (guard).
- Every test touching the database uses `RefreshDatabase` (project rule). English assertions use `app()->setLocale('en')` or `__()`.
- At the end of each task the full suite and `pint --test` are run, and the results reported as they are. Pint currently fails on 45 untouched files; new work is checked on changed files only until the style clean-up task runs.

## 5. Open technical questions

1. Should `review()` remain a separate page or fold into the standard approval inbox? (Design above keeps the page and fixes its logic.)
2. Is a `removed_in_revision` marker acceptable, or should a revision hold a full working copy of its lines? (The marker is smaller; a working copy is cleaner but larger.)
3. Per-figure audit rows for bulk verification versus one batch row: confirm preference.
