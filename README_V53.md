# v53 - live price propagation, honest sync numbers, measured interval

## 0. What was and was NOT verified
No PHP, MySQL, network, browser or provider access existed in the build sandbox.
Executed: `node --check` on store.js / checkout.js / admin-provider-sync.js; `python3 tools/mirror_v52_check.py` (v52 logic mirror, still passes); a bracket-balance scan of the edited PHP.
NOT executed: `php -l`, any PHP/SQL, any provider call, any browser test, any of the 14 tests in the task. They must be run on staging (back up the DB first).

## 1. Root causes found (by reading the code)
1. **Price only visible at Checkout** - `assets/js/store.js` polled every 5 s but skipped the refresh while the duration sheet or checkout was open, and both held a snapshot of the price taken when they were opened. A new price therefore only appeared when the user closed everything and opened Checkout again. Fixed: polling continues while they are open and patches the visible prices in place; the sheet re-resolves the plan on click; `checkout.js` gets `sync()`.
2. **Responses could be cached** - `api/products.php` sent no cache headers. It now sends `no-store`.
3. **Stale-price purchase** - the charge already used the database price (`purchase.php`, `purchase-api.php`). Added a guard: the browser sends the price it displayed (`expected_price`, a hint only, never used as the price). On mismatch nothing is charged and the server answers 409 `PRICE_CHANGED` with the real price.
4. **Wrong "checked" count** - `existing_checked` counted every mapped row that had a provider entry, even with an invalid provider price. It now counts only rows whose provider price was retrieved and validated (`existing_mapped` / `existing_checked` / `existing_unverified`).
5. **Silent exclusion of plans (probable cause of "only 1 variant checked") - UNCONFIRMED** - `loadLocal()` drops every plan that is not `source='api'` AND `provider_key='licencedashboard'` AND has a numeric `provider_variant_id`, without any report. New `ProviderSyncService::mappingAudit()` counts them per reason (other provider_key, empty id, unreadable id, duplicate id) and stores the result + sample plan ids (`sync_mapping_audit`, `sync_errors` stage `mapping_audit`). **Run one SYNC NOW and read the audit: it will name the real reason on your data.** I could not reproduce the original 1/234 figure without your database.
6. **"Actual interval" was an assumption** (`max(target, fetch time + 1)`). It is now the measured average gap of the last 20 completed server-side cycles (`sync_cycle_times`). If no cycle completed within 3x the target (min 30 s) the page says the cron worker is not running and shows no next-check time.
7. **The 4-minute display** - no code path producing 240 s was found (defaults are 10 s, unit is seconds everywhere). The most likely cause is the old "actual interval" derived from fetch time, or a stored `sync_interval_seconds` of 240 in your settings table. Check: `SELECT * FROM settings WHERE setting_key LIKE 'sync_interval%';` then save 10 on the page.

## 2. Files changed
`api/products.php`, `api/purchase.php`, `api/purchase-api.php`, `includes/functions.php` (`purchase_price_mismatch`), `includes/provider_sync.php`, `assets/js/store.js`, `assets/js/checkout.js`, `assets/js/admin-provider-sync.js`, `assets/css/store.css`, `README_V53.md`.

## 3. Not done / not verified
- Provider pagination contract, the 37 conflicts, the 1 failed operation, price correction, restore/bulk import, removal handling: v52 code reviewed only, not changed, not run. The failed operation's cause needs the `sync_errors` rows from your server.
- Effective interval, variants checked, prices corrected: no numbers exist, nothing was run. Run `php cron/provider_sync.php --force` and read the page.
- The 10 s schedule still needs the cron line `* * * * * php /path/cron/provider_sync.php --loop`. Hosts that kill long CLI jobs will give a slower measured interval; the page now shows that honestly.
- A browser tab only sees a new price within ~5 s (polling, no server push).
