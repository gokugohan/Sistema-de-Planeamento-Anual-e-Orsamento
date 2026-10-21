# 09. Usability Assessment

*Assessment of how easily each person understands the application, with proposals. Status: **Wave 1 built** (see section 6); waves 2 and 3 proposed, not built. Written after looking at the real screens (1440 × 900) as an administrator, as the head of a directorate and as a technical officer, with the development database, on branch `improvement` (commit `9a49ed4`).*

## The test

Two things you asked for:

1. When I open the **dashboard**, I understand it right away.
2. I always know **when** to plan, when not to; when to monitor; when to submit a report; **where** to update the indicators; where to update what was paid, and **where that number comes from**.

## 1. The questions, answered as the application works today

This is how the cycle runs. Nothing in the application says it in one place, which is the first finding.

| When | Who | What | Where | What the system checks |
|---|---|---|---|---|
| **Planning** (until the directorate finalizes; dates only if an administrator set a plan window) | Technical Officer of the unit (the Director can too) | Prepare activities: indicators with targets and actions, budget lines | *Plan → My plan → + New activity* | Complete before it can be submitted; a closed plan window stops everyone but an administrator |
| Submit | Technical Officer or Director | Send the activity up | Button on the activity page | Name, unit and target of every indicator; budget total above zero |
| **Approval** | Unit Director certifies; head of the directorate gives the final approval; Finance confirms budgets only if the institution asks | Certify, approve or return with a reason | *Approvals → Waiting for you* | Order of the lists; the preparer is not blocked unless the optional rule is on |
| **Finalize** | Head of the directorate (or an administrator) | End planning for the directorate once nothing waits | Card at the top of the activities list | Nothing pending, at least one approved activity |
| **Monitoring: spending** (every month, only for months that have started; inside the month window if one is set) | Director of the unit (the Technical Officer **cannot**: no access to the page) | Enter **committed** and **paid** for each budget line | *Execute → Report execution*, choose the month and the activity | Committed ≤ approved plan amount; paid ≤ committed |
| **Monitoring: indicators** (every quarter) | Director | Enter the **achieved value** of each indicator for the quarter that contains the chosen month | Same page, block "Indicators" | Only indicators due that quarter |
| Narrative | Director | What was done, constraints; for the quarter also why targets were missed and corrective measures | Same page, block "Narrative" | — |
| **Verify** | Someone other than the reporter: Finance Officer or the head (spending); a director or the administrator (progress) | Verify or return | *Approvals → Waiting for you* → the page, or the same Report execution page | Your own report cannot be verified; only verified figures count everywhere |
| Compile the quarter | Directors | Turn the units' reports into the text of the ministry report | *Execute → Compile the quarter* | Needs verified reports |
| **Change an approved activity** | Technical Officer, Director, head or administrator | Revise it with a reason; goes through approval again | *Revise* on the activity page | The approved version stays in force until the revision is approved |

**Where "Paid" comes from.** It is not imported. It is what the unit typed per budget line and month on *Report execution*, and it counts in every total only after someone else verified it (the README states there is no link to the Ministry of Finance system). No screen says this where the number is shown.

## 2. Findings by page

Severity: **High** = a person cannot tell what to do or misreads a number; **Medium** = slows understanding; **Low** = polish.

### Dashboard (administrator, Minister)

| # | Finding | Severity |
|---|---|---|
| D1 | 4,430 px tall: five figures, a chart, eight panels and two long tables. There is no headline sentence ("Where we are and what needs you"). | High |
| D2 | **What needs you** is a quarter of the way down the page, below the budget chart. It should come first. | High |
| D3 | The cycle strip shows step 1 "Plan submission" as **Now** while approval is already complete (87/87) and a third of the directorates are ready to finalize. Several steps overlap in time, and the strip shows one "current" step. | High |
| D4 | Two figures say the same thing: "Planned" and "Approved plan" are both $21,372,171 ("100.0% of what is planned"). With nothing between them, the second adds noise. | Medium |
| D5 | "Paid" has no explanation of where it comes from, and the chart legend mixes "Planned (cumulative)", "Committed", "Paid". | Medium |
| D6 | Filters (unit, programme, category) sit at the top but apply to some panels only; it is not said which. | Medium |
| D7 | The programme and unit tables repeat what *Programmes* and *Units* now show. | Low |

### My work (Director, Technical Officer)

Much better than the dashboard: one unit, three or four cards, short.

