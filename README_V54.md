# v54 - what is proven, what is not, and the tool that finds the rest

## Read this first
Same limits as every earlier build: the sandbox had **no PHP, no MySQL, no network, no provider token, and your real `catalog.php` response is not in the ZIP**. Nothing here was executed against your system. The task's test list (sync execution, real interval, variants checked, corrected prices, 14 tests) therefore has **no results**. I will not report numbers I do not have.

## Correction to v53
README_V53 told you to run `SELECT * FROM settings WHERE name LIKE ...`. The column is `setting_key`. Fixed in README_V53. Use:
`SELECT setting_key, setting_value, updated_at FROM settings WHERE setting_key LIKE 'sync%';`

## What the code audit established
- No code path anywhere produces "4 minutes": interval is stored/read in seconds only (`sync_interval_seconds`, default 10, min 4), the admin JS sends `interval_seconds`, no second scheduler exists (only `cron/provider_sync.php`), `settings.php` has no sync keys. So the value you see is not a unit bug in this ZIP.
- The old "ACTUAL INTERVAL" tile was `max(target, last_catalog_fetch_seconds + 1)`. **A cycle starts with a full catalog read (all pages, one after another), so the real interval can never be shorter than that read.** If a page takes ~10 s and the catalog has ~24 pages, one read is ~4 minutes. That is the most plausible origin of "4 minutes" and it would be a REAL limit, not a display error. It is a hypothesis: `tools/diagnose_sync.php` section B prints per-page timing and settles it.
- Report arithmetic: 196 pending + 37 conflicts + 1 existing = 234 = the whole catalog. That means essentially **no mapped local plan matched a catalog id**. Possible causes, all decidable from your data: (a) mapped plans have a different `provider_key` / empty or unreadable `provider_variant_id` (they are dropped silently by `loadLocal`), (b) the catalog carries the orderable id in a field other than `product_id`/`id`/`pid`/`service_id`, (c) the mapped ids are not in the catalog. `diagnose_sync.php` sections C and E1 print the match count per catalog field and per plan group.
- "196 waiting vs 0 newly found": waiting = rows still stored in `provider_pending_products`; "newly found" = rows first seen in that run. Both are correct and different; section E2 lists them.
- Settings cache: `settings_all()` is cached per PHP process. The cron `--loop` process re-reads settings only at the next cron minute, so an interval change takes up to a minute to apply. Not a cause of 4 minutes.

## Changes in this version
- NEW `tools/diagnose_sync.php` (CLI, read-only): interval/cron evidence, live per-page catalog timing and completeness, id-field matching against your mapped plans, price field/currency, plan audit, pending/conflict reasons, the failed operation's error messages.
- README_V53 SQL fixed.
- Nothing else was changed in the sync logic: changing it further without the real response would be a guess.

## What to do (5 minutes)
1. Back up the DB. Deploy.
2. `php tools/diagnose_sync.php > diag.txt` on the server (add `--no-net` to skip the live catalog calls).
3. Read A (cron alive? measured gaps), B (seconds per catalog read), C (which field matches your ids), E1/E3.
4. Send `diag.txt` (it contains no token). With that I can make the id/price field choice and the cadence match your provider exactly, instead of guessing.

## Known limitation to expect
If B shows one catalog read takes more than 10 s, a 10-second price check of the whole catalog is **impossible** with this provider API (no per-product price endpoint is known in this code). The fastest safe cadence is then one read per `max(read time, interval)`; the page already shows the measured value and never claims 10 s.
