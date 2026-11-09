# CHANGELOG v36 (on top of v35)

## New files
- `includes/maintenance_lock.php` – single maintenance switch helpers, global gate, order-creation guard, 503 JSON response
- `includes/refund_controller.php` – evidence gathering, rule engine, exactly-once refund execution, locking, manual refund
- `includes/finance.php` – daily financial metrics (real tables, site-timezone day boundaries)
- `api/admin/pending-orders.php` – Pending Orders admin API
- `cron/refund_controller.php` (+ `.htaccess` deny-all) – scheduled job
- `assets/js/admin-ops-v36.js`, `assets/css/ops-v36.css` – admin cards + Pending Orders page
- `assets/js/header-clock.js`, `assets/css/header-clock.css` – user header clock
- `database/migration_v36_ops.sql`, `tools/selftest_v36.php`, `README_V36.md`

## Modified files (small, additive edits)
- `includes/bootstrap.php` – loads the 3 new modules; calls `maintenance_global_gate()` after auth
- `includes/maintenance_gate.php` – reads the same authoritative setting
- `includes/schema_compat.php` – v36 migration (6 nullable/default columns on `orders`, 1 index, table `order_refund_log`, 3 settings defaults)
- `api/purchase.php`, `api/purchase-api.php`, `api/api/reseller_gateway.php` (buy) – `maintenance_guard_orders()` at the top
- `api/admin/settings.php` – maintenance toggle accepts `state=on|off`, writes an audit entry
- `api/admin/dashboard.php` – adds keys `finance`, `maintenance_mode`, `controller` (existing keys unchanged)
- `admin.php`, `assets/js/admin.js` – 10 finance cards, maintenance/controller banners, Pending Orders nav + page, confirm before maintenance ON
- `includes/header.php`, `includes/footer.php` – clock element + script (inside the existing `.dash-brand`)

## Not changed
Design CSS, existing cards, payment (Paytm), wallet math, provider calls, `order_refund_once()`, `config/*`.
