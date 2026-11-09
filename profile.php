<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/maintenance_gate.php';
$user = require_user();
header('Cache-Control: no-store, no-cache, must-revalidate');   // v62: role / wallet state must never come from a browser cache

$page_css   = ['profile.css'];
$page_js    = ['profile.js'];
$active_nav = 'profile';

$__isRes = dual_user_type(db(), (int)$user['id']) === DUAL_RESELLER;   // v62: role straight from the database on every load
$initial = strtoupper(substr((string)$user['username'], 0, 1));

require __DIR__ . '/includes/header.php';
?>

<section class="profile-page active">
  <div class="profile-wrap">
    <section class="profile-hero-card">
      <div class="profile-avatar" id="profileAvatar"><?= e($initial) ?></div>
      <div class="profile-username-row">
        <h1 class="profile-username" id="profileUsername"><?= e($user['username']) ?></h1>
        <button type="button" class="vip-copy-btn" data-copy-username="<?= e($user['username']) ?>" aria-label="Copy username" title="Copy username">
          <span class="vip-copy-ico" aria-hidden="true">📋</span><span class="vip-copy-label" aria-live="polite">Copy</span>
        </button>
      </div>
      <button type="button" class="profile-sound-toggle" id="profileSoundToggle" aria-pressed="true" aria-label="Turn tap sound off">
        <span class="profile-sound-icon">🔊</span>
        <span class="profile-sound-state">ON</span>
      </button>
      <div class="profile-pills">
        <div class="profile-pill reseller" id="profileRole"><span><?= $__isRes ? '🤝' : '👤' ?></span><span><?= $__isRes ? 'Reseller' : 'Normal User' ?></span></div>
        <div class="profile-pill balance"><span>💰</span><span id="profileHeroBalance"><?= e(money($user['balance'])) ?></span></div>
      </div>
      <div class="profile-since" id="profileSince">
        <span>📅</span><span>Since <?= e(date('d M Y', strtotime((string)$user['created_at']))) ?></span>
      </div>
    </section>

    <section class="profile-password-card">
      <div class="profile-password-heading"><span>🔑</span><span>CHANGE PASSWORD</span></div>

      <label class="profile-field-label" for="profileOldPassword">OLD PASSWORD</label>
      <div class="profile-password-input-wrap">
        <input id="profileOldPassword" type="password" autocomplete="current-password" placeholder="Your current password">
        <button type="button" class="profile-eye" data-target="profileOldPassword" aria-label="Show password">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.2 12s3.4-6 9.8-6 9.8 6 9.8 6-3.4 6-9.8 6-9.8-6-9.8-6Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><circle cx="12" cy="12" r="2.8" fill="none" stroke="currentColor" stroke-width="2"/></svg>
        </button>
      </div>

      <label class="profile-field-label" for="profileNewPassword">NEW PASSWORD</label>
      <div class="profile-password-input-wrap">
        <input id="profileNewPassword" type="password" autocomplete="off" placeholder="At least <?= (int)PASSWORD_MIN ?> characters">
        <button type="button" class="profile-eye" data-target="profileNewPassword" aria-label="Show password">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.2 12s3.4-6 9.8-6 9.8 6 9.8 6-3.4 6-9.8 6-9.8-6-9.8-6Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><circle cx="12" cy="12" r="2.8" fill="none" stroke="currentColor" stroke-width="2"/></svg>
        </button>
      </div>

      <label class="profile-field-label" for="profileConfirmPassword">CONFIRM NEW PASSWORD</label>
      <div class="profile-password-input-wrap">
        <input id="profileConfirmPassword" type="password" autocomplete="off" placeholder="Re-type new password">
        <button type="button" class="profile-eye" data-target="profileConfirmPassword" aria-label="Show password">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.2 12s3.4-6 9.8-6 9.8 6 9.8 6-3.4 6-9.8 6-9.8-6-9.8-6Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><circle cx="12" cy="12" r="2.8" fill="none" stroke="currentColor" stroke-width="2"/></svg>
        </button>
      </div>

      <button type="button" class="profile-change-btn" id="profileChangePassword">
        <span>🔒</span><span>Change Password</span><span class="profile-arrow">→</span>
      </button>
      <div class="profile-password-message" id="profilePasswordMessage" aria-live="polite"></div>
    </section>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>