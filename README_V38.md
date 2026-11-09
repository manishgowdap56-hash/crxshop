# v38 – Referral schema fix + Pending Orders status & refund tracking

## 0. What was and was NOT verified
No PHP or MySQL exists in the build sandbox, and no screenshots or DB/log access were available. **Executed:** (a) `tools/test_order_states.py` – the real order-state CASE expression evaluated on 15 synthetic orders in SQLite (all pass; each order counted once; "refunded" never shown without wallet evidence); (b) PHP tokenizer + bracket balance on 8 edited files (0 errors); (c) `node --check` on 3 JS files; (d) function-definition check. **NOT executed:** every PHP/SQL path against MySQL, every browser test, any purchase/commission/refund. Nothing below is claimed as tested beyond (a)-(d). Run `php tools/selftest_v37.php` on staging.

## 1. Root cause of "missing table referral_commissions"
The page reached the table query only because my v37 code treated `settings.schema_v37 = 1` as proof the table exists (the same mistake v35/v36 had with their flags). A flag can survive when the table does not: a restored backup, a different database, or a half-applied manual migration. I cannot see your database, so I cannot tell which of these happened; the code defect is certain. The v37 migration file also had a second defect: it began with plain `ALTER TABLE ... ADD COLUMN` lines, so on a database that already had those columns phpMyAdmin stopped at the first "Duplicate column name" and never ran `CREATE TABLE referral_commissions`.
**Fix:** the table is verified against `information_schema` every time and created if absent (outside any purchase transaction); the migration file is rewritten to be repeat-safe with the referral table first.
**To apply:** just open Admin -> Referral Commission once (auto-creates + imports history). If it still shows an error, run `database/migration_v37_ops.sql` in phpMyAdmin on the database named in `config/config.php` (DB_NAME); it can be run repeatedly. Historical commissions are copied into the ledger from `referral_events`; nothing is credited again and no balance changes.
Existing, unchanged: percentage setting, user-wise earnings, overall cards, history with filters/pagination/export, shared commission function for website/API/reseller (no webhook in this project produces commissions; deposits are never commissionable).

## 2. Pending Orders
Where: Admin -> Pending Orders (default view and behaviour unchanged).
- **Filter dropdown:** ALL PENDING (default), WITHIN WINDOW, EXPIRED / OVERDUE, PROCESSING / CHECKING, NEEDS REVIEW / REFUND STATE, CONFIRMED FAILED, REFUND ELIGIBLE, NOT YET REFUNDED, REFUND FAILED, MANUAL REVIEW REQUIRED, AUTO REFUNDED, MANUAL REFUND COMPLETED, COMPLETED / DELIVERED, ALL ORDERS. Plus date range, and search by order ID / ref, username, user ID, product. Each count card is a clickable filter.
- **Labels (one per order):** Pending, Processing / Checking, Expired / Overdue (NOT a confirmed failure), Refund Pending (verified failure, not refunded), Refund Failed, Manual Review Required, Auto Refunded, Manual Refund Completed, Confirmed Failed - Not Refunded, Completed / Delivered. "Cancelled" is not supported: the order status model has no cancelled state.
- **Order status vs refund status** remain separate columns; the label is derived. "Auto Refunded"/"Manual Refund Completed" appear only when the wallet credit is recorded (`orders.refund_ledger_id`, written inside the refund transaction).
- **Each order shows:** state, order status, provider, expiry/overdue, API status + last check, refund eligibility, controller decision and previous -> new status, refund amount/method/time/wallet ledger id, reason.
- **Controller decisions:** filter by order, date, type; paginated; history is append-only.
- **Manual refund:** unchanged workflow (details -> reason -> confirm); server re-checks eligibility, claim and ledger; auto and manual share `order_refund_once()` (row lock + status gate + ledger check) so one order can never be credited twice.
- **Past refunds:** linked once to their orders from the wallet ledger. Refunds made before v38 by flows that did not record a method show as Auto Refunded unless an admin-refund audit entry exists (then Manual).
- **Provider status:** unchanged limitation - providers document no status endpoint/webhook; ambiguous cases go to Manual Review Required and are never refunded.
- Counts scan the whole `orders` table; fine for thousands of orders, add date filters if it grows very large.

## 3. Install
Back up -> upload over site root -> open Admin once (automatic) -> if needed run the SQL file -> staging: `php tools/selftest_v37.php`, `python3 tools/test_order_states.py`. Keep the v36 cron.

## 4. Tests still to do on staging
Referral page loads with no error; 1/2/3 % and custom on a Rs100 order via website, API, reseller; failed order earns nothing; repeated call = one credit. Pending page: each filter, search by user ID, date range, pagination; trigger an expired confirmed-failure with automatic refunds ON (shows Auto Refunded + ledger id) and OFF (shows Refund Pending), a manual refund (Manual Refund Completed), a provider-ambiguous order (Manual Review Required); run the controller twice and confirm one wallet credit.
