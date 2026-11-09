# v42 – Provider Sync: storefront visibility, separate IDs/V2, USD→INR

## 0. What was and was NOT verified (read this first)
The build sandbox has **no PHP, no MySQL and no network**, and no screenshots reached me (only the ZIP and your brief). So:

**Executed here**
- `tools/mirror_money_check.py` – a line-by-line Python mirror of the PHP money functions (`toMicro`, `normalizeRate`, `convertToInr`): 12 conversion cases ($1→₹30.00, $2.50→₹75.00, $0.50→₹15.00, rounding, bounds, 64-bit overflow bound) + 9 input checks, all pass. It proves the *algorithm*, not the PHP file.
- `node --check` on `admin.js`, `admin-provider-sync.js`, `store.js`.
- A lexer/bracket-balance scan of every edited PHP file (0 errors, balanced). This is NOT a PHP lint.

**NOT executed:** any PHP, any SQL, any call to the real provider, any browser session, any purchase. Your 16 test scenarios were **not run**. They are covered only by code review and the PHP self-test `php tools/test_provider_sync.php` (pure logic, no DB) which **you must run on staging**. Please run: `php -l` on the changed files, the self-test, then `php cron/provider_sync.php --force` on staging.

The real `catalog.php` response is still unseen. Service-ID vs variant-ID: if the catalog is flat, service id = variant id = the provider's id (nothing is invented); nested `variants|plans|…` keep separate ids.

## 1. Root causes found in v41 (your products missing from the store)
1. **v40 products stayed hidden.** v40 created them with `enabled = 0` inside a *disabled* staging category. v41's README only offered an optional `UPDATE ... enabled = 1`, which was not enough: `api/products.php` lists **enabled categories only**, so even enabled products in the disabled staging category never appeared. They still showed in Admin → Provider Sync because that page lists `source='provider' AND maintenance=1` with no visibility filter.
2. **New synced products could also be hidden**: `categoryId()` reused any category with the provider's name, even a disabled one.
3. **`283|both`** is the Licence Dashboard "id|mode" packing in `provider_variant_id`. v41 wrote it for every `both` item.
4. **Prices were copied 1:1** (USD number into the INR price); there was no currency setting; "follow provider price" defaulted OFF.
No cache problem was found (the storefront reads the DB live and polls every 5 s; JSON is not cached by `.htaccess`).

## 2. What changed
- **Visibility repair** (`ProviderSyncService::repair`, idempotent, logged to `sync_logs`, deletes nothing): provider products in a missing/disabled category are moved to an *enabled* category (provider category → your rules → **Root / Non Root**); hidden maintenance products get `enabled = 1` (still `maintenance = 1`); `sync_status` re-aligned; missing `provider_service_id` filled when unambiguous; duplicates / plans without price are *reported*, never deleted. Runs automatically the first time you open Admin → Provider Sync (or the first sync/cron run); button **REPAIR EXISTING PRODUCTS**; CLI `php tools/repair_provider_products.php`.
- **New products** can no longer land in a disabled category (`resolveCategory`). Default category is now `Root / Non Root` (an existing saved default of `New Provider Products` is switched once).
- **Identifiers never concatenated:** `product_plans.provider_variant_id` = bare provider id (`283`), `product_plans.provider_api_version` = `v2|v1|both`, `products.provider_service_id` = parent/service id. `licencedash_plan_variant()` composes the old string **in memory only** for `licencedash_resolve/generate`; all three order paths (`purchase-api.php`, `reseller_gateway.php` ×2) use it. V2 order body = `{product_id, quantity, idempotency_key}` exactly as before: nothing is appended to the id, and **no `api_version:"v2"` field is invented** (the existing integration sends none for V2; confirm against the provider docs).
- **V2 policy:** every synced variant that supports V2 (`v2`, `both`, unspecified) → `v2`. A **v1-only** (device-bound) variant stays `v1` (V2 not applicable; forcing it would break ordering). Setting "API VERSION FOR SYNCED VARIANTS" can instead keep the provider's mode.
- **Currency:** Provider Sync settings: Provider currency (USD/INR), Website currency (INR), USD→INR rate (default **30**). Integer arithmetic, half-up to the paisa. Original price in `provider_price`/`provider_currency`/`price_rate`; INR selling price stays in the single `product_plans.price`. An item whose catalog says `INR` is never converted. Unknown currency ⇒ no price (stays in maintenance). Provider price of 0 / sub-paisa results stay ₹0.00 and cannot go live.
- **Price updates:** event-based (provider price changed, rate changed, first price, or a legacy 1:1 row): recalculated and logged old→new (provider + INR + rate). Legacy 1:1 prices are converted once by the first sync; a price that differs from the provider number is treated as an admin edit and kept.
- **Admin override:** typing a price (product editor or Provider Sync card) sets `price_manual`. Setting "KEEP PRICES I EDITED MYSELF" = ON protects those from sync; OFF (default) = provider price wins on the next provider/rate change.
- **Editor:** existing Admin product editor shows Service ID, Variant ID, API version (own select), provider price + initial INR; saving image/description/price never alters the id. Saving `76|v1` typed by hand is split into id + version.
- **Storefront:** `api/products.php` also hides archived products (the admin list and all purchase paths already treat them as gone).
- Schema columns are ensured on **every request** by one cheap `information_schema` query (`ensureSchemaIfNeeded`), so the storefront/purchase SELECTs cannot hit a missing column. Optional manual SQL: `database/migration_v42_provider_sync.sql`.

## 3. Decisions you should confirm
1. **Follow-price defaults to ON** and a one-time alignment switches an existing saved `NO` to `YES`. With override OFF, a live product whose price you typed is replaced at the next provider-price or rate change. Turn "KEEP PRICES I EDITED MYSELF" ON to avoid that.
2. **`both` → V2** removes the "customer may pick V1" option on those synced plans (API clients sending `api_version=v1` will get V2). Choose "KEEP PROVIDER'S MODE" to keep `both`.
3. Changing the rate takes effect at the next sync (press SYNC NOW).
4. Auto-sync is still OFF by default and needs the cron line from README_V41 §3. 4-second interval is unproven against the provider's limits.
5. Maintenance list shows the newest 300 products (you have 203).

## 4. Install
Back up files + DB → upload over the site root → open Admin → Provider Sync (repair + schema run) → check "VISIBLE IN STORE" count → set rate/policies → SAVE → SYNC NOW (converts prices) → open the storefront.

## 5. Files
New: `README_V42.md`, `database/migration_v42_provider_sync.sql`, `tools/repair_provider_products.php`, `tools/mirror_money_check.py`. Changed: `includes/provider_sync.php`, `includes/providers.php`, `includes/schema_compat.php`, `api/products.php`, `api/purchase-api.php`, `api/api/reseller_gateway.php`, `api/admin/products.php`, `api/admin/provider-sync.php`, `admin.php`, `assets/js/admin.js`, `assets/js/admin-provider-sync.js`, `tools/test_provider_sync.php`.

## 6. Test list status (your §12)
1–16: **not executed** (no PHP/DB/provider here). Logic for 1,3–8,15 is in `tools/test_provider_sync.php` (pure parts, unrun); 7 also in the Python mirror (run, passes). 2,9–14,16 need a staging DB and a browser; 14 needs a real provider order.
