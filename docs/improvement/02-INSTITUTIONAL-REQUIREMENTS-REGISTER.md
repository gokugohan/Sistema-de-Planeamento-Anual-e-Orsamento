# 02. Institutional Requirements Register

*Step 2 of the business process improvement brief. Status: **open**: no institutional answer has been received yet, so every `TO VALIDATE` entry is still open. Builds on `01-AS-IS-CURRENT-STATE.md` (references such as H5 or O1 point to that document). The section below says which entries the software work on branch `improvement`, commit `b9d286a` has addressed.*

## Status after implementation (branch `improvement`, commit `b9d286a`)

Only the software side has moved. An entry marked `TO VALIDATE` stays so until the named office answers; where the branch made the behaviour configurable, the answer now only sets a value.

| ID | What the branch did | Still open |
|---|---|---|
| R-APP-08 | **Done.** The Review action goes through the workflow service; a guard test prevents new direct status writes. | — |
| R-AMD-03 | **Done.** In-force amounts stay until a revision is approved. | — |
| R-AMD-04 | **Done.** Snapshots hold every descriptive field. | Which fields are mandatory on the official form (R-OGE-03). |
| R-AMD-05 | **Partly.** Revisions are no longer held back by the plan window. | Whether revisions need their own window. |
| R-APP-04 | **Ready.** The preparer is recorded; the rule can be off, flag or block (default off). | Which mode the institution requires. |
| R-APP-06 | Overrides by an administrator in block mode are marked in the audit trail. | Whether skip-level certification and administrator override are acceptable. |
| R-FIN-02 | **Done.** Neutral wording for planned amounts. | R-FIN-01: what may be called "approved" or "budget". |
| R-FRM-02 | **Done** for name, unit and target. | R-FRM-01: the full mandatory-field matrix. |
| R-FRM-06 | **Done** for the fields; a status filter exists. | Which statuses are copied by default. |
| R-EXE-03 | **Done.** Earlier verified values are shown. | — |
| R-EXE-05 | **Done.** One audit row per figure in bulk actions. | — |
| R-LAN-02 | **Done.** Holders of the next step, including list members and interim appointees, are told. | — |
| R-CON-04 | **Partly.** The report reads the version in force. | A formal baseline marker (FR-017, on hold). |
| R-OPS-02 | The three new migrations are reversible and were not run on any database of value. | Backup and a run on a copy before production. |
| R-LAN-01 | New Portuguese and Tetun texts were added. | Review by a competent speaker. |

Unchanged and still blocking the held work: R-OBL-01 to R-OBL-04 (submission obligations, nil returns), R-APP-05 and R-CON-03 (any consolidation approval; **no approving official is assumed**), R-FRM-04 (corrective actions), R-CAL-01 and R-CAL-02 (official calendar), R-FIN-01, R-FIN-04 to R-FIN-06, R-AMD-02, R-RET-01 to R-RET-03.

New fact from the work: official budget-book activity codes repeat across units in a year, so activity codes are unique per unit and year (01, O12).


## How to read this register

- **Status values:**
  - `CONFIRMED`: stated by the project owner in this engagement, or by a source quoted in the "Source" column. It is a design decision or fact for this project. It is **not** a legal finding.
  - `TO VALIDATE`: the software assumes or implies it, or it is plausible, but no rule has been seen. It must be confirmed by the named office before anyone relies on it.
  - `RECOMMENDATION`: a proposal from this analysis. It is not an institutional rule.
- No Timor-Leste legal requirement is asserted here. Where a law is named, it is only as the project owner supplied it, and its content has not been re-read in this step.
- **The Minister, or any other official, is not assumed to approve the consolidated plan.** That question is R-APP-05 and stays `TO VALIDATE`.
- "Accountable to validate" names a kind of office, not a person. The project owner must assign actual names.

## A. Confirmed project decisions (not legal findings)

