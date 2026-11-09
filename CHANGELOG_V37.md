# CHANGELOG v37 (on top of v36)

## Pending Orders: server error
- `api/admin/pending-orders.php` – every uncaught error is now a JSON response with a stable `code` (`SCHEMA_INCOMPLETE`, `SCHEMA_MISMATCH`, `DB_UNAVAILABLE`, `DB_PERMISSION`, `DB_ERROR`) and the real cause is written to the server error log. New settings-only request `?view=config`; `save_config` no longer depends on the order tables. Recent decisions are read in their own try/catch.
- `includes/schema_compat.php` – `ops_schema_check()` verifies (and additively repairs) every `orders` column and `order_refund_log` the page needs; `ops_classify_db_error()`. The v35 and v36 "migration done" flags are now set **only after the columns are verified to exist**.
- `assets/js/admin-ops-v36.js` – settings load independently of the list; "Loading…" always ends; failures show an in-page error (message + code) instead of a bare toast; distinct empty states for "no pending orders" and "no match".
- `database/migration_v37_ops.sql` – manual equivalent of the repair.
- Refund rules (`rc_decide`, `order_refund_once`, locks, ledger, audit) are **unchanged**.

## Referral Commission Management
- New `includes/referral.php` – ONE shared commission implementation (replaces three copies in `api/purchase.php`, `api/purchase-api.php`, `api/api/reseller_gateway.php`). Percentage read from the database on the server; commission = eligible amount × percent ÷ 100, 2 decimals.
- New table `referral_commissions` (commission ledger): referrer, referred, order, eligible amount, **percent applied**, commission, status, channel, wallet-ledger link. `UNIQUE(order_id)` + wallet-ledger check = no duplicate credit. Existing commissions (`referral_events`) are copied in once; `referral_events`, `referrals`, `wallet_ledger`, balances are not modified.
- New setting `referral_commission_percent` (seeded with 5.00 only if absent). `REFERRAL_PERCENT` in config.php is now only the first-run default. Range 0.10%–25%.
- New admin page **Referral Commission** (drawer menu, between Pending Orders and Audit): percentage selector 1–10% + custom, overall cards, user-wise earnings, commission history with search / date / status / percent / referrer / referred filters, pagination, details, CSV export. API: `api/admin/referral-commission.php`, UI: `assets/js/admin-referral-v37.js`, `assets/css/referral-admin-v37.css`.
- `referral.php` and `api/referral.php` show the active percentage instead of a fixed 5.
- `tools/selftest_v37.php` (read-only, run on staging).
