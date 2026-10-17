# 07. Implementation Backlog

*Step 6 (part 2). Status: **draft for review**. Nothing is started. Each item becomes a separate small pull request, started only after the user approves that requirement and that task (Step 7 rules). Design basis: `06-TECHNICAL-DESIGN.md`.*

**Per-task routine (from the brief):** confirm branch and working-tree state; make a focused change; add regression tests; run the relevant tests and style checks; report exact commands and real results and any check not run; review migration safety, access control, audit trail and compatibility; summarise files and risks; wait for approval before committing, pushing or deploying.

## Order and groups

| PR | Req | Title | Schema | Priority | Depends on |
|---|---|---|---|---|---|
| PR-01 | FR-001 | Route the review action through the workflow service | No | P0 | none |
| PR-02 | FR-002 | Make single approval atomic | No | P0 | none |
| PR-03 | FR-005 | Audit rows for bulk verification | No | P0 | none |
| PR-04 | FR-006 | Correct README and neutral financial labels | No | P0 | wording decision (small) |
| PR-05 | FR-003 | Keep amounts in force during a revision | **Yes** | P0 | backup, approval |
| PR-06 | FR-007 | Complete the version snapshot | No | P1 | field list |
| PR-07 | FR-004 | Report reads the version in force | No | P0 | PR-06 preferred |
| PR-08 | FR-008 | Revisions outside the plan window | No | P1 | none |
| PR-09 | FR-009 | One completeness rule | No | P1 | field matrix |
| PR-10 | FR-010 | Unique activity codes | **Yes** | P1 | duplicate report |
| PR-11 | FR-011 | Complete roll-forward | No | P1 | field and status policy |
| PR-12 | FR-012 | Preparer recorded; segregation setting | **Yes** | P1 | default mode |
| PR-13 | FR-013 | Correction history of verified figures | No | P1 | none |
| PR-14 | FR-015 | Notify the next holder | No | P2 | none |
| PR-15 | hygiene | Style clean-up (Pint) | No | P3 | after others |
| — | FR-014, 016, 017 | Obligations, corrective actions, baseline | Yes | P1/P2 | **HOLD** on decisions |

PR-01 to PR-04 need no schema change and are suggested first.

---

## PR-01 Review action through the workflow service (FR-001)
- **Scope:** replace the direct status update in `review($id)` with a service call; refuse out-of-scope ids.
- **Files:** `resources/views/livewire/pages/planning/paa-approval.blade.php`, `app/Services/ActivityWorkflow.php` (small public method if needed).
- **Tests:** `ReviewActionAuthorizationTest`: foreign-scope Director refused; list-governed unit; budget gate; audit row; regular certifier succeeds. Guard test `NoDirectStatusUpdateTest`.
- **Dependencies:** none.
- **Rollout:** deploy with code; no data change.
- **Rollback:** revert the commit.
- **Acceptance:** PRD FR-001 scenarios 1 to 4 pass; no other approval test changes result.

## PR-02 Atomic approval (FR-002)
- **Scope:** transaction and row lock around `advance()` and `approve()`.
- **Files:** `ActivityWorkflow.php`.
- **Tests:** `ApprovalAtomicityTest` (forced failure after status change leaves nothing; repeat approval is a clean no-op); existing chain tests.
- **Rollout/rollback:** code only.
- **Acceptance:** FR-002 scenarios.

## PR-03 Bulk verification audit (FR-005)
- **Scope:** per-figure audit when verifying all spending, progress and narratives.
- **Files:** the three `execution-*.blade.php` pages.
- **Tests:** first a test that records the current absence (expected red), then the fix; `AuditLogTest` extension.
- **Rollout/rollback:** code only. Existing verified rows get no retroactive audit.
- **Acceptance:** FR-005 scenarios.

## PR-04 Documentation and labels (FR-006)
- **Scope:** README notification text; neutral labels; translations.
- **Files:** `README.md`, `execution-dashboard.blade.php`, `approved-plan.blade.php`, `lang/en.json`, `pt.json`, `tet.json`.
- **Tests:** language-key guard; label assertions in report and dashboard tests.
- **Dependencies:** user chooses the neutral wording; Tetun wording flagged for review.
- **Rollout/rollback:** text only.
- **Acceptance:** FR-006.

