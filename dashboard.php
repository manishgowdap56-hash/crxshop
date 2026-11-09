<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/maintenance_gate.php';
$user = require_user();
header('Cache-Control: no-store, no-cache, must-revalidate');   // v62: role / wallet state must never come from a browser cache

$page_css   = ['store.css', 'mykeys.css', 'deposit.css', 'api.css', 'referral.css', 'profile.css', 'dashboard-display.css', 'reseller-membership.css'];
$page_js    = ['dashboard.js', 'dashboard-display.js', 'reseller-membership.js'];
$active_nav = 'home';
$page_heading = null;

// Monthly challenge countdown (kept as original: "N days left this month")
$daysLeft = (int)(new DateTimeImmutable('first day of next month'))->diff(new DateTimeImmutable('today'))->days;

// Leaderboard: top 5 resellers by LIFETIME spending (completed orders only, no daily/monthly reset)
$leaderboard = [];
try {
    $leaderboardStmt = db()->prepare(
        'SELECT u.username,
                COUNT(o.id) AS key_count,
                COALESCE(SUM(o.total_price),0) AS revenue
         FROM users u
         LEFT JOIN orders o ON o.user_id = u.id
            AND o.status = "completed"
         WHERE u.role = "user"
         GROUP BY u.id, u.username
         HAVING COUNT(o.id) > 0
         ORDER BY revenue DESC, key_count DESC, u.id ASC
         LIMIT 5'
    );
    $leaderboardStmt->execute();
    $leaderboard = $leaderboardStmt->fetchAll();
} catch (Throwable $e) {
    // A legacy database may still be missing orders.user_id until the additive
    // compatibility migration succeeds. Keep the dashboard usable in that case.
    error_log('[dashboard leaderboard] ' . $e->getMessage());
}

$membership = dual_membership_status_for_user(db(), (int)$user['id']);   // v61: server-side status; the purchase itself is verified server-side too
$displaySlides = display_slides();
$tgUser = telegram_support_username();

require __DIR__ . '/includes/header.php';
?>

<?php if ($displaySlides): ?>
<section class="dash-display" id="dashDisplay" aria-label="Dashboard display">
  <span class="dd-gem top"></span><span class="dd-gem bottom"></span><span class="dd-gem left"></span><span class="dd-gem right"></span>
  <div class="dd-viewport">
    <?php foreach ($displaySlides as $i => $sl): ?>
    <img class="dd-slide<?= $i === 0 ? ' active' : '' ?>" src="<?= e($sl['url']) ?>" alt="" decoding="async" draggable="false">
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<section class="update-card">
  <div class="update-title">🔔 Update File</div>
  <button id="dashboardJoinButton" class="join-btn" type="button"
          data-join-link="<?= e((string)get_setting('join_link', '')) ?>">JOIN NOW</button>
</section>

<section class="stats-grid">
  <div class="stat-card">
    <div class="stat-icon">💰</div>
    <div class="stat-label">WALLET BALANCE</div>
    <div class="stat-value gold" id="walletBalance"><?= e(money($user['balance'])) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon">🔑</div>
    <div class="stat-label">ORDERS TODAY</div>
    <div class="stat-value" id="ordersToday">0</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon">🧾</div>
    <div class="stat-label">TOTAL SPENT</div>
    <div class="stat-value" id="totalSpent">₹0.00</div>
  </div>
  <div class="stat-card green">
    <div class="stat-icon">📅</div>
    <div class="stat-label">TOTAL ORDERS</div>
    <div class="stat-value green-text" id="ordersMonth">0</div>
  </div>
</section>