| # | Finding | Severity |
|---|---|---|
| W1 | It says what is **waiting** but not **what phase the year is in** or what is due next ("October reporting closes on …"). The *Deadlines* card is at the bottom and empty when no window is set. | High |
| W2 | "Budget of the plan" shows Planned / Approved plan in force / Paid (verified) with no hint where Paid comes from or who enters it. | High |
| W3 | A Technical Officer sees "Nothing is waiting for you" and nothing about execution. They have no access to *Report execution* (403 if typed), and no screen says that the Director reports. | High |
| W4 | "Next for you" cards read "1 spending figure to verify / Open →" with the same wording for different pages. | Medium |

### Activities list

| # | Finding | Severity |
|---|---|---|
| A1 | The page is titled "PAA Activities", while the menu says "All activities" or "My plan", and elsewhere "Programs"/"Programmes". | Medium |
| A2 | The tabs use "In review", while everywhere else statuses are Prepared, Verified, Certified, Approved. The "In review" figure reads 0 while activities are Certified. | High |
| A3 | The planning card at the top (finalize) is collapsed to one line; its meaning ("2 of 13 directorates finalized") is clear, but it does not say who finalizes or when. | Medium |
| A4 | Two money labels on one page: "Planned budget" and "Approved". Elsewhere "Approved plan amount". | Medium |

### Activity page

| # | Finding | Severity |
|---|---|---|
| V1 | Rich and mostly clear: status, governance action, tabs (budget lines, indicators, execution, history). | — |
| V2 | The **Execution** tab shows Approved / Committed / Paid / Paid-so-far but cannot be edited; nothing says "to report, go to Report execution" with a link. | High |
| V3 | "Programmatic Logic" panel is dense for first-time users and uses terms (OGE Program, SDG, PED) without a legend. | Low |

### Report execution

| # | Finding | Severity |
|---|---|---|
| R1 | The right page for spending and indicators, coherent in one view. | — |
| R2 | The month selector drives spending, but the indicators block is labelled "Q4" without saying that the quarter follows the month ("October is in Q4"). | High |
| R3 | The empty state says "Choose an activity" but does not say which activities qualify or how many are waiting for a report this month. | Medium |
| R4 | Status chips ("Verified — · 19/10/2026") show a dash when the verifier is unknown and no "who verified". | Low |

### Approvals ("Waiting for you")
Rebuilt in this branch (`9a49ed4`); clear. Remaining: the same page title in the menu, the breadcrumb and the page ("Waiting for you") is fine.

### Menu
| # | Finding | Severity |
|---|---|---|
| M1 | At 900 px height the left menu clips: *Insights* sits behind the fiscal-year card, and the Administration and System groups are below the fold. | High |
| M2 | The groups follow the cycle (Plan → Approvals → Execute → Insights), which is right, but nothing tells a newcomer the order. | Medium |
| M3 | "Waiting for you" is in the *Approvals* group; fine. A technical officer sees the group with one entry. | Low |

### Everywhere
| # | Finding | Severity |
|---|---|---|
| X1 | **Vocabulary is not one language.** Statuses: Draft/Returned/Prepared/Verified/Certified/Approved on some screens, "In review"/"Submitted"/"Reviewed"/"Validated" on others. Money: Planned budget, Planned, Approved plan, Approved plan amount, Approved plan in force. Programme/Program. | High |
| X2 | Almost no page says **what it is for and who uses it** in one sentence. The subtitles exist on some pages only. | Medium |
| X3 | No page explains the cycle. A new user has to read the manual to learn when to plan and when to report. | High |
| X4 | 403 page: "USER DOES NOT HAVE THE RIGHT PERMISSIONS." in capitals, no way back, no explanation of whose page it is. | Medium |

## 3. Proposals

Three waves, each independent and testable. Wave 1 answers your two questions directly.

### Wave 1: say where we are and what to do (answers questions 1 and 2)

1. **"The year at a glance" strip** at the top of the Dashboard and of My work, written in plain sentences from the real dates and counts, for example:
   *Planning: open · Approval: complete · Finalize: 11 directorates ready · Reporting: October spending is due (closes 31/10).* Each phase has one link to the right place and says **for you** when it concerns you. Replaces the four-step strip that shows a single current step.
2. **A "Guide" page** (menu → Overview → *How it works*) with the table in section 1, filtered to the person's role: "You prepare… you report…". It states plainly **where Paid comes from**. Linked from the strip and from every empty state.
3. **Dashboard order**: strip, then "Needs your attention", then the five figures, then the chart; the long unit and programme tables move to *Units* and *Programmes* (already there) with a "see all" link. Target: about two screens.
4. **Merge the duplicate figure**: "Approved plan" with "planned" as its footnote; drop the percentage that is always 100.
5. **Explain Paid and Committed** wherever shown: a small *i* with "Entered by the unit each month in Report execution; counts once someone else verifies it", linking to the page.
6. **Activity page, Execution tab**: a line "To report spending or progress, open Report execution" with a button.
7. **Report execution**: "October is in Q4" next to the indicators; the empty state lists the activities that still lack a report this month.
8. **Technical Officer**: a card on My work "Who reports execution? Your Director does. You can see the figures on each activity", with a link. (Whether the officer should report is a role decision; see below.)

