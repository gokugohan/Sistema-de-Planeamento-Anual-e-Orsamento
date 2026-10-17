# Plano de Ação Anual (PAA): Audit Report

*Scope: the application as it stands at commit `17fe16c` (plus the assessment document). Method: dependency scans, static review of routes, authorization and output escaping, configuration review, and temporary probe tests that exercised edge cases (the probes were deleted afterwards). Findings marked **confirmed** were reproduced; the rest were established by reading the code.*

## Summary

| Severity | Count | Theme |
|---|---|---|
| **High** | 3 | Business-rule correctness around revisions and activity codes |
| **Medium** | 7 | Atomicity, report consistency, CI, backups, delivery of mail/queue, scope, unverified accessibility |
| **Low** | 8 | Defaults, hygiene, privacy, maintainability |

No critical security vulnerability was found: no known-vulnerable dependency, no unauthenticated data route, no SQL built from user input, and output is escaped. The serious problems are in **business rules**: three places where the application does something that contradicts its own rules.

---

## Findings

### High

**H1. The amount "in force" changes during a pending revision (confirmed).**
The rule is: while a revision is pending, the approved version stays in force. When a unit deletes a budget line during a revision, the line's approved amount is deleted with it (foreign key `adjusted_budgets → line_items` is `ON DELETE CASCADE`).
*Evidence:* an approved activity had $50,477 in force; after one line was deleted during a revision, the in-force total was **$31,841** while the activity was still version 1.
*Impact:* dashboards, spending validation and the performance report change before the revision is approved; a rejected revision cannot restore the amount.
*Fix:* keep in-force amounts until the revision is approved (do not cascade; reconcile removed lines when the new version is approved, or mark lines "removed in revision"). Effort: medium.

**H2. After the plan-submission window closes, a revision cannot be submitted by unit staff (confirmed).**
A revision returns the activity to Draft, and the Draft step is governed by the plan window. With the window closed, the next step for the technical officer is *none*; only an administrator can submit.
*Impact:* revisions are needed precisely after the planning season, so every change becomes an administrator task.
*Fix:* exempt revisions (`version > 0`) from the plan window, or give revisions their own window. Effort: small.

**H3. Duplicate activity codes are possible (confirmed).**
The next code is computed as *count of existing codes + 1*, and the database has **no unique index** on the code. After deleting a draft in the middle of a series (`-002`), the next activity gets `-003` again.
*Evidence:* the probe created `-001`, `-002`, `-003`, deleted `-002`, and the form logic produced `-003`; the database accepted a second `-003`.
*Impact:* two activities with the same official-looking code in reports and imports.
*Fix:* use the highest existing suffix + 1, add a unique index on `(fiscal_year, code)`, retry on conflict. Effort: small.

### Medium

**M1. Single approval is not atomic.** `approve()` and `advance()` change the status and then create the version in separate writes, with no transaction or row lock (only the bulk path uses `DB::transaction` and `lockForUpdate`). A failure between the two leaves an activity *Approved without a version*; two approvers acting at the same moment can both pass the check, and the second fails on the unique `(activity, version)` index after the status already changed. *Fix:* wrap in a transaction with a lock. Effort: small.

**M2. The final plan report and the dashboard disagree during a revision.** The report includes only `status = Approved`, so an activity under revision (Draft, version 1 in force) disappears from the report's totals, while the dashboard's "approved plan in force" still counts it. *Fix:* report on the version in force (use the stored snapshot). Effort: medium.

**M3. The CI style gate would fail.** `pint --test` reports **45 files** with style problems (imports order, fully-qualified names, operator spacing, line endings, braces). *Fix:* run Pint once, keep it in a pre-commit hook. Effort: small.

**M4. Backups are written to the application server's disk.** The nightly dump is good (compressed and verified), but nothing copies it elsewhere and no restore has been rehearsed. The configuration itself notes that a backup on the same disk is not a backup. *Fix:* off-site copy (object storage or second host) and a quarterly restore test. Effort: small–medium.

