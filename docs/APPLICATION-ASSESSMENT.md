# Plano de Ação Anual (PAA): Application Assessment

*A description of the application as it is today, written so that a business analyst, or an AI assistant, can read it and propose enhancements and business processes. It states what exists, what does not, and what has not been validated against real practice.*

---

## 1. What the application is

**Plano de Ação Anual (PAA)** (in English, the *Annual Action Plan*) is a web application with which a Timor-Leste public body prepares, approves, carries out and reports on its annual action plan. The plan is the centre of the application: each unit's activities, with their indicators, the actions and months that deliver them, and the budget lines that pay for them, are approved directorate by directorate, changed only through versioned revisions, and reported on month by month and quarter by quarter.

It is built for **one institution per installation** (a ministry with its directorates, or a single directorate or office). The institution's name, structure, programmes and people are configured in the application; nothing is hard-coded. The Ministry of Transport and Communications appears only as an optional **sample dataset** (about 274 real activities for 2025–2027, taken from the State Budget books).

It is in three languages (English, Portuguese, Tetum) and produces printed reports in the layout of the Ministry of Finance's annual plan form.

## 2. Context it is designed for

- The State Budget (OGE) is published with an **annexe of annual plans** (*Anexo K, "Planos Anuais dos Serviços e Entidades do Setor Público Administrativo"*). Each service or entity prints its plan there: programme, subprogramme, activity (official codes), indicator with baseline and target, division, funding source, amounts by economic category.
- The structure of the plan is therefore the budget's own: **programme → subprogramme → activity**, with **economic categories** (10 salaries, 20 goods and services, 30 public transfers, 40 minor capital, 50 development capital).
- The institution is a **tree of units** (directorate, department, unit, and autonomous bodies), to any depth the law defines. Authority follows that tree.

> Limit of this assessment: the application was shaped from the published books, forms and systems provided by its owner, and from corrections as it was built. It has **not** been validated against a full real planning cycle in a ministry. Section 12 lists what is unverified.

## 3. Who uses it (roles)

| Role | What they do in the application |
|---|---|
| **Administrator** | Sets up the institution (units, programmes, users, windows, settings), loads data, can always act; sees every plan. |
| **Minister** | Sees every plan and report; does **not** approve plans. |
| **Director / head of unit** | Certifies the activities of their unit; the head of a top-level unit (a directorate) gives the **final approval** of the plans of their own directorate and finalizes it. Keeps approval lists and interim cover. |
| **Technical officer** (*Técnico*) | Prepares activities, indicators and budget lines of their unit, submits them, and reports execution. |
| **Finance officer** | Confirms budget lines (where the institution asks for it) and verifies the spending that units report. |
| **Observer** (viewer) | Reads plans and reports; changes nothing. |

Visibility follows the unit tree: a person sees their own unit and the units below it. Administrators and the Minister see everything.

## 4. The business process as implemented

### 4.1 Overview of the annual cycle

1. **Set up** (administrator, once per year): programmes and subprogrammes for the year; units and people; funding sources; reporting windows.
2. **Write the plan** (technical officers and unit directors): create activities with indicators, actions and months, and budget lines; submit them while the **plan submission window** is open.
3. **Approve** (directors, heads of directorates, finance where configured): prepare → (finance confirms budget) → certify → approve.
4. **Finalize** (head of each directorate): when everything in the directorate is decided, the head finalizes the plan of their own directorate. This stops *new* activities only.
5. **Carry out and report** (all year): units report spending (committed and paid) per budget line each month, indicator progress each quarter, and a narrative; someone else **verifies** the figures; directors **compile the quarter**.
6. **Revise** (when needed): an approved activity changes only through a **revision**: a reason, the whole approval chain again, a new **version**; the version in force stays until the revision is approved.
7. **Report out**: printed reports (final plan report, implementation plan per unit, performance report) in the Ministry of Finance form; CSV exports; dashboards.

### 4.2 The approval chain in detail

