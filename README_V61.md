# v61 - Normal User + Reseller, dual prices, two exchange rates, Reseller Membership, per-category price review

## READ FIRST: what was and was NOT verified
The build sandbox had **no PHP, no MySQL, no network, no browser, no Paytm and no provider token**. **None of the new PHP was executed.**
Run: `python3 tools/php_lint_approx.py .` (approximate tokenizer, 0 findings over 103 files - NOT `php -l`), `node --check` on every changed JS file (pass),
`python3 tools/mirror_v61_check.py` (19 mirror checks of the pure rules, pass - proves rules, not PHP/DB). `tools/test_v61_staging.php` is written, **not run**.
**On your server, first:** `find . -name '*.php' -print0 | xargs -0 -n1 /PATH/TO/php -l | grep -v '^No syntax errors'` (must print nothing), then the staging test on a COPY.
No live payment, provider, cron or browser test was done.

## Section 1 - USDT/dollar user system
Searched the whole ZIP (php/js/sql/css): **no user-side USDT / dollar wallet exists in v59/v60**, so there was nothing to remove. Nothing was added.

## What was built
- **Account type**: `users.account_type` (`normal` default | `reseller`), separate from `users.role`. Added with DEFAULT `normal`: all existing users become Normal Users, new registrations are Normal, and **no statement ever UPDATEs account_type during migration**, so Admin-promoted resellers survive every future run. IDs, passwords, balances, orders, deposits are untouched. The category is read from the DB on every priced request (revocation is immediate); it is never taken from the session, request or JS.
- **Prices**: `product_plans.price` = **Normal price** (all old readers unchanged); new `reseller_price` + independent reseller columns (custom price, rate, calculated INR, provider baseline, pending review). Seeded once as a copy of current values (no silent price change). Product list, checkout (`purchase.php`, `purchase-api.php`), `api-variants.php` and the reseller API gateway use `dual_pick_price()` with the DB role; only the user's own price is ever sent. A reseller price never set (NULL) falls back to the Normal price so the item stays purchasable. Resellers no longer receive the Normal-price "price changed" popups.
- **Two rates**: Reseller = existing `sync_usd_inr_rate`; Normal = new `normal_usd_inr_rate` (seeded equal to the current rate, so nothing reprices at install). Admin -> Monitor -> Settings has two separate fields; each setting is written only when its own field changed. The sync cycle computes every duration twice, each with its own rate and its own columns (`includes/price_sync.php`, `includes/price_policy.php`). No hard-coded rate remains except the default `30` used only when a setting is unset/invalid.
- **Create Product / Custom Price**: each duration has Normal price + Reseller price inputs and separate Normal / Reseller custom-price boxes (admin.js, api/admin/products.php). Editing one never changes the other.
- **Review (10-15 min)**: an increase now opens a review for **each category separately** (setting `price_grace_minutes`, 10-30, default 15; migrated once from the old 25 default). Server timestamps + cron only. If the admin saves a price for that category inside the window it is kept (a plain price typed during a review is turned into that category's custom price; the new provider cost is acknowledged so it is not re-detected). Otherwise the new provider-derived price is applied at the deadline under a row lock + version guard (no duplicate review/update, no overwriting a newer admin edit). Admin page: Monitor -> **Price Review** (product, product ID, provider ID, duration, category, old/new provider price, current/proposed price, detected, deadline, remaining, status, history).
- **Membership**: Admin -> Users page: ON/OFF, any INR price, description, button, purchase history; per-user history in user detail. User dashboard "BECOME A RESELLER" (price, description, QR payment, status). Payment = existing Paytm UPI-QR + server-to-server `getTxnStatus` (same trust model as deposits). Unique payment ref (checked against deposits too), price snapshotted at creation, double-click reuses the open payment, row locks, `UNIQUE(gateway_txn_id)`, txn replay check against memberships and deposits, under-payment never activates (alert), activation is one transaction, audit log. A payment verified after the 3-minute window is still honoured for 24 h.
- **Admin users**: search, filter Normal/Reseller, grant/revoke, bulk by IDs, role badge, membership history.

## DECISIONS YOU MUST KNOW ABOUT
1. **Behaviour change vs v60**: v60 never overwrote a Custom Price. Your brief (section 13) says an unreviewed increase must auto-apply after the window **also for manually customised prices**. Implemented as briefed: after the window without an admin change the custom flag is switched OFF (value kept) and the new provider-derived price applies. Revert = in `pp_apply_expired()` skip the update when `$customOn`.
2. Review window default 15 min (was 25).
3. Price Sync still only covers Provider 5 (Licence Dashboard) plans, as before; other plans have two manually entered prices.
4. `price_changes` popups describe the Normal price only.
5. Logo / Website Name midnight reset: **no cause found.** No cron, event, cache or timezone job writes `panel_name` / `logo_path`; the saves go through `set_setting()` and reload the cache. Only latent hazard: `database/schema.sql` ends with `ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)` which would reset the name/logo (and API endpoints) **if that file is re-imported or re-run** - the app runs it only when a core table is missing. If your host restores a DB backup nightly, that is the likely cause (outside the code). I made **no change** per your rule.

## Deploy
1. Back up DB + files. 2. Upload over v60 (ZIP root = site root). 3. `php -l` all files. 4. Open the site once as admin: schema + one-time seeding run automatically (or run `database/migration_v61_dual_pricing_membership.sql` by hand; MySQL 5.7+/MariaDB). 5. Admin: set `Normal User` rate, review window, membership price/ON. 6. Cron unchanged (provider_sync.php --loop, order_review.php, refund_controller.php every minute). Then grant Reseller to chosen users.

## Test status (honest)
Items 1-28 of your list: logic implemented; only the mirror rules (rates independent, price pick, migration keeps reseller, review open/no-duplicate/admin-kept/auto-apply, categories independent, membership under/over/duplicate/replay) were executed. Registration default, migration on a real DB, payment, cron without admin login, provider sync, checkout and existing order/refund/wallet regression: **NOT RUN**.

## Files
New: includes/dual.php, api/membership.php, api/admin/membership.php, assets/js/admin-membership.js, assets/js/reseller-membership.js, assets/css/reseller-membership.css, database/migration_v61_dual_pricing_membership.sql, tools/mirror_v61_check.py, tools/test_v61_staging.php, README_V61.md.
Changed: includes/bootstrap.php, price_policy.php, price_sync.php, api/products.php, purchase.php, purchase-api.php, api-variants.php, api/api/reseller_gateway.php, notices.php, api/admin/products.php, monitor.php, users-list.php, users-update.php, admin.php, admin-monitor.php, dashboard.php, assets/js/admin.js, admin-monitor.js, assets/css/admin.css.