<?php
// v64: the Membership purchase card exists ONLY for a Normal User (users.account_type read from the database by dual_membership_status_for_user) while the
// admin has Membership switched ON. For a Reseller NOTHING of it is rendered (no card, no heading, no button, no message, no empty or hidden container).
$mcfg = $membership['config']; $isRes = $membership['is_reseller'];
if (!$isRes && $mcfg['enabled']):
?>
<section class="reseller-card" id="resellerCard" data-csrf="<?= e(csrf_token()) ?>" data-deposit="<?= e(base_url('deposit.php')) ?>">
  <div class="rs-head">
    <div class="rs-title">⭐ BECOME A RESELLER</div>
    <div class="rs-badge" id="rsBadge"><?= e(strtoupper($membership['role_label'])) ?></div>
  </div>
  <div class="rs-grid">
    <div class="rs-cell"><span>MEMBERSHIP PRICE</span><b><?= e(money($mcfg['price'])) ?></b></div>
    <div class="rs-cell"><span>YOUR WALLET</span><b id="rsBalance"><?= e(money($membership['balance'])) ?></b></div>
  </div>
  <?php if ($mcfg['description'] !== ''): ?><div class="rs-desc"><?= nl2br(e($mcfg['description'])) ?></div><?php endif; ?>
  <?php if ($mcfg['purchasable']): ?>
    <div class="rs-msg warn" id="rsNeed" <?= $membership['sufficient'] ? 'hidden' : '' ?>>Insufficient balance. You need <b id="rsNeedAmt"><?= e(money($membership['needed'])) ?></b> more.</div>
    <div class="rs-actions">
      <button class="rs-btn" id="rsBuy" type="button" <?= $membership['sufficient'] ? '' : 'hidden' ?>>BECOME A RESELLER — <?= e(money($mcfg['price'])) ?></button>
      <a class="rs-btn ghost" id="rsFunds" href="<?= e(base_url('deposit.php')) ?>" <?= $membership['sufficient'] ? 'hidden' : '' ?>>➕ ADD FUNDS</a>
    </div>
    <div class="rs-note">The price is deducted from your website wallet; the rest of your balance stays in the same wallet.</div>
  <?php else: ?>
    <div class="rs-msg">Reseller membership is not available right now.</div>
  <?php endif; ?>
  <div class="rs-msg" id="rsMsg" hidden></div>
</section>
<?php endif; ?>

<section class="challenge">
  <div class="challenge-head">
    <div class="challenge-title">🎁 <?= e(date('F Y')) ?> Challenge</div>
    <div class="live-pill">⚡ LIVE</div>
  </div>
  <div class="challenge-sub">⏰ <b><?= (int)$daysLeft ?> day<?= $daysLeft === 1 ? '' : 's' ?></b> left this month — top 3 resellers win coins</div>
  <div class="prizes">
    <div class="prize first"><div class="prize-medal">🥇</div><div class="prize-rank">1ST PLACE</div><div class="prize-coins gold">🪙 300</div></div>
    <div class="prize"><div class="prize-medal">🥈</div><div class="prize-rank">2ND PLACE</div><div class="prize-coins">🪙 200</div></div>
    <div class="prize"><div class="prize-medal">🥉</div><div class="prize-rank">3RD PLACE</div><div class="prize-coins">🪙 100</div></div>
  </div>
</section>

<section class="leaderboard">
  <div class="lb-title">🏅 &nbsp;TOP 5 RESELLERS</div>
  <?php if (!$leaderboard): ?>
    <div class="lb-row"><div class="lb-rank">#1</div><div><div class="lb-name">No sales yet</div><div class="lb-keys">0 Keys</div></div><div class="lb-money">₹0.00</div></div>
  <?php else: $rank=1; foreach ($leaderboard as $row): ?>
    <div class="lb-row">
      <div class="lb-rank">#<?= $rank++ ?></div>
      <div>
        <div class="lb-name"><?= e($row['username']) ?></div>
        <div class="lb-keys"><?= (int)$row['key_count'] ?> Keys</div>
      </div>
      <div class="lb-money"><?= e(money($row['revenue'])) ?></div>
    </div>
  <?php endforeach; endif; ?>
</section>

<?php if ($tgUser !== ''): ?>
<div class="tg-support-spacer" aria-hidden="true"></div>
<a class="tg-support-fab" id="tgSupportFab" href="https://t.me/<?= e($tgUser) ?>" target="_blank" rel="noopener noreferrer" aria-label="Telegram support">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21.5 3.2 2.9 10.4c-.9.35-.88 1.6.03 1.9l4.7 1.5 1.8 5.6c.25.8 1.25 1 1.8.38l2.6-2.9 4.8 3.5c.7.5 1.7.1 1.9-.75L22.9 4.5c.2-1-.7-1.7-1.4-1.3Z"/><path d="m8 13.2 8.6-6.4"/></svg>
  <span>SUPPORT</span>
</a>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>