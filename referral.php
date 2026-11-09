<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/maintenance_gate.php';
$user = require_user();

$page_css   = ['referral.css'];
$page_js    = ['referral.js'];
$active_nav = 'referral';

require __DIR__ . '/includes/header.php';
?>

<section class="referral-page active">
  <div class="referral-wrap">
    <section class="referral-card">
      <h2 class="referral-heading">
        <span class="ref-chain">🔗</span><span>REFER &amp; EARN MONEY</span>
      </h2>
      <div class="referral-stats">
        <div class="referral-stat">
          <div class="referral-label">COMMISSION RATE</div>
          <div class="referral-value"><?= e(referral_percent_label()) ?></div>
          <div class="referral-sub">Per Completed Order</div>
        </div>
        <div class="referral-stat">
          <div class="referral-label">TOTAL REFERRALS</div>
          <div class="referral-value" id="referralTotalCount">0</div>
          <div class="referral-sub"><span id="referralActiveCount">0</span> Active Users</div>
        </div>
      </div>
      <div class="referral-rewards">
        <div class="referral-label">TOTAL EARNED</div>
        <div class="referral-value" id="referralClaimable">₹0.00</div>
        <div class="referral-sub">Lifetime commission</div>
      </div>
    </section>

    <section class="referral-card referral-invite">
      <h2 class="referral-heading"><span>🎁</span><span>INVITE FRIENDS</span></h2>
      <p class="referral-invite-copy">
        Earn <?= e(referral_percent_label()) ?> commission — when someone joins from your link and makes a purchase, you earn.
      </p>
      <div class="referral-link-box" id="referralLinkBox">
        <div class="referral-link-label">YOUR REFERRAL LINK (TAP TO COPY)</div>
        <div class="referral-code-tag" id="referralCodeValue">—</div>
        <div class="referral-link" id="referralLinkValue">Loading…</div>
      </div>
      <button type="button" class="referral-copy-btn" id="referralCopyButton">📋 Copy Link</button>
    </section>

    <section class="referral-card referral-network">
      <h2 class="referral-heading"><span>👥</span><span id="referralNetworkTitle">MY NETWORK (0)</span></h2>
      <div class="referral-network-empty" id="referralNetworkBox">
        Abhi tak koi bhi aapke link se register nahi hua.
      </div>
    </section>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>