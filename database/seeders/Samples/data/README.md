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
