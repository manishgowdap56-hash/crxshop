# v68 - ZapUPI added next to Paytm Business

Base: v67. Paytm code (`includes/paytm.php`, Paytm branch of `deposits-create/status`, admin Paytm settings) is **byte-identical** to v67 (checked by `tools/check_v68_static.py`).

## Verification status (honest)
No PHP, MySQL, browser or ZapUPI account existed in the build sandbox. **NOT run:** `php -l`, any page, any DB query, any real ZapUPI call, any real payment.
What *was* run: `node --check` on changed JS; `tools/php_lint_approx.py` (0 findings, a hand-written tokenizer, **not** `php -l`); `tools/check_v68_static.py` (source-text invariants, incl. "Paytm files unchanged"); `tools/mirror_v68_check.py` (Python **re-implementation** of the amount/URL/verification rules + SQLite unique-key/concurrency model - it proves the rules are coherent, not that the PHP matches).
**Run first on the server:** `find . -name '*.php' -print0 | xargs -0 -n1 php -l | grep -v '^No syntax errors'`

## Files
New: `includes/zapupi.php`, `includes/zapupi_result.php`, `api/zapupi-webhook.php`, `api/admin/zapupi.php`, `payment-success.php`, `payment-failed.php`, `payment-timeout.php`, `cron/zapupi_reconcile.php`, `assets/css/deposit-gateway.css`, `assets/css/payment-result.css`, `assets/js/payment-result.js`, `database/migration_v68_zapupi.sql`, `tools/check_v68_static.py`, `tools/mirror_v68_check.py`, this file.
Modified: `includes/bootstrap.php` (require + schema ensure), `includes/maintenance_lock.php` (webhook exempt), `api/deposits-create.php` (zapupi branch), `api/deposits-status.php` (zapupi branch), `api/admin/deposits.php` (gateway filter/fields, ZapUPI verify, manual approve blocked), `deposit.php`, `assets/js/deposit.js`, `admin.php` (ZapUPI card + filters), `assets/js/admin.js`, `database/schema.sql` (fresh installs).

## Install
1. **Back up** DB + files. 2. Extract over the site (keep `config/`, `uploads/`). 3. `php -l` command above.
4. First request auto-applies the additive migration (needs ALTER/CREATE privilege - same as the existing schema layer). Otherwise run `database/migration_v68_zapupi.sql` by hand. Rollback notes are at the bottom of that file.
5. **HTTPS is required** (ZapUPI redirect + webhook URLs are built from your site URL; the code refuses to create orders on http). Set `APP_URL` in `config/config.php` to your https address (recommended - otherwise the host is auto-detected from the request).

## Configure
- **API key** (preferred): set environment variable `ZAPUPI_API_KEY` on the server (cPanel: *Setup Python/PHP* env, or `.user.ini`/`SetEnv` per your host). It overrides the panel field.
  Otherwise: Admin > Wallet & Deposits > **ZAPUPI GATEWAY** > paste key > Save. It is stored server-side in the `settings` table like the other provider keys, shown only as a mask, never sent to the browser. (If you want it out of the database, use the env variable.)
- Same card: gateway name, min/max, instructions, environment, endpoint (must be https on zapupi.com), ON/OFF. ZapUPI and Paytm switches/saves are fully independent.
- **Webhook URL to register in the ZapUPI dashboard:** `https://YOUR-SITE/api/zapupi-webhook.php` (also shown, read-only, in the card).
- Success/failed/timeout URLs are sent automatically per order: `payment-success.php`, `payment-failed.php`, `payment-timeout.php` (`?order=LOCAL_ORDER_ID`; the page requires login, checks ownership, and verifies server-side - the URL proves nothing).
- **Cron (recommended, every 5 min):** `0-59/5 * * * * /usr/local/bin/php /home/ACCOUNT/public_html/cron/zapupi_reconcile.php >> /home/ACCOUNT/zapupi_reconcile.log 2>&1`
  Checks pending orders older than 60 s, max once per 5 min each, max 48 cron checks, max 48 h old. It never credits without ZapUPI confirming, and is overlap-safe.

