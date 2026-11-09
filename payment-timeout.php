<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/maintenance_gate.php';
// ZapUPI "timeout" landing page. The URL proves nothing; the shared template verifies server-side and shows the database state.
$zp_kind = 'timeout';
require __DIR__ . '/includes/zapupi_result.php';
