# v60 - fixes on top of v59 (this ZIP is the corrected v59 project)

## What was and was NOT verified (read first)
The build sandbox still has **no PHP binary, no MySQL, no network, no browser and no Provider 5 token**. Therefore:
- **`php -l` was NOT run.** I could not produce the "No syntax errors detected" output you asked for. Run it yourself (command below).
- What WAS run: `python3 tools/php_lint_approx.py .` - a hand-written, string-aware PHP tokenizer (quote/heredoc/comment aware; unbalanced brackets; two operands side by side). It **found your reported parse error** (price_sync.php line 989, a bare `'sync'` inside a single-quoted SQL string - my bug from v59) and, after the fix, reports **0 findings over 100 PHP files**. It is approximate and can miss real errors.
- An undefined-function scan over the whole tree (every called name that is not defined in the project): only PHP built-ins remain.
- `node --check` on every JS file in assets/js: all pass. `python3 tools/mirror_v59_check.py`: 47 mirror checks pass (Python re-implementation of the pure rules - proves the rules, not the PHP/DB).
- Written, NOT run: `tools/test_v59_staging.php`. No live Provider 5, Paytm, wallet, refund-concurrency, cron or browser test was possible.

Run on the server before going live:
```
cd /home/ACCOUNT/public_html
find . -name '*.php' -print0 | xargs -0 -n1 /PATH/TO/php -l | grep -v '^No syntax errors'      # prints nothing when everything is fine
/PATH/TO/php tools/test_v59_staging.php --run        # STAGING COPY ONLY
```
`/PATH/TO/php` is **your** PHP CLI. Find it in cPanel (Terminal: `which php`; or MultiPHP/"PHP CLI" info). I do not guess it.

## Fixed in this version
1. **Parse error** `includes/price_sync.php:989` fixed (`\'sync\'` escaped, SQL otherwise unchanged; bound parameters `:p :pp :pc :pr :ic :sn :pp2 :id` are each used once, as required with real prepared statements).
2. **Three independent workers**, each with its own MySQL named lock (`royalpanel_worker_*`, plus the existing price-sync/refund locks) and a **real heartbeat** row in `worker_runs` written by the CLI process:
   | Worker | Script | Cron |
   |---|---|---|
   | Provider Price Sync | `cron/provider_sync.php --loop` | every minute (loops ~55 s, one cycle per configured interval, default 10 s) |
   | Pending Order Review (NEW) | `cron/order_review.php` | every minute |
   | Automatic Refund | `cron/refund_controller.php` | every minute |
   Admin -> **Monitor -> Workers / Cron** shows status (OK / FAILING / STOPPED / NEVER RAN), last run, last success, last error, runs/failures, skipped-because-locked, last duration, and the price-sync numbers (variants checked, corrected, already correct, changes detected, pending price updates, measured vs target interval). Status is computed only from heartbeats: a stopped cron shows STOPPED; a browser session never shows RUNNING.
3. **Order Review worker** (`includes/order_review.php`): requests stuck in SENDING > 2 min -> UNCERTAIN; parked keys (provider delivered, DB failed) are completed; every pending order is classified (window / overdue / uncertain / missing_response / parked_keys), counted, stamped (`last_reconcile_at`), written to `order_review_events` on change; one de-duplicated alert per order that needs a human. **It never refunds, never marks failed, never calls generate.**
4. **Admin Monitor tabs**: API Order Monitor, **Order Review**, **Refunds** (auto-refund state, refund history, failed attempts, duplicate-protection check = number of order references with >1 refund ledger row, must be 0), Workers / Cron, Price Increase Alerts, Duration Monitor, Price History, Settings. All read the same `orders` / `provider_order_attempts` / `order_refund_log` / `wallet_ledger` data; no new order status was invented. Existing All Orders and Pending Orders pages (filters by status/provider/product/date/user) are unchanged.
5. **Duration removal default = 3 consecutive COMPLETE catalog checks** (admin-configurable 1-1000; extra "minimum minutes" default 0). Failed/partial/invalid/timeout reads are never counted and do not reset the counter. Archived, never deleted. A catalog where more than half of the mapped durations vanish at once is treated as implausible and not counted. Existing v59 installs that still hold the old defaults (30/5) are moved to 3/0 once; values you chose yourself are kept.
6. **Restore**: when the exact service id reappears with a valid price in a complete catalog and the mapping is valid, the **same** variant row is re-enabled (no duplicate), history + alert are written, custom price and mapping are kept; with an invalid price it stays disabled and flagged "reappeared". Setting `sync_auto_restore` (default ON) can be switched off -> admin must click Restore.
7. **Custom Price is protected**: ON = automatic sync, grace-period expiry and rate changes never change the selling price and never switch the custom price off. Provider cost changes are still recorded and alerted (with the calculated INR shown next to your custom price). OFF = automatic formula + grace period. (This replaces the v59 rule that disabled a custom price below cost.)
8. **Grace period 25 min** (setting 20-30). Alert/event now carries old/new provider price, **percentage difference**, rate, old/new INR, current price, detected time, scheduled update time. **Versioning**: each newer pending price bumps `price_change_version`; the automatic update applies only when the pending event's version equals the plan's (an outdated price is refused). The original deadline is kept when a newer price arrives. A price that returns to the stored value cancels the pending update.
9. **Price Sync only**: confirmed by code search - `price_sync.php`, `cron/provider_sync.php`, `api/admin/price-sync.php` contain no INSERT into products / categories / product_plans. The catalog is read only to check mapped service ids.
10. **Exchange rate**: admin setting `sync_usd_inr_rate` (Monitor -> Settings), default 30, never hard-coded in the calculation; formula = provider USD x rate, integer micro-units, half-up to paise, one conversion. **There is no markup anywhere in the code** (v55 rule "no markup" was kept); I did not add one because no configured markup exists in the project.