| ID | Requirement / rule | Source | Status | Accountable to validate | Impact if unresolved | Stage / software area |
|---|---|---|---|---|---|---|
| R-DEC-01 | The application is institution-neutral. MTC data is optional sample data only. | Project owner; project memory | CONFIRMED | Project owner | Institution-specific rules hard-coded and wrong for other bodies | All; seeders (`database/seeders/Samples/`) |
| R-DEC-02 | The annual plan is the centre of the system. There is no fiscal-envelope distribution and no committee-decision stage in the software. | Project owner decision (envelope tables dropped, commit `36a4e7d`) | CONFIRMED | Project owner | Reintroducing removed concepts, or reports assuming them | Whole cycle; `ActivityWorkflow` |
| R-DEC-03 | Approval chain is prepare, certify, approve, with optional finance budget confirmation and optional parent-unit validation. | Project owner; `ApprovalRules.php`, `ActivityWorkflow.php:136-182` | CONFIRMED (as software design) | Project owner; each institution to confirm its own chain | Wrong signers in real use | S2-S5 |
| R-DEC-04 | The Minister has no approval right in the activity chain. Roles are named by position (Technical Officer, Finance Officer, Director, Administrator, Minister, Viewer). | Project owner statements | CONFIRMED (as software design) | Project owner | Over- or under-authorising senior users | Roles seeder |
| R-DEC-05 | Approved activities are versioned and change only through a revision with a reason. | Project owner decision | CONFIRMED | Project owner | Silent edits to approved plans | S9; `ActivityVersions` |
| R-DEC-06 | Execution figures (spending, progress, narratives) are counted only when verified by someone other than the submitter. | Project owner; code | CONFIRMED (as software design) | Project owner | Unverified numbers in reports | S11-S12 |
| R-DEC-07 | For the MTC sample, the structure follows DL 75/2023 as amended by DL 12/2026, only to the level of Direcao Nacional/Unidade. Departments are not invented. | Project memory (verified with user earlier) | CONFIRMED (sample data only) | Project owner / MTC legal office | Invented structure in sample data | Sample seeders |
| R-DEC-08 | The programme structure follows the OGE plan books (programme, outcome, subprogramme, output, activity; division and funding-source codes). | Project owner showed the books; code mirrors them in part | TO VALIDATE (fields not fully reproduced) | Planning office / Ministry of Finance budget unit | Reports do not match the official layout | Programme model, reports |

## B. Calendar and obligation to submit

| ID | Requirement / rule | Source | Status | Accountable to validate | Impact if unresolved | Stage / software area |
|---|---|---|---|---|---|---|
| R-CAL-01 | An official annual planning calendar with deadlines exists (circular, guidance) and the software should reflect its dates. | None seen. The software has a free plan window, open by default (`ReportingWindows.php:23-32`) | TO VALIDATE | Planning office; Ministry of Finance circular owner | Windows set by hand and out of line with the official calendar | S8; windows |
| R-CAL-02 | Monthly and quarterly reporting deadlines are set by an institutional rule. | None seen. Windows are admin-set | TO VALIDATE | Planning office; finance office | Late or unbounded reporting | S11; windows |
| R-CAL-03 | The fiscal year is the calendar year. | Software default (`FiscalYear.php`) | TO VALIDATE | Finance office | Wrong period boundaries | Setup |
| R-CAL-04 | Within a cycle, planning, execution and revision overlap by design (revisions allowed after the plan window). | O4 shows revisions blocked after the window for non-admins | RECOMMENDATION: revisions get their own window rule | Planning office | Every post-season change becomes an administrator task | S8-S9 |
| R-OBL-01 | Every organisational unit above a defined level must submit a plan, or declare a nil return, each year. | None seen. `plannedRoots` comes from existing activities only (H5) | TO VALIDATE | Planning office / legal office | A silent unit looks the same as a finished one; year can close without it | S8; `PlanningWindow.php:66-69` |
| R-OBL-02 | A nil return is a recognised formal outcome with its own record and sign-off. | None seen | TO VALIDATE | Planning office | Finalisation impossible for units with nothing to plan (`:251-253`) | S8 |
| R-OBL-03 | Units created, merged or dissolved during the year have defined submission duties. | `Directorate` has `established_year`/`dissolved_year`, ignored by the planning service (H5) | TO VALIDATE | Corporate services; legal office | Wrong expected-submitter list | S8; `Directorate.php` |
| R-OBL-04 | The level at which finalisation happens is the directorate. | Software design (`UnitPlanning`) | TO VALIDATE | Planning office | Wrong consolidation boundary | S8 |

## C. Approval and delegated authority

