# 09 — Fiscal envelope distribution within the institution: implementation plan

Status: **DRAFT FOR REVIEW, revision 2** (2026-11-23). It now includes your answers (section 6). Nothing is built yet.

## 0. Why this plan reverses an earlier decision

In October and November 2026 the envelope, its distribution, the allocation pages and the ceiling were removed (commits `c11ea6f`, `36a4e7d`). The Annual Action Plan (PAA) became the centre, and budget decisions were added as information only, with no distribution to units.

This plan brings distribution back, but in a different shape:

| Old envelope (removed) | Proposed distribution |
|---|---|
| The year depended on it. Planning could not proceed without an envelope. | It is an **optional rule, off by default**. The plan stays the centre, and a year without a ceiling works exactly as today. |
| It was a separate budget cycle with its own tables. | It **extends the existing budget decisions**. The ceiling is a decision, and the distribution is a split of a decision. |
| It was a hard ceiling. | Enforcement is a switch: **off / warn / block**, and block applies only at "Approve the plan". |
| It was registered and kept as a single envelope. | It is **versioned with the State Budget stages**: ceiling, committee, law, supplementary budget. |

## 1. What the law says (Timor-Leste, as in force in November 2026)

Sources read: Decreto-Lei n.º 41/2025 and Decreto-Lei n.º 42/2025, both of 15 December, Jornal da República Série I n.º 50 D. The full text of Lei n.º 3/2025 itself was **not** read. Its content below is taken from how DL 42/2025 regulates it.

| Instrument | What it is |
|---|---|
| **Lei n.º 3/2025, de 23 de abril** | The framework law on the State Budget and public financial management. It **replaces Lei n.º 2/2022**, which earlier notes cited. |
| **DL n.º 42/2025** | Regulates Lei 3/2025: planning, ceilings, accounting, execution, budget changes. Its first amendment was approved by the Council of Ministers in August 2026. |
| **DL n.º 41/2025** | Execution of the 2026 State Budget. The rules are annual; a 2027 equivalent will follow. |
| Lei n.º 8/2025 | The 2026 State Budget law. Lei n.º 8/2026 (8 June) is cited in the press as adding budget-management flexibility. Its text was not read. |

### 1.1 The cycle in the law and what each step means for the app

| When | Article of DL 42/2025 | What happens | In the app |
|---|---|---|---|
| May–June | Art. 11.5, 15.1, 15.7 | Each service or entity prepares its **annual plan**: programs, subprograms, activities **and their cost**. This is bottom-up planning. Planning, monitoring and evaluation units must exist (Art. 5). | Already done: the PAA. |
| By 30 June | Art. 10.1 j–k, 10.3 | The Fiscal Strategy Statement sets indicative ceilings for the sector and subsectors, for the budget year and the 4 years after it. | Context only. |
| By 30 June, then +15 days | Art. 18.3–18.4 | The **Jornada Orçamental** sets, and the Government then approves, the **total spending of each budget title** (título orçamental: the institution). This is top-down. | **New: the institution's ceiling.** |
| By 20 July | Art. 19.2 | The MoF **Budget Circular** gives the total for each title, the amount already committed (f), the **fiscal space for new policies** (g), the forms and the submission calendar. | **New: ceiling details.** |
| After the circular | *Not regulated* | The institution splits its title total among its directorates. The law is silent on this: it is an **internal management act**, normally a despacho of the head of the institution prepared by the planning unit or finance. | **New: the distribution. This is the core of this plan.** |
| August | Art. 20.3 | The **Policy Review Committee (CRP)** reviews the plan. The MoF changes the submission, and the institution **corrects its annual plan**, which the MoF validates. | Existing budget decision + adjustment round. **New: redistribution.** |
| October–December | — | The proposal goes to Parliament, the law is passed, and supplementary budgets may follow. | Existing budget decisions. |
| Execution | Art. 9.4 | Mandatory spending (salaries, commitments already made, debt) must be in every proposal. | Salaries kept at institution level (see 3.2). |
| Execution | Art. 35 | Every budget change between programs, subprograms, **activities** or categories is an *alteração orçamental*. Moving money **out of** "salaries and wages (regular)" or **out of** "development capital" is **prohibited**. Moves between programs, or between categories of one program, are capped at **20 %** of the source appropriation and approved by the head of the service. Each change must state its **impact on the annual plan targets**. | Phase 4 (optional): checks on revisions. |
| Execution | DL 41/2025 Art. 4 | Increasing salaries needs approval from the Civil Service Commission and the MoF. Increasing travel, accommodation, per diem and training lines is **prohibited** in 2026. | Phase 4: a configurable list of blocked lines. |
| Execution | Art. 32 | Funds are released at **25 % of the original appropriation per quarter**, or 50 % per semester for some bodies. | Out of scope; listed as a possible later feature. |
| Execution | Art. 50 | Cabimento is checked per **economic line and activity**. | The existing line items already carry both. |

