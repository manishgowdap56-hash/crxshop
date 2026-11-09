# v58 - Out-of-Stock handling: confirmed provider answer -> FAILED + OUT OF STOCK + one refund

## Read this first: what was and was NOT verified
The build sandbox had **no PHP, MySQL, network, browser or provider token**. Nothing below was run against PHP, a database or the real Licence Dashboard.
Executed: `node --check` on the 3 changed JS files (pass); bracket balance of the changed PHP (balanced - NOT `php -l`, run it); `python3 tools/mirror_out_of_stock_check.py` (15 checks, pass - a Python MIRROR of the rules, proves the rules not the PHP); `tools/test_order_states.py` (pass, unchanged).
Written, not run: `tools/test_out_of_stock_v58.php` (staging, real functions + mocked provider).
**The real Licence Dashboard documentation is not in the ZIP and the sandbox had no network.** I used only what the code base already records from the supplied source: `generate.php` replies `{ok:true,data:{keys:[..]}}` or `{ok:false,error:{message,code?}}`. The exact out-of-stock `error.code` string is **not confirmed by documentation** in the project (only used as `OUT_OF_STOCK` in the v39 fixture). So out-of-stock is recognised from **either** `error.code` = OUT_OF_STOCK **or** the provider's own message wording ("out of stock", "sold out", ...). Please capture one real out-of-stock reply and send it to me if the wording differs.

## Root cause (found by reading the code)
1. The refund engine already existed (v39: `order_settle_failure` -> `order_refund_once`, row lock + status gate + ledger check), but **nothing recorded or showed WHY an order failed**: customers got a generic popup, `My Keys` listed completed orders only (a FAILED order never appeared anywhere), the admin order list/details showed no reason.
2. Detection bug: the provider's error code is appended to the admin text as `[OUT_OF_STOCK]`, but the stock pattern needed literal spaces, so a code-only out-of-stock reply was **not** recognised.
3. Money-safety bugs (against your rule 4): an **HTTP 200 with an unrecognised body** and an **`ok:true` reply without a key** were classified "confirmed failure" and **refunded** - although the provider may have generated/accepted the order.

## What changed
- `includes/ops.php`: `provider_message_is_stock()` (underscores/hyphens as spaces), new `provider_failure_code()` (error.code or message, nothing else - never timeout/HTTP number), `order_failure_code()/order_failure_label()` (older failed rows fall back to the stored provider reason, no data rewritten), `ops_failure_code_column()` (adds `orders.failure_code` if missing, before any transaction). `order_refund_once()` gets an optional `$failureCode`, stored **in the same transaction** as FAILED + wallet credit + ledger row (ledger text "Refund: order failed - OUT OF STOCK"); a missing column never blocks the refund. `order_settle_failure()` returns `failure_code`.
- `includes/providers.php` (`licencedash_generate`): passes the provider's `error.code` through; **only an explicit `ok:false` (or a 4xx not claiming ok:true) is a confirmed failure**. `ok:true` without key, or a 2xx/3xx unrecognised body, now throws -> **uncertain: order stays PENDING, no refund, never re-sent**.
- `api/purchase-api.php`, `api/api/reseller_gateway.php`: refunded out-of-stock responses carry `status:"FAILED"`, `failure_code`, `reason:"OUT OF STOCK"`; gateway `status` action returns `reason`.
- `assets/js/checkout.js`: popup shows **FAILED** with **OUT OF STOCK** underneath (only for a confirmed, committed refund); all other failures keep the old popup.
- `api/mykeys.php`, `assets/js/mykeys.js`, `assets/css/mykeys.css`: customer history now also lists that day's FAILED orders (FAILED / OUT OF STOCK / REFUNDED amount). Key counts unchanged. Mobile rules added in the existing 700px breakpoint.
- `api/admin/all-orders.php`, `assets/js/admin.js`, `assets/css/admin.css`: admin list and details show the same label, the stored provider failure text and the refund amount/time.
- `includes/schema_compat.php`, `database/migration_v58_out_of_stock.sql` (optional, idempotent): `orders.failure_code`.
- `tools/test_instant_refund.php`: removed the old "ok:true but no key -> refunded" expectation (now uncertain by design).

## Provider response used
Only `generate.php` -> `{"ok":false,"error":{"code":"...","message":"..."}}`. OUT OF STOCK = `error.code` equals OUT_OF_STOCK **or** the provider message says out of stock/sold out/insufficient stock. Anything else is never labelled out of stock.

## Behaviour you should know (decisions I made)
- Other **explicit** refusals (`ok:false` for another reason, e.g. invalid product) are still refunded as before (provider definitively said no) but are **not** labelled OUT OF STOCK. If you want refunds for out-of-stock only, say so; it is a small change in `order_settle_failure`.
- **Status verification for timeouts is not possible with the documented API.** The only read endpoints are `balance`, `catalog`, `history`; `history` has no documented shape, so I did not parse it. Uncertain orders stay PENDING, are not refunded, not re-sent, and go to the existing Pending Orders / manual review (admin checks Recent Keys, then Complete or Refund). No endpoint or field was invented.
- Before purchase nothing changed: provider plans never show Out of Stock; the existing LOCAL-stock "OUT OF STOCK" label (stock = 0 in your own key inventory) is untouched.
- If the refund DB transaction itself fails, the order stays PENDING/refund-eligible and is retried (existing v38 design), so it is not FAILED until the refund commits.

## Duplicate-refund protection
Single refund function; order row `FOR UPDATE` + must be `pending` + ledger duplicate check + refund exactly the purchase-ledger amount. Optional DB unique index: run `database/migration_v39_refund_guard.sql` (STEP 1 must return 0 rows).

## Deploy
Back up, upload over v57, `php -l` on: includes/ops.php includes/providers.php includes/schema_compat.php api/purchase-api.php api/api/reseller_gateway.php api/mykeys.php api/admin/all-orders.php. Optional: run migration_v58. Staging: `php tools/test_out_of_stock_v58.php --run` and `php tools/test_instant_refund.php --run`. Then on staging do one real capture of an out-of-stock reply.
