<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/maintenance_gate.php';
$user = require_user();
header('Cache-Control: no-store, no-cache, must-revalidate');   // v62: role / wallet state must never come from a browser cache
dual_require_api_access($user, true);   // v62: Reseller / Admin only, checked against the database on every request

$page_css   = ['api.css'];
$page_js    = ['api.js'];
$active_nav = 'api';

require __DIR__ . '/includes/header.php';
?>

<section class="api-page active">
  <div class="api-wrap">
    <div class="api-wallet" id="apiWalletBalance">💰 Wallet Balance: <?= e(money($user['balance'])) ?></div>

    <section class="api-card">
      <div class="api-kicker">
        <?= e(strtoupper(panel_name())) ?>, RESELLER PORTAL — YOUR API
      </div>
      <h2 class="api-title">API KEY</h2>
      <div class="api-key-label">API KEY</div>
      <div class="api-key-row">
        <div class="api-key-value" id="apiTokenValue"><?= e((string)$user['api_token']) ?></div>
        <button type="button" class="api-copy" id="apiCopyToken">COPY</button>
      </div>
      <div class="api-warning">⚠️ Treat this like a password — anyone with it can spend your wallet balance. Don't share it publicly.</div>
      <button type="button" class="api-action" id="apiRegenerate">🔄 Regenerate Key</button>
    </section>

    <section class="api-card">
      <div class="api-kicker">FULL API DOCUMENTATION</div>
      <button type="button" class="api-doc-btn" id="apiDocsButton">📖 Open Full Documentation</button>
    </section>

    <section class="api-card">
      <div class="api-variants-head">
        <div class="api-variants-title">🟠 YOUR VARIANTS</div>
        <div class="api-count" id="apiVariantCount">0 VARIANTS</div>
      </div>
      <div id="apiVariantGroups">
        <div class="api-empty">Loading…</div>
      </div>
    </section>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>