# v41 – Provider Sync, final update

## 0. What was and was NOT verified
No PHP, MySQL or network exists in the build sandbox. **Executed:** `node --check` of the edited JS and a bracket-balance scan of the edited PHP. **NOT executed:** any PHP/SQL path, the admin page in a browser, any call to the real provider. Before going live: `php tools/test_provider_sync.php` (pure logic, no DB), then on STAGING `php cron/provider_sync.php --force`.

**The real `catalog.php` response was never seen** (not in the ZIP, no network). The v40 notes say the same. `normalize()` now accepts: flat lists; entries with nested `variants|plans|packages|durations|tiers|services` (each variant must carry its own id, otherwise it is skipped, never invented); category containers (`name` + `products|items`). If your JSON differs, open Admin → API Settings → LOAD CATALOG, read the raw JSON and adjust ONLY `ProviderSyncService::walk()/normalize()`.
Important limit: the only order call the code knows (`generate.php`) takes a single `product_id`. So the id stored per variant must be an id `generate.php` accepts. If your catalog's variant ids are not valid `product_id`s, tell me the real response and the mapping will need adjusting.

## 1. What was wrong in v40 (against your spec)
- New products were created **hidden** (`enabled=0`) in a hidden "staging" category, with a separate Go-Live system → now visible in the store in the EXISTING `products.maintenance` flag.
- It introduced a **reseller price** → removed from the sync and the sync page (the old column is left untouched).
- One product = one plan; no variants, no provider category → variants + categories added.
- Provider price changes overwrote your selling price → now only if "Follow provider price" is ON.
- Interval was minutes (min 5) → seconds (min 4).

## 2. Behaviour now
- NEW provider item → product `enabled=1, maintenance=1` (storefront shows it with the existing maintenance design; all three purchase endpoints already refuse `maintenance=1`). Category = provider category → your rule (Admin → Provider Sync → "Category rules", `keyword => Category`) → default category ("New Provider Products"). Categories are found by name (case-insensitive) or created enabled; existing categories are never changed.
- Price = provider price when present, else 0 → the product cannot leave maintenance until a price > 0 exists (enforced server-side).
- Variants: each orderable id = one `product_plans` row (`provider_variant_id` = exact provider id). Parent id = `products.provider_service_id`. A new variant of a known parent is added to the existing product. Nothing is generated locally; local ids are never used as provider ids. Admin edits (image, description, name, price) never touch `provider_variant_id`.
- Existing products: maintenance/live status is never changed by a sync. Price/name/category changes are detected against a per-variant snapshot; names you customised and prices you set are kept (logged as `name_skipped` / `price_skipped`). A price of 0 gets the provider price once it appears.
- Removed on provider: that **variant** is disabled (orders kept); the product is disabled only when no variant remains. Empty/failed/partial catalogs change nothing (mass-removal guard: >50 % vanishing is ignored and logged).
- Going live: the EXISTING Products → Maintenance toggle (or Provider Sync → GO LIVE). For synced products it now refuses if the category is inactive, any enabled variant has price ≤ 0, or the product was removed on the provider. Manual products behave exactly as before.

## 3. 4-second sync
cPanel cron cannot run faster than 1/minute, so:
`* * * * * /usr/local/bin/php /home/ACCOUNT/public_html/cron/provider_sync.php --loop >> /home/ACCOUNT/provider_sync.log 2>&1`
The job loops ~55 s, one sync per interval (Admin → Provider Sync → EVERY (SECONDS), min 4, set Auto-sync ON). Overlap is impossible (MySQL named lock). On errors/429 it backs off (×2, max 60 s). Whether the provider tolerates 15 calls/minute is unknown: if you see 429 in the Errors tab, raise the interval. Unchanged runs write nothing to the database except `sync_last_run`.

## 4. Install
1. Back up files + DB. 2. Upload over the site root. 3. Open Admin → Provider Sync once (schema is added automatically) – or run `database/migration_v41_provider_sync.sql` by hand. 4. Auto-sync ON, interval, rules. 5. Add the cron line. 6. If v40 already created hidden products, run the optional UPDATE at the bottom of the migration file.
Provider URL/token stay in Admin → API Settings → Provider 5 (server-side only).

## 5. Files
New: `database/migration_v41_provider_sync.sql`, `README_V41.md`. Changed: `includes/provider_sync.php`, `cron/provider_sync.php`, `api/admin/provider-sync.php`, `api/admin/products.php` (maintenance toggle check + provider fields in the list), `admin.php`, `assets/js/admin-provider-sync.js`, `tools/test_provider_sync.php`.

## 6. Not done / caveats
- Product list in Admin → Products shows `provider_service_id`/`sync_status` in the API response, but I did not edit the big `admin.js` UI; the provider ids per variant are visible there already as the existing "Variant ID" field, and read-only in Provider Sync.
- Image upload and feature sections stay in Admin → Products (existing screens).
- Nothing was tested against a live provider or database. Items 1–15 of your test list are covered only by the pure-logic tests plus code review.
