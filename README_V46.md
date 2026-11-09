# v46 – no silent loss of provider services, pagination, review/audit tooling

## 0. What was and was NOT verified (read first)
The build sandbox has **no PHP, no MySQL, no network, no staging database and no access to your provider**. Your real `catalog.php` response is **not in the ZIP**, so the real field names, nesting and pagination are still unconfirmed. Therefore:
- **Executed (all pass):** `python3 tools/mirror_v46_check.py` (22 checks), `tools/mirror_duration_only_check.py` (v45, 56 checks), `tools/mirror_grouping_check.py`, `tools/mirror_money_check.py`, `node --check` on the edited JS, a bracket-balance scan of the edited PHP. These are Python MIRRORS of the algorithms using invented sample data. They prove the logic, **not** that the PHP file works and **not** that your provider's data behaves like the samples.
- **NOT executed:** any PHP/SQL, `php -l`, `tools/test_provider_sync.php`, the merge on a database, any provider call, any storefront / checkout / order test. Tests A–J of your list that need PHP, a database or the provider (A, B, D–J) are **not done**. Nothing here is "verified against real provider data".
The way to close that gap is the new audit (section 3) on your server, which reads the live catalog and reports on every service.

## 1. Root causes found in v45 (by reading the code)
1. **A category container that has its own `id` was imported as ONE product and all its children were lost** (`walk()` only treated an entry as a container when it had no id). Silent.
2. **Variants without a duration label were dropped**, with no record. Variants/entries without a numeric id, duplicate ids, nameless entries were skipped but only a number was kept - no reason, no id.
3. **Only page 1 of the catalog was ever requested.** If your provider paginates, everything after page 1 is missing - and, worse, would later look "removed".
4. **A service whose card the admin archived ("deleted") was silently held forever:** it looks new (archived rows are excluded from matching), `applyNew()` finds the plan on the archived card, returns early, and the run still counts it as a new product. It never reappears and nothing says why.
5. **Catalogs shaped as an object keyed by category name** (`{"Root":[…],"iOS":[…]}`) produced "no usable products".
6. Review items had a generic message, were only visible as log rows, and the review list was not accessible in the admin.
7. Sync counts mixed products and variants and did not mention skipped / review entries in the message.
Still true from v45: a card whose name is only a duration and whose catalog entry has no product-name field / category cannot be placed - it is listed, never guessed.

## 2. What changed (`includes/provider_sync.php` unless noted)
- `walk()`: container detection (container unless it also has a price); label-less variants kept (own id, shown "Default"); every skipped entry recorded with **id, name, reason, field names**; depth limit 5.
- `extractList()` / `normalizeList()` / `normalize()`: also accepts an object keyed by category name.
- **Pagination:** `pageInfo()` detects `has_more/has_next`, `next_page`, `next_page_url`, `current_page`+`last_page|total_pages|pages`, in `pagination|meta|links|data.*`; `fetchAllPages()` reads all pages (max 100). A failed, unsuccessful, empty-but-"more", repeated (provider ignoring `?page=`) or >100-page fetch **aborts the whole sync before anything is changed** - a partial catalog can never trigger removals. If the response has no pagination fields it is treated as one page (**assumption, unverified**; if your provider paginates differently the audit will show it).
- **Archived services** are no longer silently swallowed: they are listed in REVIEW (`on_archived_product`) and not counted as imported. They are not auto-restored (that would undo an admin deletion) - restore the card in Admin → Products → archived view.
- **Review is actionable and event-based:** `reviewMessage()` names the service id, what was looked for and which fields the entry has. Logged once per new/changed reason (`needs_review`), resolved entries logged once (`review_resolved`), skipped entries logged once per change. Lists are recomputed on every run, so an entry leaves the list by itself when the data can resolve it. Missing parent metadata (`no_parent`), duplicate-duration ambiguity (`ambiguous_duration`) and `on_archived_product` are distinct.
- **Counts:** `new` is split into `new_products` / `new_variants`; review and skipped entries are never counted as imported and are named in the summary; pages read are reported.
- **Admin:** new tab **NEEDS REVIEW** (`api/admin/provider-sync.php?view=review`, `assets/js/admin-provider-sync.js`, `admin.php`) lists review services and skipped entries with reasons, plus a **RUN CATALOG AUDIT** button (`?view=audit`, read-only, all pages).
- **`ProviderSyncService::audit()` + `php tools/audit_catalog.php [--raw]`:** for every usable service of the live catalog: `ok | review:* | on_archived_product | product_disabled | category_disabled_or_missing | variant_disabled | marked_removed | parent_mismatch | missing_in_db`, plus skipped entries and local services no longer listed. `--raw` prints the first raw entry so the real response structure can be confirmed.
- `consolidate()` now also reads all catalog pages.

## 3. What you must run (staging first, back up the DB)
1. `php -l includes/provider_sync.php api/admin/provider-sync.php tools/audit_catalog.php`
2. `php tools/test_provider_sync.php` (v46 cases added; unrun by me)
3. `php tools/audit_catalog.php --raw` - tells you: the real structure, how many catalog entries vs usable vs skipped, which services are missing / hidden / on an archived card / in review. **This is the real answer to "which products are missing".** Send me the `--raw` output (one entry is enough, remove nothing but the token if any) and I will map the exact fields.
4. `php tools/consolidate_duplicate_products.php --dry-run`, then Admin → Provider Sync → MERGE.
5. SYNC NOW, then the NEEDS REVIEW tab.

## 4. Not changed / not re-verified
Order paths, checkout, prices, USD→INR, categories (the sync still never creates one), Maintenance Mode, auth, referrals, storefront queries and `store.js` were **not modified**. I re-read `api/products.php` / `store.js`: they show every enabled, non-archived product in an enabled category with its enabled plans as a variant selector; a card in a disabled or missing category stays hidden (the audit reports it as `category_disabled_or_missing`; `repair()` from v42 moves such cards). Concurrency: sync, repair and merge share one MySQL named lock (v41-v43, unchanged).

## 5. Files
Changed: `includes/provider_sync.php`, `api/admin/provider-sync.php`, `assets/js/admin-provider-sync.js`, `admin.php`, `tools/test_provider_sync.php`. New: `tools/audit_catalog.php`, `tools/mirror_v46_check.py`, `README_V46.md`.
