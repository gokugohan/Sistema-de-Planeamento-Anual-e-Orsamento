# Changelog

Every release of the application, newest first. Numbers follow MAJOR.MINOR.PATCH: PATCH for fixes, MINOR for new features, MAJOR for changes that break data or how people work.

## [1.7.0] — 2026-11-28

- Final plan report in the State Budget's layout (Anexo K): each programme's outcome, the subprogramme's output and the activities serving it, with subtotals, the unit's division code (Div), and the men and women in an indicator's baseline and target

## [1.6.0] — 2026-11-28

- Report execution: "send everything" ends with one message saying what each part sent, or why nothing went
- Certify at both levels, and each level certifies its own: the head of the unit certifies, the head of the unit directly above certifies too, and a head further up stands in only when the unit's head prepared the activity

## [1.5.1] — 2026-11-28

- How the year works follows the modules: the administrator is told to choose them, and the calendar and revision steps leave out reporting when execution is off

## [1.5.0] — 2026-11-28

- Modules: execution reporting, performance and the budget envelope are add-ons the administrator switches on in Settings > Modules; off, they leave the menu and every page and their addresses are not found, while their data stays

## [1.4.2] — 2026-11-28

- Error messages in Tetum and Portuguese: sign-in, password and form checks no longer show their keys (validation.required)

## [1.4.1] — 2026-11-28

- Docker: the image brings the MySQL 8 login plugin, so the database schema loads on CapRover's MySQL 8.4

## [1.4.0] — 2026-11-24

Planning follows its dates, and the approvals page says what is waiting.

- Waiting for you shows what is waiting, unit by unit, with the first activities of each
- Planning closes when its dates have passed: no new activity outside the plan window unless an adjustment round or the administrator reopens it; the demo records approved years as finalized

## [1.3.0] — 2026-11-24

Demo data on the legal calendar, clearer approval steps in the activity list, the app's own confirmation dialog, and unit links on the approval pages.

- Approval pages link each directorate and department to its unit page
- The app's own confirmation dialog in place of the browser's alert, for every confirmation
- Activities list: one button per step (submit, certify, validate) instead of Move on, select all and clear selection; the final approval is never given in bulk
- Sample data on the legal calendar: choose whether the coming year's plan is submitted or approved; the current year is approved and carried out

## [1.2.0] — 2026-11-24

The budget envelope: the ceiling, the State Budget law and the retificativo on the legal calendar, optionally shared among the directorates.

- Budget envelope: the ceiling, the State Budget law and the retificativo, shared among the directorates, on the legal calendar

## [1.1.0] — 2026-11-14

PAA by program in the ministry's form, sample data only from the State Budget books, the version on the sign-in page, and Timor-Leste time.

- Application timezone: Asia/Dili
- PAA by program: the plan in the ministry's form for the whole institution, each program on its own page
- Sample data only from the State Budget books: GDSC loaded alone has the same amounts as in the whole ministry
- Sign-in page shows the release number (not the build or commit, which stay for signed-in users)

## [1.0.3] — 2026-11-13

Adds the business-process improvement brief to the repository.

- The business-process improvement brief that the docs/improvement programme follows

## [1.0.2] — 2026-11-13

Fixes the CapRover build, which timed out before it started.

- Docker: no syntax directive, so CapRover builds no longer time out resolving the base images

## [1.0.1] — 2026-11-13

Fixes the first changelog entry and documents versioning, night mode and the collapsible sidebar in the README.

- README: versions and releases as a section of its own, the version at the top, night mode and the collapsible sidebar
- paa:release: the first release does not repeat its notes as a change

## [1.0.0] — 2026-11-13

First numbered release.