## cPanel cron (use your own PHP path)
```
* * * * * /PATH/TO/php /home/ACCOUNT/public_html/cron/provider_sync.php --loop >> /home/ACCOUNT/logs/provider_sync.log 2>&1
* * * * * /PATH/TO/php /home/ACCOUNT/public_html/cron/order_review.php        >> /home/ACCOUNT/logs/order_review.log 2>&1
* * * * * /PATH/TO/php /home/ACCOUNT/public_html/cron/refund_controller.php   >> /home/ACCOUNT/logs/refund_controller.log 2>&1
```
Create the `logs` folder first. cPanel's minimum is one minute; the 10-second rhythm is the `--loop` inside the Price Sync job and is only a **target** - the Monitor shows the measured interval. Check Monitor -> Workers / Cron after 2-3 minutes: all three must show OK. Provider 5 history.php limitation: see below.

## Provider 5 / history.php (documented limitation)
Documented endpoints: balance, catalog, generate, history. The supplied material gives **no response shape and no filter by order reference / provider order id / status** for history.php. No automatic key lookup was implemented (guessing fields could complete or refund the wrong order). Uncertain orders stay in Order Review; workflow: Admin -> API Settings -> Recent Keys (history.php), match by time/product, then Pending Orders -> Complete order with key, or Refund order. Whether Provider 5 honours `idempotency_key` is unverified; nothing relies on it (each order is sent once, guarded by the UNIQUE attempt row). V2 is the default; V1 only when the mapped id is `NN|v1` (existing v34 behaviour, unchanged).

## Migrations
`database/migration_v59_reliability_pricing.sql` + `database/migration_v60_workers_threshold.sql` - additive, repeatable, no DROP/DELETE of data (the only UPDATEs move the three old v59 default settings). The application applies the same changes automatically. Verified only by reading; not executed against MySQL. Rollback: restore the DB backup and v58/v59 files; the added columns/tables are harmless; optional `DROP TABLE worker_runs, order_review_events;`.

## Feature checklist (honest)
Legend: [x] implemented and statically checked only (lint + reading + mirror rules). [ ] not done / not verifiable here.
- [x] price_sync.php parse error fixed (approx lint clean; **php -l not run**)
- [ ] Full-project `php -l` passed - **NOT RUN (no PHP)**; approximate audit clean
- [x] JavaScript syntax check passed (`node --check`, all files)
- [ ] Price Sync works / Custom Price / grace / restore / workers / refunds in a live environment - **not executed**; logic implemented, rules mirror-tested
- [x] Custom Price protected (code); [x] grace 25 min + percentage + version guard (code); [x] default threshold 3 (code + mirror); [x] incomplete catalogs never counted (code + mirror); [x] restore after validation (code + mirror)
- [x] No automatic product import (code search)
- [x] Website and reseller paths share one executor (code); [ ] verified against a real/staged provider
- [x] Order Review worker + Refund worker + Price Sync worker each lock + heartbeat (code); [ ] cron actually running on your host - check Monitor
- [x] Duplicate-refund protection = existing exactly-once engine (unchanged) + monitor check; [ ] concurrent refund test - **not run**
- [ ] Wallet / Paytm / deposits / user dashboard regression - **not run** (files untouched except order settlement, price sync, admin product save, admin nav)
- [x] ZIP root = site root, no tokens in the package

## Files
New: includes/workers.php, includes/order_review.php, cron/order_review.php, database/migration_v60_workers_threshold.sql, tools/php_lint_approx.py, README_V60.md (v59 additions: order_executor.php, price_policy.php, monitor page/API/JS/CSS, v59 migration, tools).
Modified in this round: includes/price_sync.php, includes/price_policy.php, includes/bootstrap.php, cron/provider_sync.php, cron/refund_controller.php, api/admin/monitor.php, admin-monitor.php, assets/js/admin-monitor.js, tools/mirror_v59_check.py, README_V59.md.
