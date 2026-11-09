# v43 – One product card per base product (durations = variants), duration-aware sync, safe merge of existing duplicate cards

## 0. What was and was NOT verified (read this first)
The build sandbox has **no PHP, no MySQL and no network**; no screenshot or real provider response reached me. So:

**Executed here**
- `python3 tools/mirror_grouping_check.py` – a Python mirror of the new pure functions (`splitDuration`, `groupKey`, `durationSeconds`) and of the "group by base name" step of `normalize()` + `applyNew()`. 30 checks, all pass: 1/3/7 DAY, 30 DAYS, 1 HOUR / 3 HOURS, `7D`, `_7DAY`, `(7 DAYS)`, leading `7 DAY - Name`, LIFETIME, names that must NOT be split (`Windows 10`, `Alpha2`, `Netflix Premium`), 7 catalog entries → 4 cards, repeat sync = no duplicates, a later duration joins the same parent, an admin rename of the parent does not break grouping, prices 30/60/90 at ₹30. It proves the **algorithm and the regexes**, not the PHP file. Running it also found and fixed one real bug (variant ordering helper).
- `node --check assets/js/admin-provider-sync.js`; a bracket/quote balance scan of every edited PHP file (not a PHP lint).

**NOT executed:** any PHP, any SQL, the consolidation against a real database, any call to the real provider, any browser session, any purchase. **Your 15 test scenarios were not run end-to-end.** New PHP test cases are in `tools/test_provider_sync.php` (unrun). Please run on **staging**: `php -l` on the changed files, `php tools/test_provider_sync.php`, `php tools/consolidate_duplicate_products.php --dry-run`, then `php cron/provider_sync.php --force`.

The real `catalog.php` response is still unseen. Grouping assumes duration is part of the product **name** (as in your example `ALPHAREGEDIT_IOS 3 DAY`). If the catalog instead nests durations under a product, v41/v42 already grouped those and that path is unchanged.

## 1. Root cause
`normalize()` turned every flat catalog entry into its own product, so `ALPHAREGEDIT_IOS 1 DAY / 3 DAY / 7 DAY` became three parents with one plan each. The storefront (`api/products.php` + `store.js`) already renders several plans per product with a duration picker, so **no storefront change was needed** – the fix is in the sync and in repairing existing data.

## 2. What changed
- **Duration parsing** (`ProviderSyncService::splitDuration`, pure): base name + duration. Recognised: `<n> DAY(S)/HOUR(S)/HR(S)/WEEK(S)/MONTH(S)/YEAR(S)/MIN(S)`, attached `7D/12H/2W`, `LIFETIME/PERMANENT`, leading `7 DAY - Name`. The number must be separated from the base and the base must contain a letter, so `Windows 10`, `Alpha2` etc. are **not** grouped. Similar-looking names are never merged: only an identical base name (case/whitespace-insensitive) groups.
- **One parent per base product.** New column `products.provider_group_key` (stable key of the base name). A new variant finds its parent by: provider parent id → group key → same-name provider product. Because the key is separate from the display name, **renaming the parent in the admin never causes a duplicate parent**. Repeated syncs are idempotent (known provider ids are never "new").
- **Each variant = a `product_plans` row** with its own `provider_variant_id` (the real provider id), `provider_api_version` (own column – nothing appended to ids), `provider_price/currency/rate` and the single INR `price`. A grouped parent has `provider_service_id = NULL` (the provider supplies no parent id); the variant id **is** the service id, shown per variant in the admin editor ("Variant ID").
- **Variants are created shortest-first** (1 DAY, 3 DAY, 7 DAY …) and merged parents are re-ordered the same way.
- **Safety:** a new variant added to an already-live parent is created **disabled** when its price is 0/missing (it would otherwise be purchasable for free). New parents still start visible + Maintenance; purchase endpoints still reject maintenance products.
- **Price sync:** unchanged logic from v42 (detects provider price changes per variant, converts once at the configured rate, logs old→new), plus one fix: when a variant has no snapshot yet, the stored `provider_price` is the baseline, so a change is not missed.
- **Merge of existing duplicate cards** – `ProviderSyncService::consolidate()`; Admin → Provider Sync → **PREVIEW MERGE OF DUPLICATE CARDS** (dry run, writes nothing) and **MERGE DUPLICATE CARDS INTO ONE PRODUCT**; CLI `php tools/consolidate_duplicate_products.php [--dry-run]`.
  - Candidates: provider products that already are grouped parents, or flat products with one provider variant whose name splits into base + duration **and still has the provider's own name** (a name you changed is skipped and counted).
  - Per group, one transaction: the surviving card is chosen (enabled > live > already a parent > has image > lowest id); every variant is **moved** (not recreated) so plan id, provider id, API version, prices, snapshot and order history are unchanged; `orders`, `product_keys`, `price_changes`, `admin_alerts` that point at a moved variant are re-pointed; missing image/video/description/features are filled from the merged cards; merged cards are **archived** (restorable in Admin → Products → archived), never deleted.
  - Verification inside the transaction (provider id + price of every moved variant, no order pointing at another product); any failure rolls back that group only.
  - Maintenance is preserved: a variant coming from a Maintenance (or disabled) card into a **live** survivor is moved in **disabled** and counted as "held for review", so nothing becomes purchasable without your approval.
  - It is **not automatic** (decision for you: you press PREVIEW, then MERGE). Until you do, new durations of a product whose old cards are still separate would create the correct parent next to the old cards; the merge folds them together afterwards (idempotent, safe to run again).

