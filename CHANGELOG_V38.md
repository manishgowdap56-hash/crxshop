# CHANGELOG v38 (on top of v37)

## Referral Commission: "database is missing a table (referral_commissions)"
- `includes/referral.php` – `referral_schema_ensure()` no longer trusts the `schema_v37` flag: it checks `information_schema` for the table on every use, creates it when missing, re-imports history when the table had to be recreated, and never runs DDL while a purchase transaction is open (MySQL DDL would implicitly COMMIT it). A failing history import no longer blocks the ledger. The failure reason is returned to the admin page (classified, no SQL/credentials).
- `api/admin/referral-commission.php` – returns that reason; settings view reports whether history was imported.
- `database/migration_v37_ops.sql` – rewritten: referral ledger FIRST; every ALTER guarded by an information_schema check (repeat-safe; the old file stopped at its first "Duplicate column name" in phpMyAdmin before reaching CREATE TABLE referral_commissions); history import credits nothing.

## Pending Orders (existing behaviour kept; "ALL PENDING" is still the default)
- `includes/refund_controller.php` – one derived state per order (`RC_STATE_CASE_SQL`), order status and refund status stay separate columns; decision log gains previous/new status and API result; wait / re-check / admin re-check decisions are now logged; cron run self-heals schema.
- `includes/ops.php` – `order_refund_once()` (the only refund code path) now also records refund amount, wallet-ledger id, method (auto/manual) and time INSIDE the same transaction as the wallet credit. Signature gains an optional `$method`; `api/admin/alerts.php` passes `manual`.
- `includes/schema_compat.php` – new tracking columns on `orders` (`refund_amount`, `refund_ledger_id`) and `order_refund_log` (`prev_status`, `new_status`, `api_status`), index, and a one-time idempotent link of PAST refunds to their orders.
- `api/admin/pending-orders.php` – new filters (all orders, processing, confirmed failed, refund eligible, not yet refunded, refund failed, manual review, auto refunded, manual refund completed, completed), date range, search by user ID, per-state counts, decision history endpoint (`?view=decisions`, filter by order / date / type, paginated).
- `admin.php`, `assets/js/admin-ops-v36.js`, `assets/css/ops-v36.css` – state chips, state/filter cards, refund and controller lines per order, decision filters.
- New: `tools/test_order_states.py`.
