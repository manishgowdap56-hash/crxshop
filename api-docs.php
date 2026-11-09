<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/maintenance_gate.php';
$user = require_user();
header('Cache-Control: no-store, no-cache, must-revalidate');   // v62: role / wallet state must never come from a browser cache
dual_require_api_access($user, true);   // v62

$page_css   = ['api-docs.css'];
$page_js    = ['api-docs.js'];
$active_nav = 'api';

require __DIR__ . '/includes/header.php';
?>

<section class="api-docs-page active">
  <div class="api-docs-wrap">
    <div class="api-docs-topbar">
      <a class="api-docs-back" href="<?= e(base_url('api.php')) ?>">← Back to API</a>
      <span><?= e(strtoupper(panel_name())) ?> API</span>
    </div>

    <section class="api-docs-hero">
      <div class="api-docs-kicker">📖 RESELLER API — QUICK INTEGRATION</div>
      <h2>Connect your bot, panel or website</h2>
      <p>Use your personal API key with the live reseller gateway. Copy the PHP example below, use a Variant ID from the My API page, and handle the JSON response before delivering a key to your customer.</p>
    </section>

    <section class="api-doc-section">
      <div class="api-doc-section-title">🔑 AUTHENTICATION</div>
      <div class="api-auth-box">
        <div class="api-auth-label">API KEY</div>
        <div class="api-doc-code-row">
          <code id="apiDocTokenLine">api_key = <?= e((string)$user['api_token']) ?></code>
          <button type="button" class="api-doc-copy" data-copy-target="apiDocTokenLine">📋 Copy</button>
        </div>
        <div class="api-auth-label api-auth-url-label">Base URL:</div>
        <div class="api-doc-code-row">
          <code id="apiDocBaseLine"><?= e(base_url('api/api/reseller_gateway.php')) ?></code>
          <button type="button" class="api-doc-copy" data-copy-target="apiDocBaseLine">📋 Copy</button>
        </div>
        <p class="api-auth-warning">⚠️ If your key leaks, regenerate it immediately from the <a href="<?= e(base_url('api.php')) ?>">My API</a> page.</p>
      </div>
    </section>

    <section class="api-doc-section">
      <div class="api-doc-section-title">💻 PHP EXAMPLE — BUY A KEY</div>
      <div class="api-code-wrap">
        <button type="button" class="api-doc-copy api-code-copy" data-copy-target="apiPhpExample">📋 Copy</button>
        <pre class="api-code-block api-php-documentation" id="apiPhpExample"></pre>
      </div>
      <p class="api-doc-tip">💡 Always check <code>$result['ok']</code> before reading the key. On failure you get a clear error message instead (e.g. insufficient balance, invalid variant_id).</p>
    </section>

    <section class="api-doc-section">
      <div class="api-doc-section-title">🧩 FIND YOUR VARIANT IDS</div>
      <p>Your real, live Variant IDs (with price + stock status) are on the <a href="<?= e(base_url('api.php')) ?>">My API</a> page — tap any one there to copy it.</p>
    </section>

    <section class="api-doc-section api-endpoints-section">
      <div class="api-doc-section-title">📚 ALL ENDPOINTS</div>
      <p>Every request needs your key, sent either as <code>api_key=...</code> (field) or an <code>x-master-key</code> header. Every response is JSON: <code>{"ok":true,...}</code> on success, <code>{"ok":false,"error":"..."}</code> on failure.</p>

      <article class="endpoint-card">
        <div class="endpoint-heading"><span class="method-badge">GET/POST</span><code>?action=balance</code></div>
        <p>Read-only — spends nothing.</p>
        <div class="endpoint-table">
          <div>Param</div><div>Required</div><div>Notes</div>
          <div>— none, just your key —</div><div></div><div></div>
        </div>
        <p class="endpoint-return">Returns: <code>{ ok, username, is_reseller, wallet_balance }</code></p>
      </article>

      <article class="endpoint-card">
        <div class="endpoint-heading"><span class="method-badge">GET/POST</span><code>?action=variants</code></div>
        <p>Read-only — returns one row per plan.</p>
        <div class="endpoint-table">
          <div>Param</div><div>Required</div><div>Notes</div>
          <div>— none, just your key —</div><div></div><div></div>
        </div>
        <p class="endpoint-return">Returns: <code>{ ok, variants: [ {variant_id, product, variant, validity, price, in_stock} ] }</code></p>
      </article>

      <article class="endpoint-card">
        <div class="endpoint-heading"><span class="method-badge post">POST</span><code>?action=buy</code> <span class="alias">(alias: ?action=order)</span></div>
        <p>Spends real wallet balance. Generates and returns key(s) instantly.</p>
        <div class="endpoint-table endpoint-buy">
          <div>Param</div><div>Required</div><div>Notes</div>
          <div><code>variant_id</code></div><div class="required">required</div><div>From the My API page.</div>
          <div><code>quantity</code></div><div class="optional">optional</div><div>Default 1, max 10 per call.</div>
          <div><code>android_id</code></div><div class="optional">optional</div><div>Only for device-bound (v1) variants, where it is required. Ignored for every other variant.</div>
          <div><code>api_version</code></div><div class="optional">optional</div><div><code>v1</code> or <code>v2</code>. Only meaningful for variants that support both; default <code>v2</code>.</div>
        </div>
        <p class="endpoint-return">
          Success: <code>{ ok:true, keys:[...], orders:[...], balance, order_id, key, expires_at, ... }</code><br>
          Failure (e.g. low balance): <code>{ ok:false, error, required, available, shortfall }</code>
        </p>
      </article>

      <article class="endpoint-card">
        <div class="endpoint-heading"><span class="method-badge">GET/POST</span><code>?action=status</code></div>
        <p>Read-only — look up any past order you made, by ID.</p>
        <div class="endpoint-table">
          <div>Param</div><div>Required</div><div>Notes</div>
          <div><code>order_id</code></div><div class="required">required</div><div>From a previous buy response.</div>
        </div>
        <p class="endpoint-return">Returns: <code>{ ok, order_id, status, key, product_name, plan_label, amount, expires_at, is_expired }</code></p>
      </article>

      <article class="endpoint-card">
        <div class="endpoint-heading"><span class="method-badge">GET/POST</span><code>?action=history</code></div>
        <p>Read-only — your recent orders, newest first.</p>
        <div class="endpoint-table">
          <div>Param</div><div>Required</div><div>Notes</div>
          <div><code>limit</code></div><div class="optional">optional</div><div>Default 20, max 100.</div>
        </div>
        <p class="endpoint-return">Returns: <code>{ ok, orders: [ ...same shape as status ] }</code></p>
      </article>
    </section>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>