An activity moves through these statuses (shown to users as):

| Status | Meaning | Who moves it on |
|---|---|---|
| Draft | Being prepared | Technical officer or unit director submits |
| Prepared | Submitted | Director of the unit (after finance confirms the budget, if that option is on) |
| Verified | Certified by the unit's director; waits for the unit above | Director of the unit above (only where "parent validation" is on) |
| Certified | Validated | Head of the top-level unit, or the named approvers |
| Approved | Final approval; the activity gets a version | |
| Returned | Sent back with a **required reason** | The unit corrects and resubmits |

Rules around the chain:
- **Submission checks:** an activity needs a budget line with an amount, an indicator with a target, dates in order, and the plan submission window open.
- **Approval lists:** a unit's director can name the people who **prepare**, **certify** and **approve** its activities; the nearest list above a unit applies; with no list, the default rule (the director acts) applies.
- **Signing in order:** a certify/approve list of two or more people can be set so that *everyone* signs, in turn, and the activity moves on only after the last signature.
- **Interim approvers:** someone stands in for a director between two dates; they may certify and approve (not prepare).
- **Restructuring:** a unit can be moved to another parent; one person can replace another in every list; an administrator can move an activity to another unit; authority is worked out when someone acts, so nothing needs cleaning up.
- **Bulk approval:** many activities can be approved at once; each is re-checked as it is written.
- **No self-verification:** a person cannot verify their own reported figures.

### 4.3 Revisions and versions (change control)

- Every approval makes a **version** (1, 2, …): a kept copy of the activity, its indicators with actions, and its budget lines, with the total, who approved it, when, and the reason.
- A **revision** needs a reason, returns the activity to Draft, runs the **whole approval chain again**, and meanwhile the **approved version stays in force** (its amounts are what spending is reported against).
- Reviewers see **what changes** against the version in force, line by line.
- A budget line with reported spending and an indicator with reported progress **cannot be removed**; an activity approved once **cannot be deleted**.
- Signatures are **versioned in rounds**: after a return or a revision, earlier signatures stay on record as *voided* (who voided them and why); nothing is erased.

### 4.4 Execution and reporting

- **Windows:** dated periods in which the plan may be submitted and each month/quarter may be reported. No dates means open. Administrators can always act.
- **Monthly spending:** per budget line, *committed* and *paid*, validated against the approved amounts.
- **Quarterly indicators:** progress per indicator in the quarters its months fall in (indicators are cumulative or "level").
- **Narratives:** results achieved, constraints and a rating for each month and quarter.
- **Verification:** figures count only once someone else verifies them; returned figures can be corrected and resubmitted.
- **Compile the quarter:** directors draft the quarter's report text from what units reported, edit it and save it.

### 4.5 Ending planning per directorate

The head of each top-level unit finalizes the plan of their own directorate when nothing in it waits for a decision. A finalized directorate takes no new activities, imports or roll-forward copies for that year; the others keep planning. The year's planning has ended when every directorate that planned has finalized. Reopening needs the head or an administrator and a reason. **Finalizing does not stop revisions.**

## 5. What the application contains (by module)

- **My work / Units (home screens):** a workspace per unit (what waits for the person, the plan by status, budget, recent steps, deadlines, units below with status lights); a **Units** page for administrators, the Minister and heads to find the plan of any directorate, department or unit.
- **Plan:** plan cycle page (where the year stands); activity list with filters, tabs, export; activity form (indicators with actions and months, budget lines with funding source and optional link to an indicator); copy last year's plan as drafts; CSV import (checked line by line).
- **Approvals:** one inbox of everything waiting for the person; activities to decide; budget review (finance); approval lists; interim approvers.
- **Execute:** report execution (spending and progress), narratives, verification, compile the quarter.
- **Insights:** performance (planned vs approved vs paid, by quarter and programme), reporting (rolled up by unit), dashboard for administrators and the Minister.
- **Administration:** users and roles; structure (units, programmes, subprogrammes, economic categories); funding sources; reporting windows; settings (institution identity in three languages, institution level, approval options); import; audit log; sample data; backups.

