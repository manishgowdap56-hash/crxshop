# v48 - glued durations ("LKTEAM1days") now group under one parent

## NOT verified (read first)
No PHP, MySQL, network, browser or provider access here, and your two screenshots and the real catalog.php response did not reach me (only the ZIP). Nothing was run against the real provider or a database. Only Python mirrors ran (all pass): tools/mirror_v48_check.py plus the older mirror checks. `php -l` and the PHP tests are NOT run.

## Root cause found (reproduced in the mirror)
`ProviderSyncService::splitDuration()` needed a separator between the base name and the number. `LKTEAM1days`, `LKTEAM5days`, `LKTEAM10days`, `LKTEAM30days` matched no rule, so each was imported as its own parent. `LKTEAM 1 days` (with a space) already grouped.

## Change (one rule, includes/provider_sync.php)
Letter + 1-4 digits + a FULL unit word (days/hours/weeks/months/years/minutes), all glued, no separator -> base + duration. Bare D/H/W is never accepted, so `Alpha2`, `Windows10`, `Model3D`, `Windows10 DAYS` stay unsplit. Ids, API versions, prices, INR conversion (x30 once) are untouched.

## Still open
- The parent would be named from the catalog name (`LKTEAM`), not the screenshot's "Lk team Root+Pc". Which field carries that text is unknown until I see one raw entry.
- Existing LKTEAM cards: run `php tools/consolidate_duplicate_products.php --dry-run`, then MERGE (moves variants, keeps orders, archives old cards).
## Run on staging (back up DB first)
1. `php -l includes/provider_sync.php`  2. `php tools/test_provider_sync.php`  3. `php tools/audit_catalog.php --raw` (send one raw LKTEAM entry, token removed)  4. dry-run merge, then MERGE  5. SYNC NOW twice (no new cards).

## v48 part 2 - duplicate ids, restore diagnostics (still NOT run on PHP/DB/provider)
Inputs missing: the second ZIP (`mainsite(2).zip`), your new screenshots, the live DB and the real catalog. Everything below is from reading the v47 source.
- **"5 duplicate IDs" vs "0 groups merged" are two different things.** The 5 are repeated service ids INSIDE the provider catalog (counted in `normalize()`); the merge tool only folds duplicate CARDS in the DB whose names split into base + duration. It correctly reports 0 when no such cards exist. Not a merge bug.
- **Real bug fixed:** every repeated catalog id was counted as "skipped", so even a harmless identical repeat kept the run at OK_INCOMPLETE ("last complete sync: NEVER"). Now an identical repeat (same name, price, api version) is ignored and counted (`identical_repeats`); a repeat with DIFFERENT data is still skipped and the REVIEW reason now names both entries. Mirror-tested (`mirror_v48_check.py`).
- **Service #279:** the guard is correct and was NOT loosened. It blocks because active card #22 "Test" holds a variant with provider id 279. Whether that card is a leftover test or the right owner cannot be known without the DB. The error now states that card's source, state, the variant plan and its order count. Run `php tools/diagnose_service.php 279 280 286` (read-only).
- **#286 / #280 (ON_ARCHIVED_PRODUCT):** by design the sync never restores deleted cards by itself; RESTORE (v47) does it with confirmation. If the archived card also contains a variant that conflicts (e.g. 279), the whole restore is refused; resolve the conflicting variant first.
- **Pagination / V1-V2 order format:** code already follows the documented contract (body `{product_id, quantity, idempotency_key}`, v1 adds api_version + android_id, validated before any charge). 234 entries on 1 page is accepted as-is; whether the provider paginates cannot be proven without the raw response.
- Changed: `includes/provider_sync.php`, `tools/mirror_v48_check.py`, `tools/mirror_grouping_check.py`; new: `tools/diagnose_service.php`.
