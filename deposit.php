<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/maintenance_gate.php';
$user = require_user();

$page_css   = ['deposit.css', 'deposit-premium.css', 'deposit-gateway.css'];   // v67: premium QR / success presentation (CSS + markup only)
$page_js    = ['deposit.js'];
$active_nav = 'deposit';

// v68: only gateways the admin switched on are offered. Paytm and ZapUPI switches are independent.
$zpCfg   = zapupi_config();
$gateways = [];
if (paytm_config()['enabled']) {
    $gateways[] = ['id' => 'paytm', 'label' => 'Paytm Business', 'min' => deposit_min(), 'max' => deposit_max(), 'note' => 'UPI QR on this page • expires in 3 minutes'];
}
if (zapupi_available()) {
    $gateways[] = ['id' => 'zapupi', 'label' => $zpCfg['name'], 'min' => $zpCfg['min_paise'] / 100, 'max' => $zpCfg['max_paise'] / 100,
                   'note' => 'You will be redirected to the secure ZapUPI payment page', 'instructions' => $zpCfg['instructions']];
}
$gwDefault = $gateways ? $gateways[0]['id'] : 'paytm';
$fmtAmt = static function (float $v): string { return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.'); };

require __DIR__ . '/includes/header.php';
?>

<section class="balance-page deposit-page active">
  <div class="deposit-wrap">
    <div class="deposit-form-view" id="depositFormView">
      <div class="deposit-wallet-card">
        <span class="deposit-wallet-icon">💰</span>
        <span>Wallet Balance: <strong id="balancePageAmount"><?= e(money($user['balance'])) ?></strong></span>
      </div>

      <section class="deposit-add-card">
        <div class="deposit-card-kicker">＋ ADD BALANCE</div>
        <?php if (count($gateways) > 1): ?>
        <label>PAYMENT METHOD</label>
        <div class="deposit-gateways" id="depositGateways" role="radiogroup" aria-label="Payment method">
          <?php foreach ($gateways as $i => $g): ?>
          <button type="button" class="deposit-gw<?= $i === 0 ? ' active' : '' ?>" role="radio" aria-checked="<?= $i === 0 ? 'true' : 'false' ?>" data-gateway="<?= e($g['id']) ?>">
            <strong><?= e($g['label']) ?></strong><small><?= e($g['note']) ?></small>
          </button>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if (!$gateways): ?>
        <div class="deposit-gw-instructions">No payment method is available right now. Please try again later.</div>
        <?php endif; ?>
        <!-- Per-gateway limits / instructions: public values only (no keys). JS swaps them when the method changes. -->
        <div id="depositGatewayData" hidden data-default="<?= e($gwDefault) ?>" data-count="<?= count($gateways) ?>">
          <?php foreach ($gateways as $g): ?>
          <span data-gateway="<?= e($g['id']) ?>" data-min="<?= e($fmtAmt((float)$g['min'])) ?>" data-max="<?= e($fmtAmt((float)$g['max'])) ?>" data-label="<?= e($g['label']) ?>" data-instructions="<?= e((string)($g['instructions'] ?? '')) ?>"></span>
          <?php endforeach; ?>
        </div>
        <div class="deposit-gw-instructions" id="depositGatewayInstructions" hidden></div>
        <label for="depositAmount">ENTER AMOUNT (₹)</label>
        <div class="deposit-input-wrap">
          <input id="depositAmount" type="number"
                 min="<?= e((string)deposit_min()) ?>" max="<?= e((string)deposit_max()) ?>"
                 step="0.01" inputmode="decimal"
                 placeholder="Minimum ₹<?= e(rtrim(rtrim(number_format(deposit_min(), 2, '.', ''), '0'), '.')) ?>" autocomplete="off">
        </div>
        <div class="deposit-hint">
          Minimum ₹<?= e(rtrim(rtrim(number_format(deposit_min(), 2, '.', ''), '0'), '.')) ?> — maximum ₹<?= e(rtrim(rtrim(number_format(deposit_max(), 2, '.', ','), '0'), '.')) ?>.
        </div>
        <button type="button" class="deposit-pay-btn" id="depositCreateOrder">→ Payment Now</button>
        <div class="deposit-form-message" id="depositFormMessage" aria-live="polite"></div>
      </section>

      <section class="deposit-history-card">
        <div class="deposit-history-head">
          <div>
            <div class="deposit-card-kicker">▣ DEPOSIT HISTORY</div>
            <p>Every deposit is credited only after the payment gateway verifies it. Paytm payments expire after 3 minutes; ZapUPI payments stay pending until ZapUPI confirms them.</p>
          </div>
        </div>
        <div class="deposit-history-list" id="depositHistoryList"></div>
        <div class="deposit-history-empty" id="depositHistoryEmpty">No deposit orders yet.</div>
      </section>
    </div>

    <div class="deposit-payment-view" id="depositPaymentView" hidden>
      <div id="depositQrView">
      <div class="payment-topline">
        <div class="payment-brand">
          <img class="payment-brand-logo" data-global-logo src="<?= e(panel_logo_url()) ?>" alt="" width="28" height="28">
          <?php $p = preg_split('/\s+/', panel_name(), 2); echo e(strtoupper($p[0] ?? 'KEYS')); ?>
        </div>
        <div class="payment-secure">● SECURE</div>
      </div>
      <section class="payment-card">
        <div class="payment-kicker">AMOUNT PAYABLE</div>
        <div class="payment-amount" id="depositPayableAmount">₹0.00</div>
        <div class="payment-order-grid">
          <span>Order For</span><strong>Wallet Top-up</strong>
          <span>Order ID</span><strong id="depositOrderId">—</strong>
        </div>
        <div class="payment-qr-frame" id="depositQrFrame" aria-label="Payment QR code"></div>
        <button type="button" class="payment-download" id="depositDownloadQr">▣ Download QR</button>
        <div class="payment-expiry"><span>●</span> Expires in <strong id="depositCountdown">--:--</strong></div>
        <div class="payment-status waiting" id="depositPaymentStatus">
          <span class="status-dot"></span><span>Waiting for payment…</span>
        </div>
        <ol class="payment-steps">
          <li>Open any UPI app</li><li>Scan this QR code</li><li>Pay the exact amount shown</li><li>Wallet is credited after verification</li>
        </ol>
        <div class="payment-footnotes">
          <span>🔒 256-bit Encrypted</span><span>⚡ Instant Credit on Payment</span><span>🛡 Verified payments only</span>
        </div>
      </section>
      <button type="button" class="payment-cancel" id="depositCancelPayment">← Cancel &amp; Go Back</button>
      </div>

      <!-- Shown ONLY after the server has verified the payment with Paytm (same sequence as index.php) -->
      <section class="pay-result-card" id="depositProcessingView" hidden aria-live="polite">
        <div class="pay-result-ring-wrap"><div class="pay-result-ring"></div><div class="pay-result-core"><span>↻</span></div></div>
        <p class="pay-result-kicker">PAYMENT RECEIVED</p>
        <h2 class="pay-result-title">PROCESSING PAYMENT</h2>
        <p class="pay-result-text">Payment verified with Paytm.<br>We are securely updating your wallet.</p>
        <div class="pay-result-progress"><span></span></div>
        <div class="pay-result-pill"><i></i> VERIFYING &amp; CREDITING WALLET</div>
      </section>

      <section class="pay-result-card success" id="depositSuccessView" hidden aria-live="polite">
        <div class="pay-logo-frame"><img data-global-logo src="<?= e(panel_logo_url()) ?>" alt="<?= e(panel_name()) ?>"></div>
        <div class="pay-result-ring-wrap"><div class="pay-result-check"><span>✓</span></div></div>
        <p class="pay-result-kicker success">PAYMENT CONFIRMED</p>
        <h2 class="pay-result-title success">PAYMENT SUCCESSFUL</h2>
        <div class="pay-result-amount" id="depositSuccessAmount">₹0.00</div>
        <p class="pay-result-text">Your payment has been verified and your wallet has been credited.</p>
        <div class="pay-result-order">ORDER ID <strong id="depositSuccessOrder">—</strong></div>
        <div class="pay-result-balance">WALLET BALANCE <strong id="depositSuccessBalance">—</strong></div>
        <div class="pay-result-pill success"><i></i> ORDER COMPLETED • WALLET CREDITED</div>
      </section>
    </div>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>