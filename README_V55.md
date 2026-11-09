# v55 - Product Sync removed, only automatic Provider PRICE sync kept

## Read this first: what was and was NOT verified
The build sandbox had **no PHP, no MySQL, no network and no provider token**. **Nothing in this ZIP has been executed against PHP, a database or the real provider.** Because of that:
- none of the 14 tests in the task could be run end to end,
- I cannot report a real effective interval, a real number of mapped variants, or a real number of corrected prices.
Those numbers appear on **Admin -> Price Sync** after the first cycles on your server. Do not trust a number from me that is not from your server.

What I DID do (all static, labelled as such):
- Read the whole v54 project and every reference to the removed features.
- Token/bracket lint of all new or changed PHP files with Pygments (catches gross syntax errors only; it is **not** `php -l`). **Run `php -l` on `includes/price_sync.php`, `api/admin/price-sync.php`, `cron/provider_sync.php` before deploying.**
- `node --check` on every changed JS file (passed).
- `python3 tools/mirror_price_sync_check.py`: a Python *mirror* of the price algorithm (integer arithmetic, id matching, keep-on-invalid decisions) on synthetic catalogs: 24 checks passed, including $1/$2/$3.50/$5 -> INR 30/60/105/150 and "admin custom price is corrected". This proves the algorithm, not the PHP.
- `tools/test_price_sync.php` is a real server-side test (fake catalog through the CLI-only hook, creates and deletes its own temporary product). **I did not run it.** Run it on staging.

## Removed (not just hidden)
- Admin pages **Provider Sync** (catalog monitor), **New Provider Products** (pending import + "Provider catalog - add / restore"), their nav buttons, badges, sections in `admin.php`, page-map entries in `admin.js`.
- `assets/js/admin-new-provider.js`, `assets/js/admin-provider-sync.js` (deleted), `api/admin/provider-sync.php` (deleted: every import / add_to_site / restore_product / consolidate / repair / go_live / catalog / maintenance_update endpoint; the old URL now returns 404).
- `includes/provider_sync.php` (3,400 lines: discovery, pending queue, import, restore, consolidation, repair, removal handling, sequential job engine) - replaced by the 850-line `includes/price_sync.php`.
- Scheduled catalog work: `cron/provider_sync.php` now only runs price cycles. The old job tables are no longer read or written.
- Obsolete tools (catalog audit, duplicate consolidation, repair, diagnose_sync, old tests and mirrors) and migrations v43 / v49 / v50.
- Catalog-sync settings (removed once, idempotently, on first run; also in `database/migration_v55_price_sync_only.sql`).

## Deliberately NOT removed
Products, variants, mappings (`product_plans.provider_variant_id / provider_key / source`), orders, users, wallet, payments, referrals, checkout, the provider API client (`includes/providers.php`), Admin -> Products (including its archived view and maintenance toggle - these are product management, not sync), `rp_provider_go_live_check()` (used by Admin -> Products). **No table is dropped**: `provider_pending_products`, `sync_jobs`, `sync_job_items` stay in the database, unused (optional DROP statements are commented in the migration).

## Price Sync (what remains)
Per cycle, under a MySQL named lock (`GET_LOCK`, so cycles never overlap): read the provider catalog (all pages) -> match each mapped variant by exact provider id -> price = **provider USD price x 30**, always from the original provider price, converted once, half-up to the paisa, **no markup** -> if `product_plans.price` differs it is corrected (an admin-typed price or the old "keep admin price" flag no longer protects it) -> one transaction per variant with a read-back check -> history card.
- The rate 30 is a constant in code (`PriceSyncService::RATE`), not a setting.
- An explicit provider currency of INR is used as is (never multiplied again); any other currency, a missing / zero / non-numeric price, or an id listed twice with different prices is NOT used and the stored price is kept. Failed catalog read = nothing changed.
- Only existing mapped variants (`source='api'`, `provider_key='licencedashboard'`, product not archived). Nothing is created, imported, published, restored, renamed, disabled or deleted.
- History (Admin -> Price Sync): one card per genuine correction or failed correction (product, product id / provider variant id, duration, previous price, provider USD price, rate, correct INR price, final saved price, detected time, saved time, result Corrected / Already Correct / Failed). A price that is already right writes no card; an open failed card is updated in place (attempts + 1), and becomes "Already Correct" when a later check verifies it.
- Bug found and fixed: v52 created `provider_price_history.direction` as VARCHAR(8) but wrote the 9-character `'corrected'`; in strict mode that INSERT fails and the history card was silently lost. Widened to VARCHAR(10).

## Interval
Target default **10 s** (min 4). cPanel cron cannot run faster than once a minute, so `cron/provider_sync.php --loop` loops for ~55 s inside each minute:
`* * * * * /usr/local/bin/php /home/ACCOUNT/public_html/cron/provider_sync.php --loop >> /home/ACCOUNT/provider_sync.log 2>&1`
**A cycle reads the whole provider catalog first, so the real interval can never be shorter than one catalog read** (the old v54 README measured this problem; it has not been measured here). The page shows the *measured* interval (average gap of the last real cycles), the seconds per cycle, and a note when it is slower than the target. If one catalog read takes more than 10 s, 10-second checking is not possible with this provider API (no per-product price endpoint is known in this code); the page then shows the real, longer interval and the code never claims 10 s. On provider errors / 429 the loop backs off (x2 up to 60 s).
The old unit confusion is gone: the interval exists only as `sync_interval_seconds` (seconds), the page says "SECONDS".

## Price reaches the customer without clicking Checkout
- `product_plans.price` is the single authoritative column; the sync writes it, nothing caches it server-side.
- `api/products.php` (already `no-store` since v53) and now `api/api-variants.php` send no-store headers.
- Store page: polls every 5 s (v53) and patches the open product sheet / duration selector / checkout in place. Key Generator (`api.js`): new 5 s poll, re-renders only when a price / stock changed and keeps open cards open.
- `api/purchase.php` and `api/purchase-api.php` charge from the database row inside the transaction; `expected_price` from the browser is only compared (mismatch -> HTTP 409 `PRICE_CHANGED`, nothing charged), never used as the amount. (Already so in v53; reviewed, not changed. There is no separate cart.)

## Deploy
1. Back up the DB. Upload. Run `php -l` on the three PHP files named above.
2. Open Admin once (tables/columns are added automatically) -> Price Sync -> **CHECK NOW** and read the result tiles. **MAPPED VARIANTS / CHECKED** is the real number of variants matched. If MAPPED is high but CHECKED is low, open the PROBLEMS tab: it lists every variant that could not be checked and why (id not in catalog, unusable price, ...). (v54 reported ~1 matched of 234; the cause was never established because the real catalog response was not available - the PROBLEMS tab and `tools/diagnose_service.php <id>` now show it per variant.)
3. Keep the cron line above. `php cron/provider_sync.php --force` runs one cycle by hand.
4. On staging: `php tools/test_price_sync.php`.

## Known limits
- Matching is by exact provider id as stored by the earlier importer; if mapped ids do not appear in `catalog.php` under `product_id|id|pid|service_id|variant_id|plan_id`, those variants are reported as mapping errors and not touched.
- Not tested: provider rate limits at 10 s, hosting cron behaviour, real catalog shape, any browser behaviour.