**M5. Mail can fail silently.** The default is `MAIL_MAILER=log`. If production is deployed without configuring it, password resets are never delivered, and no screen says so. Correction: in-app notifications ("it is your turn to sign") are synchronous and database-only (`SystemNotification` does not implement `ShouldQueue`), so they need no queue worker; the README statement that they are queued is wrong. *Fix:* a production readiness check on the health page and in the admin settings. Effort: small.

**M6. A finance officer is limited to their own unit and below.** The scope rule is unit-based for everyone except administrators and the Minister. A ministry-wide finance function cannot verify spending across directorates without full visibility. *Fix:* a ministry-wide finance role, or a scoped "all units" permission. Effort: small–medium.

**M7. Accessibility and performance of the new screens are unverified.** I found no automated accessibility test in the repository (earlier checks were manual scripts), and the new *My work* and *Units* pages have no query-budget or accessibility test. *Fix:* add them to the test suite. Effort: small.

### Low

- **L1. Development defaults.** Sample accounts use the password `password`, and the administrator seeder falls back to it outside production. Production is guarded, and a banner warns while samples exist; the risk is a staging server left open.
- **L2. `.env.example` is development-oriented** (`APP_DEBUG=true`, `APP_ENV=local`). Documented, but a readiness check would make it harder to ship by mistake.
- **L3. Session settings.** Cookies are not encrypted and `SESSION_SECURE_COOKIE` is not set by default; set it when serving HTTPS.
- **L4. The audit log is not tamper-evident.** The application cannot edit it, but anyone with database access can. A hash chain or write-once storage would raise assurance.
- **L5. Legacy and drift.** Leftovers: the legacy "Waiting Approval" status and a duplicate `STATUS_Approved` constant, `is_test_data` columns and the "Testing Zone", the table name `adjusted_budgets` (misleading since the envelope was removed), hundreds of unused translation keys.
- **L6. Irreversible migrations.** The migration that dropped the envelope and budget-cycle tables has no way back; always take a backup before deploying it.
- **L7. Privacy.** No data-retention policy and no export or erasure of personal data; IP addresses are stored in the audit log.
- **L8. Delivery speed.** The full test suite takes about ten minutes, and there is no static analysis (PHPStan/Larastan).

---

## What was checked and found sound

- **Dependencies:** `composer audit` and `npm audit` report no known vulnerabilities.
- **Access control:** every application route requires authentication; administration routes require the Administrator role and the pages guard again on the server; Livewire actions that change data authorize directly or call a service that does, **with one exception found later: `paa-approval::review` (`paa-approval.blade.php:193-209`) changes the status directly with only a user-level check and no scope check (see `docs/improvement/01-AS-IS-CURRENT-STATE.md`, O1)**. The only unauthenticated routes are login, password reset, the language switch, the health check and old-address redirects.
- **Mass assignment:** models declare `$fillable`; none uses `$guarded = []`.
- **Injection:** every `selectRaw`/`whereRaw` I reviewed uses static strings or bound values; none interpolates user input.
- **Output escaping:** unescaped output is limited to generated markup that escapes its own values (`e()`), and report CSS.
- **Authentication:** login and two-factor attempts are rate-limited; registration is closed; temporary passwords force a change.
- **Transport and headers:** HTTPS is forced in production; `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy` and HSTS are set (no Content-Security-Policy, deliberately, because of Alpine/Livewire).
- **File upload:** the CSV import validates type and size and re-parses the file on the server; it does not trust the preview.
- **Change control:** approvals, versions, signature rounds and the audit log are append-only in application code; a line with reported spending or an indicator with reported progress cannot be removed.
- **Tests:** 376 automated tests pass.

## Not audited (honest limits)

A penetration test; a load test beyond the earlier 3,000-activity measurement; a backup *restore*; accessibility of the new screens with a tool; the correctness of the rules against the real Ministry of Finance process; data-protection law compliance.

## Suggested order of work

1. **H2, H3, H1** (small to medium; they contradict the application's own rules).
2. **M1, M2** (consistency of approval and reporting).
3. **M3, M5, M4** (delivery and operations; cheap and protect everything else).
4. **M6, M7**, then the Low items as maintenance.
