<?php
declare(strict_types=1);
/**
 * Provider PRICE sync - scheduled job (CLI only, never reachable over HTTP).   (v55: price verification and correction ONLY)
 *
 * EVERY 10 SECONDS by default. cPanel cron cannot run faster than once a minute, so the job loops inside the minute:
 *     * * * * *  /usr/local/bin/php /home/ACCOUNT/public_html/cron/provider_sync.php --loop >> /home/ACCOUNT/provider_sync.log 2>&1
 *   --loop keeps running for ~55 s and runs one cycle every "interval" seconds (Admin -> Price Sync, default 10, minimum 4).
 *   One cycle = read the provider catalog (all pages) -> compare every mapped variant -> correct wrong prices (provider USD price x the category rate: Reseller = sync_usd_inr_rate, Normal User = normal_usd_inr_rate).
 *   The named MySQL lock makes overlapping processes harmless: a second process just skips.
 *   If the provider answers with errors / 429 the loop backs off (x2, up to 60 s) and returns to the normal interval after a success.
 *
 * ONE RUN (no loop), e.g. every 5 minutes or for testing:
 *     php cron/provider_sync.php            run one cycle if due
 *     php cron/provider_sync.php --force    run one cycle now, ignoring the auto-sync toggle and the interval
 *
 * This job never discovers, imports, restores, creates, renames, disables or deletes a product. It only corrects prices of EXISTING mapped variants.
 * It honours Admin -> Price Sync -> "Auto price sync" (ON/OFF) and works while the site-wide Maintenance Mode is ON.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/bootstrap.php';

$force = in_array('--force', $argv ?? [], true);
$loop  = in_array('--loop', $argv ?? [], true) && !$force;
$budget = 55;                                   // seconds of one cron minute that the loop may use
$t0 = time(); $fails = 0; $exit = 0;

do {
    $wait = 0;
    try {
        $r = PriceSyncService::tick(db(), 'cron', $force, $loop ? 1 : PriceSyncService::RETRIES);   // loop mode: one fetch attempt per round, the loop is the retry
        // v60: REAL heartbeat for Admin -> Monitor (written by this CLI process, independent of any browser/admin session)
        if ($r['status'] === 'skipped') {
            if ($r['message'] === 'Another price check is already running.') worker_record(db(), WORKER_PRICE_SYNC, true, [], '', 0, true);
        } else {
            worker_record(db(), WORKER_PRICE_SYNC, in_array($r['status'], ['ok', 'partial'], true), (array)($r['counts'] ?? []) + ['status' => $r['status']],
                          $r['status'] === 'failed' ? (string)$r['message'] : '', (int)($r['counts']['duration_ms'] ?? 0));
        }
        if ($r['status'] === 'failed') { $fails++; $exit = 1; } elseif ($r['status'] !== 'skipped') { $fails = 0; $exit = 0; }
        if ($r['status'] !== 'skipped' && ($r['status'] !== 'ok' || !$loop || (int)($r['counts']['updated'] ?? 0) > 0)) {
            printf("[%s] %s: %s\n", date('Y-m-d H:i:s'), $r['status'], $r['message']);   // quiet when nothing happened
        }
        $interval = PriceSyncService::settings(db())['interval_sec'];
        $wait = $fails > 0 ? min(60, $interval * (2 ** min($fails, 5))) : $interval;
        if ($r['status'] === 'skipped' && $r['message'] === 'Auto price sync is switched OFF.') break;   // nothing to loop for
        if ($r['status'] === 'skipped' && $r['message'] === 'Interval not reached yet.') $wait = 1;       // another process just ran: look again soon
    } catch (Throwable $e) {
        error_log('[cron/provider_sync] ' . $e->getMessage());
        try { worker_record(db(), WORKER_PRICE_SYNC, false, [], 'crashed: ' . $e->getMessage(), 0); } catch (Throwable $e2) {}
        fwrite(STDERR, 'Price sync crashed: ' . $e->getMessage() . "\n");
        $fails++; $exit = 1; $wait = min(60, 4 * (2 ** min($fails, 5)));
    }
    if (!$loop) break;
    if ((time() - $t0) + $wait >= $budget) break;
    sleep(max(1, $wait));
} while (true);
exit($exit);
