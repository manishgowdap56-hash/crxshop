# v66 - New API: Provider 6 = VIP SELLER HUB

Base: v65. **No migration needed**: the 4 new settings rows (`api6_*`) are seeded automatically by `schema_compat.php` (and `schema.sql` for fresh installs). `provider_key` = `vipsellerhub` (12 chars, fits VARCHAR(20)). Existing products/orders/prices/Price Sync/membership are untouched.

## Verification status (honest)
No PHP/MySQL/browser/provider in the build environment. **Not run:** `php -l`, any live call to the provider. Run: `node --check` on admin.js, `tools/php_lint_approx.py` (0 findings), existing v63/v64/v65 static + mirror checks (pass). Test on staging before enabling.
Run on the server: `find . -name '*.php' -print0 | xargs -0 -n1 php -l | grep -v '^No syntax errors'`

## Contract used (ONLY what the supplied vipsellerhub-client.php states)
Header `X-API-Token`, Accept JSON. `GET balance.php`, `GET products.php`, `GET key_history.php?limit=N`, `POST generate_key.php` (form: variant_id, quantity). Success `{success:true, keys[], order_id,...}`, failure `{success:false, code, error}`.
Default base URL `https://whitexmodz.store/api/v1` (the client's `API_BASE`; the client's header comment mentions resellerpanelpro.store - **confirm the correct base URL** and change it in Admin -> API settings -> Provider 6 if different).
Token format check mirrors the client: 32-128 hex chars.

## Safety rules (same philosophy as Provider 5)
- Success only if `success === true` AND at least one real key. Never fabricated.
- Cloudflare can wrap an origin 5xx as HTTP 200 + `success:false`: JSON `success` is the truth. A real 5xx, or a `success:false` whose code names a server/gateway fault, is UNCERTAIN (held for reconciliation, never blindly refunded). `success:true` with no key = uncertain.
- Explicit refusal (`success:false` / 4xx) = confirmed failure -> existing refund path. Error text keeps the provider code (e.g. `[OUT_OF_STOCK]`) so the existing out-of-stock detection works.
- 429 is retried (Retry-After honoured, capped at 5 s, 1s/2s/4s). No other re-send (the API documents no idempotency key).
- Variant ID must be digits; validated before any wallet charge. Provider OFF / not configured is checked before charging (website, API and reseller gateway).
- Per-order limit stays the site's 20 (provider allows 100).
- Token only in the `X-API-Token` header; never returned, logged or put in a URL. HTTPS required, redirects not followed.

## Files changed
`includes/providers.php` (constant, info, `vipseller_*` functions, dispatcher), `api/admin/settings.php` (save/toggle/remove/test/catalog/history), `api/admin/products.php` (variant validation), `api/api/reseller_gateway.php` (pre-charge check), `includes/schema_compat.php`, `database/schema.sql`, `admin.php` (Provider 6 card), `assets/js/admin.js` (card handlers, provider list, labels), `README_V66.md`.

## Not included (by design)
Auto price-sync / price check / auto ID detection are Provider-5-only; for Provider 6 enter the selling price manually. The provider `products.php` has a USD `price`: say the word and I'll add price sync for it. `reset_key.php` / `reset_apis.php` are not wired (no product-reset flow in this site).

## Deployment
1. Back up DB + files. 2. Extract over the site (keep `config/`, `uploads/`). 3. `php -l` command above. 4. Hard-refresh admin -> API Settings -> Provider 6: enter token, CHECK BALANCE, LOAD CATALOG, switch ON. 5. Create a test duration: source API, provider VIP SELLER HUB, Variant ID from the catalog; buy 1 key with a test user. Rollback: restore previous files (no DB change).
