<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
ensure_configured_admin();
$adminUser = require_admin();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e(panel_name()) ?> — Monitor</title>
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="base-url" content="<?= e(base_url()) ?>">
<link rel="stylesheet" href="<?= e(asset('css/base.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/monitor.css')) ?>">
<?php if (function_exists('theme_head')) { echo theme_head('admin'); } /* v67 Master Frontend Control: saved theme injected last, inside <head> -> first paint, no flash */ ?>
</head>
<body data-page="admin-monitor">
<main class="mon">
  <header class="mon-top"><a href="<?= e(base_url('admin.php')) ?>">&larr; Admin</a><h1>Monitor</h1><button id="monRefresh" type="button">Refresh</button></header>
  <nav class="mon-tabs">
    <button data-tab="orders" class="on" type="button">API Order Monitor</button>
    <button data-tab="review" type="button">Order Review</button>
    <button data-tab="refunds" type="button">Refunds</button>
    <button data-tab="workers" type="button">Workers / Cron</button>
    <button data-tab="alerts" type="button">Price Review</button>
    <button data-tab="durations" type="button">Duration Monitor</button>
    <button data-tab="history" type="button">Price History</button>
    <button data-tab="settings" type="button">Settings</button>
  </nav>
  <div id="monScheduler" class="mon-sched"></div>
  <section id="tab-orders"></section>
  <section id="tab-review" hidden></section>
  <section id="tab-refunds" hidden></section>
  <section id="tab-workers" hidden></section>
  <section id="tab-alerts" hidden></section>
  <section id="tab-durations" hidden></section>
  <section id="tab-history" hidden></section>
  <section id="tab-settings" hidden></section>
</main>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<script src="<?= e(asset('js/admin-monitor.js')) ?>"></script>
</body>
</html>