| ID | Requirement / rule | Source | Status | Accountable to validate | Impact if unresolved | Stage / software area |
|---|---|---|---|---|---|---|
| R-APP-01 | The head of a top-level unit (or a named list, or Administrator) gives final activity approval. | Software default (`ActivityWorkflow.php:175-182, 296-301`) | TO VALIDATE per institution | Head of institution / legal office | Activities approved by the wrong person | S5 |
| R-APP-02 | Delegation of certify/approve to an interim appointee is permitted, and for what duration. | Software (`ApprovalDelegation`); no source | TO VALIDATE | Legal office / human resources | Invalid signatures if delegation is not allowed | S7 |
| R-APP-03 | Delegation of the prepare step is not permitted. | Software behaviour | RECOMMENDATION (keep) | Planning office | None if kept | S7 |
| R-APP-04 | Segregation of duties: the preparer must differ from the certifier and approver. | None. The activity chain has no `submitted_by`, and a head or administrator may do all steps (O7) | TO VALIDATE | Internal audit / legal office | Self-approval possible without an audit signal | S2-S5; `ActivityWorkflow` |
| R-APP-05 | Whether any consolidated plan needs approval above directorate level (by a minister, secretary-general or other), and by whom. | **No rule seen. The software does not model it.** | TO VALIDATE | Head of institution / legal office | Either a missing stage or an invented one | Between S8 and S9 of the target process |
| R-APP-06 | Skip-level certification (a head certifying a unit two levels down) and administrator override are acceptable. | Software behaviour (`directsUnit`, `ActivityWorkflow.php:507-517`) | TO VALIDATE | Internal audit | Weak control over who certified | S4 |
| R-APP-07 | A returned activity requires a reason and previous signatures are voided. | Software behaviour | RECOMMENDATION (keep) | Planning office | None if kept | S6 |
| R-APP-08 | The `paa-approval` review action must follow the same authority rules as the workflow service. | Code finding O1 | RECOMMENDATION (urgent, P0 candidate) | Project owner; technical lead | A user may advance an activity outside their scope | S4; `paa-approval.blade.php:193-209` |

## D. Classifications and official codes

| ID | Requirement / rule | Source | Status | Accountable to validate | Impact if unresolved | Stage / software area |
|---|---|---|---|---|---|---|
| R-OGE-01 | Official codes for programme, outcome, subprogramme, output, division and funding source are fixed by the national budget book of the year. | OGE books seen by project owner; app stores programme and subprogramme codes, not outcome, output or division codes | TO VALIDATE | Ministry of Finance budget unit | Plans cannot be reconciled with the budget book | Programme model; reports |
| R-OGE-02 | Economic categories used are the official OGE categories (salaries and wages, goods and services, public transfers, minor capital, capital and development). | Seeder defines categories | TO VALIDATE | Ministry of Finance | Wrong category mix in reports | `LineItem`; categories seeder |
| R-OGE-03 | Markers (gender, procurement, location) are required on the official form. | Fields exist (`procurement_required`, `gender_responsive`, `location`) but are omitted from snapshots (H4) | TO VALIDATE | Planning office | Missing data in the form and in version history | `ActivityVersions::snapshot` |
| R-OGE-04 | Sample programme codes (`MTC-001..007`) are placeholders and carry no official standing. | Project memory | CONFIRMED | Project owner | Placeholders mistaken for official codes | Sample data |

## E. PAA approval versus legal budget authorisation

| ID | Requirement / rule | Source | Status | Accountable to validate | Impact if unresolved | Stage / software area |
|---|---|---|---|---|---|---|
| R-FIN-01 | Approval of the activity plan is an internal planning decision. It is not the legal authorisation of an allocation. | Project owner decision (no envelope); labels are mixed (H8) | TO VALIDATE (wording and meaning) | Ministry of Finance; legal office | Readers treat planned amounts as authorised funds | Report and dashboard labels |
| R-FIN-02 | The report title should not call planned amounts a "budget" or "approved budget" unless an authorised amount is held. | `approved-plan.blade.php:94, 117`; `execution-dashboard.blade.php:73` | RECOMMENDATION | Planning office; finance office | Misleading labels | S13 |
| R-FIN-03 | The application has no link to the Ministry of Finance financial system. Spending is entered by hand and verified internally. | `README.md:419-420` | CONFIRMED (current state) | Finance office | Duplicate entry and drift from the official ledger | S11 |
| R-FIN-04 | A future reconciliation or integration with the financial system is wanted. | None | TO VALIDATE | Ministry of Finance IT unit | Unnecessary build, or none when needed | Future area |
| R-FIN-05 | Finance confirmation of budget lines before certification is required, and for which units. | Software option, default off (`ApprovalRules.php:21-24`) | TO VALIDATE | Finance office | Wrong default | S3 |
| R-FIN-06 | The finance function is ministry-wide, not limited to one unit. | Software scopes finance officers by unit (earlier audit M6) | TO VALIDATE | Finance office | Finance officers cannot verify across directorates | S3, S12; scope policy |

