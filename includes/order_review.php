<?php
declare(strict_types=1);

/*
 * v60 - PENDING ORDER REVIEW (worker 2). Read-mostly: it never refunds, never completes with an invented key and NEVER calls generate.php.
 *
 * What it does on every pass (cron/order_review.php, once a minute):
 *   1. attempts stuck in SENDING longer than 2 minutes (PHP died after the send) -> state UNCERTAIN + order marked "UNCERTAIN:" (so the refund controller holds it)
 *   2. completes orders whose delivered keys were parked after a database failure (ox_recover_stranded) - keys already paid for
 *   3. classifies every pending order: window | overdue | uncertain | missing_response | parked_keys, counts the check, stamps last_reconcile_at
 *   4. writes one order_review_events row whenever a review state CHANGES (plus every 10th unchanged check) = the request history
 *   5. raises ONE de-duplicated admin alert per order that needs a human (overdue/uncertain/missing response)
 *
 * PROVIDER RECONCILIATION LIMITATION (documented, not hidden): Provider 5 documents history.php, but the supplied material gives no response
 * shape and no filter by order reference / provider order id / status. Therefore NO automatic "was a key generated?" lookup is implemented here.
 * Matching a guessed field could complete or refund the wrong order. Unresolved orders stay in Admin Order Review with a manual workflow:
 *   Admin -> API Settings -> RECENT KEYS (history.php), find the key by time/product, then Pending Orders -> "Complete order with key", or "Refund order".
 */

function or_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    if ($pdo->inTransaction()) throw new RuntimeException('or_ensure_schema must run outside a transaction');
    ox_ensure_schema($pdo);
    rp_add_column($pdo, 'provider_order_attempts', 'review_checks', 'INT UNSIGNED NOT NULL DEFAULT 0');
    rp_add_column($pdo, 'provider_order_attempts', 'review_state', 'VARCHAR(20) DEFAULT NULL');
    $pdo->exec("CREATE TABLE IF NOT EXISTS order_review_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id BIGINT UNSIGNED NOT NULL,
        order_ref VARCHAR(40) NOT NULL,
        review_state VARCHAR(20) NOT NULL,
        detail VARCHAR(255) DEFAULT NULL,
        check_no INT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY idx_ore_order (order_id, id), KEY idx_ore_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $done = true;
}

/** Pure. Review state of one pending order from facts. */
function or_classify(array $f): string
{
    if (!empty($f['parked_keys'])) return 'parked_keys';
    if (($f['attempt_state'] ?? '') === 'SENDING' && (int)($f['attempt_age'] ?? 0) > 120) return 'missing_response';
    $unc = strpos((string)($f['failure_reason'] ?? ''), 'UNCERTAIN:') === 0 || in_array((string)($f['attempt_state'] ?? ''), [OX_UNCERTAIN, OX_SUCCESS_NO_KEY, OX_INVALID_RESPONSE, OX_INTERNAL_ERROR], true);
    if ($unc) return 'uncertain';
    return (int)($f['age'] ?? 0) > (int)($f['window'] ?? 600) ? 'overdue' : 'window';
}