### 1.2 What this means in the real world

1. **Two directions meet in July.** Units plan bottom-up in May and June. The ceiling for the title arrives top-down in July. The institution must then **fit the plan to the ceiling** before submission. Today the app has no point where this fit happens before the committee stage.
2. **The ceiling is set for the institution, never for its directorates.** Splitting it is the institution's own decision, so the app must record **who decided it and by which act** (despacho number and date).
3. **The split is by economic category in practice.** Salaries are mandatory, come from the payroll, and the Civil Service Commission governs them, so they are usually kept centrally. Development capital often goes through the Infrastructure Fund, which is a separate title. **TO VALIDATE:** whether your circular or ceiling letter splits the title total by category. Please share the MTC ceiling letter or circular for 2027 if you have it; the D:\MTC books contain the proposal, not the circular.
4. **The ceiling changes several times a year:** circular, then CRP, then law, then supplementary budget. Each change needs a redistribution and units correcting their plans. That last part already exists as the adjustment round.

## 2. Scope

### In scope
- Phase 1: the ceiling, its distribution to top-level units by category, approval and versions.
- Phase 2: units see their share against their plan, with warn or block at plan approval.
- Phase 3: redistribution after the CRP, law or supplementary budget feeds the adjustment round. Sample data. Reports.
- Phase 4 (optional, can be cut): Art. 35 checks on revisions of approved activities.

### Out of scope (possible later)
- Cascading the distribution below the top-level units (from a directorate-general to its national directorates).
- Release of funds per quarter (Art. 32) and cabimento: these belong to the financial management system (GRP), not to the plan.
- Indicative ceilings for the next 4 years (forward estimates).

## 3. Design

### 3.1 Data model

The ceiling **is** a budget decision. Two stages are added to `BudgetDecision::STAGES`, in the order of the year:
- `ceiling` ("Budget ceiling (circular)"), placed before `committee`;
- `proposal` ("Government proposal to Parliament"), placed before `law`.

Settings gains two fields: the **budget unit** (a directorate, e.g. the DGAF) and the **distribution approver** (the head of the institution by default, or a named person). One new pair of tables holds the distribution:

```
budget_distributions
  id, budget_decision_id (FK, cascade), version (1..n per decision)
  status            draft | approved | superseded
  act_reference     e.g. "Despacho n.º 12/2027/MTC" (required to approve)
  act_date
  reserve_note
  prepared_by, approved_by, approved_at, timestamps
  unique(budget_decision_id, version)

budget_distribution_lines
  id, budget_distribution_id (FK, cascade)
  directorate_id          nullable  -> NULL = kept at institution level (reserve / central salaries)
  economic_classification_id
  amount decimal(16,2)
  unique(budget_distribution_id, directorate_id, economic_classification_id)
```

- A decision may carry these optional fields: `committed_amount` (Art. 19.2 f) and `new_policy_space` (Art. 19.2 g). They are shown as information.
- Only **one approved distribution counts for a year**: the approved one that belongs to the latest decision. Approving a new one marks the previous one `superseded`. Nothing is ever deleted (audit).
- **Which money counts.** Settings gains a list of the funding sources that belong to the title, by default the General State Budget sources. Infrastructure Fund and development-partner lines are left out of the comparison, because they are other titles.
- Both models use `Concerns\Auditable`, and sample rows are registered in `SampleRegistry`.

### 3.2 Rules (new `PlanOptions` fields, all off by default)

| Option | Values | Default | Meaning |
|---|---|---|---|
| `budgetDistribution` | bool | off | Turns on the ceiling stage, the distribution page and the unit cards. Requires `budgetDecisions`, and switches it on with it. |
| `ceilingMode` | off / warn / block | warn | **warn**: the plan can be approved over its share, but approval requires a remark and the overrun is shown. **block**: "Approve the plan" is refused while the directorate's approved and in-force amount is over its share in any category. Drafting and submitting are never blocked. |
| `salariesCentral` | bool | on | Category 10 (salaries) stays at institution level. It is not split, and units are not compared on it. |

