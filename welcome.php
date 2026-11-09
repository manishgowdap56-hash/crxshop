<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

// Admin login route is exempt from the splash and must not hit the maintenance gate below.
// (login.php?admin=1 only shows a sign-in form; authentication is still fully enforced by api/auth-login.php.)
$__ret = (string)($_GET['return'] ?? '');
if ($__ret !== '' && $__ret[0] === '/' && strpos($__ret, '//') !== 0 && strpos($__ret, '\\') === false) {
    $__parts = parse_url($__ret);
    $__q = [];
    parse_str((string)($__parts['query'] ?? ''), $__q);
    if (is_string($__parts['path'] ?? null) && basename($__parts['path']) === 'login.php' && ($__q['admin'] ?? '') === '1') {
        redirect('login.php?admin=1');
    }
}

if (get_setting('maintenance_mode', '0') === '1' && !is_admin()) {
    require __DIR__ . '/maintenance.php';
    exit;
}

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
<title>Welcome — <?= e(strtoupper($name)) ?></title>
<?php try { require_once __DIR__ . '/includes/share_meta.php'; echo share_meta_tags(); } catch (Throwable $e) { error_log('[share-meta] ' . $e->getMessage()); } ?>
<meta name="base-url" content="<?= e(base_url()) ?>">
<link href="https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@500;600;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('css/base.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/auth.css')) ?>">
<?php if (function_exists('theme_head')) { echo theme_head('user'); } /* v67 Master Frontend Control: saved theme injected last, inside <head> -> first paint, no flash */ ?>
</head>
<body class="welcome-body">
<section id="loadingScreen" class="welcome-body">
  <div class="welcome-grid"></div>
  <div class="welcome-wrap">
    <div class="welcome-badge">
      <div class="welcome-ring"></div>
      <img id="authLogoLoading" data-global-logo src="<?= e($logo) ?>" alt="<?= e($name) ?>">
    </div>
    <div class="welcome-eyebrow">🔐 Secure Access Point</div>
    <h1 class="welcome-title">Welcome To<br><span><?= e($first . ' ' . $rest) ?></span></h1>
    <div class="welcome-tagline">Setting things up, taking you to your page…</div>
    <div class="welcome-loader"><div class="welcome-loader-bar"></div></div>
    <div class="welcome-note">Redirecting automatically</div>
  </div>
</section>
<script>
(function () {
    'use strict';
    const base = document.querySelector('meta[name="base-url"]').content.replace(/\/$/, '');
    const qs = new URLSearchParams(location.search);
    const rawReturn = qs.get('return') || '';

    // Only allow local PHP routes; never turn welcome.php into an open redirect.
    function safeReturn(value) {
        if (!value || value.charAt(0) !== '/' || value.indexOf('\\') !== -1 || value.indexOf('//') === 0) return '';
        const basePath = new URL(base + '/').pathname.replace(/\/$/, '');
        if (basePath && value.indexOf(basePath + '/') !== 0 && value !== basePath) return '';
        return value;
    }

    const target = safeReturn(rawReturn);
    try { sessionStorage.setItem('kpWelcomeSeen', '1'); } catch (e) {}

    setTimeout(async function () {
        try {
            const r = await fetch(base + '/api/me.php', { credentials: 'same-origin' });
            const data = await r.json();
            if (data && data.ok && data.user) {
                if (target && !/\/login\.php(?:$|[?])/i.test(target) && !/\/register\.php(?:$|[?])/i.test(target)) {
                    location.replace(target);
                    return;
                }
                location.replace(base + '/' + (data.user.role === 'admin' ? 'admin.php' : 'dashboard.php'));
                return;
            }
        } catch (e) {}
        location.replace(target || (base + '/login.php'));
    }, 2900);
})();
</script>
</body>
</html>
