# v36 – Install, behaviour, limits, tests

## 1. Install (hosting)
1. Back up the database and files.
2. Upload this ZIP's contents over the site root (same structure; no outer folder).
3. Open any page once. The migration runs automatically and sets `settings.schema_v36=1`.
   If your DB user cannot ALTER, run `database/migration_v36_ops.sql` manually.
4. **Add the cron job** (cPanel → Cron Jobs → every minute):
   `* * * * * /usr/local/bin/php /home/ACCOUNT/public_html/cron/refund_controller.php >/dev/null 2>&1`
   Without cron, the controller still runs (at most every 2 minutes) when an admin opens the Dashboard or Pending Orders – but it will NOT run while nobody is in the admin panel. Cron is strongly recommended. A red banner appears if expired orders exist and nothing has run for 15 minutes.
5. Run `php tools/selftest_v36.php` on staging (read-only).

## 2. Pending Orders / Refund Controller
- Admin → **Pending Orders**. Columns: order ID, user, product, amount, created, provider, API expiry, latest API status, refund status.
- "Expired" = still `pending` after `order_pending_window_seconds` (default 600 s, editable on that page; applies to all providers).
- Rules (in `rc_decide`, in this order): never refund if keys/completion record/provider order id/refund row exist or the user was never charged; refund automatically only when failure is **proven** (provider says failed, the purchase flow recorded a confirmed failure but the refund did not finish, or a local-stock order is pending with no key). Everything else → admin review (alert type `refund_review`).
- Refunds use the existing `order_refund_once()` (row lock, pending→failed gate, wallet credit + ledger row in one transaction), plus a claim flag and a DB `GET_LOCK` so concurrent runs cannot double-refund.
- Manual refund: expired orders only, reason required, blocked if keys exist. "Complete order with key" is available when the provider did deliver.
- Every decision → table `order_refund_log` and `audit_logs`.

### Provider limitation (read this)
None of the five providers in `includes/providers.php` documents a per-order status endpoint or a callback/webhook, so **the controller cannot ask a provider whether an order was delivered** and no webhook receiver exists (an unauthenticated one would be unsafe). Consequences: orders where the provider's answer was lost/ambiguous (timeout, unreadable reply, crash) always go to **admin review** – they are never auto-refunded. If a provider later documents a status call, implement it in `rc_provider_status()`; the rules already handle `completed` / `failed` / `pending`.

## 3. Maintenance mode
One setting: `settings.maintenance_mode` (Admin → Settings toggle). Enforced server-side by `maintenance_global_gate()` (every request) and `maintenance_guard_orders()` (website purchase, plan purchase, reseller API `buy`). Non-admins get the maintenance page (pages) or HTTP 503 `{"ok":false,"code":"MAINTENANCE_MODE","maintenance":true}` + `Retry-After` (JSON). New orders are refused for everyone, admins included. Still working: admin panel, login/logout, deposit-status verification, reseller API `balance/variants/status/history`, cron/refunds. Pending orders are not auto-cancelled. Turning it OFF takes effect on the next request; no redeploy.

## 4. Financial cards – definitions
See header of `includes/finance.php`. Day = 00:00–24:00 in `APP_TIMEZONE` (Asia/Kolkata), shifted automatically if the MySQL clock is in another zone. Orders are dated by `created_at` (no completion timestamp exists).
**Withdrawals / pending withdrawals show N/A: the project has no withdrawal feature or table.** They are not faked; add a source in `fin_today()` if you add withdrawals.

## 5. Header clock
`12-hour hh:mm:ss AM/PM` + `dd Mon yyyy`, server-synced offset, timezone from `APP_TIMEZONE`, inside the existing `.dash-brand`, user pages only.

## 6. Security note
`config/config.php` in the uploaded project contains a hard-coded DB password default (also `CSRF_KEY`). I did not change it so your site keeps working. Prefer the `ROYALPANEL_DB_PASS` environment variable and rotate the password if this ZIP was shared.

## 7. What was and was NOT tested (honest)
**Executed:** clock formatting logic in Node (midnight, noon, 11:59 PM, 1 PM, invalid timezone fallback); header layout in headless Chromium at 320/360/390/768/1280 px (height unchanged, no overlap/clipping; fallback fonts because Google Fonts were unreachable); JS syntax (`node --check`); a bracket-balance check on every edited PHP file.
**NOT executed:** the sandbox has no PHP and no MySQL, so **no PHP code and no SQL was run** – no PHP lint, no refund, maintenance, dashboard or regression test has been executed. `tools/selftest_v36.php` is written but unrun. Please run it and the checklist below on staging before production.

### Manual test checklist (staging)
Refunds: expired order with recorded failure → auto refund once; run cron twice/in parallel → one ledger row; order with keys → blocked; ambiguous/uncertain order → review, no refund; provider disabled → retries ×3 then review; manual refund twice → second refused; simulate DB error → `refund_failed` then safe retry.
Maintenance: ON → store/dashboard/direct URLs show maintenance; POST `api/purchase.php`, `api/purchase-api.php`, reseller `buy` → 503 + code; reseller `status` still works; admin panel works; OFF → orders work again.
Finance: empty day → zeros; compare each card with SQL; deposits pending/rejected excluded; a non-admin session calling `api/admin/dashboard.php` → 403/redirect.
Existing site: login, store, purchase, deposit (Paytm), referral, API keys, menus.
