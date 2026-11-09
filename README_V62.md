# v62 - Wallet-based Reseller Membership, Reseller-only API, real role display, UI redesign

Base: v61. Supersedes the v61 Paytm membership flow. Read README_V61.md for the rest (dual prices, two rates, review) - unchanged except where noted here.

## Verification status (honest)
No PHP, MySQL, browser, Paytm or provider access was available. **Nothing new was executed against PHP/MySQL.**
- Static inspection: done. Approximate PHP lint (`tools/php_lint_approx.py`, NOT `php -l`): 0 findings, 104 files. `node --check`: all JS pass.
- Local rule mirror (`python3 tools/mirror_v61_check.py`): 19 + wallet/API rules pass (rules only, not the PHP).
- SQL: only a static bracket/quote check of both migration files; **not executed**.
- `tools/test_v61_staging.php` extended with wallet-purchase tests: written, **NOT run**.
- NOT performed: every live/DB/browser test (list items 1-45 except syntax-level), Paytm deposit run, cron run, provider sync, concurrency test, mobile layout check.
Before deploying run on a copy: `find . -name '*.php' -print0 | xargs -0 -n1 php -l | grep -v '^No syntax errors'`, then `php tools/test_v61_staging.php --run`.

## Changes
1. **Membership = website wallet only.** `dual_membership_purchase()` (includes/dual.php), endpoint `api/membership.php?action=buy` (POST + CSRF). One DB transaction: lock user row (FOR UPDATE) -> reject banned / already Reseller (not charged) -> read ON/OFF + price from settings inside the lock -> insufficient balance = rollback, nothing changes -> insert membership record -> atomic `UPDATE users SET balance = balance - price WHERE balance >= price` -> balance cross-check -> `wallet_ledger` row (type `purchase`, reference `MEM#<id>`, "Reseller Membership (Website Wallet)") -> activate Reseller. Any exception rolls everything back. The row lock serialises double-clicks and concurrent requests; the second finds a Reseller and is not charged. Only the price is deducted; the remainder stays in the same wallet (1000 - 400 = 600).
   All QR / Paytm membership code was removed (`dual_membership_create/verify/activate`, QR UI). **Paytm deposit code was not touched.**
2. **Insufficient balance**: server returns 402 with price, balance, amount needed; the card shows "You need ₹X more" and an ADD FUNDS button linking to the existing `deposit.php`.
3. **History** (`reseller_memberships`, new columns payment_method, amount_deducted, wallet_ledger_id, wallet_ref, balance_before/after): shown in Admin -> Users (Membership panel + per-user detail) as "Website Wallet" / "Admin" / "Paytm (legacy)". Old rows are kept.
4. **API restricted to Resellers (+ Admins)**, enforced server-side from the DB role on every request: `api.php`, `api-docs.php` (redirect to dashboard), `api/api-token-regenerate.php`, `api/api-docs-sample.php`, `api/api-variants.php` (403 JSON) and the key-authenticated gateway `api/api/reseller_gateway.php` (403 RESELLER_ONLY; revoking Reseller disables the key immediately, the key and account data are kept). The API menu item is hidden for Normal Users (convenience only). Website checkout (`purchase.php` / `purchase-api.php`) is unchanged and not API-gated.
5. **Core Profile**: the static "Reseller" pill was hard-coded; it now shows **Normal User** or **Reseller** from the DB on every load. The drawer role label is also real. Role-bearing pages send `Cache-Control: no-store`.
6. **UI**: Admin membership settings redesigned (status badge, ON switch, button switch, ₹ price field with hint, description, history table; responsive). User "BECOME A RESELLER" card redesigned in the same location (price, wallet balance, description, role badge, buy / add-funds, success state, mobile layout).
7. Logo / Website Name midnight reset: re-checked, **still no root cause found; no change made** (see README_V61 item 5: only latent risk is re-running `database/schema.sql`, or a host-side nightly DB restore).

## Notes / limits
- Membership revenue is a wallet debit with a `MEM#` reference; the existing Finance dashboard counts only order-linked purchases, so it is **not included in "spending"** there (no finance code was changed).
- A Reseller promoted by admin is not charged. Revoking does not refund.
- Existing v61 databases: the 6 new columns are added automatically on first request (or run `database/migration_v62_wallet_membership.sql`).

## Deploy
Backup -> upload over v61 -> `php -l` all files -> open site once as admin (auto-migration) -> Admin -> Users -> Membership: set price, ON -> test with a small-balance and a funded test account. Cron unchanged.

## v62 hotfix (this build)
`includes/dual.php` contained `dual_membership_config()` and `dual_membership_save()` twice (a copy/paste overlap from the v61 -> v62 rewrite), which is a PHP fatal "Cannot redeclare". The second, identical copy was removed; nothing else changed. A project-wide scan of top-level function/const names finds no other redeclaration.
