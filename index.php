<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

// The welcome/loading screen is a real standalone page.
// Global maintenance still takes priority for normal users.
if (get_setting('maintenance_mode', '0') === '1' && !is_admin()) {
    require __DIR__ . '/maintenance.php';
    exit;
}

redirect('welcome.php');
