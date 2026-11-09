# v37 – Pending Orders error fix + Referral Commission Management

## 0. Read this first: what was and was NOT verified
The build sandbox has **no PHP and no MySQL**, and the screenshot / server error log were **not available** (only the ZIP). So:
- **Executed:** tokenizer pass + bracket-balance check on every edited/new PHP file (0 error tokens, balanced); `node --check` on the 3 edited/new JS files; a check that every function the new PHP calls is defined in the project.
- **NOT executed (no runtime):** no PHP code, no SQL, no browser test, no purchase, no refund, no commission credit. Nothing else is claimed as passed. `tools/selftest_v37.php` is written but **unrun** – run it on staging.
- The root cause below is established **from the code**. Whether it is exactly what happened on your server must be confirmed from your error log (1.3).

## 1. Pending Orders – "Server error. Check the database/schema configuration."
### 1.1 Where the message comes from
Only the fatal-error handler in `includes/bootstrap.php` (~line 34). It fires when an uncaught PHP error ends the request and hides the real reason. For `api/admin/pending-orders.php` the realistic uncaught error is a failed SQL statement.
### 1.2 Root cause found in the code
The page's queries read 7 columns on `orders` (`failure_reason`, `refund_status`, `refund_method`, `refunded_at`, `last_api_status`, `last_checked_at`, `check_attempts`) and the table `order_refund_log`. The automatic v35/v36 migrations in `includes/schema_compat.php` create them, but **marked themselves "done" even when an ALTER silently failed**:
- `rp_add_column()` swallows every error (log only);
- v35 set `schema_v35=1` unconditionally – one failed ALTER left `orders.failure_reason` missing permanently;
- v36 verified only 2 of its 6 columns before setting `schema_v36=1`.
After that nothing retried, the query died with "Unknown column …", and the generic message appeared. The settings and decisions failed too because one request loaded everything.
### 1.3 Confirm on your server
In cPanel → Errors (or `error_log`) look for `Uncaught PDOException … Unknown column 'o.failure_reason'` (or `refund_status`, or `order_refund_log doesn't exist`). After deploying v37 the page shows the missing column in a red banner if the repair cannot run (e.g. the DB user has no ALTER privilege), and the log line `[ops_schema_check] STILL MISSING: …` names it.
### 1.4 Fix
Changed: `includes/schema_compat.php`, `api/admin/pending-orders.php`, `assets/js/admin-ops-v36.js`. New: `database/migration_v37_ops.sql`. See `CHANGELOG_V37.md`. Refund rules are unchanged.
### 1.5 Provider status – limitation (as in v36)
The configured providers document **no order-status endpoint or webhook** (`rc_provider_status()` reports "unsupported"), so the controller cannot ask a provider whether an order failed. It auto-refunds only on evidence the site itself recorded and sends ambiguous cases (timeouts etc.) to admin review. I did not invent a provider call.

## 2. Install
1. Back up files + database.
2. Upload the ZIP contents over the site root.
3. Open any page once; the repair and referral migration run automatically. If your DB user cannot ALTER/CREATE, run `database/migration_v37_ops.sql` in phpMyAdmin (skip lines that say "Duplicate column/key").
4. Check Admin → Pending Orders and Admin → **Referral Commission**.
5. Keep the v36 cron (`cron/refund_controller.php`, every minute).
6. Staging: `php tools/selftest_v37.php`.

## 3. Referral Commission Management
- **Location:** Admin drawer → **Referral Commission** (between Pending Orders and Audit & Price Log).
- **Change the percentage:** choose 1–10 % or CUSTOM (0.10–25, 2 decimals) → UPDATE → confirm. Stored in `settings.referral_commission_percent`. Admin session + CSRF required; the server validates the range.
- **Applies to** future completed purchases on all three paths (website, API, reseller `buy`) through one function, `referral_credit_commission()`.
- **History kept:** each ledger row stores the percentage applied; old rows and balances are never recalculated.
- **Eligibility:** buyer has `referred_by` = an existing other user; the ORDER exists, is the buyer's and is `completed`. Deposits never reach this code.
- **Duplicates:** `UNIQUE(order_id)` + wallet-ledger check + row lock.
- **Failure isolation:** SAVEPOINT; a failed credit does not abort the purchase, is logged/audited and kept as a `failed` row.
- **Reversal:** `referral_reverse_commission()` exists but nothing calls it: no existing path refunds a completed order.
- **Legacy:** Refer & Earn still reads `referrals` / `referral_events`, written as before. Pre-v37 commissions are copied into the ledger once.
- **Limits:** pre-v37 events without an order id are not imported (the page warns if totals differ). No current path creates `pending` commissions.

## 4. Tests
Executed: tokenizer (0 errors, 12 PHP files), bracket balance, `node --check` (3 JS files), function-definition check.
NOT executed: everything functional. Please test on staging: Pending Orders load/save/run/search/filter/reset/empty/error; 1 %, 2 %, 3 % and custom % on a ₹100 order; persistence after re-login; website + API + reseller paths; failed orders earn nothing; repeated call = one credit; history unchanged after a change; card totals = ledger; non-admin is refused.

## 5. Security note
`config/config.php` still has a hard-coded DB password default and admin bootstrap values. Prefer the `ROYALPANEL_DB_PASS` environment variable and rotate the password if this ZIP was shared.
