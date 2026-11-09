# v63 - Membership UI styling + one source of truth for the two exchange rates

Base: v62 fixed. Read README_V61/V62 for earlier work. Targeted fixes only.

## Honest verification status
This build environment has **no PHP binary, MySQL, browser, Paytm, provider or cron**. Therefore:
- **`php -l` was NOT run** (php: command not found). What ran: `tools/php_lint_approx.py` (tokenizer, 0 findings / 104 files - NOT a real lint) and a project-wide top-level redeclaration scan (none). **Run on your server:** `find . -name '*.php' -print0 | xargs -0 -n1 php -l | grep -v '^No syntax errors'` (must print nothing).
- `node --check`: executed on every .js file, all pass.
- `python3 tools/check_v63_static.py`: 14 source-level invariants pass (does not execute PHP).
- Rate scenario 35/32 -> 40/32 -> 40/25: only a Python rule mirror was run, **not** the PHP/DB.
- NOT performed: any browser / 320-430px layout check, live DB, provider, cron, Paytm, membership save round trip.

## Root causes found and fixed
1. **Unstyled admin Membership card**: `admin.php` never linked `reseller-membership.css` (only the user dashboard did). Now linked (cache-busted with filemtime) in the existing stylesheet block. No CSS was copied; the one file serves both pages. The admin rules (scoped `.amc*`, `.admin-membership-*`) were rewritten in that file.
2. **Price Sync showed a fixed 30** (`admin.php` subtitle, PRICE RULE text, read-only "RATE (FIXED) 30" input) and `api/admin/price-sync.php` history returned the PHP constant. Now: the status overview and history responses carry `rates {reseller, normal}` read from the settings `sync_usd_inr_rate` / `normal_usd_inr_rate`; `admin-price-sync.js` fills the subtitle, the price-rule text and two **read-only** displays ("set in Monitor"). The page polls every 5 s, so a Monitor change shows within seconds. No second editable rate exists; Monitor stays the only editor.
3. **Real backend defect (not just display)**: Create Product's provider lookup / name search priced with the RESELLER rate and auto-filled the NORMAL price box with it. `PriceSyncService::lookupVariant()` and the candidate search now return both prices; the Normal box is filled from `normal_usd_inr_rate`, the Reseller box from `sync_usd_inr_rate`; entered prices are never overwritten. (The existing v61 save/verify path already stored each category with its own rate.)
4. Stale comments saying "x 30" were reworded (comment text only).

## Verified by reading the code (not executed)
Worker (`PriceSyncService::cycle`): Normal = `normal_rate` (normal_usd_inr_rate), Reseller = `rate` (sync_usd_inr_rate); settings are read from the DB every cycle; review period, decrease policy, custom price protection, mappings, cron, products are untouched. Membership backend unchanged: wallet purchase transaction, ON/OFF, button, price, description save through the existing `api/admin/membership.php` (`dual_membership_save`); history unchanged; no role or balance is modified by this release.

## Membership UI
Heading + ENABLED/DISABLED badge, Resellers / Normal Users tiles, real styled switches (Membership, Purchase button) with ON/OFF text bound to the actual checkbox, ₹ INR price field, description textarea, full-width Save (disabled while saving, so no duplicate request), history table in a scroll box. After Save the card re-renders from the server's stored settings, so the display always equals the database. Layout uses grid/min-width:0/box-sizing rules for 320-430px, but **it was not viewed in a browser**.

## Files changed
admin.php, assets/css/reseller-membership.css, assets/js/admin-membership.js, assets/js/admin-price-sync.js, assets/js/admin.js, api/admin/price-sync.php, api/admin/products.php, includes/price_sync.php, cron/provider_sync.php (comment only), tools/check_v63_static.py (new), README_V63.md (new). No database change, no migration needed.
