# v39 – Licence Dashboard failed orders: instant, exactly-once wallet refund

## 0. What was and was NOT verified
The build sandbox has **no PHP, no MySQL and no network**. **Executed:** PHP bracket/string structure check on every edited PHP file (0 errors), `node --check assets/js/checkout.js` (OK), `tools/test_order_states.py`. **NOT executed:** any PHP/SQL path, any browser test, any call to the real Licence Dashboard. Everything below about runtime behaviour is derived from reading the code, not from running it. Run `php tools/test_instant_refund.php --run` on a **staging copy** (it uses a mocked provider, spends nothing, deletes its own rows) before going live.
**Not verifiable against the real Licence Dashboard:** its exact out-of-stock reply (the code is written for the documented `{"ok":false,"error":{"message":..}}`; any HTTP 4xx is now also treated as a refusal), whether it honours `idempotency_key` on a replayed request, and whether `history.php` can prove a missing order. None of these is documented in the supplied source, so none is relied on.

## 1. Root cause (from the code)
`api/purchase-api.php` charges the wallet and creates a **pending** order *before* calling the provider. After the call it sorted every outcome into "rejected" (refund) or "uncertain" (keep PENDING, no refund). In `includes/providers.php` `licencedash_request()` **threw** whenever the reply was not valid JSON — including a **4xx refusal with an empty/HTML body** — and `provider_failure_is_certain()` only recognised config/connection errors. So such a rejection was classified *uncertain*: the order stayed PENDING, the wallet stayed charged, nothing refunded, yet the customer still got the generic "Order Failed" popup. The refund controller then could not help (no provider status endpoint exists → "manual review"). Secondary defects: the refund result was ignored (a failed refund still showed "Order Failed"), the amount refunded was the caller's figure rather than the ledger's deduction, the reseller gateway's local-stock refund was a private inline copy (no tracking columns, not idempotent), and no marker was left if PHP died mid-request.

## 2. What changed
- `includes/providers.php` – HTTP 4xx (not 408) from provider 5 = confirmed refusal even with an empty/HTML body; unexplained 5xx without an explicit `ok:false` = uncertain; provider error `code` kept in the admin text (so OUT_OF_STOCK is recognised); CLI-only test hook.
- `includes/ops.php` – `order_refund_once()` now refunds the **exact amount found in the purchase ledger row**, and refuses (`no_charge`) when nothing was deducted. New `provider_call_classified()` (shared classifier) and `order_settle_failure()` (single failure path: confirmed → FAILED + refund committed immediately, one safe retry; uncertain → held, never refunded blindly). New customer messages.
- `api/purchase-api.php` – uses the two helpers; returns `ORDER FAILED — AMOUNT REFUNDED TO WALLET` (HTTP 409, code `ORDER_FAILED_REFUNDED`, real post-refund balance) **only after the refund commits**; refund DB error → `ORDER_FAILED_REFUND_PENDING` (honest, wallet not credited); unclear outcome → `ORDER_PENDING_VERIFICATION`. `ignore_user_abort`, longer time limit, shutdown marker.
- `api/api/reseller_gateway.php` – same settle path for every provider failure and for local-stock exhaustion (the private inline refund is gone).
- `api/admin/alerts.php` – handles `no_charge`.
- `assets/js/checkout.js` – shows the server's message, updates the header wallet balance, new titles for the verifying case. Layout/CSS untouched.
- New: `tools/test_instant_refund.php`, `database/migration_v39_refund_guard.sql` (optional).

## 3. Rules as implemented
Out of stock / explicit rejection / 4xx / "ok but no key" = FAILED + full refund now. Key delivered = completed, no refund. Timeout, unreadable reply, unexplained 5xx = **not** refunded and **not** re-sent to the provider; order is marked `UNCERTAIN:` and handled by the existing Pending Orders / Refund Controller flow (no documented provider status call exists, so it needs admin review: "Complete order with key" or "Refund order"). Exactly-once: order row `FOR UPDATE` + status must be `pending` + ledger check, all in the refund transaction; repeated request, refresh, cron or admin refund all return `already_settled`.

## 4. Install
Back up files and DB → upload the ZIP contents over the site root (keep `config/config.php` as yours) → no migration is required. Optional: `database/migration_v39_refund_guard.sql` (read STEP 1 first) → on staging run `php tools/test_instant_refund.php --run`.

## 5. Known limits
A genuinely ambiguous provider outcome stays PENDING (by design, to avoid refunding a delivered key); those orders now carry the `UNCERTAIN:` marker and an admin alert. Orders that were already stuck PENDING before this update are not auto-changed; review them in Admin → Pending Orders.