/** One review pass. @return array{checked:int, changed:int, stale_fixed:int, recovered:int, alerts:int, by_state:array} */
function or_run(PDO $pdo): array
{
    or_ensure_schema($pdo);
    $sum = ['checked' => 0, 'changed' => 0, 'stale_fixed' => 0, 'recovered' => 0, 'alerts' => 0, 'by_state' => []];
    $window = rc_window_seconds();

    // 1) requests that died after the send
    $st = $pdo->query("SELECT a.order_id FROM provider_order_attempts a JOIN orders o ON o.id = a.order_id
                       WHERE a.state = 'SENDING' AND o.status = 'pending' AND a.created_at < (NOW() - INTERVAL 120 SECOND) LIMIT 100")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($st as $oid) {
        $pdo->prepare("UPDATE provider_order_attempts SET state = 'UNCERTAIN', error_message = 'no outcome was recorded (request died after the send)', updated_at = NOW() WHERE order_id = :o AND state = 'SENDING'")->execute([':o' => (int)$oid]);
        $pdo->prepare("UPDATE orders SET failure_reason = 'UNCERTAIN: no outcome was recorded (request died after the send)' WHERE id = :o AND status = 'pending' AND (failure_reason IS NULL OR failure_reason NOT LIKE 'UNCERTAIN:%')")->execute([':o' => (int)$oid]);
        $sum['stale_fixed']++;
    }
    // 2) paid-for keys that were parked
    $rec = ox_recover_stranded($pdo);
    $sum['recovered'] = $rec['completed'];

    // 3-5) review queue
    $rows = $pdo->query(
        "SELECT o.id, o.order_ref, o.plan_id, o.failure_reason, TIMESTAMPDIFF(SECOND, o.created_at, NOW()) AS age, o.plan_source,
                a.state AS attempt_state, a.review_state, a.review_checks, a.pending_keys IS NOT NULL AS parked, TIMESTAMPDIFF(SECOND, a.created_at, NOW()) AS attempt_age
         FROM orders o LEFT JOIN provider_order_attempts a ON a.order_id = o.id
         WHERE o.status = 'pending' ORDER BY o.id ASC LIMIT 500"
    )->fetchAll();
    foreach ($rows as $r) {
        $state = or_classify(['parked_keys' => (int)$r['parked'] === 1, 'attempt_state' => $r['attempt_state'], 'attempt_age' => $r['attempt_age'], 'failure_reason' => $r['failure_reason'], 'age' => $r['age'], 'window' => $window]);
        $sum['checked']++; $sum['by_state'][$state] = ($sum['by_state'][$state] ?? 0) + 1;
        $checks = (int)$r['review_checks'] + 1;
        $changed = (string)$r['review_state'] !== $state;
        if ($r['attempt_state'] !== null) {
            $pdo->prepare('UPDATE provider_order_attempts SET review_checks = :c, review_state = :s, last_reconcile_at = NOW() WHERE order_id = :o')->execute([':c' => $checks, ':s' => $state, ':o' => (int)$r['id']]);
        }
        if ($changed || $checks % 10 === 0) {
            $pdo->prepare('INSERT INTO order_review_events (order_id, order_ref, review_state, detail, check_no, created_at) VALUES (:o, :r, :s, :d, :c, NOW())')
                ->execute([':o' => (int)$r['id'], ':r' => $r['order_ref'], ':s' => $state, ':c' => $checks,
                           ':d' => $changed ? 'State changed to ' . $state . '. Provider history lookup is not supported (no documented order lookup); manual verification required.' : 'Still ' . $state . ' (check ' . $checks . ').']);
            if ($changed) $sum['changed']++;
        }
        if (in_array($state, ['overdue', 'uncertain', 'missing_response'], true)) {
            alert_raise(ops_plan_context($pdo, (int)$r['plan_id']) + [
                'dedupe_key' => 'order_review:' . $r['id'], 'type' => 'order_review', 'severity' => $state === 'overdue' ? 'warning' : 'critical', 'order_id' => (int)$r['id'], 'order_ref' => (string)$r['order_ref'],
                'reason' => 'Order needs review (' . $state . '). It was NOT refunded and the provider was NOT asked to generate again. The provider documents no order lookup, so this cannot be reconciled automatically.',
                'recommended_action' => 'Check the provider history (API Settings -> Recent Keys). If a key exists: Pending Orders -> Complete order with key. If not: Refund order.',
            ]);
            $sum['alerts']++;
        }
    }
    // close review alerts of orders that are no longer pending
    foreach ($pdo->query("SELECT open_key FROM admin_alerts WHERE status = 'open' AND open_key LIKE 'order\\_review:%'")->fetchAll(PDO::FETCH_COLUMN) as $k) {
        $q = $pdo->prepare('SELECT status FROM orders WHERE id = :id'); $q->execute([':id' => (int)substr((string)$k, strlen('order_review:'))]);
        $s = $q->fetchColumn();
        if ($s !== false && $s !== 'pending') alert_resolve_key((string)$k, 'Order settled (' . $s . ')');
    }
    return $sum;
}

