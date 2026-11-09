# v50 - automatic price sync for EXISTING products + "New Provider Products" review page + manual import

## 0. What was and was NOT verified (read first)
No PHP, MySQL, network, browser or provider access in the build sandbox. **Executed and passing:** `python3 tools/mirror_v50_check.py` (21 checks; a Python *mirror* of the decisions on invented data: USD->INR examples, zero/missing price guards, pending upsert idempotency, import conflict rules), `node --check` on the 3 edited/new JS files, a bracket-balance scan of the edited PHP. **NOT executed:** `php -l`, `tools/test_v50_pending.php` (new), `tools/test_sync_jobs.php` (rewritten for v50), `tools/test_provider_sync.php`, any SQL, any provider call, any browser test. **Your tests 1-12 are therefore NOT done; nothing here is "tests passed".** Treat the code as unrun: lint, run the two PHP tests on a staging copy of the database first.

## 1. What changed
**Automatic (cron / SYNC NOW)** - `includes/provider_sync.php`
- Existing mapped variants: price compared with the last stored provider price; changed -> provider price, currency, rate, INR cost stored in their own columns, INR selling price recalculated ONCE (existing "follow provider price" and "keep prices I edited" settings still decide), logged with old/new value, variant (plan id + name), time. Hand-made products that are mapped to a provider id are now **price-synced only** (never renamed, removed or re-versioned).
- A provider price that is missing, 0, invalid or in an unsupported currency never replaces a stored valid price (also not the stored provider price/snapshot); it is counted ("invalid provider price") and written once to Errors; the sync is then reported INCOMPLETE, not complete. A currency change of the same number is treated as a price change.
- **New provider services are never created.** `applyNew` now refuses (only `importConfirm` creates). Each unknown service is written to the new table `provider_pending_products` (one row per provider id; same id again = update in place, `first_seen_at` kept; vanished = status `unavailable`, kept; mapped later = `imported`). Entries that are unusable, on an archived product, ambiguous, price-less, or belong to a parent group that already exists in the store are stored with status `conflict` and cannot be imported.
- A vanished id + a new id with the same name is **no longer re-mapped automatically** (name guess). The old variant is treated as removed by the provider; the new id waits as a conflict with an explanatory warning.
- Pending products do not make a price sync INCOMPLETE; unusable entries, invalid provider prices, unverifiable rows, failed rows, blocked removals and failed pages still do. A failed page changes nothing (no price, no pending status).
- Counters added: existing checked, prices updated, price change not applied (settings), invalid prices, failed requests, new discovered, pending waiting, conflicts. "Next automatic sync" shown when known. Default interval remains 10 s (min 4); see limits.

**Manual import** - Admin -> **New Provider Products** (new page, nav badge shows the waiting count)
- Shows per variant: provider name, parent/group id, service id, variant id, API version, validity, provider price + currency (+rate), INR cost, first found, last checked, status, warnings; variants grouped under their provider parent.
- **ADD TO MY STORE** -> re-reads the whole provider catalog and re-verifies ids/prices/conflicts -> review form (name, category, description, selling price per variant, Maintenance or Live) -> **CONFIRM IMPORT** verifies everything a second time and creates ONE product + the ticked variants in one transaction (rolled back on any problem). Starts in Maintenance by default; "Live" runs the same go-live checks as the Products toggle. Saves bare provider ids, API version, provider price/currency/rate; audit entries in `sync_logs` and the admin audit log; the pending row becomes `imported`.
- Blocked (with reasons, nothing created): id already mapped (also to archived), parent group/service id already owned by a store product, duplicate product name, duplicate variant names, id no longer on the provider, invalid price, conflict/unavailable status, a sync job in progress.
- **Image** is not in this dialog: set it in Admin -> Products right after the import (uses the existing upload).

## 2. Decisions you should check
1. A new variant of a provider group that already has a store product is a CONFLICT (not importable here, not added automatically). Partial import: variants not ticked cannot be added through this page later (parent then exists); add them by hand in Products.
2. Price-only sync of hand-made mapped products: if you keep custom selling prices (markup), switch **KEEP PRICES I EDITED MYSELF** ON, otherwise the provider price x rate replaces them when the provider changes its price (same rule synced products already had). There is no separate cost/markup field in this database.
3. Products already created by v41-v49 (visible, in Maintenance) are left as they are.
4. Sync price changes are written to `sync_logs` (old/new, variant, time), **not** to `price_changes`, because that table also drives customer price notices.
5. "Variant id vs service id": this provider's orderable id is the *variant id*; the *service id* is the parent/group id (or the same id for flat products). The provider field names in `catalog.php` are still unconfirmed (run `php tools/audit_catalog.php --raw`).

## 3. Limits
- Provider rate limit unknown; each idle tick makes >= 1 catalog request (6/min per page at 10 s). Retry-After is not parsed. Watch Errors for 429s.
- Cron must run (`* * * * * php .../cron/provider_sync.php --loop`) for the sync to work with the browser closed (cannot be verified here).
- Import is refused while a sequential job is active (retry in seconds).
- Old tests that expected auto-import (`test_sync_jobs.php` v49) were rewritten; `mirror_*` scripts from v43-v49 still describe the old auto-create behaviour and are obsolete.

## 4. Run on staging (back up the DB first)
1. `php -l includes/provider_sync.php api/admin/provider-sync.php cron/provider_sync.php admin.php`
2. `php tools/test_v50_pending.php` then `php tools/test_sync_jobs.php` then `php tools/test_provider_sync.php` (staging DB only)
3. Admin -> Provider Sync -> SYNC NOW; open New Provider Products; import one test service; run SYNC NOW twice; confirm no duplicates.

## 5. Files
Changed: `includes/provider_sync.php`, `api/admin/provider-sync.php`, `cron/provider_sync.php`, `admin.php`, `assets/js/admin.js`, `assets/js/admin-provider-sync.js`, `assets/css/provider-sync.css`, `tools/test_sync_jobs.php`. New: `assets/js/admin-new-provider.js`, `database/migration_v50_pending.sql` (optional), `tools/test_v50_pending.php`, `tools/mirror_v50_check.py`, `README_V50.md`.
