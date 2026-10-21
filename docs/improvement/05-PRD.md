# 05. Product Requirements Document (PRD)

*Step 5 of the improvement brief. Requirements trace to gaps in `04-GAP-ANALYSIS.md` and to the register in `02-INSTITUTIONAL-REQUIREMENTS-REGISTER.md`. The requirement texts are unchanged; the table gives each one's status on branch `improvement`, commit `b9d286a`.*

## Status of the requirements (branch `improvement`, commit `b9d286a`)

| Requirement | Status | Acceptance scenarios | Deviation from the text below |
|---|---|---|---|
| FR-001 | **done** | 1, 2, 4 tested; 3 (unit with a certify list) covered by `ApproverListsTest` through the same service | — |
| FR-002 | **done** | 1 tested; 2 tested with a stale copy | not tested with two truly simultaneous requests |
| FR-003 | **done** | 1, 2 tested | scenario 3 ("returned and withdrawn"): the app has no withdrawal; amounts stay while the revision is open, which scenario 1 covers |
| FR-004 | **done** | 1, 2 tested; page and PDF render | — |
| FR-005 | **done** | 1 tested for all three bulk buttons; 2 by existing tests | progress and narratives have bulk *submit*, not bulk verify; both are audited |
| FR-006 | **done** | 1, 2, 3 | final wording waits for R-FIN-01 |
| FR-007 | **done** | 1, 2 tested | — |
| FR-008 | **done** | 1, 2 tested | a revision-specific window is not built (R-AMD-05) |
| FR-009 | **partly done** | name, unit and target | the field matrix waits for R-FRM-01 |
| FR-010 | **done** | 1, 2 tested | uniqueness is per **unit** and year, not per year: official codes repeat across units |
| FR-011 | **done** | 1, 2, 3 tested | the result summary is the existing `copied` / `skipped` count; no page change for the status filter |
| FR-012 | **done** | block, flag, off and null preparer tested | in block mode an administrator is not refused; the override is marked |
| FR-013 | **done** | tested on the service and the page | built as a view over the audit trail (the design's first option) |
| FR-014 | **on hold** | — | R-OBL-01 to R-OBL-04 |
| FR-015 | **done** | director, list member, interim, no double notice | — |
| FR-016 | **on hold** | — | R-FRM-04 |
| FR-017 | **on hold** | — | R-APP-05, R-CON-03, R-CON-04 |

Non-functional (Part B): auditability and maintainability improved (audit of bulk actions, Pint passes, guard tests); numeric targets are still to be confirmed.


**Conventions.** IDs are `PAA-FR-nnn`. "Gap" and "Reg" give the trace. Requirements marked **[HOLD]** depend on an unanswered institutional question and must not be built until it is answered. Acceptance criteria are written as Given / When / Then scenarios. Statuses used in scenarios are the existing ones (Draft, Prepared, Verified, Certified, Approved, Returned). No numeric service levels are invented: those are marked for confirmation in the non-functional section.

**Common to all requirements unless stated otherwise:**
- Every state change writes an audit entry (user, time, old and new values, reason where required).
- Server-side authorisation is enforced by the service layer, not only by the page.
- Existing approved versions, signatures and audit rows are never edited or deleted.
- Schema changes need a backup and explicit approval before they run on any data of value.

---

## Part A. Functional requirements

### PAA-FR-001 One authority check for approval steps
- **Statement:** The system shall perform every approval-chain transition through `ActivityWorkflow`, so that the same authority, scope, list, signing-order and budget-confirmation rules apply from every screen.
- **Trace:** G-01; Reg R-APP-08; stages 4, 5.
- **Rationale:** `paa-approval::review` changes the status directly with a user-level check only.
- **Actor:** Director, listed certifier, Administrator.
- **Preconditions:** the activity is Prepared or Verified.
- **Main flow:** the user presses the review action; the page calls the service; the service checks scope and rules; the status changes; audit and notification follow.
- **Alternative/error flows:** the activity is outside the user's scope: refused with a message and no change. The budget is unconfirmed while confirmation is required: refused. A list exists and the user is not on it: refused.
- **Business rules:** parent validation follows the `parentValidation` setting, not "a grandparent exists".
- **Data/audit:** same fields as the service; audit event `activity.status_changed`.
- **Permissions:** unchanged from the service.
- **Notifications/reports:** same as the service.
- **Acceptance:**
  1. *Given* a Director of unit A and an activity of unit B outside their scope, *when* they call the review action with its id, *then* nothing changes and an error is shown.
  2. *Given* budget confirmation is on and unconfirmed, *when* a certifier reviews, *then* the activity stays Prepared.
  3. *Given* a unit with a certify list the user is not on, *when* they review, *then* it is refused.
  4. *Given* a valid certifier, *when* they review, *then* the result equals the one from the standard approval page.
- **Priority:** P0. **Dependency:** none.
- **Affected code:** `paa-approval.blade.php:193-209`, `ActivityWorkflow.php`.
- **Compatibility:** no data change.

### PAA-FR-002 Atomic approval and version creation
- **Statement:** The system shall change an activity to Approved and create its version in one database transaction with a row lock.
- **Trace:** G-02; stage 7.
- **Actor:** approver, Administrator.
- **Preconditions:** the activity is awaiting final approval.
- **Main flow:** lock the activity; re-check status and authority; set status and approval fields; create the version and amounts in force; commit.
- **Alternative/error flows:** version creation fails: everything rolls back, status unchanged, error shown. A second approver acts after the first: shown as already approved, no error state.
- **Business rules:** exactly one version per approval; unique `(activity, version)` stays.
- **Data/audit:** `activity.approved` with version number.
- **Acceptance:**
  1. *Given* version creation throws, *when* an approver approves, *then* status is not Approved and no partial rows exist.
  2. *Given* two simultaneous approvals, *when* both run, *then* one version exists and the second reports "already approved".
- **Priority:** P0. **Dependency:** none.
- **Affected code:** `ActivityWorkflow.php:220-256, 308-320, 395-400`.
- **Compatibility:** no data change.

### PAA-FR-003 Amounts in force are stable during a revision
- **Statement:** The system shall keep the amounts in force of an approved activity unchanged until a revision of it is approved, including when lines are deleted or edited in the revision.
- **Trace:** G-03; Reg R-AMD-03, R-DEC-05; stage 14.
- **Actor:** Technical Officer (editing); approver (approving).
- **Main flow:** the revision edits a working copy of the lines; amounts in force remain; on approval the new set replaces them and removed lines are recorded as removed.
- **Alternative flows:** the revision is returned or abandoned: amounts in force unchanged.
- **Business rules:** a line with reported spending cannot be deleted (existing rule); an in-force amount is never removed by a cascade.
- **Data/audit:** a "removed in revision" marker; audit of the removal.
- **Acceptance:**
  1. *Given* an approved activity with 50,477 in force, *when* a line is deleted in a revision, *then* the in-force total is still 50,477.
  2. *When* the revision is approved, *then* the in-force total equals the new line total.
  3. *When* the revision is returned and withdrawn, *then* the in-force total is unchanged.
- **Priority:** P0. **Dependency:** backup and approved migration for the foreign-key change.
- **Affected code:** `adjusted_budgets` migration, `LineItem`, `ActivityVersions.php`, execution pages.
- **Compatibility:** existing rows stay; migration must not delete data; rollback plan required in Step 6.

### PAA-FR-004 Approved-plan report reflects the version in force
- **Statement:** The system shall build the approved-plan report from the version in force of each activity, and shall show any pending revision separately.
- **Trace:** G-04; Reg R-CON-04; stages 10, 14.
- **Actor:** any user with report access.
- **Main flow:** for each activity, read its in-force version; list pending revisions in a separate section.
- **Business rules:** totals equal the sum of amounts in force; an activity under revision stays in the report.
- **Acceptance:**
  1. *Given* an approved activity under revision, *when* the report is produced, *then* the activity appears with its in-force values and the revision is listed separately.
  2. *Then* the report total equals the dashboard "plan in force" total.
- **Priority:** P0. **Dependency:** PAA-FR-007 for full fidelity.
- **Affected code:** `ApprovedPlanReport.php`, `approved-plan.blade.php`.
- **Compatibility:** none; report output changes and must be noted in the release notes.

### PAA-FR-005 Audit of bulk verification
- **Statement:** The system shall write an audit entry for every figure verified in bulk (spending, progress, narrative).
- **Trace:** G-05; Reg R-EXE-05; stage 12.
- **Preconditions:** the verifier is not the submitter (existing rule).
- **Main flow:** bulk action verifies each figure; one audit entry per figure, or one batch entry listing every id.
- **Acceptance:**
  1. *Given* five submitted spending rows, *when* a verifier verifies all, *then* five audit entries (or one batch entry with five ids) exist.
  2. *Given* the verifier submitted a row, *then* it is not verified.
- **Priority:** P0. **Dependency:** a test first confirms the current absence.
- **Affected code:** `execution-spending/progress/narrative.blade.php`, `Auditable`.

### PAA-FR-006 Truthful documentation and neutral labels
- **Statement:** The system and its documents shall describe notification delivery accurately and shall not label planned amounts as an authorised budget.
- **Trace:** G-06; Reg R-FIN-01, R-FIN-02.
- **Main flow:** correct README statements about queued notifications; replace "Approved budget" and "Budget Proposal" labels with neutral wording until the institution defines its terms.
- **Acceptance:**
  1. *Then* README states that notifications are in-app and synchronous.
  2. *Then* no screen shows "Approved budget" without a validated definition.
  3. *Then* every new label exists in `lang/en.json`, `pt.json`, `tet.json` (existing guard test passes).
- **Priority:** P0. **Dependency:** R-FIN-01 for the final wording **[HOLD on wording only]**.
- **Affected code:** `README.md`, `execution-dashboard.blade.php:73`, `approved-plan.blade.php:94, 117`, `lang/*.json`.

### PAA-FR-007 Complete version snapshot
- **Statement:** The system shall store in each version snapshot every activity, indicator and budget-line field that affects its meaning.
- **Trace:** G-09; Reg R-AMD-04, R-OGE-03; stage 14.
- **Business rules:** snapshot carries a schema version; old snapshots are left untouched and rendered as they are.
- **Fields to add:** activity `location`, `procurement_required`, `gender_responsive`, `period_type`, `quarter`; indicator `measure`, `target_text`; line `code`, `account_code`.
- **Acceptance:**
  1. *Given* a newly approved activity, *then* the snapshot contains each listed field.
  2. *Given* a pre-existing version, *then* it still displays and compares without error.
- **Priority:** P1. **Dependency:** required-set confirmation (R-OGE-03) **[HOLD on the final list]**.
- **Affected code:** `ActivityVersions.php:48-70`, version comparison view.

### PAA-FR-008 Revisions follow their own window rule
- **Statement:** The system shall let authorised unit staff submit a revision (activity with `version > 0`) outside the plan-submission window, under a revision rule.
- **Trace:** G-08; Reg R-AMD-05, R-CAL-04.
- **Alternative flows:** the revision rule closed: only Administrator may submit.
- **Acceptance:**
  1. *Given* the plan window is closed, *when* a Technical Officer submits a revision, *then* it becomes Prepared.
  2. *Given* the same window, *when* they submit a new activity, *then* it is refused.
- **Priority:** P1. **Dependency:** R-AMD-05 **[HOLD on any revision-specific window; the exemption itself can proceed]**.
- **Affected code:** `ActivityWorkflow.php:139`, `ReportingWindows.php`.

### PAA-FR-009 One completeness rule for every entry path
- **Statement:** The system shall apply one completeness check, driven by a defined field list, in the form, the importer, the roll-forward and the submit transition.
- **Trace:** G-11; Reg R-FRM-01, R-FRM-02.
- **Business rules:** an indicator needs a target before submission (zero allowed unless the institution says otherwise).
- **Acceptance:**
  1. *Given* an indicator with no target, *when* submit is attempted from any path, *then* it is refused with the listed problem.
  2. *Then* the message text matches the check.
- **Priority:** P1. **Dependency:** field matrix R-FRM-01 **[HOLD on the matrix]**.
- **Affected code:** `ActivityWorkflow::problems`, `activity-form.blade.php`, `ActivityCsvImporter.php`.

### PAA-FR-010 Unique activity codes
- **Statement:** The system shall guarantee that an activity code is unique within a fiscal year.
- **Trace:** G-12; stage 3.
- **Main flow:** new code uses the highest existing suffix plus one; conflicts retry.
- **Alternative flows:** existing duplicates are listed for the Administrator before the unique index is applied.
- **Acceptance:**
  1. *Given* codes -001, -002, -003 and -002 deleted, *when* a new activity is created, *then* it receives -004.
  2. *When* an insert would duplicate a code, *then* it is refused.
- **Priority:** P1. **Dependency:** pre-migration duplicate report.
- **Affected code:** form, roll-forward, importer, new migration.
- **Compatibility:** the index migration must fail safely if duplicates exist.

### PAA-FR-011 Complete roll-forward
- **Statement:** The system shall copy into the next year a defined set of fields and relations of selected activities and shall report what was and was not copied.
- **Trace:** G-10; Reg R-FRM-06; stage 15.
- **Fields to add:** baseline, means of verification, target text, `due_quarters`, actions and months, line-to-indicator link; a status filter.
- **Business rules:** copies are Drafts; no approvals or execution are copied.
- **Acceptance:**
  1. *Given* an indicator with baseline, means and due quarters, *when* rolled forward, *then* the copy has the same values.
  2. *Given* a line linked to an indicator, *then* the copy keeps the link.
  3. *Then* a summary shows the counts copied and skipped.
- **Priority:** P1. **Dependency:** R-FRM-06 **[HOLD on status and field policy]**.
- **Affected code:** `ActivityRollForward.php:95-98`, roll-forward page.

### PAA-FR-012 Preparer recorded; segregation of duties
- **Statement:** The system shall record who prepared an activity and shall enforce or flag the rule that the preparer differs from the certifier and approver.
- **Trace:** G-13; Reg R-APP-04, R-APP-06.
- **Business rules:** mode is a setting: `block`, `flag`, or `off`. In `flag` mode, an Administrator or head override is allowed and marked in the audit.
- **Acceptance:**
  1. *Given* mode `block`, *when* the preparer tries to certify, *then* it is refused.
  2. *Given* mode `flag`, *then* the action succeeds and the record carries an exception flag.
- **Priority:** P1. **Dependency:** R-APP-04 **[HOLD on default mode]**.
- **Affected code:** `activities` migration (`submitted_by`), `ActivityWorkflow.php`, `ApprovalRules.php`.

### PAA-FR-013 Visible correction history of verified figures
- **Statement:** The system shall show, for any verified figure that was corrected, each earlier verified value with who verified it and when.
- **Trace:** G-14; Reg R-EXE-03; stage 12.
- **Acceptance:**
  1. *Given* a verified figure reopened and re-entered, *then* the figure's page lists the earlier value, verifier and time.
- **Priority:** P1. **Dependency:** none (can read from audit).
- **Affected code:** `BudgetExecution`, spending page.

### PAA-FR-014 Submission obligations and nil returns **[HOLD]**
- **Statement:** The system shall list, per cycle, the units obliged to submit, and shall let a directorate record a nil return that counts as finalisation.
- **Trace:** G-07; Reg R-OBL-01 to R-OBL-04; stages 2, 8.
- **Acceptance (draft):** a unit without activities appears as "not started"; a recorded nil return lets the year end; a dissolved unit is excluded.
- **Priority:** P1. **Dependency:** R-OBL-01 to R-OBL-03 answers.
- **Affected code:** `PlanningWindow.php`, `UnitPlanning`, new table.

### PAA-FR-015 Notifications reach the next holder
- **Statement:** The system shall notify the users who currently hold the next step, including approver-list members and active interim appointees.
- **Trace:** G-15; Reg R-LAN-02.
- **Acceptance:** *Given* a list of two certifiers and one interim, *when* an activity is submitted, *then* each current holder receives a notification and nobody else does.
- **Priority:** P2. **Affected code:** `ActivityWorkflow::notify`, `ApproverLists`.

### PAA-FR-016 Corrective actions **[HOLD]**
- **Statement:** The system shall let a Director record corrective actions with description, owner, due date and status against an off-track report, and list open actions in the next review.
- **Trace:** G-16; Reg R-FRM-04. **Priority:** P2. **Dependency:** institutional agreement.

### PAA-FR-017 Baseline and optional consolidation review **[HOLD]**
- **Statement:** The system shall be able to record a baseline of approved versions at a date and, only if configured, a review decision by a configured rule-holder.
- **Trace:** G-17; Reg R-APP-05, R-CON-03, R-CON-04. **Priority:** P2.
- **Rule:** no approver is assumed; the feature is off until configured.

*Further P2/P3 gaps (G-18 to G-23) are specified only when their questions are answered.*

---

## Part B. Non-functional requirements

Numeric targets are **not invented**; each is marked for confirmation by the institution or IT unit.

| Area | Requirement | Measure |
|---|---|---|
| Security | Authorisation is enforced in services; all approval entry points share one check; login and two-factor throttling stay | Review and tests; no direct status updates outside services |
| Security | No secrets in repository, logs or documents | Review |
| Performance | Pages for lists, My work and reports stay responsive at the planned data volume; earlier tests used about 3,000 activities | **Target to be confirmed** |
| Availability | Planning and reporting windows have defined availability expectations | **To be confirmed by IT unit** |
| Usability | Errors state the reason and the next step; reasons are required when returning or revising | User testing |
| Accessibility | Pages work with keyboard and screen reader basics; an automated check is added | **Standard to be confirmed**; today unverified (earlier audit M7) |
| Localisation | Every new string exists in English, Portuguese, Tetun; Tetun reviewed by a competent speaker | Existing guard test; human review |
| Auditability | Every state change and bulk action is audited; audit log append-only in the application | Tests |
| Backups/recovery | Off-server backups and rehearsed restore before any schema change | **Frequency and retention to be confirmed** (R-OPS-01, R-RET-01) |
| Maintainability | Style gate passes; new rules have regression tests; small pull requests | CI |

## Part C. Traceability

| Requirement | Gap | Register |
|---|---|---|
| PAA-FR-001 | G-01 | R-APP-08 |
| PAA-FR-002 | G-02 | R-DEC-05 |
| PAA-FR-003 | G-03 | R-AMD-03 |
| PAA-FR-004 | G-04 | R-CON-04 |
| PAA-FR-005 | G-05 | R-EXE-05 |
| PAA-FR-006 | G-06 | R-FIN-01, R-FIN-02 |
| PAA-FR-007 | G-09 | R-AMD-04, R-OGE-03 |
| PAA-FR-008 | G-08 | R-AMD-05 |
| PAA-FR-009 | G-11 | R-FRM-01, R-FRM-02 |
| PAA-FR-010 | G-12 | none (integrity) |
| PAA-FR-011 | G-10 | R-FRM-06 |
| PAA-FR-012 | G-13 | R-APP-04, R-APP-06 |
| PAA-FR-013 | G-14 | R-EXE-03 |
| PAA-FR-014 | G-07 | R-OBL-01 to R-OBL-04 |
| PAA-FR-015 | G-15 | R-LAN-02 |
| PAA-FR-016 | G-16 | R-FRM-04 |
| PAA-FR-017 | G-17 | R-APP-05, R-CON-03, R-CON-04 |
