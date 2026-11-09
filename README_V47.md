# v47 – honest sync status, restore/relink of archived cards, stricter Go Live

## 0. What was and was NOT verified (read first)
The build sandbox has **no PHP, MySQL, network, browser or provider access**; your real `catalog.php` response is still not in the ZIP.
- **Executed (all pass):** `python3 tools/mirror_v47_check.py` (12 checks, Python mirror of the three new decisions), `mirror_v46_check.py`, `mirror_duration_only_check.py`, `mirror_grouping_check.py`, `mirror_money_check.py`; `node --check` on every JS file; bracket-balance comparison of `includes/provider_sync.php` against v46 (identical).
- **NOT executed:** any PHP/SQL, `php -l`, `tools/test_provider_sync.php`, the restore on a database, any provider call, storefront or order test. Your acceptance tests A-K that need PHP, a database or the provider are **not done**; I do not claim they pass.

## 1. Audit result: what v46 already covered (not rewritten)
Pagination with abort-on-incomplete, retries/timeout (3 attempts, 20 s), named-lock overlap guard, cron `--loop`, grouping with stable `provider_group_key`, separate provider id / api version columns (order paths build the id from the plan row, never "213V2"), single INR price with configurable rate (default 30, integer maths, no double conversion), category reuse (never created), maintenance enforced server-side in `purchase.php`, `purchase-api.php`, `reseller_gateway.php`, duplicate preview/merge, audit tool.

## 2. Gaps found and fixed (this version)
1. **Archived services could only be listed, not recovered.** New admin action `restore_archived` (`ProviderSyncService::restoreArchived`) and a **RESTORE** button in Provider Sync -> REVIEW. Admin-confirmed only, one transaction, row-locked, refused if any of the card's provider ids is already mapped on an active card (no duplicate), card returns visible but in Maintenance Mode, category kept if enabled else chosen/default (never created), orders/keys untouched, logged as `restored_archived`, review list updated at once.
2. **An incomplete sync could be reported as fully successful.** New status `ok_incomplete` whenever services are held back for review or entries were skipped. New setting `sync_last_complete`, written only for a fully clean run; the admin header now shows **LAST COMPLETE SYNC** (previously it showed any finished run).
3. **Missing statistics.** Run counts now include entries fetched, usable, pages, price changes (from this run's logs), failed API requests (from this run's error log), duplicate service ids; the summary message states them and says INCOMPLETE when applicable.
4. **Go Live validation** (`rp_provider_go_live_check`, used by the existing Maintenance toggle and Provider Sync -> GO LIVE) now also requires, per enabled variant: a valid numeric provider service id, a valid api version, and no service id shared by two variants of the same card.

## 3. Files
Changed: `includes/provider_sync.php`, `api/admin/provider-sync.php`, `assets/js/admin-provider-sync.js`, `tools/test_provider_sync.php` (1 case added, unrun). New: `tools/mirror_v47_check.py`, `README_V47.md`. **No database migration** (no new columns; `sync_last_complete` is a row in the existing `settings` table).

## 4. What you must run on staging (back up first)
1. `php -l includes/provider_sync.php api/admin/provider-sync.php`
2. `php tools/test_provider_sync.php`
3. `php tools/audit_catalog.php --raw` and send me one raw entry (token removed) - this is still the only way to confirm the real field names and pagination.
4. `php tools/consolidate_duplicate_products.php --dry-run`, then MERGE if the preview is right.
5. SYNC NOW, then NEEDS REVIEW; try RESTORE on one archived card.

## 5. Known limits
- If the provider paginates differently than the detected fields, the sync aborts rather than importing a partial catalog; the audit shows it.
- Cards with a duration-only name and no parent field in the catalog stay in review (never guessed).
- No per-run "pages that could not be retrieved" count: a failed page aborts the whole run (by design), recorded as failed.
- A provider that exposes no parent/group id relies on normalized name matching (documented in v43/v45).
