# Real MTC budget data (2025, 2026, 2027)

`mtc_oge_2025.json`, `mtc_oge_2026.json` and `mtc_oge_2027.json` hold the annual plans of the
Ministério dos Transportes e Comunicações (entity 025) and of APORTIL (056), TIC Timor (059), AACTL (063)
and ANC (066), as printed in the State Budget books:

| Year | Book | Budget law |
|---|---|---|
| 2025 | Livro Orçamental 2025, Vol. II (Planos Anuais, Anexo K) | Lei n.º 8/2024, de 26 de novembro (J.R. Série I, N.º 46 F) |
| 2026 | Livro Orçamental 2026, Vol. II-A and II-B | Lei n.º 8/2025, de 27 de novembro (J.R. Série I, N.º 48 B), amended by Lei n.º 8/2026, de 8 de junho (J.R. Série I, N.º 22 B) |
| 2027 | Livro Orçamental 2027 (proposal), Vol. II-A and II-B | Proposta de Lei do OGE 2027, delivered on 1 October 2026; not yet law |

The books are published by the Ministry of Finance and mirrored by La'o Hamutuk (`laohamutuk.org/econ/OGE25`,
`OGE26`, `OGE27`).

## How the files were made

The tables were read from the PDFs by position (each row of the printed table is an activity, with
its indicator, starting point, target, owning division and the amount per economic category), then
checked: for every entity and year the activities add up to each programme total printed in the book
and to the category totals of the budget tables (13 353 528 / 12 800 471 / 14 620 942 USD for the ministry,
and the totals of the four bodies). The 2026 amendment (ministry 12 674 262 USD) is read from the
tables of Lei n.º 8/2026.

## Fields

`activities[]`: `code` (official activity code), `programme`, `subprogramme` and its name, `name`,
`div` (budget division codes, see `MtcCostCentres`), `indicator`, `baseline`, `target` (as printed),
`amounts` by economic category (`10` salaries, `20` goods and services, `30` public transfers,
`40` minor capital, `50` development capital), `page` in the source PDF.

Activities with no amount are kept in the file and skipped by the seeder.

`results[]` (2027 only, so far): the rows of the plan above the activities. `kind` is `outcome` (the
medium-term objective of a programme, coded like `502.0.3`) or `output` (the short-term result of a
subprogramme, coded like `50202.0.0`); `statement` is its text, then `indicator`, `baseline` and
`target` as printed, and `page`. They were read from Volumes II-A and II-B (Outubro 2026) by column
position, using the table borders of each page, and every row was checked against the printed page;
where a cell of the book spills into the next row (the first activity's indicator, a target printed above
its row, the page footer), the text belongs to the row it is printed for. Long cells are kept whole in
the file and cut to the database columns (500 characters for the name, 255 for the starting point and
target) when loaded. A few texts are the book's own: "tbd" as a statement, an English indicator, apostrophes
printed as `&#39;` (written here as `'`).

The seeder gives each outcome to the year's programme and each output to its subprogramme, as a result
indicator named after the entity and the code (e.g. "APORTIL 502.0.3: …"): the ministry and its bodies
each set their own outcome for a programme they share. Only a programme or subprogramme that the loaded
activities use gets them (in 2027 one TIC output, A0805, has no funded activity and is not loaded).

## New policy priorities (2027)

The 2027 plan prints the activities funded by a new Government Policy Priority with an "X" in the code
(e.g. `50202X1`). `MtcBudgetSeeder::POLICY_PRIORITIES` marks them as new policies and names the priority,
from Elementos Informativos Volume X: PPG 3 (six activities, 1 200 000 USD, Tabela 9) and PPG 18 (four
activities, 2 215 000 USD, Tabela 21). The new activities of APORTIL coded `51002EO`–`51002ER` are not a
policy priority: Volume X (Tabela 29) gives APORTIL only a saving under the priorities and an increase as an
estimate variation (additional funding for ongoing work), so they stay existing services.