## 6. Reports and outputs

- **Final plan report:** the approved plan in the Ministry of Finance layout (A4 landscape): programme and subprogramme bands, indicator, baseline, target, means of verification, unit, funding source, quarterly schedule, amounts by category; *complete* or *summary*; print or PDF; carries a document reference and fingerprint.
- **Implementation plan per unit:** each activity with its indicators and numbered actions, a January–December grid, budget per indicator, and "Prepared by / Approved by".
- **Performance report:** monthly or quarterly, with narratives and verified figures, in the same layout.
- **Exports:** CSV of activities and of report data.
- **Dashboards:** plan cycle tracker, key figures (planned, approved in force, paid, activities approved, delivery against targets), "needs your attention", charts by category, status and unit.

## 7. Data model in plain words

- **Institution tree:** units (directorates, departments, units, autonomous bodies) with parents.
- **Programme → Subprogramme** (per fiscal year) with official OGE codes; result indicators and SDG/PED alignments are described at programme and subprogramme level.
- **Activity:** code, description, objective, unit, programme/subprogramme, priority, dates, quarters it runs in, procurement and gender flags, status, **version**, revision reason.
- **Indicator** (per activity): name, type (output/outcome), unit of measure, baseline, target, means of verification, quarters due; **actions** under it with months.
- **Budget line:** economic category, funding source, amount, optional indicator; plus the **amount in force** from the approved version.
- **Version:** snapshot of the activity at approval.
- **Signatures, approver lists, interim appointments, reporting windows, unit finalizations.**
- **Execution:** monthly committed/paid per line; quarterly progress per indicator; narratives; verification trail.
- **Audit log:** every create/update/delete with old and new values, user, time and IP.

## 8. Controls and security

- Permissions are checked **on the server** for every action, not only hidden in menus; authority comes from the unit tree, approval lists and interim appointments at the moment of acting.
- Audit trail of all changes; approval history; versions and signature rounds that are never deleted.
- Sign-in rate limiting, **no self-registration**, optional/required **two-factor** sign-in (authenticator and recovery codes), temporary passwords forced to change.
- Nightly verified database backup (needs the scheduler); in-app notifications (written to the database in the same request; no queue worker or e-mail is needed); health check endpoint.
- 376 automated tests guard the approval, revision, window, report and permission rules.

## 9. Technology and deployment

Laravel 12, Livewire 3 (Volt), Tailwind CSS, Alpine.js, MySQL; Spatie roles/permissions; PDF generation on the server; three interface languages; responsive screens; sample data loadable/removable by an administrator; documented install and operation (README) and a complete user manual (Markdown, PDF, Word) and presentation deck.

## 10. Strengths today

- The plan and its approval are modelled closely on the budget books' structure and the Ministry of Finance form.
- A clear, auditable change-control story (versions, revisions, rounds, history).
- Flexible authority: tree-based, named approvers, in-order signing, interim cover, reshuffle tools.
- Role-aware screens: each person starts from the work that waits for them.
- Reporting that distinguishes *reported* from *verified*.

## 11. Known gaps and limitations (candidly)

Compared with the printed plan form and general practice:

