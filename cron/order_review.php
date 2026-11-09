<?php
declare(strict_types=1);
/**
 * Pending Order Review worker (CLI only, never reachable over HTTP). Worker 2 of 3.
 *   * * * * *  /PATH/TO/php /home/ACCOUNT/public_html/cron/order_review.php >> /home/ACCOUNT/order_review.log 2>&1
 * Finds pending / overdue / uncertain orders, recovers parked keys, records every check and sends unresolved orders to Admin -> Monitor -> Order Review.
 * It never refunds, never changes an order to failed and NEVER calls the provider's generate endpoint. Overlap is impossible (named MySQL lock).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/bootstrap.php';

$pdo = db(); $t0 = microtime(true);
if (!worker_lock($pdo, WORKER_ORDER_REVIEW)) {
    worker_record($pdo, WORKER_ORDER_REVIEW, true, [], '', 0, true);
    echo '[' . date('Y-m-d H:i:s') . "] SKIPPED (another order_review process is running)\n";
    exit(0);
}
$exit = 0;
try {
    $s = or_run($pdo);
    $ms = (int)round((microtime(true) - $t0) * 1000);
    worker_record($pdo, WORKER_ORDER_REVIEW, true, $s, '', $ms);
    printf("[%s] checked=%d changed=%d stale_fixed=%d recovered=%d alerts=%d (%d ms) %s\n", date('Y-m-d H:i:s'), $s['checked'], $s['changed'], $s['stale_fixed'], $s['recovered'], $s['alerts'], $ms, json_encode($s['by_state']));
} catch (Throwable $e) {
    $exit = 1;
    error_log('[cron/order_review] ' . $e->getMessage());
    fwrite(STDERR, 'Order review failed: ' . $e->getMessage() . "\n");
    worker_record($pdo, WORKER_ORDER_REVIEW, false, [], $e->getMessage(), (int)round((microtime(true) - $t0) * 1000));
} finally {
    worker_unlock($pdo, WORKER_ORDER_REVIEW);
}
exit($exit);