### Wave 2: one vocabulary and one page purpose

9. A single glossary, used everywhere: statuses *Draft, Returned, Prepared, Certified, Approved* (plus *Verified* only where parent validation is on); money *Planned, Approved plan amount, Committed, Paid (verified)*; *Programme*.
10. Replace "In review" and the old status names in tabs, filters, KPI cards and reports; one automated test lists the allowed words.
11. Every page gets a one-sentence subtitle: what it is for and who uses it.
12. A friendlier 403 page with the reason and a way back to the person's own page.

### Wave 3: layout

13. Left menu: fix the clipping (collapse the fiscal-year card or make the menu scroll with a visible edge), and add short group captions.
14. Dashboard filters say which panels they affect.
15. Activities list: the planning card states who finalizes and what happens next.

## 4. Decisions needed from you

1. **Should the Technical Officer be able to report spending and indicators**, or does the Director do all reporting? Today only the Director (and administrators) can; the officer gets a 403. Both are valid; the application should match your practice. (A permission change is small.)
2. **Dashboard length**: move the unit and programme tables off the dashboard (recommended), or keep them?
3. **Vocabulary**: confirm *Prepared / Certified / Approved* (plus Returned), and *Approved plan amount* for the in-force amount, as the single set of words.

## 5. Decisions taken

1. The **Director reports** spending and indicators; the Technical Officer does not. The officer is told so on My work, with a link to follow the figures on each activity.
2. The unit and programme **tables move off the dashboard**; they are on *Units* and *Programmes*.
3. The vocabulary is **Prepared / Certified / Approved**, with **Approved plan amount** (wave 2).
4. Build **wave 1 now**, then review.

## 6. Wave 1: built

| Proposal | Built |
|---|---|
| 1 Year at a glance | A strip of four phases (Planning, Approval, Finalizing, Reporting) in plain sentences from the real dates and counts, each marked done, now or next, with one button when the step is the person's own. On the Dashboard and on My work. Service `YearAtAGlance`. |
| 2 Guide | *Overview → How the year works*: the table of section 1, with the person's own steps marked ("Yours"), where "Paid" comes from, and the words used for an activity. |
| 3 Dashboard order | Strip, then Needs your attention and Worth a look, then the figures and charts. The unit and programme tables are replaced by two links. |
| 4 One figure | "Planned" and "Approved plan" merged: Approved plan, with "of $ X planned in N activities". |
| 5 Explain Paid | On the dashboard card, on My work, on the activity's Execution tab and in the guide. |
| 6 Activity page | The Execution tab explains where the figures come from and has **Report this activity**, which opens Report execution on that activity. |
| 7 Report execution | "What the activity achieved in Q4 (October to December)"; the empty state lists the activities still without a spending report for the month, each with a Report button; the page opens on `?activity=`. |
| 8 Technical Officer | A line in the Budget card of My work: the Director of the unit reports it; follow it on each activity. |

Not changed in wave 1: the menu clipping (M1), the old status names in tabs and cards (A2, X1), page subtitles (X2) and the 403 page (X4). These are waves 2 and 3.

## 7. Two more pages, built after review

**Verify progress** (Approvals): it showed a bare number and two icon buttons. Each pending line now opens into the details a reviewer needs: who reported it and when, the indicator (kind, target, baseline, means of verification, what was planned for the quarter), what was verified in the other quarters and where that leaves the indicator against its target (with a warning above the target), and the words the unit wrote for the quarter. Buttons say what they do (*Verify*, *Return with a reason*), and a return needs a reason that the unit reads.

**Report execution**: a long dropdown of every activity, three stacked forms and three submit buttons. It now opens on a **worklist** (one row per activity, with badges for spending and indicators: To report, Returned, Waiting for verification, Verified; filters and search), explains that the quarter follows the month, shows a three-step checklist on an opened activity with a way back, keeps the narrative folded until needed, shows a currency sign on amounts and has one send button, *Send everything for verification*.

## 8. What this assessment does not cover

- Mobile layouts and accessibility beyond what was checked in earlier sessions.
- The Portuguese and Tetun versions: screens were reviewed in English; the new wording for each change needs a competent speaker.
- Screens of administrators' set-up pages (users, structure, settings) beyond the menu: they were not found confusing in this pass, but were not tested with real users. Real users should try the changed pages; a one-hour session with a director and a technical officer would show more than any review.
