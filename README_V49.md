# v49 - sequential, resumable product-by-product sync (spec section 13)

## 0. What was and was NOT verified (read first)
No PHP, MySQL, network, browser or provider access in the build sandbox (as in v46-v48). **Executed and passing:** `python3 tools/mirror_v49_check.py` (19 checks: item validation, crash/resume without a duplicate write, idempotent re-run, status rules, failed page = zero writes, one worker at a time), `node --check assets/js/admin-provider-sync.js`, a bracket-balance scan of the edited PHP. Those are Python mirrors of the decisions on invented data. **NOT executed:** `php -l`, `tools/test_sync_jobs.php` (new, unrun), `tools/test_provider_sync.php`, any SQL, any provider call, any browser test. Your test list K1-K12 is therefore **not done**; do not read this file as "tests passed".

## 1. What the spec asked for vs. what v48 already had
Already in v46-v48 (reused, unchanged): all-page fetch that aborts the run on any failed/repeated/empty page, 3 retries with back-off, named-lock overlap guard, cron loop, duplicate/ambiguity detection, archived-card review, honest incomplete status, server-side token handling, idempotent apply functions (each in its own short transaction).
Missing, added now: a **job with checkpoints**, **row-by-row processing with live progress**, a **resumable queue**, a **10 s default**, **per-row validation before writing**.

## 2. How it works
- `ProviderSyncService::tick()` (cron) / `syncNow()` (SYNC NOW) / `stepActive()` (page polling). The MySQL named lock is held only while a *step* runs (never for the whole job); a second worker returns `skipped`.
- Step 1 (fetch + plan): every page is fetched; nothing is written unless the whole catalog was read. A work list is saved in `sync_job_items`, one row per usable provider entry, per local row that changes/disappears, per entry held for review and per unusable entry. Order: new -> id-changed -> changed -> restored -> removed -> already-correct -> review -> unusable.
- Step 2 (process): rows run one at a time. Before a row is applied it is validated (numeric id, name, price present and valid, currency USD/INR, API version known; for existing rows: the local plan still exists and is not archived; a missing/invalid provider price never overwrites a valid price). Then the existing `applyNew/applyChanged/applyVariantChange/applyRestored/applyRemoved` run (own transaction, duplicate guards inside), the row is marked, counters and "current product" are saved. No transaction is open between rows.
- Resume: state is in the database. A row left in `running` after a crash is re-run; `applyNew` finds the existing mapping and reports `already_mapped` (no copy). A failed row is retried once at the end of the queue; validation problems are never retried (they go to review).
- Statuses (job): `complete` only when all pages were fetched, every row was processed and nothing is held, skipped or failed. `awaiting_review` (held/skipped entries; reported separately), `partial` (a row failed, a row could not be verified, or removals were blocked by the mass-removal guard), `failed` (catalog not fully fetched or empty: **nothing changed, nothing removed**), `stopped` (admin stop). Legacy `sync_last_status` is mapped to `ok / ok_incomplete / ok_with_errors / failed`; "LAST COMPLETE SYNC" moves only on `complete`.
- Automatic cycles with nothing to do are verified in memory and **not stored** (otherwise a 10 s interval would write ~8,640 jobs and ~2 M rows a day). Manual SYNC NOW stores every row, including unchanged ones. SYNC NOW during an automatic job supersedes it. Old jobs are pruned after 14 days.
- Progress panel (Admin -> Provider Sync): entries, pages, rows processed, imported, variants, updated, price changes, already correct, review, archived, duplicate-id conflicts, invalid price, unusable, failed, current product + provider id, status, last complete sync, and the list of rows needing attention. The page calls `sync_step` (~2 s of work per request) and redraws from the answer (about every 2 s, no extra provider calls; a separate parallel poll would be serialised by PHP sessions). A closed page does not stop cron.
- Repair / merge / restore are refused (409) while a job is active, so they cannot change data under a queued plan.
- Default interval is now **10 s** (only when nothing was configured; an existing setting is kept). Minimum stays 4 s.

## 3. Limits you must know
- **Provider rate limit is unknown** (not in the supplied source). Every idle tick makes at least one `catalog.php` request (more with pagination), i.e. up to 6/minute per page at 10 s. Failures back off (x2, max 60 s). `Retry-After` headers are **not** parsed. Watch Admin -> Provider Sync -> ERRORS for 429s and raise the interval if they appear.
- The fetch phase is atomic: if it is interrupted it is repeated from page 1 (it changes nothing). Per-page checkpoints are not stored.
- Without a cron worker a job only advances while an admin page is open; the panel shows "WAITING FOR THE NEXT WORKER" when no progress was made for 90 s.
- A row re-run after a crash is idempotent for new imports; for updates it re-applies the same values (an extra log line is possible).
- Real catalog field names are still unconfirmed (see v46-v48): send `php tools/audit_catalog.php --raw` output.
- `ProviderSyncService::run()` (v48 one-shot) is kept for the old tests and now skips while a job is active; cron and the admin buttons use the job layer.

## 4. Run on staging (back up the DB first)
1. `php -l includes/provider_sync.php api/admin/provider-sync.php cron/provider_sync.php`
2. `php tools/test_sync_jobs.php` (staging DB only) and `php tools/test_provider_sync.php`
3. `php tools/audit_catalog.php --raw`
4. Cron: `* * * * * php /home/ACCOUNT/public_html/cron/provider_sync.php --loop`; Admin -> Provider Sync -> auto-sync ON, interval 10.
5. SYNC NOW and watch the panel; kill the cron/close the page mid-job and confirm it resumes; run SYNC NOW twice and confirm no new cards.

## 5. Files
Changed: `includes/provider_sync.php`, `api/admin/provider-sync.php`, `cron/provider_sync.php`, `assets/js/admin-provider-sync.js`, `assets/css/provider-sync.css`, `admin.php`. New: `database/migration_v49_sync_jobs.sql` (optional, auto-created), `tools/test_sync_jobs.php`, `tools/mirror_v49_check.py`, `README_V49.md`.
