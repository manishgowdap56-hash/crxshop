# v69 - ZapUPI: fix "payment verified on ZapUPI, website stays PENDING"

Base: v68. **Paytm is byte-identical** (checked: `includes/paytm.php`, `api/deposits-status.php`, `api/deposits-create.php`, config, finance, admin settings, webhook, cron, `deposit.js` all unchanged). Only two PHP files changed: `includes/zapupi.php`, `includes/zapupi_result.php`.

## Exact root cause
ZapUPI's `POST /api/order-status` does not answer with a flat object. Its published integration examples (zapupi.com/docs: PHP "double confirm" step and the Android `getOrderStatus` handler) show an **envelope**:

    { "status": "success",                         <- only means "the API call worked"
      "data": { "order_id": "...", "status": "Pending|Success|Failed", "amount", "pay_amount", "txn_id", "utr", "environment" } }

`verifyZapupiPayment()` in v68 read `status`, `order_id`, `txn_id`, `utr`, `environment` from the **top level**. For a real, paid order it saw top-level `status = "success"`, found **no `order_id`** there and returned `verification_failed / order_id_missing`. Every caller (webhook, status polling, result page, cron, admin button) goes through that one function, so no path could ever credit; the row was parked as `status = pending, credit_status = verification_failed`. Same bug meant `data.status = "Failed"` was never recognised either.
v68's README flagged this layout as an unverified assumption, and its Python "mirror" test modelled a flat reply, so it passed while the real reply could not.

Secondary hardening in this release (not the cause, but real weaknesses):
* ZapUPI appends `?order_id=…&utr=…` to the success/failed/timeout URL; v68 had put its own `?order=…` there (a possible double `?`) and the page only read `order`. URLs are now clean, the pages read `order_id` **or** `order` (even if mangled), and fall back to the user's latest own ZapUPI order. The URL is still never proof of payment.
* Provider calls from page loads/refreshes/polling were allowed every 3 s. Now per caller: webhook 1 s, admin 3 s, browser polling 10 s, result-page load 15 s, cron 5 min (unchanged).

## What changed
Modified: `includes/zapupi.php` (envelope unwrap; verification split into a **pure** `zapupi_judge_order_status()`; per-caller throttle; tolerant order-id extraction; clean return URLs), `includes/zapupi_result.php`.
New: `tools/zapupi_selftest.php`, `tools/check_v69_fix.py`, `database/v69_diagnose_pending_zapupi.sql` (read-only), this file. `tools/mirror_v68_check.py` got a SUPERSEDED warning.
**Database migration: none.**

## Deploy
1. Back up. Extract over the site (keep `config/`, `uploads/`). 2. Syntax check: `find . -name '*.php' -print0 | xargs -0 -n1 php -l | grep -v '^No syntax errors'`
3. **Run `php tools/zapupi_selftest.php`** - it executes the real PHP judge against the documented layout; every line must say PASS (exit code 0).
4. Webhook URL (sent automatically on every order, and can also be set in the ZapUPI dashboard): `https://YOUR-DOMAIN/api/zapupi-webhook.php`. It must answer HTTP 200 `{"status":"ok"}`, which it does.
5. Cron (every 5 min) - also heals orders stuck by the v68 bug (pending, < 48 h old, < 48 checks): `0-59/5 * * * * /usr/local/bin/php /home/ACCOUNT/public_html/cron/zapupi_reconcile.php >> /home/ACCOUNT/zapupi_reconcile.log 2>&1`
   Older stuck orders: Admin > Wallet & Deposits > deposit > **VERIFY WITH ZAPUPI** (credits at most once, only if ZapUPI says Success and amount/order/txn/UTR match).
6. Diagnostics: `database/v69_diagnose_pending_zapupi.sql` (webhook arrivals, what ZapUPI replied, exactly-once proof query).

## Flow after the fix
Pay Now -> local row + ZapUPI order (random `ZP…` id, amount from the server) -> ZapUPI page -> user pays -> **ZapUPI webhook** -> `zapupi_settle()` -> Order Status API (server-to-server, `zap_key`) -> `zapupi_judge_order_status()` -> `zapupi_credit_verified()` (one DB transaction, row locks, UNIQUE(deposit_id), UNIQUE(gateway,txn), SQL decimal add, one ledger row `DEP#id`) -> deposit `approved`. The return page reads the DB first: approved => shows SUCCESS at once, no polling, no provider call. Pending => "verification in progress", polls the server every 5 s (provider hit at most every 10 s). Backups: cron + admin button. No UTR entry, no second verify click.

## Verification actually performed (honest)
Executed here: `tools/check_v69_fix.py` (54 checks: Python port of the judge on the documented layout incl. proof that v68's logic cannot credit it; source checks; approximate bracket lint; byte-identity of Paytm and all untouched files) and the 24 source-invariant checks of `tools/check_v68_static.py` (its "unchanged vs v67" block needs the old v67 zip, not available; the byte-identity check above covers it against your v68 upload).
**NOT executed (no PHP, MySQL, ZapUPI account or network here):** `php -l`, the PHP self-test, any page, any DB query, any real ZapUPI call or payment, the 19-item scenario list (duplicate/concurrent webhooks, refresh, browser closed, etc.). Do not treat those as passed.

## Remaining provider-side facts to confirm on your first real ₹10-20 payment
1. The nested layout comes from ZapUPI's published examples, not from a captured live reply. After the first payment look at Admin > deposit detail / `deposits.gateway_response` and the "last API call" line (it states `nested layout` or `flat layout`). If it says `verification_failed` with a reason (e.g. `utr_missing`, `environment_invalid`, `txn_id_mismatch`) that reason names exactly the field to relax in `zapupi_judge_order_status()`.
2. No webhook signature is documented, so none is invented: the webhook is only a trigger; money moves only after ZapUPI's own Order Status answer (authenticated by your `zap_key`) matches. 
3. Orders ZapUPI reports `Failed` are closed (not re-opened if paid later); admin sees them.
4. `config/config.php` still holds a plaintext DB password and admin bootstrap password (pre-existing): move to environment variables and rotate.