## F. Forms, templates and indicators

| ID | Requirement / rule | Source | Status | Accountable to validate | Impact if unresolved | Stage / software area |
|---|---|---|---|---|---|---|
| R-FRM-01 | The official form lists mandatory fields per activity, indicator and budget line. | `Sample_Plano.xlsx` (untracked, not analysed in this step); form rules `activity-form.blade.php:160-197` | TO VALIDATE | Planning office | Submission checks differ from the form (H2) | S1-S2 |
| R-FRM-02 | An indicator must have a target (zero allowed or not) before submission. | Message says so (`ActivityWorkflow.php:48`); check does not | TO VALIDATE, then align message and check | Planning office | Submission with an empty indicator | S2 |
| R-FRM-03 | Required reporting templates exist for monthly, quarterly and annual reports. | Software implements its own reports | TO VALIDATE | Planning office; finance office | Reports not accepted upstream | S13 |
| R-FRM-04 | Corrective measures must be tracked with owner, due date and status. | Currently free text only (O10) | RECOMMENDATION (needs institutional agreement) | Planning office; directors | Findings never closed | Future stage 13 |
| R-FRM-05 | Rating scale On track, Partly, Off track is the institution's scale. | Software (`ActivityReport.php:17`) | TO VALIDATE | Planning office | Non-comparable ratings | S11 |
| R-FRM-06 | Roll-forward must copy defined fields and statuses into the next year. | Partial copy (H3) | TO VALIDATE (which fields, which statuses) | Planning office | Silent loss of baselines, means, actions | S10; `ActivityRollForward.php` |

## G. Finalisation and consolidation

| ID | Requirement / rule | Source | Status | Accountable to validate | Impact if unresolved | Stage / software area |
|---|---|---|---|---|---|---|
| R-CON-01 | A directorate finalises its plan when all its activities are decided; finalisation stops new activities but not revisions. | Software (`PlanningWindow.php:238-262`) | TO VALIDATE | Planning office | Premature or late closure | S8 |
| R-CON-02 | The year counts as ended when every directorate with activities has finalised. | Software (`endedBy`, `PlanningWindow.php:80-87`) | TO VALIDATE (see H5) | Planning office | Year closes with units missing | S8 |
| R-CON-03 | An institution-wide consolidation or review step exists between directorate finalisation and publication. | None seen | TO VALIDATE | Head of institution; planning office | Step missing, or built without basis | Between S8 and baseline |
| R-CON-04 | A formal baseline (the published approved plan for a year) exists, and reports refer to a fixed baseline during revisions. | Not modelled. The report shows live data and drops activities under revision (H1) | RECOMMENDATION | Planning office | Report totals change, or drop, during a revision | S13; `ApprovedPlanReport.php:24-27` |

## H. Amendments after approval

| ID | Requirement / rule | Source | Status | Accountable to validate | Impact if unresolved | Stage / software area |
|---|---|---|---|---|---|---|
| R-AMD-01 | Changes to approved activities need a recorded reason and re-approval. | Software (`reopen`, `ActivityWorkflow.php:476-486`) | TO VALIDATE (reason length, who may open) | Planning office; legal office | Weak or excessive control | S9 |
| R-AMD-02 | Some amendments (budget transfers between lines, programmes or economic categories) need a higher authority or the finance ministry. | None seen | TO VALIDATE | Ministry of Finance; legal office | Local approval of changes that need outside approval | S9 |
| R-AMD-03 | The in-force amount must not change before a revision is approved. | Project design (R-DEC-05); contradicted by O5 | CONFIRMED (as design); defect to fix | Project owner | Reports and spending limits change mid-revision | S9; `adjusted_budgets` cascade |
| R-AMD-04 | A version snapshot must contain every field that affects meaning, not a subset. | Current snapshot omits several fields (H4) | RECOMMENDATION | Project owner; auditors | Past versions cannot be reproduced faithfully | `ActivityVersions::snapshot` |
| R-AMD-05 | Revisions after the planning window are allowed for unit staff within defined limits. | O4 | TO VALIDATE | Planning office | Revisions blocked or uncontrolled | S8-S9 |

## I. Financial reporting and execution

