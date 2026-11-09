<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
ensure_configured_admin();
$adminUser = require_admin();
/*
 * v67 - MASTER FRONTEND CONTROL (admin only).
 * This page deliberately does NOT inject the saved theme: the control panel always keeps the original look, so a bad colour
 * combination can never lock the admin out of the screen that fixes it. It only edits settings.theme_config (see includes/theme.php).
 */
$cssFile = __DIR__ . '/assets/css/admin-theme.css'; $jsFile = __DIR__ . '/assets/js/admin-theme.js';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e(panel_name()) ?> — Master Frontend Control</title>
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="base-url" content="<?= e(base_url()) ?>">
<link rel="stylesheet" href="<?= e(asset('css/admin-theme.css')) ?>?v=<?= e(is_file($cssFile) ? (string)filemtime($cssFile) : '1') ?>">
</head>
<body data-page="admin-theme">
<main class="mfc">
  <header class="mfc-top">
    <a href="<?= e(base_url('admin.php')) ?>">&larr; Admin</a>
    <h1>MASTER FRONTEND <span>CONTROL</span></h1>
    <span class="mfc-note">Settings save to the database and load on the server before the page paints.</span>
  </header>

  <nav class="mfc-tabs" id="mfcTabs">
    <button type="button" class="on" data-tab="colors">Global Color Studio</button>
    <button type="button" data-tab="fonts">Global Font Studio</button>
    <button type="button" data-tab="reset">Restore / ALL RESET</button>
  </nav>
  <div class="mfc-msg" id="mfcMsg" role="status" aria-live="polite"></div>

  <!-- ============ COLOR STUDIO ============ -->
  <section class="mfc-pane on" id="pane-colors">
    <div class="mfc-scope" data-for="color" role="radiogroup" aria-label="Colour scope">
      <label><input type="radio" name="cscope" value="all" checked><span><b>ALL — GLOBAL</b><small>Admin + User</small></span></label>
      <label><input type="radio" name="cscope" value="user"><span><b>USER SIDE</b><small>User website only</small></span></label>
      <label><input type="radio" name="cscope" value="admin"><span><b>ADMIN SIDE</b><small>Admin panel only</small></span></label>
    </div>
    <p class="mfc-hint" id="cscopeHint"></p>
    <div class="mfc-grid">
      <div class="mfc-list" id="colorList"></div>
      <aside class="mfc-preview" id="colorPreview" aria-label="Live preview">
        <div class="pv-title">LIVE PREVIEW</div>
        <div class="pv-page">
          <div class="pv-nav">Navigation</div>
          <div class="pv-side">Sidebar</div>
          <div class="pv-body">
            <h4 class="pv-h">Heading text</h4>
            <p class="pv-p">Primary text sample.</p><p class="pv-m">Secondary text sample.</p>
            <div class="pv-card"><b class="pv-h">Primary card</b><div class="pv-card2">Secondary card</div></div>
            <input class="pv-input" value="Input field" readonly>
            <button type="button" class="pv-btn">Button</button>
            <table class="pv-table"><tr><th>Table head</th><th>Value</th></tr><tr><td>Row</td><td>₹100</td></tr></table>
            <div class="pv-modal">Modal</div>
            <div class="pv-status"><span class="pv-ok">Success</span><span class="pv-warn">Warning</span><span class="pv-err">Error</span><span class="pv-acc">Accent</span></div>
          </div>
        </div>
      </aside>
    </div>
    <div class="mfc-actions">
      <button type="button" class="mfc-btn" id="saveAllColors">SAVE &amp; APPLY ALL COLORS</button>
      <button type="button" class="mfc-btn ghost" id="revertColors">DISCARD UNSAVED CHANGES</button>
    </div>
  </section>

  <!-- ============ FONT STUDIO ============ -->
  <section class="mfc-pane" id="pane-fonts">
    <div class="mfc-scope" data-for="font" role="radiogroup" aria-label="Font scope">
      <label><input type="radio" name="fscope" value="all" checked><span><b>ALL — GLOBAL</b><small>Admin + User</small></span></label>
      <label><input type="radio" name="fscope" value="user"><span><b>USER SIDE</b><small>User website only</small></span></label>
      <label><input type="radio" name="fscope" value="admin"><span><b>ADMIN SIDE</b><small>Admin panel only</small></span></label>
    </div>
    <p class="mfc-hint" id="fscopeHint"></p>
    <div class="mfc-fonts" id="fontList"></div>
    <div class="mfc-actions"><button type="button" class="mfc-btn" id="saveFont">SAVE &amp; APPLY FONT</button></div>
    <p class="mfc-hint">Monospace text (keys, order IDs, labels) and other dedicated fonts keep their own style on purpose. “Normal / Original” removes the override completely, so every element returns to its own original font.</p>
  </section>

  <!-- ============ RESET ============ -->
  <section class="mfc-pane" id="pane-reset">
    <div class="mfc-reset">
      <h2>RESET ALL TO ORIGINAL DEFAULTS</h2>
      <p>Removes every saved colour and font for <b>ALL, USER SIDE and ADMIN SIDE</b> and brings back the original theme immediately.</p>
      <p class="safe">Frontend appearance only. User accounts, admin accounts, wallet balances, deposits, payments, orders and gateway settings are never touched.</p>
      <button type="button" class="mfc-btn danger" id="resetAll">RESET ALL TO ORIGINAL DEFAULTS</button>
    </div>
    <p class="mfc-hint">Tip: the Deposit QR page and the Payment Success screen use these same colours and the global logo (Admin → Settings → logo).</p>
  </section>
</main>

<div class="mfc-modal" id="resetModal" hidden>
  <div class="mfc-modal-box" role="dialog" aria-modal="true" aria-labelledby="resetTitle">
    <h3 id="resetTitle">Confirm reset</h3>
    <p>Are you sure you want to restore all frontend settings to their original default configuration?</p>
    <div class="mfc-actions"><button type="button" class="mfc-btn ghost" id="resetCancel">Cancel</button><button type="button" class="mfc-btn danger" id="resetConfirm">Confirm Reset</button></div>
  </div>
</div>
<script src="<?= e(asset('js/admin-theme.js')) ?>?v=<?= e(is_file($jsFile) ? (string)filemtime($jsFile) : '1') ?>"></script>
</body>
</html>
