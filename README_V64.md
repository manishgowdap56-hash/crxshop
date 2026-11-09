# v64 - Dual Check Again, strict category/rate isolation, Membership card removal

Base: v63. Targeted fixes only. **No database change, no migration.** No table, column, setting or product/user/order/wallet row is created, altered or touched by this release.

## HONEST VERIFICATION STATUS (read first)
The build environment has **no PHP binary, MySQL, browser, provider or cron**. Therefore:
- **`php -l` was NOT run.** Run on your server (must print nothing):
  `find . -name '*.php' -print0 | xargs -0 -n1 php -l | grep -v '^No syntax errors'`
- Executed and passing: `node --check` on all 33 JS files; `tools/php_lint_approx.py` (tokenizer, 0 findings - NOT a real lint); a string/brace balance scan of every modified PHP file; `tools/check_v63_static.py` (14), `tools/check_v64_static.py` (23 source-text invariants), `tools/mirror_v61_check.py`, `tools/mirror_price_sync_check.py`, `tools/mirror_money_check.py`, `tools/mirror_find_variant_check.py`, `tools/mirror_v59_check.py`, `tools/mirror_out_of_stock_check.py`, `tools/test_order_states.py`.
- `tools/mirror_v64_check.py` (21 checks, new) is a **Python mirror of the rules** (spec tests 1-4, 7-9, 11-13 and the missing-rate cases). It does **not** execute the PHP.
- **NOT performed** (need your staging server): PHP execution, real DB transactions/locks, concurrent Price Sync / concurrent membership requests (spec tests 14-15, A-G), live provider calls, browser/mobile layout of the new verification cards, `tools/test_v61_staging.php` / `test_price_sync.php` against a database.

## Root causes found in the real code, and the fix
1. **Check Again verified only Normal.** The editor's CHECK AGAIN called `lookup_price`, which filled/compared only the Normal box (`lkShowPrice`); Reseller was only auto-filled when empty and never compared. Now CHECK AGAIN calls the new `check_prices` action: ONE fresh provider read, then Normal (`normal_usd_inr_rate`, `price`) and Reseller (`sync_usd_inr_rate`, `reseller_price`) are calculated and compared independently and returned as two results.
2. **Cross-category rate fallback (confirmed).** `PriceSyncService::settings()` fell back `normal_rate -> sync_usd_inr_rate -> 30`; `pp_config()` the same; `dual_rate()` fell back to 30; `websitePrice()` defaulted to 30. All removed. A missing/invalid rate is now `null`; that category's automatic updates are rejected and its stored price kept. INR provider prices never use a rate.
3. **One bad category blocked both in Auto Sync.** `cycle()` skipped the whole variant if either calculation was invalid. Now each category is validated and processed on its own (problems are reported per category).
4. **Save-time diagnostic compared the Normal price with the RESELLER calculation** (`api/admin/products.php`). Now Normal vs `normal_inr`, Reseller vs `inr`; each category's provider columns are written only when that category's calculation exists.
5. **Silent category coercion.** `dual_cat_valid()` turned anything into `normal`. It now throws on anything except `normal`/`reseller`; `dual_cat_cols()` is strict; new `dual_cat_try()` returns null. `api/admin/monitor.php` (`set_custom`, `confirm_increase`) now **requires** an explicit category (the Monitor JS already sent one).
6. **Membership card stayed for Resellers** (`dashboard.php` condition `$isRes || enabled || last_purchase`). Now rendered only when `!$isRes && Membership ON`; the Reseller branch is deleted from the template, so nothing (no heading, button, message, empty/hidden container) is rendered for a Reseller. `$isRes` comes from `users.account_type` read from the database.
7. **Delayed membership reload** (1.5 s timer). After the server answers `is_reseller = true`, the whole card is removed at once and the page reloads immediately; no success is shown before the backend confirms. A 409 "already a Reseller" also removes the card (no charge).

## Check Again behaviour (admin product editor)
Two cards, each: Provider Price, Exchange Rate, Calculated Price, Current Selling Price, Difference, Status. Statuses: CORRECT, PRICE MISMATCH, CUSTOM PRICE, MANUAL PRICE, PENDING REVIEW, INVALID RATE, INVALID PROVIDER PRICE, UNVERIFIED. For a saved duration the "current" price is the **database** value (only if plan id, product id and provider id all match); for an unsaved duration it is the typed value.
- Correction buttons `USE NORMAL ₹x` / `USE RESELLER ₹x` are independent. Each sends its own category to `apply_price`; the server re-reads the provider, recalculates with that category's own rate, validates, and writes **only that category's columns** through the existing `PriceSyncService::correct()` (per-plan `FOR UPDATE`, read-back check, history card with `price_category`). The browser never supplies a price.
- Protections: Custom Price (that category only) and a running review block the correction with a clear message. **Manual price**: the existing policy never treated `price_manual` as a hard lock (Price Sync resets it), so it is reported as MANUAL PRICE and replacing it needs an explicit confirm dialog; this is a decision for you to confirm.
- `apply_price` takes the existing named MySQL lock (`royalpanel_provider_sync`, 5 s wait), so it cannot write the same plan while a Price Sync cycle runs.
- Check Again is read-only: it writes no history and no rows, so repeating it cannot create duplicates.
- UI guards: no second request while one is running; answers for a changed Variant ID / closed or switched product are discarded.

## Files changed
includes/dual.php, includes/price_sync.php, includes/price_policy.php, api/admin/products.php, api/admin/monitor.php, assets/js/admin.js, assets/js/admin-price-sync.js (missing rate shows NOT SET), assets/js/reseller-membership.js, dashboard.php, tools/check_v63_static.py (two brittle text patterns updated to the new code shape), tools/check_v64_static.py (new), tools/mirror_v64_check.py (new), README_V64.md (new).
Unchanged and re-read: `dual_membership_purchase()` (user row `FOR UPDATE`, existing-Reseller rejection, ON/OFF + button + price + balance validation, atomic `balance >= price` deduction, ledger + membership rows, `account_type` update, rollback on any failure) - the existing code already met the spec; Admin membership controls; wallet/deposits.

## Behaviour changes to be aware of
- If `normal_usd_inr_rate` or `sync_usd_inr_rate` is missing/invalid, that category is no longer silently priced with a default; it is reported (Price Sync problems list, INVALID RATE in Check Again). Migrations v59/v61 already seed both settings, so existing installs are unaffected. Fix by saving the rate in Admin -> Monitor -> Settings.
- Any custom code that calls `dual_cat_valid()` with a non-category value now throws instead of returning `normal`.

## Deployment
1. **Backup first**: database (`mysqldump --single-transaction DBNAME > backup_pre_v64.sql`) and the current site folder/ZIP.
2. Upload/extract this ZIP over the site (keep `config/` and `uploads/` as on the server). No SQL to run.
3. Run the `php -l` command above; it must print nothing. Hard-refresh the admin (JS files are cache-busted).
4. Smoke test on staging: Admin -> Monitor shows both rates; open a Provider 5 product -> CHECK AGAIN shows both cards; try the USE buttons on a test duration; run Price Sync once; buy Membership with a test Normal User.
5. **Rollback**: restore the previous site files (no DB change to undo; any prices corrected by USE/Price Sync are recorded in the price history tables and `backup_pre_v64.sql` restores everything).

## Recommended staging tests (not run here)
Spec tests 1-15 and A-G against a staging DB, in particular: both USE buttons on one duration (the other price must not change), Normal rate cleared (Reseller must still sync), concurrent `apply_price` + Price Sync, concurrent membership purchase requests (one activation), 320-430 px layout of the new cards.