1. **One unit per activity.** In the published plans, one activity can be carried out by **several divisions, each with its own amounts** (for example one activity across eleven municipal directorates). The application gives an activity one owning unit and budget lines without a division.
2. **Results hierarchy.** The books show programme *outcomes* (mid-term objectives, with codes, indicator, baseline, target) and subprogramme *outputs* (short-term results, with codes). The application stores result indicators as descriptions, without the outcome/output distinction or codes.
3. **Codes for division and funding source.** The form uses numeric codes (division, funding source); the application keeps names.
4. **Several indicators per activity** are written "a) … b) …" in the books; the sample data collapses them.
5. **Markers and revenue.** Markers used in the official plans (for example indirect-administration, child and gender measures) and the revenue (*Receitas*) form are **not implemented**.
6. **No link to the government's financial system.** Spending is entered by units and verified by a second person; there is no import or reconciliation with the system where commitments and payments are booked.
7. **No budget-law control.** There is no check of the plan's totals against the amount the budget law gives each programme (the former envelope/ceiling logic was removed on purpose).
8. **No attachments or discussion.** Documents, supporting files and comment threads on an activity are not supported.
9. **Procurement is only a flag**, not a procurement plan.
10. **One institution per installation**; no multi-institution tenancy.
11. **Scale and operations:** tested with about 3,000 activities; no offline mode; production hardening (cache, Redis, off-server backups) is documented but not applied; no queue worker is needed today.
12. **Analytics:** dashboards and CSV only; no data-warehouse or BI export, no forecasting.

## 12. What is **not** validated (to confirm with practitioners)

- Who really prepares, certifies and approves in a small directorate, and whether others (planning cabinet, inspection) sign.
- The real **calendar and templates** the Ministry of Finance requires for submission and for monthly/quarterly reports.
- Whether the plan is expected to follow a budget **circular and ceilings**, and how adjustments after approval (*orçamento retificativo*) are submitted.
- How the book's divisions, funding-source codes and markers are meant to be filled by a unit.
- The role of a ministry-wide **finance** function (today a finance officer works within their own unit and the units below).
- Whether outcome/output indicators are *reported* during the year or only *described*.

## 13. Suggested areas to design business processes and enhancements for

These are prompts, not decisions; each needs validation with real users.

1. **Plan preparation process:** guidance, checklists and quality rules before submission; templates by programme; copy-forward rules; per-division budgeting for shared activities.
2. **Review and approval process:** service levels (time to certify/approve), escalation to interim, handling of disagreements, collaboration comments and attachments.
3. **Budget alignment process:** how a unit's plan is checked against the budget law and ceilings; processing of amended budgets (*retificativo*) as batches of revisions.
4. **Execution monitoring process:** monthly cadence, reconciliation with the financial system, early warnings (under-spending, delayed indicators), variance explanations.
5. **Performance and accountability:** quarterly review meetings supported by the compiled report; outcome and output tracking; links to the Strategic Development Plan and SDGs.
6. **Governance and risk:** separation of duties, audit procedures, data-quality rules, access reviews, backup and continuity.
7. **Adoption and capacity:** training by role, a unit "focal point" network, support desk, change management, indicators of adoption.
8. **Scaling beyond one institution:** a model for ministries, autonomous bodies and municipal authorities (separate installations or a shared platform).

## 14. Glossary

| Term | Meaning |
|---|---|
| PAA | *Plano de Ação Anual*: the annual action plan. |
| OGE | *Orçamento Geral do Estado*: the State Budget and its classification. |
| Programme / Subprogramme | The budget classification an activity belongs to (official codes). |
| Activity | What a unit will do in the year. |
| Indicator / Action | A measurable result with baseline and target; a numbered step that delivers it, with its months. |
| Budget line | An amount in an economic category, with a funding source. |
| Version / Revision | A copy of an activity as approved; a controlled change to an approved activity. |
| Round | One run of signatures; a return or a revision starts a new round. |
| Window | The dates between which something may be done. |
| Directorate | A top-level unit (directorate, gabinete or autonomous body). |
| Verified | Confirmed by someone other than the person who reported it. |

---

### How to use this with an AI assistant (suggested instruction)

> You are a public-sector business analyst. Below is an assessment of a web application for annual action plans in a Timor-Leste public body. First list the business processes it supports and draw them as step-by-step flows with actors, inputs, outputs and controls. Then, using Section 11–12 (gaps and unvalidated assumptions), propose enhancements as new or changed business processes, each with the problem, the proposed process, the roles involved, the data and rules needed, the benefits, and the risks. Prioritise by value and effort. Mark clearly anything that depends on a fact to be confirmed with practitioners, and list the questions to ask them.
