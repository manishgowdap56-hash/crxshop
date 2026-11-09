<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

if (get_setting('maintenance_mode', '0') === '1' && !is_admin()) {
    require __DIR__ . '/maintenance.php';
    exit;
}

if (is_logged_in()) {
    redirect(is_admin() ? 'admin.php' : 'dashboard.php');
}

$logo = panel_logo_url();
$name = panel_name();
$ref  = trim((string)($_GET['ref'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Create Account — <?= e(strtoupper($name)) ?></title>
<?php try { require_once __DIR__ . '/includes/share_meta.php'; echo share_meta_tags(); } catch (Throwable $e) { error_log('[share-meta] ' . $e->getMessage()); } ?>
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="base-url" content="<?= e(base_url()) ?>">
<link href="https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@500;600;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('css/base.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/auth.css')) ?>">
<?php if (function_exists('theme_head')) { echo theme_head('user'); } /* v67 Master Frontend Control: saved theme injected last, inside <head> -> first paint, no flash */ ?>
</head>
<body class="auth-body">


<section id="registerScreen" class="auth-screen active">
  <div class="auth-page">
    <div class="auth-wrap">
      <div class="auth-badge">
        <div class="auth-ring"></div>
        <img id="authLogoRegister" data-global-logo src="<?= e($logo) ?>" alt="<?= e($name) ?>">
      </div>
      <h1 class="auth-brand">
        <?php $p = preg_split('/\s+/', $name, 2); ?>
        <?= e(strtoupper($p[0] ?? 'KEYS')) ?> <span><?= e(strtoupper($p[1] ?? 'PANEL')) ?></span>
      </h1>
      <p class="auth-subtitle">🔐 Create your account to continue</p>

      <div class="auth-card register-card">
        <div class="auth-card-title">📝&nbsp; Create Your Account</div>

        <form id="registerForm" autocomplete="off" novalidate>
          <input type="hidden" name="ref" value="<?= e($ref) ?>">

          <div class="auth-field">
            <label for="registerUsername">Choose a Username</label>
            <div class="auth-input-wrap">
              <input id="registerUsername" name="username" class="auth-input" type="text"
                     placeholder="e.g. rahul_123" autocomplete="username">
            </div>
          </div>
          <div class="auth-field">
            <label for="registerPassword">Choose a Password</label>
            <div class="auth-input-wrap">
              <input id="registerPassword" name="password" class="auth-input password" type="password"
                     placeholder="Min 6 characters" autocomplete="new-password">
              <button class="password-toggle" type="button" aria-label="Show password" data-target="registerPassword">
                <svg viewBox="0 0 24 24"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="2"/></svg>
              </button>
            </div>
          </div>
          <div class="auth-field">
            <label for="registerConfirm">Confirm Password</label>
            <div class="auth-input-wrap">
              <input id="registerConfirm" name="confirm" class="auth-input password" type="password"
                     placeholder="Re-type password" autocomplete="new-password">
              <button class="password-toggle" type="button" aria-label="Show password" data-target="registerConfirm">
                <svg viewBox="0 0 24 24"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="2"/></svg>
              </button>
            </div>
          </div>
          <?php if (access_key_required()): ?>
          <div class="auth-field">
            <label for="registerAccessKey">Access Key</label>
            <div class="auth-input-wrap">
              <input id="registerAccessKey" name="access_key" class="auth-input" type="text"
                     placeholder="Enter your Access Key" autocomplete="off" autocapitalize="characters"
                     autocorrect="off" spellcheck="false" maxlength="40">
            </div>
          </div>
          <?php endif; ?>
          <button class="auth-submit" type="submit">📝&nbsp; Create Account&nbsp; →</button>
          <div class="auth-form-message" id="registerMessage" aria-live="polite"></div>
        </form>

        <p class="auth-switch">Already have an account? <a href="<?= e(base_url('login.php')) ?>">Log in</a></p>
      </div>
    </div>
  </div>
</section>

<script src="<?= e(asset('js/app.js')) ?>"></script>
<script src="<?= e(asset('js/auth.js')) ?>?v=<?= e((string)(is_file(__DIR__ . '/assets/js/auth.js') ? filemtime(__DIR__ . '/assets/js/auth.js') : 1)) ?>"></script>
<script src="<?= e(asset('js/page-entry.js')) ?>?v=<?= e((string)(is_file(__DIR__ . '/assets/js/page-entry.js') ? filemtime(__DIR__ . '/assets/js/page-entry.js') : 1)) ?>"></script>
</body>
</html>