## How a payment is handled
create (session user, server-side amount, random order id `ZP...`, row stored first) -> ZapUPI `create-order` -> response validated (status, order_id, txn_id, https `zapupi.com` payment_url) -> same-tab redirect.
Webhook / polling / result page / cron / admin button all call **one** function `zapupi_settle()` -> `verifyZapupiPayment()` (calls `order-status`) -> only a verified success reaches `zapupi_credit_verified()` (one DB transaction: `FOR UPDATE` lock on deposit + user, `balance = balance + DECIMAL`, ledger row `DEP#id`, `payment_credits` row with UNIQUE(deposit_id) and UNIQUE(gateway, txn), guarded UPDATE). Deposit `status`: pending / approved (credited) / rejected (ZapUPI said Failed). `credit_status`: pending / success / failed / verification_failed.

## Security summary
HTTPS + TLS verify + timeouts + no redirects; prepared statements; CSRF + `require_admin` on settings; key masked/write-only/env-capable; key never logged (`ops_sanitize` + whitelist); user from session only; order ownership + gateway ownership checked everywhere; payment_url host allow-list; per-user create rate limit (3/min, 5 open) ; per-IP webhook rate limit (120/min); per-deposit provider-call throttle; test env / `DUMMY*` never credited on a live site; amounts compared as integer paise, wallet math done in SQL DECIMAL; no manual-credit path for ZapUPI (admin button only re-verifies with ZapUPI, audit-logged); failed/pending/mismatched/unverifiable payments are never credited.

## ZapUPI facts the documentation did NOT give - confirm with a real call before go-live
1. **Webhook authentication**: none documented -> none invented. The webhook is only a trigger; security rests on the Order Status call + strict matching + idempotency. Residual risk: anyone who knows a valid (80-bit random) local order id can make the server perform an extra status check (rate-limited); they cannot cause a credit.
2. **Order Status response layout / status vocabulary**: assumed top-level `status` ("Success"/"Failed" as in the webhook docs), `order_id`, `txn_id`, `utr`, `amount`, `pay_amount`, `environment`. A payment is accepted only if status=success **and** order_id, txn_id, utr, environment (cashier/zappay) and amount all agree; anything else stays pending/`verification_failed` (visible in admin with the reason). If real responses nest these fields (e.g. under `data`) or omit `utr`/`environment`, payments will **not** credit until `verifyZapupiPayment()` is adjusted - fail-safe, not fail-open. Use the admin "VERIFY WITH ZAPUPI" button + the deposit detail on your first real small payment.
3. `txn_id` returned by order-status is compared with the one stored at create time. If ZapUPI changes it after payment, relax that single check.
4. `pay_amount` lower than the order amount is rejected; higher (e.g. paise offsets) is accepted and the **order amount** is credited.
5. The "Test API connection" only proves TLS/reachability/JSON using a non-existent order id; it cannot prove key validity (reply for a bad key is undocumented).

## Known limitations
- `wallet_ledger` has no unique key (legacy rows may legitimately repeat); the unique credit record lives in `payment_credits`.
- `client_ip()` (existing helper) trusts forwarded headers, so IP rate limiting can be evaded by a spoofer behind a permissive proxy.
- Pending ZapUPI orders never auto-"expire"; they stay pending until ZapUPI says Failed or the cron age/attempt limit stops checking (admin can still re-verify).
- Paytm still uses its original 180-second window and polling; unchanged by design.
- Existing config issue (not changed): `config/config.php` contains a hard-coded DB password and admin bootstrap password in plain text. Move them to environment variables and rotate them.

## Tests (see honest status above)
Executed here: static + mirror checks listed above (all pass). **Still to do on a real server** (needs PHP/MySQL, and for 8-9 and 20-22 real ZapUPI credentials/sandbox): order creation; invalid/zero/negative amount; duplicate order id; API timeout; invalid JSON; payment_url validation; real success / failed / pending / timeout; duplicate and concurrent webhooks (`ab`/curl x20 on one order -> ledger must have exactly one `DEP#id`); fake/unknown order id; wrong gateway; wrong amount; wrong user; missing txn id; test env + DUMMY webhook; reconciliation cron; existing Paytm payment + history; mobile/desktop layout of deposit selector + result pages; admin save/toggle/mask; Paytm unaffected when ZapUPI toggled; DB rollback (simulate by revoking INSERT on `payment_credits` and confirming balance unchanged).