## 3. Decisions you should confirm
1. Grouping uses the **base name only** (not the provider category). Two unrelated provider products with the same base name and a duration would be grouped.
2. The merge is manual (preview first) because it moves live data and could not be tested here.
3. The parent of a grouped product has no provider service id (blank in the Provider Sync header).
4. Everything about price policy (follow provider price ON by default, "keep prices I edited" OFF, rate 30, 4-second loop via cron) is as in README_V42 §3 – 4 seconds is still unproven against the provider's rate limit.

## 4. Install
Back up files + DB → upload over the site root → open any admin page (adds `products.provider_group_key` automatically; or run `database/migration_v43_grouping.sql`) → Admin → Provider Sync → **PREVIEW MERGE OF DUPLICATE CARDS** → read the list → **MERGE DUPLICATE CARDS INTO ONE PRODUCT** → SYNC NOW → check the storefront and Admin → Provider Sync → Logs (`consolidated`, `new_variant`, `price_changed`).

## 5. Files
New: `README_V43.md`, `database/migration_v43_grouping.sql`, `tools/consolidate_duplicate_products.php`, `tools/mirror_grouping_check.py`. Changed: `includes/provider_sync.php`, `api/admin/provider-sync.php`, `admin.php`, `assets/js/admin-provider-sync.js`, `tools/test_provider_sync.php`.

## 6. Your 15-point test list – honest status
| # | Scenario | Status |
|---|---|---|
| 1,2 | one card, durations selectable | algorithm run (Python mirror); storefront already renders plans; **not run in PHP/browser** |
| 3,4 | each variant keeps its service id / price | mirror + in-transaction check in the merge; **not run on a DB** |
| 5 | order goes to the right provider service | purchase code untouched (selects the plan row by id); **not run** |
| 6,7,8 | price change per variant, ₹30 rate, no double conversion | v42 logic + new PHP test cases; mirror covers 30/60/90; **PHP unrun** |
| 9 | storefront shows new prices | storefront reads the DB live; **not run** |
| 10,11 | new parent visible in Maintenance, purchase blocked | unchanged v41/v42 behaviour; **not run** |
| 12 | existing duplicates merged safely | implemented with preview + rollback; **not run on a DB** |
| 13 | repeat sync creates no duplicates | mirror run; PHP test case added (unrun) |
| 14,15 | images/descriptions/orders kept; payment/auth untouched | merge keeps/re-points them; no payment/auth file was edited; **not run** |