Totals must reconcile: the distribution lines plus the institution reserve must equal the decision in each category. Approval is refused otherwise, and the page shows the difference live.

### 3.3 Roles and permissions

| Action | Who | Note |
|---|---|---|
| Record the ceiling (a decision) | Administrator, or the Planning role with a new permission `budget.ceiling.record` | **TO VALIDATE:** today only administrators record decisions. |
| Prepare a distribution | People of the **budget unit** named in Settings (for a ministry, normally the DGAF, Direção-Geral de Administração e Finanças), the Planning role, administrator | Several drafts are allowed. |
| Approve a distribution | The **distribution approver** in Settings. Default: the head of the institution (the Minister role in a ministry, the president or director-general of an agency). It can be set to the head of the budget unit (DGAF) where the Minister has delegated this. | It needs an act reference (despacho). The preparer cannot approve their own draft, whatever the segregation preset. If an administrator acts, it is recorded as on behalf of the approver. |
| See all shares | Wide audience (planning, finance, heads, Minister) | — |
| See own share | Each directorate's people | — |

Every action is checked on the server inside the Livewire action, as on every existing page. When a distribution is approved, the heads and planners of each directorate get a notification (database channel, synchronous).

### 3.4 Pages and UI (TailAdmin tokens, night mode via CSS variables, no `dark:` variants)

