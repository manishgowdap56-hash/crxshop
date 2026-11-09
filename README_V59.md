> SUPERSEDED in part by README_V60.md (removal threshold is now 3 checks, Custom Price is never overridden, restore is automatic after validation, 3 workers). Read README_V60.md first.

# v59 - shared order executor, price-increase grace period, custom price, duration lifecycle

## READ FIRST: what was and was NOT verified
The build sandbox had **no PHP, no MySQL, no network, no browser and no Provider 5 token**. **No PHP code in this release has been executed.**
Actually executed here: `node --check` on the 3 changed/new JS files (pass); a bracket/quote balance scan of the 12 changed PHP files (balanced - this is **not** `php -l`);
`python3 tools/mirror_v59_check.py` (38 checks, all pass - a **Python mirror of the pure rules**; it proves the rules, not the PHP).
Written but **not run**: `tools/test_v59_staging.php` (real PHP functions + the existing CLI-only provider test hook, no real provider call).
**Run `php -l` on every changed file and the staging test on a COPY of the site before production.** Section "Test report" lists every scenario of your brief with its true status.

## Root cause of "PENDING" reseller orders (found by reading the code; not reproduced live)
1. Website checkout and the gateway classified Provider 5 replies the same way, but **settled them differently**:
   - Gateway **finalize failure** (database error while saving the key) was only logged: keys were returned to the caller, but the order stayed **PENDING** with no key stored, no alert and no retry (the website had a retry + alert). The refund controller could later treat it as unfinished.
   - Gateway answered an **uncertain** order with **HTTP 502 `{ok:false}`**: clients treat that as a failure and **re-send the buy**, creating a second charged order while the first stays PENDING. Website answered 202.
   - No client idempotency existed on the gateway.
   - A PHP death after the provider call left no marker anywhere except a `failure_reason` update.
2. The two channels had separate copies of the provider call + settlement code.
Provider 5's real behaviour (does it honour `idempotency_key`?) is **unverified** - see Limitations.

## What changed
**One shared executor** `includes/order_executor.php` -> `order_execute_provider()` is now the only code that runs after the wallet charge, for both `api/purchase-api.php` (website) and `api/api/reseller_gateway.php` (reseller API). It:
- records a row in new table `provider_order_attempts` (**UNIQUE(order_id)**) **before** sending; a second execution for the same order never sends again;
- calls the existing `provider_call_classified()` and maps to the 7 states: SUCCESS, CONFIRMED_REJECTED, CONFIRMED_OUT_OF_STOCK, UNCERTAIN, SUCCESS_WITHOUT_KEY, INVALID_RESPONSE, INTERNAL_ERROR;
- stores HTTP code, response time ms, upstream id, error text and a **key-masked** response snapshot;
- SUCCESS -> `ox_finalize_success()`: order COMPLETED + keys + referral (once) in one transaction; second essential-only attempt; if both fail the keys are **parked** in `provider_order_attempts.pending_keys`, a critical alert is raised, and `cron/refund_controller.php` completes the order automatically (`ox_recover_stranded()`); the refund controller now **blocks** any refund for an order with parked keys / recorded SUCCESS;
- CONFIRMED_* -> existing `order_settle_failure()` / `order_refund_once()` (row lock, ledger duplicate check, exact deducted amount, FAILED only after the refund commits);
- UNCERTAIN / SUCCESS_WITHOUT_KEY / INVALID_RESPONSE / INTERNAL_ERROR after the send -> order stays PENDING marked `UNCERTAIN:`, admin alert, **never refunded, never re-sent**;
- shutdown handler marks any order the request abandoned; `ignore_user_abort(true)` so a client disconnect cannot stop settlement.
- Behaviour change to know: a provider reply "accepted but no key" (non-Provider-5 adapters too) is now **held**, not refunded (the key may exist).

**Reseller API responses** (`action=buy`): success `{ok:true,status:"COMPLETED",order_id,product,validity,key,keys,balance}`; out of stock HTTP 409 `{ok:false,status:"FAILED",failure_code:"OUT_OF_STOCK",reason:"OUT OF STOCK",refunded:true,refunded_amount,balance}` (only after the refund committed); uncertain HTTP **202** `{ok:true,delivered:false,status:"PENDING",pending_review:true,refunded:false,key:"",keys:[]}` - **API change: clients must not treat this as an error nor re-send; poll `action=status`**. New optional `Idempotency-Key` header / `idempotency_key` field (8-64 chars): the same key never creates/charges a second order; the first order's current state is returned (`replayed:true`). `action=status` now also returns `status_label`, `pending_review`, `failure_code`. Fixed: `action=variants` returned `validity:null` (alias bug).

