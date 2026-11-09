<?php
declare(strict_types=1);
/**
 * ZapUPI reconciliation (CLI only, never reachable over HTTP). Safety net for delayed / missed webhooks.
 *   0-59/5 * * * *  /PATH/TO/php /home/ACCOUNT/public_html/cron/zapupi_reconcile.php >> /home/ACCOUNT/zapupi_reconcile.log 2>&1
 * Checks eligible pending ZapUPI deposits (older than 60 s, not checked in the last 5 min, fewer than 48 cron checks,
 * younger than 48 h) through the SAME verification + atomic credit function the webhook uses. It never credits
 * without ZapUPI confirming success, and it never marks a pending order failed unless ZapUPI says Failed.
 * Overlap is impossible (named MySQL lock).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/bootstrap.php';

$pdo = db();
$lock = $pdo->query("SELECT GET_LOCK('zapupi_reconcile', 0)")->fetchColumn();
if ((int)$lock !== 1) { echo '[' . date('Y-m-d H:i:s') . "] SKIPPED (another zapupi_reconcile process is running)\n"; exit(0); }

$exit = 0;
try {
    $s = zapupi_reconcile_pending($pdo, 25);
    printf("[%s] checked=%d success=%d failed=%d still_pending=%d\n", date('Y-m-d H:i:s'), $s['checked'], $s['success'], $s['failed'], $s['pending']);
} catch (Throwable $e) {
    $exit = 1;
    error_log('[cron/zapupi_reconcile] ' . $e->getMessage());
    fwrite(STDERR, 'ZapUPI reconcile failed: ' . $e->getMessage() . "\n");
} finally {
    $pdo->query("SELECT RELEASE_LOCK('zapupi_reconcile')");
}
exit($exit);