1. **Planning > Budget ceiling and distribution.** The existing `planning.budget-decisions` page is extended, not duplicated (navigation config + feature flag).
   - Tab *Decisions*: as today, plus the ceiling stage and the committed and new-policy fields.
   - Tab *Distribution*: a matrix with directorates as rows, categories as columns, and an institution reserve row. Each cell shows the share, the plan in force and the plan in preparation, with an over or under badge.
   - Three **suggestions** fill the matrix, and every cell stays editable: *as in the plan now*, *as in last year's approved plan*, *proportional to the plan, scaled to the ceiling*.
   - Buttons: Save draft, Approve (with the act reference), and Print (an A4 annex for the despacho, in the institution's letterhead style).
   - A history of versions with differences between them.
2. **Unit home and dashboard: the card "Your share of the ceiling".** It shows the share, the plan in force and in preparation, and the room left in each category, with an amber or red state. It reuses `PlanBreakdown` totals and has no new query per row (query budget).
3. **Activity form.** Next to each budget line, a small note: "room left in Goods and services for <unit>: 12,300". It is read-only and never blocks.
4. **The "Approve the plan" card.** In warn mode it shows the overrun and requires a remark. In block mode it explains what to reduce. This goes through `PlanningWindow::planReadiness`.
5. **The year bar.** No change. The page year is used, as today.

### 3.5 Services

- `App\Services\BudgetDistributions`: `draft()`, `suggest(decision, method)`, `save()`, `approve()`, `current(year)`, `shareFor(year, directorate)`, `overrun(year, directorate)`. The arithmetic per directorate and category reuses `PlanBreakdown::unitTotals` / `classTotals`, extended to unit × class in **one** grouped query.
- The shares rarely change, so they go through `StructureCache`, keyed by year and flushed on approve.
- `PlanStatus` / `PlanningWindow::planReadiness` gain the ceiling check and respect the existing `holdBack` logic.
- `AdjustmentRounds::open`: when an approved distribution exists for the new decision, each directorate's **target = plan in force − new share** (positive means reduce). Otherwise it falls back to today's gap × share.

### 3.6 Phase 4: budget-change checks on revisions (optional)

When a revision of an approved activity is sent for approval, the amounts in force are compared with the new amounts per program and category of the directorate:
- Money moved **out of** salaries (regular) or development capital is flagged as "Not allowed by DL 42/2025 Art. 35.2" (warn or block).
- A move above **20 %** of the source appropriation is flagged "Above the 20 % limit (Art. 35.3)".
- The revision reason must state the impact on targets (Art. 35.9). This is already close to the current required reason; a separate field "Effect on targets" is added.
- A configurable list of economic lines that may not be increased covers annual rules such as DL 41/2025 Art. 4.3. These rules change every year, so they are kept in Settings per year, never in code.

**TO VALIDATE:** the 20 % limit applies to the institution's appropriation in the State Budget, which the app does not hold line by line. The check would use the plan in force as a stand-in. That is an approximation, and that is why it is optional.

## 4. Sample data (real data only)

There is **no 2027 ceiling letter**, so the sample invents none. It uses only what the State Budget books publish:

| Year | Decision recorded in the sample | Source already in the repo |
|---|---|---|
| 2026 | Stage `law`, Lei n.º 8/2025; then stage `supplementary`, Lei n.º 8/2026 | `mtc_oge_2026.json` (approved, amended) |
| 2027 | Stage `proposal` (new stage: "Government proposal to Parliament", 1 October 2026), MTC total 14,620,942 USD | `mtc_oge_2027.json` |

- The sample **distribution** is the amount per directorate and category printed in the book. The numbers are real, so they reconcile exactly. Salaries go to the reserve when `salariesCentral` is on.
- No `ceiling` stage is loaded in the sample at all, because no circular figure is published. The stage exists for real use: the budget unit types it in from the letter when it arrives.
- Gaps are reported on the Sample Data page.

## 5. Delivery steps

| # | Step | Files (main) | Tests |
|---|---|---|---|
| 1 | Migrations: `ceiling` stage, decision extras, distribution tables, `funding_sources.counts_in_title` | `database/migrations/2026_11_30_*` | migration up and down |
| 2 | Models + `BudgetDistributions` service + options | `app/Models/BudgetDistribution*.php`, `app/Services/BudgetDistributions.php`, `app/Support/PlanOptions.php` | `BudgetDistributionTest` (reconcile, approve, supersede, permissions, audit) |
| 3 | Distribution tab on the budget page + print | `livewire/pages/planning/budget-decisions*`, `config/navigation.php` | Livewire tests per role; `NavigationTest` stays green |
| 4 | Unit card, activity-form note, approve-plan check (warn and block) | `components/ceiling-card`, `activity-form`, `approve-plan`, `PlanningWindow` | `CeilingEnforcementTest` (warn needs remark, block refuses, drafts never blocked) |
| 5 | Adjustment round targets from redistribution | `AdjustmentRounds.php` | extend `AdjustmentRoundTest` |
| 6 | Sample ceiling and distribution from the books | `database/seeders/Samples/*` | `SampleDataTest`: purge removes all |
| 7 | Reports: "Ceiling vs plan by directorate" in the final plan report and workbook | `AnnualPlanReport`, `AnnualPlanWorkbook` | report test |
| 8 | Translations en/pt/tet (US English) | `lang/*.json` | `TranslationCompletenessTest` |
| 9 | (Optional) Phase 4 revision checks | `ActivityWorkflow`, `ActivityVersions`, settings | `BudgetChangeRulesTest` |
| 10 | Manuals (Word): a new section in the user and admin manuals | `docs/MANUAL-*.md` → `.docx` | **asked first**, because you edit the Word files by hand |
| 11 | Full suite (about 20 min, one process, no edits while it runs), `paa:release` minor (v1.2.0) | — | — |

Phases 1–3 are steps 1–8. I estimate them at about 2–3 working sessions. Phase 4 is about one more.

### Guardrails kept
- `NoDirectStatusUpdateTest`: approval statuses are still written only by `ActivityWorkflow`. The distribution has its own status, which is written only by `BudgetDistributions`.
- Performance: one grouped query for unit × category totals; `StructureCache` for shares; query budgets in existing tests not exceeded.
- Institution-neutral: no "MTC" anywhere outside the sample seeders. "Title" or "institution" is used in labels.
- New strings in all three languages. The pt/tet texts need a native reviewer.

## 6. Decisions (user, 2026-11-23)

| # | Question | Decision |
|---|---|---|
| 1 | Bring distribution back? | **Yes, as an optional rule, built to enterprise standard and harmonized with the rest of the application** (section 7). |
| 2 | Split | **By economic category.** |
| 3 | Salaries | **Kept at institution level**, outside unit shares. |
| 4 | Enforcement default | **warn.** |
| 5 | Who prepares and who approves the distribution | **The budget unit prepares** (in a ministry, the DGAF, Direção-Geral de Administração e Finanças). **The head of the institution approves** (the Minister in a ministry). Both are set in Settings, so another institution can name its own. See the recommendation below. |
| 6 | Depth | **Top-level directorates** for now. See the recommendation below. |
| 7 | Art. 35 checks | Explained below; **awaiting your choice.** |
| 8 | 2027 ceiling letter | **Not available.** The sample uses the 2026 law and the 2027 proposal from the books (section 4). |

### Recommendation on 5: who prepares and who approves

In Timor-Leste ministries the DGAF (or the finance and administration directorate of an agency) manages the institution's budget and deals with the Ministry of Finance. The planning unit (UPMA) coordinates the plan. The split of the ceiling is a decision about the institution's money among its own directorates, so it should be **signed by the head of the institution**, as a despacho. The DGAF and the UPMA prepare it.

- **Default:** the DGAF prepares, with the UPMA able to edit; the Minister approves.
- **Option:** where the Minister has delegated it by despacho, Settings can name the DGAF director-general as approver. The delegation reference is then printed on the distribution.
- The preparer and the approver are always two different people.

### Recommendation on 6: depth

**Now:** split only to the top-level directorates (directorates-general, the cabinet, regional offices). These are the units whose heads already "approve the plan" in the application, so a share has a clear owner and the warning appears exactly where that head decides.

**Later (after one full year of use):** let each directorate-general head split their own share among their national directorates. That is the same screen one level down, approved by that head. The tables are already shaped for it (`directorate_id` can be any unit), so nothing has to be rebuilt.

Starting deeper now is not recommended:
- It doubles the approvals at the busiest time of the year (July).
- The law and the books stop at the Direção Nacional level, so the figures below that level would be internal estimates only.

### Explanation for 7: what Art. 35 of DL 42/2025 says, and what the checks would do

Art. 35 governs **changes to the budget after the State Budget law is in force** (alterações orçamentais). In plain words:

1. **What counts as a change (35.1):** moving money between programs, subprograms, **activities**, categories or lines.
2. **Never allowed (35.2):** taking money **out of** salaries (regular pay) or **out of** development capital to put it anywhere else.
3. **Size limit (35.3):** between programs, or between categories of one program, at most **20 %** of the appropriation the money comes from.
4. **Who approves (35.4):** the head of the service or entity. A change from the "General State Appropriation" needs the Minister of Finance (35.6).
5. **Justification (35.9):** each change must say where the money came from, where it went, and **its effect on the annual plan's targets**.
6. **Reporting (35.10):** it is sent to the Ministry of Finance's planning and budget directorate.

In the application, such a change is a **revision of an approved activity**. The optional checks would, when a revision is sent for approval:
- warn if it takes money out of salaries or development capital;
- warn if it moves more than 20 % out of a category or program;
- ask for a short "Effect on targets" text;
- print a "budget change" summary (from and to, by program and category) to attach to the request to the Ministry of Finance.

**Recommendation: later, as phase 4, with warnings only.** The real check is done by the Ministry of Finance in its financial system (GRP), on the official appropriation. The application only has the plan's amounts, so it can warn early but must not block. The checks add most value once units are revising during the year, which is after phases 1–3 are in use.

## 7. Enterprise standard and harmonization

| Area | How the distribution fits in |
|---|---|
| One place | Built into the existing **Budget decisions** page and its history, not a new module. It uses the same stages, baselines (`PlanBaselines::capture` on each approval) and adjustment round. |
| One workflow vocabulary | Draft, then Approved, then Superseded; the same status component, the same "Waiting for you" inbox entry for the approver, and the same notification channel. |
| Security | Server-side permission checks in every Livewire action. Two people (preparer and approver). Policies and the guard test for statuses. Rate-limited actions. |
| Audit | `Auditable` on every model. Act reference and date are required to approve. Versions are never deleted; superseded versions stay readable and printable. |
| Configuration, not code | Rules on the Optional rules page. Budget unit and approver in Settings. Which funding sources count in the title, in Settings. Nothing about MTC in code. |
| Performance | One grouped query for unit × category totals. `StructureCache` for shares. Existing query-budget tests must stay green. |
| UI | Design system v3 tokens, night mode through CSS variables, `x-ui.*` components, the page year bar, navigation from `config/navigation.php`, Ctrl+K entry. |
| Language | US English, with Portuguese and Tetum strings added (`TranslationCompletenessTest`). The Portuguese terms follow the law: *teto*, *distribuição*, *despacho*, *alteração orçamental*. |
| Reports and exports | The distribution appears in the final plan report, the Excel workbook and a printable despacho annex. Printing scales with `transform`. |
| Tests | One test file per service and page. Role smoke tests in `NavigationTest`. The full suite runs before release. |
| Documentation | A section in the user and admin manuals (Word). Regenerating them is **asked first**, because you edit the Word files. |
| Release | `paa:release` minor (v1.2.0), pushed with tags. |
