<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

// Determine the requested login mode BEFORE the global maintenance gate.
// This keeps the ADMIN ACCESS link on the maintenance screen functional.
$isAdminLogin = isset($_GET['admin']) && $_GET['admin'] === '1';
$isUserLogin = isset($_GET['user']) && $_GET['user'] === '1';

if (get_setting('maintenance_mode', '0') === '1' && !$isAdminLogin && !is_admin()) {
    require __DIR__ . '/maintenance.php';
    exit;
}

// If already logged in, bounce.
if (is_logged_in() && !$isUserLogin) {
    redirect(is_admin() ? 'admin.php' : 'dashboard.php');
}

$logo = panel_logo_url();
$name = panel_name();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $isAdminLogin ? 'Admin Sign In' : 'Sign In' ?> — <?= e(strtoupper($name)) ?></title>
<?php try { require_once __DIR__ . '/includes/share_meta.php'; echo share_meta_tags(); } catch (Throwable $e) { error_log('[share-meta] ' . $e->getMessage()); } ?>
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="base-url" content="<?= e(base_url()) ?>">
<link href="https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@500;600;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('css/base.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/auth.css')) ?>">
<?php if (function_exists('theme_head')) { echo theme_head(!empty($isAdminLogin) ? 'admin' : 'user'); } /* v67 Master Frontend Control: saved theme injected last, inside <head> -> first paint, no flash */ ?>
</head>
<body class="auth-body" data-admin-login="<?= $isAdminLogin ? '1' : '0' ?>" data-user-login="<?= $isUserLogin ? '1' : '0' ?>">

<section id="loginScreen" class="auth-screen active">
  <div class="auth-page">
    <div class="auth-wrap">
      <div class="auth-badge">
        <div class="auth-ring"></div>
        <img id="authLogoLogin" data-global-logo src="<?= e($logo) ?>" alt="<?= e($name) ?>">
      </div>
      <h1 class="auth-brand">
        <?php $p = preg_split('/\s+/', $name, 2); ?>
        <?= e(strtoupper($p[0] ?? 'KEYS')) ?> <span><?= e(strtoupper($p[1] ?? 'PANEL')) ?></span>
      </h1>
      <p class="auth-subtitle"><?= $isAdminLogin ? '🛡️ Admin sign in to continue' : '🔐 Sign in to continue' ?></p>

      <div class="auth-card">
        <div class="auth-card-title"><?= $isAdminLogin ? '🛡️' : '🔐' ?>&nbsp; <?= $isAdminLogin ? 'Admin Sign In' : 'Sign In' ?></div>

        <form id="loginForm" autocomplete="off" novalidate>
          <div class="auth-field">
            <label for="loginUsername">Username</label>
            <div class="auth-input-wrap">
              <input id="loginUsername" name="username" class="auth-input" type="text"
                     placeholder="Your username" autocomplete="username">
            </div>
          </div>
          <div class="auth-field">
            <label for="loginPassword">Password</label>
            <div class="auth-input-wrap">
              <input id="loginPassword" name="password" class="auth-input password" type="password"
                     placeholder="Your password" autocomplete="current-password">
              <button class="password-toggle" type="button" aria-label="Show password" data-target="loginPassword">
                <svg viewBox="0 0 24 24"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="2"/></svg>
              </button>
            </div>
          </div>
          <button class="auth-submit" type="submit">🔐&nbsp; Log In&nbsp; →</button>
          <div class="auth-form-message" id="loginMessage" aria-live="polite"></div>
        </form>

        <p class="auth-switch"><?= $isAdminLogin ? 'Not an admin? <a href="' . e(base_url('login.php')) . '">User login</a>' : 'New here? <a href="' . e(base_url('register.php')) . '">Create an account</a>' ?></p>
      </div>
    </div>
  </div>
</section>

<script src="<?= e(asset('js/app.js')) ?>"></script>
<script src="<?= e(asset('js/auth.js')) ?>"></script>
<?php if (!$isAdminLogin): /* Admin login (reached via the maintenance "ADMIN ACCESS" link) bypasses the welcome/splash redirect. User login keeps it. */ ?>
<script src="<?= e(asset('js/page-entry.js')) ?>?v=<?= e((string)(is_file(__DIR__ . '/assets/js/page-entry.js') ? filemtime(__DIR__ . '/assets/js/page-entry.js') : 1)) ?>"></script>
<?php endif; ?>
</body>
</html>
