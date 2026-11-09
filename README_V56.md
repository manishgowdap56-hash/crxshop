# v56 - Create Product: Provider Variant ID -> provider price -> INR price (Price Sync engine reused)

## Read this first: what was and was NOT verified
The build sandbox had **no PHP, no MySQL, no network, no provider token**. **Nothing here was executed against PHP, a database or the real provider.**
Tests A-I from the task: **none was run end to end.** What WAS done (static only):
- `node --check assets/js/admin.js`: passed.
- Bracket-balance check of the changed PHP files with Pygments (before = after = balanced). This is **not** `php -l`. **Run `php -l includes/price_sync.php api/admin/products.php` before deploying.**
- A Python *mirror* of the lookup rules ($1.50 -> 45.00, $3.00 -> 90.00, INR used as is, unknown id / zero / EUR / duplicate-with-different-price refused): passed. Proves the rule, not the PHP.
- `tools/test_create_product_lookup.php` (tests A-E and G on a fake catalog via the CLI-only hook) is written but **not run**. Run it on staging.
Not tested: the browser UI, the real catalog shape, provider rate limits, hosting cron behaviour.

## What changed (4 files + 2 new)
- `includes/price_sync.php`: new `PriceSyncService::lookupVariant()` - reads the same catalog as the cron (`fetchAllPages` + `collectPrices`), matches the **exact provider id**, prices it with the existing `websitePrice()` (USD x 30 once, explicit INR as is, anything else refused). Read-only. **No second conversion, no second sync engine.**
- `api/admin/products.php`: new POST `action=lookup_price` (admin + CSRF; id only from the browser, price never). On `save`, for each **new or changed** Provider 5 Variant ID the server re-verifies the provider price after the commit (best effort, never blocks/undoes the save) and writes only `provider_price / provider_currency / price_rate / inr_cost / provider_snapshot`; the selling price is left as saved. If it differs from provider price x 30 the response carries a note.
- `assets/js/admin.js`: inside the existing duration card (Provider 5 + API STOCK only): **CHECK PROVIDER PRICE** button, automatic lookup ~0.8 s after the ID stops changing, status line, **USE ₹x** button. Stale answers are ignored; changing the ID clears an auto-filled price; a price the admin typed is never overwritten (button offered instead); Save waits while a check is running; errors show **RETRY**.
- New: `tools/test_create_product_lookup.php`, this README. No migration. No schema change. Cron, lock, logging untouched.

## Behaviour you should know
1. **Lookup exists for Provider 5 only.** The only provider whose catalog the Price Sync engine can read is Licence Dashboard. For Providers 1-4 the form works as before (manual price) and the lookup endpoint answers that it is unavailable. I did not invent an adapter for them.
2. **Existing rule, not changed:** the v55 Price Sync forces `price = provider USD x 30` for every mapped Provider 5 variant on its next cycle, **even over an admin-typed price**. So a custom selling price on a Provider 5 variant is corrected by the sync. That is v55's documented behaviour; you asked me not to change conversion rules, so I did not. The form warns (message after save) when your price differs.
3. The local product name never takes part in matching - only the id. The duration check is a **warning only** (provider name says "N days" and the duration field says a different N).
4. Auto-matching of id by name/duration: **not implemented** - no documented provider endpoint for it; manual id entry is the path.

## Cron (checked by reading the code, not run)
`/usr/local/bin/php /home/fytcjoyd/public_html/cron/provider_sync.php --loop >> /home/fytcjoyd/provider_sync.log 2>&1` is compatible: CLI-only guard (`PHP_SAPI !== 'cli'` -> 404), `--loop` supported, named MySQL lock `GET_LOCK` prevents overlap, ~55 s budget per minute. New plans are eligible when `source='api'`, `provider_key='licencedashboard'`, product not archived, numeric id - exactly what Create Product saves. Caveat: one cycle reads the whole catalog (20 s timeout per page), so a slow provider can run a cycle past 55 s; the next minute's process then just skips on the lock. Keep exactly one cron line. Schedule not changed.

## Deploy
1. Back up. Upload files over v55. 2. `php -l` on the two PHP files. 3. Admin -> Products -> new duration -> API STOCK -> LICENCE DASHBOARD -> type the id -> price fills. 4. Staging: `php tools/test_create_product_lookup.php`.
Hosting note: the lookup reads the full catalog (like one sync cycle), so expect a few seconds per check.
