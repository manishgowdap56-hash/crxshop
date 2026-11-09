<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/maintenance_gate.php';
require_user();

$page_css   = ['mykeys.css'];
$page_js    = ['mykeys.js'];
$active_nav = 'mykeys';

require __DIR__ . '/includes/header.php';
?>

<section class="mykeys-page active">
  <div class="mykeys-wrap">
    <div class="history-date">
      <div class="history-date-icon">📅</div>
      <input type="date" id="historyDate" aria-label="Select history date">
      <span class="history-date-arrow">⌄</span>
    </div>

    <div class="history-stats">
      <div class="history-stat api">
        <div class="history-stat-icon">🔌</div>
        <div class="history-stat-label">TODAY'S API BUYS</div>
        <div class="history-stat-value" id="apiBuyCount">0</div>
      </div>
      <div class="history-stat site">
        <div class="history-stat-icon">🌐</div>
        <div class="history-stat-label">TODAY'S SITE BUYS</div>
        <div class="history-stat-value" id="siteBuyCount">0</div>
      </div>
    </div>

    <div class="history-list-head">
      <span id="historyDateLabel">KEY PURCHASE HISTORY</span>
      <span id="historyTotalLabel">0 KEYS</span>
    </div>
    <div class="mykeys-list" id="myKeysList"></div>
    <div class="mykeys-empty" id="myKeysEmpty" hidden>
      <div class="mykeys-empty-icon">🔐</div>
      <strong>NO KEYS FOUND</strong>
      <span>No purchases were found for the selected date.</span>
    </div>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>