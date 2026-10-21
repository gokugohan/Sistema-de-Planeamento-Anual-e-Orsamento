# 08. Implementation Status

*Branch `improvement`, pushed to `origin/improvement` (pull request into `main` not opened yet). Latest commit `b9d286a`. Work is traced to `07-IMPLEMENTATION-BACKLOG.md`. No migration has been run against the development or any production database: schema changes ran only in the automated tests, on the test database.*

**Tests:** full suite on `0659f97`: **439 passed, 0 failed** (1,839 assertions); `vendor/bin/pint --test` passed.

| PR | Requirement | Gap | Commit subject | Schema | Status |
|---|---|---|---|---|---|
| PR-01 | PAA-FR-001 | G-01 | Approval integrity: Review goes through the workflow service; guard test against direct status writes (`b9d286a`) | no | done |
| PR-02 | PAA-FR-002 | G-02 | (same commit) approval is atomic | no | done |
| PR-03 | PAA-FR-005 | G-05 | Bulk execution actions leave one audit row per figure | no | done |
| PR-04 | PAA-FR-006 | G-06 | Neutral wording for planned amounts; notifications described as they work | no | done (pt/tet wording to be reviewed) |
| PR-05 | PAA-FR-003 | G-03 | A revision that removes a budget line keeps the amount in force | `line_items.deleted_at` | done, **migration to run** |
| PR-06 | PAA-FR-007 | G-09 | Version snapshots hold every field that says what the activity is | no | done |
| PR-07 | PAA-FR-004 | G-04 | The approved-plan report keeps an activity under revision as it was approved | no | done |
| PR-08 | PAA-FR-008 | G-08 | A revision is not held back by the closed plan-submission window | no | done (a separate revision window still needs R-AMD-05) |
| PR-09 | PAA-FR-009 | G-11 | Submission also checks that every indicator has a name, a unit and a target | no | **partly done**: full field matrix waits for R-FRM-01 |
| PR-10 | PAA-FR-010 | G-12 | Activity codes: next code from the highest number in use; unique per unit and year | unique index | done, **migration to run after a duplicate check** |
| PR-11 | PAA-FR-011 | G-10 | Roll-forward keeps the whole indicator and which lines pay for it | no | done (default status policy still to decide, R-FRM-06) |
| PR-12 | PAA-FR-012 | G-13 | Record who prepared an activity; optional rule to keep preparer and approver apart | `activities.submitted_by` | done, **migration to run**; default `off` |
| PR-13 | PAA-FR-013 | G-14 | Corrected spending figures show the values that were verified before | no | done |
| PR-14 | PAA-FR-015 | G-15 | Tell whoever holds the next step | no | done |
| PR-15 | hygiene | G-23 | Style: run Pint over the code base (`094242b`) | no | done; `pint --test` passes |
| PR-16 | PAA-FR-014, 016, 017; G-18 to G-21 | G-07, G-16 to G-21 | Optional rules, off by default (*Administration → Optional rules*) | 4 migrations | done; the consolidation review decision is not built (no rule-holder known) |
| PR-17 | — | G-24 | Simulator approvals follow the real chain (`b5ee79d`) | no | done |
| PR-18 | — | G-22 | Copy each backup to a second place (`b437a0d`) | no | done |
| PR-19 | — | — | Plan → Programmes page for everyone (scoped to the user's units); Administration menu split into Administration and System | no | done |
| PR-20 | — | — | Scope fixes: *Activities to decide* and the planning card showed other directorates to a directorate user; they now list only the user's units | no | done |

## Findings made while building

- **Activity codes are not globally unique by design.** The sample data reuses official budget-book activity codes in several units of the same year, so the planned unique index on `(fiscal_year, code)` was wrong. It is `(fiscal_year, directorate_id, code)`. The same official code in two units is legitimate.
- **Soft deletes instead of a marker column** carry PR-05 (see `06-TECHNICAL-DESIGN.md`).
- **Only one bulk verification existed** (spending). The progress and narrative bulk buttons submit, not verify. All three now audit each figure.
- **The Simulator wrote statuses directly** on simulation records and let the Minister approve them (01, O11; gap G-24). Only directors and administrators approve there now; it still writes the status itself, on simulation records only, and is allowed in the guard test.

## Before using this branch on real data

1. Back up the database.
2. Run `php artisan migrate` on a **copy** first. The first three migrations below are new; the development database `paa_orcamento` has them (run on 2026-10-21 after a backup, totals identical before and after). Four more came with the optional rules: `2026_11_12_100000_add_external_approval_to_activities`, `2026_11_12_110000_add_nil_return_to_unit_plannings`, `2026_11_12_120000_create_corrective_actions_table`, `2026_11_12_130000_create_plan_baselines_table` (they add columns and tables only). The first three: `2026_11_10_100000_add_soft_delete_to_line_items`, `2026_11_10_110000_make_activity_codes_unique_per_unit_and_year` (stops with a list if two activities share a code in a unit and year, and changes nothing), and `2026_11_10_120000_add_submitted_by_to_activities`. Each has a working `down()`; rolling back the first removes lines set aside by a pending revision.
3. Compare the plan totals before and after on the copy.
4. Have a competent speaker review the new Portuguese and Tetun texts.
5. The manual's PDF and Word files were regenerated from `MANUAL.md` (`b9d286a`).

## Found while using the application

- **A directorate user saw other directorates' data in two places**: the planning card (names, who finalized, pending counts) and the *Activities to decide* page (the activities themselves, for any Director). Both are now limited to the user's units; `DirectorateSeesOnlyOwnPlanTest` guards them. The activity list, My work and the reports already were.
- **A stale "Send to the Minister" button** on the activity page duplicated "Approve Activity" and named a step that does not exist; removed.

## Not done

- A consolidation **review decision** (FR-017): no rule-holder is known and none is assumed.
- The institution's answers that switch the optional rules on, and the default status filter on roll-forward.
- `docs/APPLICATION-ASSESSMENT.md` is corrected (notifications) but still untracked; commit it if it should be kept.
- Static analysis, and the retention period (an institutional rule, R-RET-01). Backups can now be copied to a second place (`BACKUP_COPY_PATH`).