/** Admin Order Review data (database only). */
function or_admin_list(PDO $pdo): array
{
    or_ensure_schema($pdo);
    $rows = $pdo->query(
        "SELECT o.id, o.order_ref, o.created_at, TIMESTAMPDIFF(MINUTE, o.created_at, NOW()) AS age_min, o.total_price, o.failure_reason, o.upstream_order_id, u.username,
                p.name AS product, pl.duration, o.provider_key, a.state AS provider_state, a.http_code, a.response_time_ms, a.error_message, a.review_state, a.review_checks, a.last_reconcile_at,
                a.channel, (a.pending_keys IS NOT NULL) AS parked
         FROM orders o JOIN users u ON u.id = o.user_id JOIN products p ON p.id = o.product_id JOIN product_plans pl ON pl.id = o.plan_id
         LEFT JOIN provider_order_attempts a ON a.order_id = o.id
         WHERE o.status = 'pending' ORDER BY o.id DESC LIMIT 200"
    )->fetchAll();
    $hist = $pdo->query('SELECT order_ref, review_state, detail, check_no, created_at FROM order_review_events ORDER BY id DESC LIMIT 100')->fetchAll();
    return ['rows' => $rows, 'history' => $hist,
            'limitation' => 'Provider 5 history.php has no documented order lookup (no response shape, no filter by order reference or provider order id). Automatic reconciliation is therefore NOT performed; resolve each order manually from the provider history.'];
}

/** Admin Refunds data (database only): refund state, failures, history, duplicate-protection status. */
function or_refund_overview(PDO $pdo): array
{
    $o = ['auto_enabled' => rc_auto_enabled(), 'window_seconds' => rc_window_seconds(), 'recent' => [], 'failed_attempts' => [], 'decisions' => [], 'counts' => [], 'duplicate_refunds' => 0];
    $o['counts'] = [
        'refunded_24h' => (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE refund_status = 'refunded' AND refunded_at > (NOW() - INTERVAL 24 HOUR)")->fetchColumn(),
        'eligible_waiting' => (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'pending' AND refund_status = 'eligible'")->fetchColumn(),
        'refund_failed_open' => (int)$pdo->query("SELECT COUNT(*) FROM admin_alerts WHERE status = 'open' AND alert_type = 'refund_failed'")->fetchColumn(),
    ];
    // duplicate-protection status: any order reference with more than one refund ledger row is a violation (must be 0)
    $o['duplicate_refunds'] = (int)$pdo->query("SELECT COUNT(*) FROM (SELECT reference_id FROM wallet_ledger WHERE type = 'refund' GROUP BY user_id, reference_id HAVING COUNT(*) > 1) d")->fetchColumn();
    $o['recent'] = $pdo->query("SELECT o.order_ref, u.username, o.refund_amount, o.refund_method, o.refunded_at, o.failure_code, o.failure_reason, o.status, o.refund_status
                                FROM orders o JOIN users u ON u.id = o.user_id WHERE o.refund_status = 'refunded' ORDER BY o.refunded_at DESC LIMIT 60")->fetchAll();
    $o['failed_attempts'] = $pdo->query("SELECT a.alert_type, a.order_ref, a.reason, a.occurrences, a.last_seen, a.status FROM admin_alerts a WHERE a.alert_type = 'refund_failed' ORDER BY a.id DESC LIMIT 30")->fetchAll();
    try { $o['decisions'] = $pdo->query('SELECT * FROM order_refund_log ORDER BY id DESC LIMIT 40')->fetchAll(); } catch (Throwable $e) { $o['decisions'] = []; }
    return $o;
}
