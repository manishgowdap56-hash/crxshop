<?php
declare(strict_types=1);
/**
 * Whole-site maintenance gate.
 * - Admin (role=admin) always bypasses.
 * - The maintenance overlay file must exist at assets/maintenance.html.php.
 */
if (maintenance_is_on() && !is_admin()) {
    // Render the standalone maintenance screen and stop.
    require __DIR__ . '/../maintenance.php';
    exit;
}