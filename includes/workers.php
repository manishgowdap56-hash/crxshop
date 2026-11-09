<?php
declare(strict_types=1);

/*
 * v60 - background worker framework: one named MySQL lock per worker + a REAL heartbeat table (worker_runs).
 *   price_sync     cron/provider_sync.php        provider catalog -> prices / grace periods / durations
 *   order_review   cron/order_review.php         pending / overdue / uncertain orders -> Admin Order Review (never refunds, never calls generate)
 *   refund         cron/refund_controller.php    eligible failed orders -> exactly-once refund
 * Status shown in Admin -> Monitor is computed ONLY from these rows (written by the CLI processes), never from a browser session.
 */

const WORKER_PRICE_SYNC   = 'price_sync';
const WORKER_ORDER_REVIEW = 'order_review';
const WORKER_REFUND       = 'refund';

/** Expected cadence (seconds) used to decide "alive"; a worker is alive when it ran within 3 x cadence + 60 s. */
function worker_expected_interval(string $w): int
{
    if ($w === WORKER_PRICE_SYNC) return max(PriceSyncService::MIN_INTERVAL, (int)PriceSyncService::settings(db())['interval_sec']);
    return 60;
}

function worker_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    if ($pdo->inTransaction()) throw new RuntimeException('worker_ensure_schema must run outside a transaction');
    $pdo->exec("CREATE TABLE IF NOT EXISTS worker_runs (
        worker VARCHAR(30) NOT NULL,
        last_start_at DATETIME DEFAULT NULL,
        last_run_at DATETIME DEFAULT NULL,
        last_success_at DATETIME DEFAULT NULL,
        last_error VARCHAR(500) DEFAULT NULL,
        last_error_at DATETIME DEFAULT NULL,
        runs BIGINT UNSIGNED NOT NULL DEFAULT 0,
        failures BIGINT UNSIGNED NOT NULL DEFAULT 0,
        fail_streak INT UNSIGNED NOT NULL DEFAULT 0,
        skipped_locked BIGINT UNSIGNED NOT NULL DEFAULT 0,
        last_duration_ms INT UNSIGNED DEFAULT NULL,
        last_stats TEXT DEFAULT NULL,
        last_pid INT UNSIGNED DEFAULT NULL,
        PRIMARY KEY (worker)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $done = true;
}

function worker_lock_name(string $w): string { return 'royalpanel_worker_' . preg_replace('/[^a-z_]/', '', $w); }

/** true = this process now holds the worker lock. false = another process of the same worker is running (caller exits quietly). */
function worker_lock(PDO $pdo, string $w): bool
{
    try { return (int)$pdo->query("SELECT GET_LOCK('" . worker_lock_name($w) . "', 0)")->fetchColumn() === 1; }
    catch (Throwable $e) { error_log('[worker_lock] ' . $e->getMessage()); return false; }   // cannot lock = do not run (fail closed)
}

function worker_unlock(PDO $pdo, string $w): void
{
    try { $pdo->query("SELECT RELEASE_LOCK('" . worker_lock_name($w) . "')")->fetchColumn(); } catch (Throwable $e) { /* frees on disconnect */ }
}

/** Record one finished pass. $ok=false stores the error and bumps the failure streak. $skipped = another process held the lock. */
function worker_record(PDO $pdo, string $w, bool $ok, array $stats = [], string $error = '', int $ms = 0, bool $skipped = false): void
{
    try {
        worker_ensure_schema($pdo);
        $pdo->prepare('INSERT IGNORE INTO worker_runs (worker) VALUES (:w)')->execute([':w' => $w]);
        if ($skipped) {
            $pdo->prepare('UPDATE worker_runs SET skipped_locked = skipped_locked + 1 WHERE worker = :w')->execute([':w' => $w]);
            return;
        }
        $pdo->prepare(
            'UPDATE worker_runs SET last_start_at = DATE_SUB(NOW(), INTERVAL :s SECOND), last_run_at = NOW(), runs = runs + 1,
                    last_duration_ms = :ms, last_stats = :st, last_pid = :pid,
                    last_success_at = IF(:ok1 = 1, NOW(), last_success_at),
                    failures = failures + IF(:ok2 = 1, 0, 1), fail_streak = IF(:ok3 = 1, 0, fail_streak + 1),
                    last_error = IF(:ok4 = 1, last_error, :err), last_error_at = IF(:ok5 = 1, last_error_at, NOW())
             WHERE worker = :w'
        )->execute([':s' => (int)ceil($ms / 1000), ':ms' => $ms, ':st' => substr((string)json_encode($stats), 0, 4000), ':pid' => function_exists('getmypid') ? (int)getmypid() : null,
                    ':ok1' => $ok ? 1 : 0, ':ok2' => $ok ? 1 : 0, ':ok3' => $ok ? 1 : 0, ':ok4' => $ok ? 1 : 0, ':ok5' => $ok ? 1 : 0,
                    ':err' => $ok ? null : mb_substr(ops_sanitize($error, 480), 0, 490), ':w' => $w]);
    } catch (Throwable $e) { error_log('[worker_record] ' . $e->getMessage()); }
}

/** Admin view: real status of the three workers, from the heartbeat rows only. */
function worker_status_all(PDO $pdo): array
{
    worker_ensure_schema($pdo);
    $out = [];
    $rows = [];
    foreach ($pdo->query("SELECT worker, UNIX_TIMESTAMP(last_run_at) lr, UNIX_TIMESTAMP(last_success_at) ls, UNIX_TIMESTAMP(last_error_at) le, last_error, runs, failures, fail_streak,
                                 skipped_locked, last_duration_ms, last_stats, UNIX_TIMESTAMP() AS now_ts FROM worker_runs")->fetchAll() as $r) $rows[$r['worker']] = $r;
    foreach ([WORKER_PRICE_SYNC => 'Provider Price Sync', WORKER_ORDER_REVIEW => 'Pending Order Review', WORKER_REFUND => 'Automatic Refund'] as $w => $label) {
        $r = $rows[$w] ?? null;
        $exp = worker_expected_interval($w);
        $lr = $r ? (int)$r['lr'] : 0; $now = $r ? (int)$r['now_ts'] : time();
        $alive = $lr > 0 && ($now - $lr) <= (3 * $exp + 60);
        $status = !$r || $lr === 0 ? 'never_ran' : (!$alive ? 'stopped' : ((int)$r['fail_streak'] > 0 ? 'failing' : 'ok'));
        $out[] = [
            'worker' => $w, 'label' => $label, 'status' => $status, 'alive' => $alive, 'expected_interval' => $exp,
            'last_run' => $lr ?: null, 'last_success' => $r && $r['ls'] ? (int)$r['ls'] : null, 'last_error' => $r ? $r['last_error'] : null, 'last_error_at' => $r && $r['le'] ? (int)$r['le'] : null,
            'runs' => $r ? (int)$r['runs'] : 0, 'failures' => $r ? (int)$r['failures'] : 0, 'fail_streak' => $r ? (int)$r['fail_streak'] : 0, 'skipped_locked' => $r ? (int)$r['skipped_locked'] : 0,
            'last_duration_ms' => $r && $r['last_duration_ms'] !== null ? (int)$r['last_duration_ms'] : null, 'stats' => $r ? (json_decode((string)$r['last_stats'], true) ?: []) : [],
        ];
    }
    return $out;
}
