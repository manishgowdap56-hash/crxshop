# v45 – duration-only product names ("2 HOURS", "3 HOURS", "168 HOURS") grouped under the real provider product

## What was and was NOT verified (read first)
The build sandbox has **no PHP, MySQL, network or browser**, and your real `catalog.php` response is **not in the ZIP**. So:
**Executed:** `python3 tools/mirror_duration_only_check.py` (Python mirror of the new PHP logic + an in-memory simulation of sync / merge / orders: 56 checks, all pass), `tools/mirror_grouping_check.py` and `tools/mirror_money_check.py` (still pass), `node --check` on the edited JS, a bracket-balance scan of the edited PHP.
**NOT executed:** any PHP or SQL, the merge on a real database, the provider API, a browser, a real purchase. Run on STAGING: `php -l includes/provider_sync.php`, `php tools/test_provider_sync.php`, `php tools/consolidate_duplicate_products.php --dry-run`.
I could not confirm which field of your catalog holds "ROOT / NON ROOT". The code therefore reads every plausible provider field (below) and **refuses to guess** when none exists.

## Root cause (found in the code)
`ProviderSyncService::splitDuration()` needs a base name containing letters, so "2 HOURS" returned null. `walk()` then treated each entry as its own product, named after whatever `name` held (the duration). Other provider fields (a product-name field, the category, a duration field) were never consulted for the parent.

## The fix (`includes/provider_sync.php`)
For every flat catalog entry the parent is resolved in this order; the first rule that applies wins:
1. **name** – name contains base + duration ("ALPHAREGEDIT_IOS 3 DAY", "15DAYS PATO BLUE") – unchanged from v44.
2. **field** – name is ONLY a duration → the provider's own product-name field: `product_name, product_title, parent_name, parent_product, parent, service_name, group_name, product, service` (value must not itself be a duration or a number).
3. **category** – name is only a duration and no such field exists → the provider category (`category_name | category | group`, or the enclosing category container's name). `type` is never used.
4. **duration_field** – name is a real product name and the entry has an explicit duration field (`duration, plan, plan_name, period, validity, duration_label`, or a number + `duration_unit|unit|period_unit`) → same-named entries are variants.
5. Otherwise a duration-only entry is flagged `review = no_parent`. It is **not turned into a card** (that was the bug) and not merged.

Safety rules
- A parent inferred from the category (rule 3) is flagged `ambiguous_duration` when the same duration appears twice under it (e.g. two different "2 HOURS" products in one category): the catalog cannot tell them apart, so none of that group is created or merged. Parents named by an explicit provider field (rule 2) are never blocked this way.
- Flagged entries stay in the catalog list, so they are never mistaken for products the provider removed. They are logged once (action `needs_review`, Provider Sync → Logs) and counted in the sync summary ("N provider entries need admin review").
- Duration is a variant attribute: label keeps the provider's wording (upper-cased), matching uses `durationKey()` (2 HOUR = 2 hours = 120 MINUTES). The raw provider name stays in `item.name`, the snapshot and the audit log.
- Parent name = the provider's own text (whitespace collapsed only); stable `provider_group_key` = lower-cased parent name, so repeated syncs find the same parent (v43 mechanism, unchanged). Nothing is appended to or changed in any provider id.
- Each variant stays its own `product_plans` row: own `provider_variant_id`, `provider_api_version`, provider price, INR price. Order paths (`purchase-api.php`, `reseller_gateway.php`) are untouched and read the selected plan's id.

## Repairing cards that already exist
`ProviderSyncService::consolidate()` (Admin → Provider Sync → PREVIEW MERGE / MERGE DUPLICATE CARDS, or `php tools/consolidate_duplicate_products.php [--dry-run]`) now also handles cards **named only by a duration**: it fetches the live catalog once and, for each such card, looks up the parent via the card's own provider id (same rules as above). Resolved cards are merged by the existing v43 procedure: variants are moved (not recreated), orders / keys / price history / alerts are re-pointed, image / description / Maintenance state are kept (a variant from a Maintenance card is moved in disabled when the survivor is live), merged cards are archived (not deleted), one transaction per group with in-transaction verification of ids and prices. Cards that cannot be resolved (catalog unavailable, id not in the catalog, no parent info, ambiguous) are **left untouched** and listed in the preview / CLI output. `sync_consolidate_done` was bumped to `v45`, so the admin page shows "NOT MERGED YET" once; **the merge is still a button you press after previewing, not automatic.**

## Not changed
Provider API calls, auto-sync/cron, price updates and USD→INR conversion, single price, categories (the sync still never creates one), admin editor, images/descriptions, Maintenance Mode, auth, orders/payments/deposits, storefront files (`api/products.php`, `store.js` already render one card with a variant selector per product).

## Known limits
- If your catalog carries the product name in a field not listed above, those entries land in "review" (nothing wrong is created). Send me one raw `catalog.php` entry and I'll add the field.
- A bare "3D"/"7D" name is not treated as duration-only (could be a real product name).
- Admin-renamed cards are not regrouped (same rule as v43).

## Files
Changed: `includes/provider_sync.php`, `assets/js/admin-provider-sync.js` (report text), `tools/consolidate_duplicate_products.php`, `tools/test_provider_sync.php`. New: `tools/mirror_duration_only_check.py`, `README_V45.md`.
