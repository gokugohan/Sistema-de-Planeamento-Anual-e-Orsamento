# Annual plans of every State Budget entity, 2027 (proposal)

One file per entity (`<code>.json`, the entity's budget code) and `index.json` listing them. Read by
`OgeCatalog` and loaded by `OgeEntitySampleSeeder` (Management > Sample Data, "Another public entity").

## Source

Orçamento Geral do Estado 2027 (Proposta de Lei delivered to Parliament on 1 October 2026; not yet law),
Elementos Informativos, Volume II-A and Volume II-B: Plano de Ação Anual (Anexo K, "Planos Anuais dos Serviços e
Entidades do Setor Público Administrativo", 1 258 pages). Ministry of Finance, October 2026.

The two published PDF files do not contain pages 644–663 (Volume II-A ends on page 643 and Volume II-B starts on
page 664), which hold the plan of the Autoridade Municipal de Manufahi (054): it is therefore not here. The Fundo de
Reserva da Segurança Social (107) prints no activity with an amount and is not listed either. That leaves 109 entities.

## How the files were made

Every page's table was read by its printed borders (PyMuPDF `find_tables`), so each cell keeps its own text. Rows
are recognised by their first cell (PROGRAMA, Objetivo, Subprograma, Resultado, Atividade). An activity printed over
several budget divisions (one row per division, the name only on the first) adds the divisions and their amounts
up; a row cut by a page break is joined to the row it continues. The division legend printed at the end of each
entity ("*Código Divisão (Div): …", sometimes running over a page break) gives the names of the divisions.

Checked: for every entity, every activity's amounts add up to its printed total, and the activities of each
subprogramme add up to the subprogramme total printed above them. All 109 entities agree, except one figure of the
book itself: in the Ministério das Obras Públicas (024) the activities of subprogramme 51001 add up to 1 326 520 USD
while the subprogramme row prints 1 327 520 USD (`"check": "mismatch"` in its file); the activities are kept as printed.

## Fields

`code`, `name`, `abbr` (a working abbreviation: the initials of the name, or the acronym the name carries; municipal
authorities are "AM <municipality>"), `kind` (ministry, secretariat, body, municipal, fund), `check`, `source`.

`units[]`: every division of the legend: `code` (a working abbreviation made from the initials, unique in the entity),
`div` (the 7-digit budget code), `name` as printed, `type`, and `parent`. **The hierarchy is a reading of the budget
codes, not of each entity's organic law:** the divisions whose codes share their first five digits go under the one
among them that the book names as a general directorate ("Direção-Geral …", "Gabinete de/da Direção-Geral …",
"Secretariado de Apoio à Direção-Geral …"); every other division stays at the top. Group 01 (the cabinets) is never
grouped.

`programmes`: code => name (sentence case of the printed heading).

`activities[]` and `results[]`: as in `../README.md` (code, programme, subprogramme and its name, name, `div`,
indicator, baseline, target, `amounts` by economic category, page; outcomes and outputs with their indicator,
starting point and target). Only activities with an amount are kept.

New policy priorities: as in the ministry sample, an activity with an "X" right after the subprogramme code (e.g.
`50202X1`) is funded by a Government Policy Priority; the loader marks it as a new policy ("PPG (OGE 2027, Vol. X)")
without naming the priority, which Volume X gives per ministry.
