<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
$logo = panel_logo_url();
$name = panel_name();
$parts = preg_split('/\s+/', $name, 2);
$first = strtoupper($parts[0] ?? 'KEYS');
$rest  = strtoupper($parts[1] ?? 'PANEL');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Maintenance — <?= e(strtoupper($name)) ?></title>
<?php try { require_once __DIR__ . '/includes/share_meta.php'; echo share_meta_tags(); } catch (Throwable $e) { error_log('[share-meta] ' . $e->getMessage()); } ?>
<link href="https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@500;600;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('css/base.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/maintenance.css')) ?>">
<?php if (function_exists('theme_head')) { echo theme_head('user'); } /* v67 Master Frontend Control: saved theme injected last, inside <head> -> first paint, no flash */ ?>
</head>
<body class="auth-body">

<section id="siteMaintenanceScreen" class="active">
  <div class="site-maintenance-bg"></div>
  <div class="site-maintenance-grid"></div>
  <span class="site-maintenance-corner tl"></span>
  <span class="site-maintenance-corner tr"></span>
  <span class="site-maintenance-corner bl"></span>
  <span class="site-maintenance-corner br"></span>
  <div class="site-maintenance-card">
    <div class="site-maintenance-logo-wrap">
      <span class="site-maintenance-logo-ring"></span>
      <img class="site-maintenance-logo" data-global-logo src="<?= e($logo) ?>" alt="<?= e($name) ?>">
    </div>
    <div class="site-maintenance-kicker">WELCOME TO</div>
    <h1 class="site-maintenance-name"><?= e($first) ?> <span><?= e($rest) ?></span></h1>
    <div class="site-maintenance-title">WEBSITE MAINTENANCE MODE</div>
    <p class="site-maintenance-text">We are currently performing scheduled maintenance and improvements. The website will be available again shortly.</p>
    <div class="site-maintenance-status"><i></i> TEMPORARILY UNAVAILABLE</div>
    <a href="<?= e(base_url('login.php?admin=1')) ?>" style="display:inline-block;margin-top:20px;color:#d8b765;font-size:11px;font-weight:800;letter-spacing:1px;text-decoration:none;opacity:.75">ADMIN ACCESS →</a>
  </div>
</section>

</body>
</html>