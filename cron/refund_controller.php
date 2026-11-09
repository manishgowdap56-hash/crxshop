<?php
declare(strict_types=1);
/**
 * Refund Controller - scheduled job (CLI only, never reachable over HTTP).
 *
 * cPanel -> Cron Jobs -> every minute (recommended):
 *     * * * * *  /usr/local/bin/php /home/ACCOUNT/public_html/cron/refund_controller.php >/dev/null 2>&1
 * (use the PHP binary path shown by your host; add a log file instead of /dev/null while testing)
 *
 * Each run: finds orders still 'pending' after the processing window, verifies them with the rules in
 * includes/refund_controller.php, refunds the ones that are provably failed (exactly once) and sends the rest to
 * admin review. Overlapping runs are impossible (named DB lock) and a repeated run never refunds twice.
 * It keeps working while Maintenance Mode is ON.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../includes/bootstrap.php';

$started = microtime(true);
try {
    // v59: first finish orders whose keys were parked (provider delivered, database save failed) so the refund rules below can never touch them.
    $rec = ox_recover_stranded(db());
    if ($rec['completed'] || $rec['referral'] || $rec['failed']) printf("[%s] recovery: completed=%d referral=%d failed=%d\n", date('Y-m-d H:i:s'), $rec['completed'], $rec['referral'], $rec['failed']);
    $s = rc_run(db(), 'cron');
    // v60: REAL heartbeat for Admin -> Monitor
    worker_record(db(), WORKER_REFUND, true, $s, '', (int)round((microtime(true) - $started) * 1000), !empty($s['skipped']));
} catch (Throwable $e) {
    try { worker_record(db(), WORKER_REFUND, false, [], $e->getMessage(), (int)round((microtime(true) - $started) * 1000)); } catch (Throwable $e2) {}
    error_log('[cron/refund_controller] ' . $e->getMessage());
    fwrite(STDERR, "Refund controller failed: " . $e->getMessage() . "\n");
    exit(1);
}
printf(
    "[%s] checked=%d refunded=%d review=%d blocked=%d retry=%d refund_failed=%d%s (%.2fs)\n",
    date('Y-m-d H:i:s'), $s['checked'], $s['refunded'], $s['review'], $s['blocked'], $s['retry'], $s['refund_failed'],
    $s['skipped'] ? ' SKIPPED(another run in progress)' : '', microtime(true) - $started
);
exit(0);