| ID | Requirement / rule | Source | Status | Accountable to validate | Impact if unresolved | Stage / software area |
|---|---|---|---|---|---|---|
| R-EXE-01 | Execution is reported monthly as committed and paid per budget line. | Software (`execution-spending.blade.php`) | TO VALIDATE (committed/paid are the official terms and stages) | Finance office | Reporting stages differ from the ledger | S11 |
| R-EXE-02 | Committed cannot exceed the amount in force; paid cannot exceed committed. | Software (`:137-141`) | TO VALIDATE (overspend and transfers rules) | Finance office | Legitimate changes blocked, or overspending allowed | S11 |
| R-EXE-03 | A verified figure that is later corrected keeps its earlier values as a visible history. | O8: only `audit_logs.old_values` | RECOMMENDATION | Internal audit | Prior verified values are hard to find | S12 |
| R-EXE-04 | Quarterly indicator progress is cumulative or level, as defined per indicator. | Software | TO VALIDATE | Planning office | Misreported progress | S11 |
| R-EXE-05 | Bulk verification must leave an audit record per figure. | O3 | RECOMMENDATION | Internal audit | Verification invisible in the audit trail | S12; `execution-*.blade.php` |

## J. Retention, audit, security, language

| ID | Requirement / rule | Source | Status | Accountable to validate | Impact if unresolved | Stage / software area |
|---|---|---|---|---|---|---|
| R-RET-01 | Records (plans, versions, signatures, execution, audit log) are kept for a stated number of years. | None. No retention policy exists in code or documents | TO VALIDATE | Archives office; legal office | Records lost or kept without basis | All; audit tables |
| R-RET-02 | Approved plans and audit logs must be tamper-evident beyond the application. | The application cannot edit the audit log, but database access can (earlier audit L4) | TO VALIDATE | Internal audit; IT | Weaker assurance than required | Audit trail |
| R-RET-03 | Personal data (IP addresses, user names) handling follows a data-protection rule. | None seen | TO VALIDATE | Legal office | Non-compliant storage | Audit log |
| R-LAN-01 | Screens and reports must be available in Tetun and Portuguese, and official outputs in the language the institution requires. | Software ships en, pt, tet. Tetun not reviewed by a competent speaker | TO VALIDATE | Language office; planning office | Unclear or wrong official wording | `lang/*.json` |
| R-LAN-02 | Notifications reach the people who actually hold the next step, including list members and interim appointees. | O9: routing by role and unit only | RECOMMENDATION | Planning office | Missed turns in the chain | S14; `ActivityWorkflow::notify` |
| R-OPS-01 | Backups are held off the application server and restores are tested. | Earlier audit M4 | RECOMMENDATION | IT unit | Data loss on server failure | Operations |
| R-OPS-02 | Irreversible migrations must not run on any live data without a verified backup. | H7 | RECOMMENDATION | IT unit; project owner | Unrecoverable data change | Deployment |

## K. Items that cannot be settled by the register

These are the questions in section 10 of the AS-IS document, grouped by the office best placed to answer them.

| Office | Questions it should answer |
|---|---|
| Planning office | R-CAL-01, R-CAL-02, R-OBL-01, R-OBL-02, R-CON-01 to R-CON-04, R-FRM-01 to R-FRM-06, R-AMD-01, R-AMD-05 |
| Ministry of Finance (budget and treasury) | R-OGE-01, R-OGE-02, R-FIN-01, R-FIN-04, R-AMD-02, R-EXE-01, R-EXE-02 |
| Legal office | R-APP-01, R-APP-02, R-APP-05, R-OBL-03, R-RET-01, R-RET-03 |
| Internal audit | R-APP-04, R-APP-06, R-EXE-03, R-RET-02 |
| Head of institution | R-APP-05, R-CON-03 |
| IT unit | R-OPS-01, R-OPS-02, R-FIN-04 |
| Language office | R-LAN-01 |

## L. Summary

- 61 entries: 10 `CONFIRMED` (project decisions and current-state facts), 38 `TO VALIDATE`, 13 `RECOMMENDATION`. A few rows combine two labels; the first listed governs.
- The highest-value validations for the next step (the target process) are R-APP-05 (any consolidation approval), R-OBL-01/02 (who must submit, nil returns), R-CON-03/04 (consolidation and baseline), R-AMD-02 (amendments needing outside approval), and R-FIN-01 (meaning of "approved").
- R-APP-08 (O1) and R-AMD-03 (O5) are software defects, not institutional questions. They do not need to wait for validation.
