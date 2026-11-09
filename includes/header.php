<?php
declare(strict_types=1);

$__user = current_user();
$__active = $active_nav ?? 'home';
$__name = panel_name();
$__logo = panel_logo_url();
$__balance = $__user ? (float)$__user['balance'] : 0.0;
$__username = (string)($__user['username'] ?? 'guest');
$__role = !$__user ? 'USER' : (($__user['role'] ?? '') === 'admin' ? 'ADMIN' : strtoupper(dual_role_label(db(), (int)$__user['id'])));   // v62: real role from the database
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($__name) ?> — <?= e(ucfirst($__active)) ?></title>
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="base-url" content="<?= e(base_url()) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@500;600;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600;700&display=swap" rel="stylesheet">
<?php if (($__active ?? '') === 'store'): ?>
<link href="https://fonts.googleapis.com/css2?family=Roboto+Slab:wght@500;700;800&display=swap" rel="stylesheet">
<?php endif; ?>
<link rel="stylesheet" href="<?= e(asset('css/base.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/dashboard.css')) ?>">
<?php foreach (($page_css ?? []) as $css): ?>
<?php $cssFile = __DIR__ . '/../assets/css/' . ltrim($css, '/'); $cssVer = is_file($cssFile) ? (string)filemtime($cssFile) : '1'; ?>
<link rel="stylesheet" href="<?= e(asset('css/' . $css)) ?>?v=<?= e($cssVer) ?>">
<?php endforeach; ?>
<!-- Reference UI: this stylesheet is the supplied index.html design reference. -->
<link rel="stylesheet" href="<?= e(asset('css/reference.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/page-fix.css')) ?>">
<?php $clockCssFile = __DIR__ . '/../assets/css/header-clock.css'; ?>
<link rel="stylesheet" href="<?= e(asset('css/header-clock.css')) ?>?v=<?= e(is_file($clockCssFile) ? (string)filemtime($clockCssFile) : '1') ?>">
<link rel="icon" href="<?= e($__logo) ?>">
<?php if (function_exists('theme_head')) { echo theme_head('user'); } /* v67 Master Frontend Control: saved theme injected last, inside <head> -> first paint, no flash */ ?>
</head>
<body class="auth-body" data-page="<?= e($__active) ?>">

<div class="dash-shell">
<header class="dash-header">
  <img id="dashLogo" data-global-logo class="dash-logo" src="<?= e($__logo) ?>" alt="<?= e($__name) ?>">
  <div class="dash-brand">
    <h1 class="dash-brand-name">
      <?php $parts = preg_split('/\s+/', $__name, 2); ?>
      <?= e(strtoupper($parts[0] ?? 'KEYS')) ?> <span><?= e(strtoupper($parts[1] ?? 'PANEL')) ?></span>
    </h1>
    <div class="dash-user"><span class="person">♟</span> <?= e($__username) ?></div>
    <div class="dash-clock" id="dashClock" data-tz="<?= e(APP_TIMEZONE) ?>" data-server-ms="<?= e((string)(int)round(microtime(true) * 1000)) ?>" aria-label="Current date and time"><span class="dash-clock-time"></span><span class="dash-clock-date"></span></div>
  </div>
  <div class="dash-balance" id="headerBalance">💰 <?= e(money($__balance)) ?></div>
  <button class="dash-menu" id="dashMenu" type="button" aria-label="Menu" aria-controls="dashDrawer" aria-expanded="false"><span></span></button>
</header>

<aside class="dash-drawer" id="dashDrawer" aria-hidden="true">
  <div class="drawer-head">
    <div class="drawer-avatar" id="drawerAvatar"><?= e(strtoupper(substr($__username, 0, 1) ?: 'R')) ?></div>
    <div class="drawer-user">
      <div class="drawer-username" id="drawerUsername"><?= e($__username) ?></div>
      <div class="drawer-role">🤝 <?= e($__role) ?></div>
      <div class="drawer-wallet" id="drawerWallet">💰 <?= e(money($__balance)) ?></div>
    </div>
    <button class="drawer-close" id="drawerClose" type="button" aria-label="Close menu">×</button>
  </div>
  <div class="drawer-body">
    <div class="drawer-section-title">MENU</div>
    <a class="drawer-item <?= $__active === 'home' ? 'active' : '' ?>" href="<?= e(base_url('dashboard.php')) ?>"><span class="drawer-icon">⌂</span><span>Home</span></a>
    <a class="drawer-item <?= $__active === 'store' ? 'active' : '' ?>" href="<?= e(base_url('store.php')) ?>"><span class="drawer-icon">🏪</span><span>Store</span></a>
    <a class="drawer-item <?= $__active === 'deposit' ? 'active' : '' ?>" href="<?= e(base_url('deposit.php')) ?>"><span class="drawer-icon">💰</span><span>Deposit</span></a>
    <a class="drawer-item <?= $__active === 'mykeys' ? 'active' : '' ?>" href="<?= e(base_url('mykeys.php')) ?>"><span class="drawer-icon">🔑</span><span>My Key</span></a>
    <?php if (($__user && dual_can_use_api(db(), (int)$__user['id']))): /* v62: API menu only for Resellers / Admins (the pages enforce it on the server too) */ ?>
    <a class="drawer-item <?= $__active === 'api' ? 'active' : '' ?>" href="<?= e(base_url('api.php')) ?>"><span class="drawer-icon">🔌</span><span>API</span></a>
    <?php endif; ?>
    <a class="drawer-item <?= $__active === 'referral' ? 'active' : '' ?>" href="<?= e(base_url('referral.php')) ?>"><span class="drawer-icon">👥</span><span>Refer &amp; Earn Money</span></a>
    <div class="drawer-section-title" style="margin-top:14px">ACCOUNT</div>
    <a class="drawer-item <?= $__active === 'profile' ? 'active' : '' ?>" href="<?= e(base_url('profile.php')) ?>"><span class="drawer-icon">👤</span><span>Profile</span></a>
    <div class="drawer-divider"></div>
    <a class="drawer-item logout" href="<?= e(base_url('logout.php')) ?>"><span class="drawer-icon">↪</span><span>Logout</span></a>
  </div>
</aside>

<div id="globalToastStack"></div>
<main class="dash-content" id="mainContent">
<?php if (!empty($page_heading)): ?>
  <h2 class="page-heading"><?= e($page_heading) ?></h2>
<?php endif; ?>
