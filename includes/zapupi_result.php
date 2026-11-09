<?php
declare(strict_types=1);
/**
 * Shared template for payment-success.php / payment-failed.php / payment-timeout.php (ZapUPI result pages).
 * Expects $zp_kind = 'success' | 'failed' | 'timeout'  (set by the including page).
 *
 * The URL (page name or ?order=) is NEVER proof of anything. This page loads the deposit that belongs to the
 * logged-in user, asks ZapUPI (server-side, through zapupi_settle) and then shows what the DATABASE says.
 * It never credits the wallet itself: only zapupi_credit_verified() inside zapupi_settle() can.
 */
$user = require_user();

// ZapUPI redirects back with ?order_id=<our id>&utr=<utr> appended (v68 URLs used ?order=). Both are read; the utr is ignored (never trusted).
$zp_order = zapupi_order_from_request();
$zp_dep = null;
$zp_id = 0;
if ($zp_order !== '') {
    $q = db()->prepare('SELECT id FROM deposits WHERE order_ref = :o AND user_id = :u AND method = :m LIMIT 1');   // ownership + gateway check
    $q->execute([':o' => $zp_order, ':u' => (int)$user['id'], ':m' => 'zapupi']);
    $zp_id = (int)$q->fetchColumn();
} else {
    // No order id came back: use THIS user's most recent ZapUPI order (last 6 h). It only triggers the same server-side verification.
    $q = db()->prepare('SELECT id FROM deposits WHERE user_id = :u AND method = :m AND created_at > (NOW() - INTERVAL 6 HOUR) ORDER BY id DESC LIMIT 1');
    $q->execute([':u' => (int)$user['id'], ':m' => 'zapupi']);
    $zp_id = (int)$q->fetchColumn();
}
if ($zp_id > 0) {
    zapupi_settle(db(), $zp_id, 'page');                       // already-final orders return at once; pending ones are verified (throttled, idempotent)
    $zp_pub = zapupi_public_state(db(), $zp_id);
    $zp_dep = $zp_pub['row'] ?? null;
    $zp_state = $zp_pub['state'] ?? 'FAILURE';
    $zp_balance = (string)($zp_pub['balance'] ?? '0.00');
}
if ($zp_dep === null) $zp_state = 'NOTFOUND';

$page_css   = ['deposit.css', 'deposit-premium.css', 'payment-result.css'];
$page_js    = ['payment-result.js'];
$active_nav = 'deposit';
require __DIR__ . '/header.php';

$zp_money = static function ($v): string { return '₹' . number_format((float)$v, 2, '.', ','); };
$zp_h = [
    'SUCCESS'  => ['success', '✓', 'PAYMENT SUCCESSFUL', 'Your payment was verified with ZapUPI and your wallet has been credited.'],
    'PENDING'  => ['pending', '⏳', $zp_kind === 'timeout' ? 'PAYMENT TIMED OUT — VERIFYING' : 'PAYMENT PENDING', 'We have not received a confirmed payment yet. If you already paid, please wait: your wallet is credited automatically once ZapUPI confirms it. Do not pay again.'],
    'FAILURE'  => ['failed', '✕', 'PAYMENT UNSUCCESSFUL', 'ZapUPI did not confirm this payment, so nothing was added to your wallet. You can safely try again with a new payment.'],
    'EXPIRED'  => ['failed', '✕', 'PAYMENT EXPIRED', 'This payment window has closed. You can safely start a new payment.'],
    'NOTFOUND' => ['failed', '?', 'ORDER NOT FOUND', 'We could not find this payment order on your account.'],
];
[$zp_cls, $zp_icon, $zp_title, $zp_text] = $zp_h[$zp_state] ?? $zp_h['FAILURE'];
?>
<section class="balance-page deposit-page active">
  <div class="deposit-wrap">
    <div class="payment-topline">
      <div class="payment-brand">
        <img class="payment-brand-logo" data-global-logo src="<?= e(panel_logo_url()) ?>" alt="" width="28" height="28">
        <?php $p = preg_split('/\s+/', panel_name(), 2); echo e(strtoupper($p[0] ?? 'KEYS')); ?>
      </div>
      <div class="payment-secure">● SECURE</div>
    </div>

    <section class="zp-result <?= e($zp_cls) ?>" id="zpResult" aria-live="polite"
             data-state="<?= e($zp_state) ?>" data-deposit="<?= e((string)($zp_dep['id'] ?? 0)) ?>">
      <div class="zp-icon"><span><?= e($zp_icon) ?></span></div>
      <h1 class="zp-title"><?= e($zp_title) ?></h1>
      <p class="zp-text"><?= e($zp_text) ?></p>

      <?php if ($zp_dep !== null): ?>
      <dl class="zp-grid">
        <dt>Order ID</dt><dd><?= e((string)$zp_dep['order_ref']) ?></dd>
        <?php if ($zp_state === 'SUCCESS'): ?>
          <dt>Transaction ID</dt><dd><?= e((string)($zp_dep['gateway_txn_id'] ?? '—')) ?></dd>
          <dt>Amount (verified)</dt><dd><?= e($zp_money($zp_dep['amount'])) ?></dd>
          <dt>UTR</dt><dd><?= e((string)($zp_dep['utr'] ?? '') !== '' ? (string)$zp_dep['utr'] : '—') ?></dd>
          <dt>Payment date</dt><dd><?= e((string)($zp_dep['paid_at'] ?: $zp_dep['created_at'])) ?></dd>
          <dt>Wallet balance</dt><dd id="zpBalance"><?= e($zp_money($zp_balance)) ?></dd>
        <?php else: ?>
          <dt>Amount</dt><dd><?= e($zp_money($zp_dep['amount'])) ?></dd>
          <dt>Created</dt><dd><?= e((string)$zp_dep['created_at']) ?></dd>
        <?php endif; ?>
      </dl>
      <?php endif; ?>

      <?php if ($zp_state === 'PENDING'): ?>
        <div class="zp-status" id="zpStatusLine">Checking payment status…</div>
        <button type="button" class="deposit-pay-btn" id="zpCheck">CHECK STATUS NOW</button>
      <?php endif; ?>

      <?php if ($zp_state === 'FAILURE' || $zp_state === 'EXPIRED'): ?>
        <?php /* Retry creates a brand-new unique order through the normal deposit flow; this order is never reused. */ ?>
        <a class="deposit-pay-btn zp-btn" href="<?= e(base_url('deposit.php?gateway=zapupi' . ($zp_dep ? '&amount=' . rawurlencode(number_format((float)$zp_dep['amount'], 2, '.', '')) : ''))) ?>">↻ TRY AGAIN</a>
      <?php endif; ?>
      <a class="zp-link" href="<?= e(base_url('deposit.php')) ?>">← Back to Wallet &amp; Deposits</a>
    </section>
  </div>
</section>
<?php require __DIR__ . '/footer.php'; ?>
