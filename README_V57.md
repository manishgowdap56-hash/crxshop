# v57 - Create Product: provider product NAME + DURATION -> Variant ID + price, automatically

## Read this first: what was and was NOT verified
No PHP, MySQL, network, browser or provider token in the build sandbox. **None of your tests 1-9 was run end to end.** Not executed: any PHP, SQL, the provider API, the browser UI, the cron.
Executed (static / mirror only): `node --check assets/js/admin.js` (passed); bracket-balance of the changed PHP (balanced; NOT `php -l` - run `php -l includes/price_sync.php api/admin/products.php`);
`python3 tools/mirror_find_variant_check.py` - a Python MIRROR of the matching algorithm on invented catalogs, 16 checks passed (name+duration -> id, 1 Day vs 10 Days, BRPC vs BRPC SILENT, 24 h = 1 day, "Root+Pc" vs "Root Pc", ambiguous, unknown, duration-only entries, EUR refused, INR not x30 again, $0.20/0.70/1.50/3.00 -> 6/21/45/90). One of my own expected values was wrong in the first run (270.00, not 9.00); the algorithm was right, I fixed the test.
Written, not run: `tools/test_find_variant.php` (staging).

## The big limitation (exact)
**The real `catalog.php` response is not in the project and the code base itself says its shape is undocumented.** Matching therefore reads every plausible shape (same ids/containers as the sync): nested `{name, variants:[{id,name|duration}]}`, flat `{id, name:"BRMOD 7 Days"}`, flat `{id, name, duration}`, and `{id, name:"7 Days", product_name|parent_name|…}`. An entry that is only a duration with **no** product-name field (e.g. only a category) is **never auto-matched** (its parent would be a guess); the admin then enters the ID manually. If your catalog uses a field I do not read, nothing wrong is picked - you get "no match". Send me one raw catalog entry (`php tools/verify_licencedashboard.php --catalog`) and I will add the field.

## What changed
- `includes/price_sync.php`: `catalogSnapshot()` (all pages, 45 s file cache in the system temp dir, flock-serialised so several plans share one read; `fresh` bypasses it), `normKey()`, `parseDuration()`, `indexCatalog()`, `findVariants()`, `providerProductNames()`. Prices come from the existing `collectPrices()` + `websitePrice()` (USD x 30 once, explicit INR as is) - no second converter. `lookupVariant()` (CHECK PROVIDER PRICE / CHECK AGAIN / save-time verification) now always reads **fresh**, never the cache.
- `api/admin/products.php`: new read-only actions `find_variant`, `provider_products` (admin + CSRF). Save, cron, sync engine: unchanged from v56.
- `assets/js/admin.js` (existing Duration Plan card only): new optional **PROVIDER PRODUCT NAME** field (empty = the product name above; suggestion list), duration suggestion list (datalist), automatic search ~0.9 s after name/duration/provider/source change, per-plan independent state.

## Matching rules
Exact product name (case, spacing, punctuation ignored; "+" and "&" kept as words) AND exact duration (24 hours = 1 day; 1 month is NOT 30 days). No substring/fuzzy auto-pick (BRPC never matches BRPC SILENT). Exactly one priced variant -> ID and price filled. Several -> buttons to choose. None -> message with the durations that exist and similar names, manual ID kept. Provider failure -> error + FIND ID retry. A found ID with an unusable price fills nothing.

## Safety behaviour
- Name or duration change: an auto-filled ID and auto-filled price are removed and the search runs again; only that plan changes. A new plan starts empty (nothing copied).
- An ID the admin typed, or a saved plan's ID, is never overwritten: a **USE ID n** button is offered. A price the admin typed is never overwritten: **USE ₹x** is offered.
- Messages show the time the provider data was read. Cached data (<=45 s) is used only to search; CHECK AGAIN and save always read fresh.
- Not run on page load (editing a product does not trigger searches). Provider 5 only; other providers keep manual entry.
- Existing v55 rule still applies: Price Sync sets Provider 5 prices to provider price x 30 on its next cycle, even over a typed price.

## Cron
Not modified. A plan saved with the detected ID is `source='api'`, `provider_key='licencedashboard'`, numeric id, i.e. exactly what `loadLocal()` selects. Verified by reading code only (test 8 not executed).

## Deploy
Upload over v56, `php -l` the two PHP files, open Admin -> Products, pick API STOCK -> LICENCE DASHBOARD, type a provider product name and a duration. Staging: `php tools/test_find_variant.php`. No migration. Temp-dir cache: if not writable, the cache is skipped and every search reads the catalog (slower, still correct).