**Price model** (`includes/price_policy.php`, `includes/price_sync.php`): six values stay separate - provider price (`provider_price`), currency, rate (`price_rate`, from setting `sync_usd_inr_rate`, **admin-configurable, default 30**), calculated INR (`inr_cost`), custom price (`custom_price` + `custom_price_enabled`), customer-facing price (`price`, the only column purchase code reads). Money maths stays integer micro-units; converted once; no markup.
- **Increase**: selling price is NOT changed; row in `price_change_events` + admin alert (product, duration, old/new provider price, rate, old/new INR, current price, detected time, deadline). Grace default **25 min (20-30)**. The original deadline is kept even if the provider price moves again.
- **Deadline reached** (runs every cycle, also when the catalog fetch failed, using the last verified pending price): new calculated price applied at the **current** rate; history + "auto update completed" alert. Rule for a custom price: if it is >= the new calculated price it is **kept**; if it is **below** the new calculated price it is switched off (value kept) and the calculated price applies - we never silently sell below the new cost.
- **Decrease**: setting `price_decrease_policy` = `apply` (default; non-custom prices follow) or `hold`; a **custom price is never lowered**.
- Missing / zero / invalid provider price: stored price kept (unchanged v55 rule). Rate change: non-custom prices follow on the next check, custom prices kept; the change is logged.
- A new later increase opens a new event + new grace period; earlier events stay in history.

**Custom Price (orange box)** in the admin duration editor (API durations): enable switch, custom price field, and read-only provider price / rate / calculated / current selling price. While custom is ON the plain Amount box no longer changes the price. Per duration, survives catalog refreshes, never touches provider cost. Turning it OFF returns the price to the calculated provider-based price.

**Duration lifecycle**: only after a **complete** catalog read (all pages, valid JSON, no fetch errors, not "implausible": more than half of the mapped durations vanishing at once is not trusted). Exact provider service id from `product_plans.provider_variant_id` (never by name). Missing -> `missing` + alert + event; after **N consecutive complete checks (default 30) AND at least M minutes (default 5)** -> `removed`: `enabled=0, sync_removed=1, lifecycle_state='removed'`. Nothing is deleted (orders, ledger, mapping, custom price, history stay); the duration disappears from the website, `api-variants.php`, gateway `variants` and `buy`, and `purchase-api.php` rejects it. Reappearance: state `reappeared` + alert; **restored only by an admin click** (Monitor -> Duration Monitor -> Restore) unless you enable `sync_auto_restore`. Durations stay grouped under their product; nothing creates products/categories.

**Admin Monitor** (`admin-monitor.php`, drawer link "Monitor"; API `api/admin/monitor.php`, admin + CSRF): API Order Monitor, Price Increase Alerts (countdown, set custom price, apply now), Duration Monitor, Price History (increases, decreases, custom, auto, rate, removal/restore), Settings (rate, grace 20-30, decrease policy, removal thresholds, auto-restore), and real scheduler health (from the Price Sync cycle data; never a fake RUNNING).

