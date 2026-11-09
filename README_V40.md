# v40 – Automatic Provider Sync (Licence Dashboard)

## 0. What was and was NOT verified
No PHP/MySQL/network in the build sandbox. **Executed:** structure check of all new/edited PHP, `node --check` of the JS. **NOT executed:** every PHP/SQL path, the admin page in a browser, any call to the real provider. Run `php tools/test_provider_sync.php` (pure logic, no DB) and then, on STAGING, `php cron/provider_sync.php --force`.
**Unverified against the real provider:** the shape of `catalog.php` (the supplied source does not document it). `ProviderSyncService::normalize()` accepts a list of objects with `id|product_id|pid`, `name|title|product_name`, optional `price|cost|rate|amount`; each entry = 1 product with 1 plan. Open Admin → API Settings → LOAD CATALOG, read the raw JSON, and adjust ONLY `normalize()` if it differs (e.g. nested plan tiers, different price field). If the catalog has no price, new products get price 0 and **Go Live is blocked until you set one**.

## 1. Flow
cron (every 5 min) or SYNC NOW → lock → additive schema → fetch catalog (3 attempts, errors → `sync_errors`) → normalize → PURE diff vs local → one transaction per product → `sync_logs`.
- NEW → product created **hidden** (`enabled=0`, `sync_status='maintenance'`, `source='provider'`), category "Provider Sync (staging)" (disabled), plan "Default", user price = provider price, reseller price = user price. Never live automatically.
- REMOVED → disabled (`sync_status='removed'`, hidden, purchases blocked) or deleted if setting = "delete if no orders" AND no orders exist. Order history is never deleted. A removal guard skips removals when >50 % of managed products vanish at once (API glitch protection). An empty/unreadable catalog changes nothing.
- SERVICE ID CHANGED → detected as one vanished + one new id with the same name; mapping updated (critical, applied, logged). Ambiguous cases are not guessed (they become removed + new).
- PRICE CHANGED → compared with the last provider price stored in the product's snapshot (so your own markup is not mistaken for a provider change). Applied + logged; reseller price follows only if it was still equal to the user price.
- NAME CHANGED → applied only if you never customised the name; otherwise logged as `name_skipped`.
- A product that comes back on the provider returns to **maintenance** (hidden), never live.

## 2. Install
Back up → upload over the site root → open Admin once → **Provider Sync** page (tables/columns are created automatically and idempotently) → set Auto-sync ON → add the cron line from `cron/provider_sync.php` (`*/5 * * * * php /home/ACCOUNT/public_html/cron/provider_sync.php`). Credentials: this site stores the provider URL/token in Admin → API Settings → Provider 5 (the `settings` table, never returned to the browser); there is no `.env` in this codebase and none was introduced.

## 3. Admin
Admin → **Provider Sync**: Auto-sync ON/OFF, interval (min 5), removal mode, "also manage manual products" (default NO), SYNC NOW, tabs *Maintenance Products* (edit name / plan / prices / category / description, GO LIVE), *Sync Logs* (old → new), *Errors*.
GO LIVE refuses until a real active category is chosen and both prices are > 0.

## 4. Safety defaults / limits
- Hand-made products are never changed or removed unless "also manage manual products" is ON; their provider ids still count as known, so no duplicate is created. Their first sync only records a baseline.
- `reseller_price` is stored/editable but **the reseller gateway still charges the plan price** (changing that is a separate pricing decision).
- New sync_status/source/provider_service_id/last_synced_at columns are on `products`; existing products default to `manual` / `live`.