## PR-05 Amounts in force during a revision (FR-003) — schema
- **Scope:** FK change, `removed_in_revision` marker, reconcile on approval.
- **Files:** new migration, `LineItem.php`, `ActivityVersions.php`, activity form line deletion, execution pages.
- **Tests:** delete line in revision keeps total; approve updates; return keeps; line with spending still undeletable.
- **Rollout plan:** (1) backup; (2) run the migration on a copy; (3) compare in-force totals per activity before and after; (4) approval from the user; (5) run on live data.
- **Rollback:** the migration's `down()` restores the FK and drops the column; restore from backup if totals differ.
- **Acceptance:** FR-003 scenarios; totals identical before and after the migration on the copy.

## PR-06 Complete snapshot (FR-007)
- **Scope:** add fields and a schema key to the snapshot.
- **Files:** `ActivityVersions.php`, version comparison view.
- **Tests:** new fields present; old snapshot renders.
- **Rollout/rollback:** code only (JSON column).
- **Acceptance:** FR-007.

## PR-07 Report reads the version in force (FR-004)
- **Scope:** report built from in-force version; pending revisions section.
- **Files:** `ApprovedPlanReport.php`, `approved-plan.blade.php`.
- **Tests:** `ApprovedPlanReportTest` extension: activity under revision stays; total equals dashboard.
- **Rollout:** report totals may change for years that had revisions; add a release note.
- **Rollback:** revert the commit.
- **Acceptance:** FR-004.

## PR-08 Revisions outside the plan window (FR-008)
- **Files:** `ActivityWorkflow.php`, optionally `ReportingWindows.php`.
- **Tests:** revision submittable with closed window; new activity refused.
- **Rollout/rollback:** code only.
- **Acceptance:** FR-008.

## PR-09 One completeness rule (FR-009)
- **Files:** `ActivityWorkflow.php`, `activity-form.blade.php`, `ActivityCsvImporter.php`, config for the field matrix.
- **Tests:** each mandatory field blocks submission from form, import and roll-forward paths.
- **Dependency:** the validated field matrix; until then, enforce the indicator target only.
- **Acceptance:** FR-009.

## PR-10 Unique activity codes (FR-010) — schema
- **Files:** `activity-form.blade.php`, `ActivityRollForward.php`, `ActivityCsvImporter.php`, new migration, a command that lists duplicates.
- **Tests:** gap-tolerant next code; duplicate insert refused; migration aborts on duplicates.
- **Rollout plan:** (1) run the duplicate report on live data (read-only); (2) fix or renumber duplicates with the user; (3) approve and run the index migration.
- **Rollback:** `down()` drops the index.
- **Acceptance:** FR-010.

## PR-11 Complete roll-forward (FR-011)
- **Files:** `ActivityRollForward.php`, roll-forward page.
- **Tests:** `RollForwardFidelityTest`: every listed field, actions, line-to-indicator link, status filter, summary.
- **Dependency:** the field and status policy.
- **Acceptance:** FR-011.

## PR-12 Preparer and segregation setting (FR-012) — schema
- **Files:** new migration (`submitted_by`), `ActivityWorkflow.php`, `ApprovalRules.php`, settings page.
- **Tests:** block mode; flag mode; off mode; null `submitted_by` skipped.
- **Default:** `off` so deployed behaviour is unchanged until the institution decides.
- **Rollback:** `down()` drops the column.
- **Acceptance:** FR-012.

## PR-13 Correction history (FR-013)
- **Files:** `execution-spending.blade.php`, a small audit-reading helper.
- **Tests:** earlier verified value, verifier and time shown after a correction.
- **Acceptance:** FR-013.

## PR-14 Notify the next holder (FR-015)
- **Files:** `ActivityWorkflow::notify`, `ApproverLists`, `ApprovalDelegation`.
- **Tests:** list members and interim notified; others not.
- **Acceptance:** FR-015.

## PR-15 Style clean-up
- **Scope:** run Pint on the 45 files in one commit with no behaviour change; keep it separate from feature work so diffs stay readable.
- **Tests:** full suite before and after.

## Held items
FR-014, FR-016 and FR-017 are not scheduled. Each returns to the PRD for a full specification once the related institutional answers exist.

## Release and safety notes
- Never run migrations against live data without a verified backup and explicit approval.
- No production commands are run by Claude Code in this programme.
- Each PR reports exact commands run and results; unrun checks are stated.