## Deployment
1. **Back up** database + files. 2. Upload over v58 (ZIP root = site root). 3. `php -l` on: includes/order_executor.php includes/price_policy.php includes/price_sync.php includes/providers.php includes/bootstrap.php includes/refund_controller.php api/purchase-api.php api/api/reseller_gateway.php api/admin/products.php api/admin/monitor.php admin-monitor.php cron/refund_controller.php.
4. Schema is applied automatically (additive, idempotent). If your DB user cannot ALTER/CREATE run `database/migration_v59_reliability_pricing.sql` by hand (also needs v58's `orders.failure_code`).
5. Cron (unchanged design; both every minute):
```
* * * * * /usr/local/bin/php /home/ACCOUNT/public_html/cron/provider_sync.php --loop >> /home/ACCOUNT/provider_sync.log 2>&1
* * * * * /usr/local/bin/php /home/ACCOUNT/public_html/cron/refund_controller.php >> /home/ACCOUNT/refund_controller.log 2>&1
```
`--loop` runs ~55 s per minute with one cycle every Admin -> Price Sync "interval" (default 10 s, min 4) under the MySQL lock `royalpanel_provider_sync`; it works with the admin logged out. The **actual measured interval is displayed**; 10 s is a target, not a promise (one cycle reads the whole catalog; provider rate limits/back-off apply).
6. Admin -> Monitor -> Settings: confirm exchange rate (30), grace (25), thresholds. 7. Run the staging test on a copy.

## Admin how-to
- **Custom price**: Products -> edit -> duration -> orange CUSTOM PRICE box -> tick, type price, save.
- **Price increase alert**: Monitor -> Price Increase Alerts. Inside the countdown: *Set custom price* (keeps your price) or *Apply new price now*. If you do nothing the new calculated price is applied at the deadline (see custom-price rule above). You can set a custom price any time afterwards.
- **Pending API orders**: an order with `UNCERTAIN:` is held. Check the provider's Recent Keys; then Pending Orders -> Complete order with key, or Refund. The reseller customer polls `action=status`.
- **Removed duration**: nothing to do; Restore it from Duration Monitor if the provider re-adds it.

## Test report (true status)
Legend: MIRROR = Python mirror executed, PASS proves the rule only. STAGING = written in tools/test_v59_staging.php, NOT RUN. NOT RUN = no test exists/executable here.
| Scenario | Status |
|---|---|
| API 1-2 success one/many keys | MIRROR PASS; STAGING written (not run) |
| API 3 explicit reject; 4 OUT_OF_STOCK | MIRROR PASS; STAGING written |
| API 5 timeout; 6 invalid JSON; 7 ok:true no key | MIRROR PASS (held, not refunded); STAGING written |
| API 8 DB fails saving key | design: parked keys + recovery; **NOT RUN** |
| API 9 client disconnects | design: ignore_user_abort + shutdown marker; **NOT RUN** |
| API 10 same request twice | UNIQUE(order_id) mirror PASS; gateway Idempotency-Key **NOT RUN** |
| API 11 website vs API consistent | structurally one code path; STAGING written, **NOT RUN** |
| Duration 1-9 | MIRROR PASS for the counting/threshold/flap/reappear/partial-catalog rules; PHP + DB behaviour **NOT RUN** |
| Price 1-12 | MIRROR PASS for classification, grace clamp, custom rule, rate/rounding/no double conversion; DB + UI **NOT RUN** |
| Refund 1-6 | existing v39/v58 engine reused unchanged; STAGING covers 1,2,6; duplicate/retry **NOT RUN** |
| Regression (login, checkout, wallet, deposits, Paytm, My Keys, referral, maintenance, other providers) | **NOT RUN** - no PHP/DB. Changed code only in order settlement, price sync, admin product save and listings; run your own smoke test. |
| JS syntax (admin.js, admin-monitor.js, checkout untouched) | node --check PASS |

## Limitations that could not be verified
- Provider 5 **idempotency_key honouring** and its exact **out-of-stock `error.code`** are not documented in the project; out of stock is recognised from `error.code = OUT_OF_STOCK` or the provider's own wording ("out of stock", "sold out", ...). Send one real out-of-stock reply to confirm.
- Provider 5 `history.php` has no documented shape, so **no automatic reconciliation by provider history** exists; uncertain orders go to admin review (never auto-refunded, never re-sent). I did not invent fields.
- Catalog pagination format is inferred by the existing v55 code; the catalog-complete rule stays fail-closed.
- The 10 s interval depends on host and provider limits.
- Order status stays within the existing enum (`pending` + `UNCERTAIN:` marker); no new enum value was added to avoid breaking existing readers.
- The admin Monitor is a separate page (not inside the admin SPA router) to avoid touching the working admin shell.

## Files changed / added
New: includes/order_executor.php, includes/price_policy.php, api/admin/monitor.php, admin-monitor.php, assets/js/admin-monitor.js, assets/css/monitor.css, database/migration_v59_reliability_pricing.sql, tools/mirror_v59_check.py, tools/test_v59_staging.php, README_V59.md.
Changed: includes/providers.php (call metadata hook), includes/price_sync.php (rate setting, lifecycle + policy wiring), includes/bootstrap.php, includes/refund_controller.php (never refund parked/succeeded), cron/refund_controller.php (recovery), api/purchase-api.php, api/api/reseller_gateway.php, api/admin/products.php (custom price), admin.php (nav link), assets/js/admin.js, assets/css/admin.css, database/migration_v55_price_sync_only.sql (no longer deletes the rate setting).

## Backup / rollback
Back up DB + files first. Roll back by restoring the v58 files and the DB backup. The v59 schema is purely additive (new columns/tables with defaults), so old code keeps working on the new schema; nothing needs dropping. To disable the new price behaviour without a rollback: Price Sync OFF. Parked keys (`provider_order_attempts.pending_keys`) must be completed before rolling back code.
