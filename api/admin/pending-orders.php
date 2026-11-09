<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
$admin = require_admin();

/*
 * Admin > PENDING ORDERS + REFUND CONTROLLER  (admin only; works during maintenance)
 *   GET  ?filter=all|expired|active|review &q= &page=      list + counters + controller health + recent decisions
 *   GET  ?id=N                                              one order: evidence, rule decision, decision log
 *   POST action=run_now                                     run the controller now
 *   POST action=recheck       id                            re-verify one order (auto-refunds only if the rules say it is safe)
 *   POST action=manual_refund id, reason                    refund one expired pending order (exactly once)
 *   POST action=complete_order id, keys[]                   provider DID deliver: attach key(s), no wallet change
 *   POST action=save_config   window_seconds, auto (0|1)    processing window + automatic refunds ON/OFF
 * Every money action goes through order_refund_once() (row lock + status gate + ledger, one transaction).
 */
$pdo = db();
$adminId = (int)$admin['id'];

/*
 * v37: every uncaught error in this endpoint becomes a consistent JSON response with a stable code, and the real cause is
 * written to the server error log. (Before v37 an uncaught PDOException hit the global fatal handler in bootstrap.php, which can
 * only say "Server error. Check the database/schema configuration." and hides which column/table was missing.)
 * No credentials, SQL text or stack traces are ever returned.
 */
set_exception_handler(static function (Throwable $e): void {
    $c = ops_classify_db_error($e);
    error_log('[pending-orders] ' . $c['code'] . ' ' . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
    if (!headers_sent()) json_error($c['message'], $c['status'], ['code' => $c['code'], 'detail' => $c['detail']]);
});

/** Schema gate: verify (and additively repair) before running any query that needs the v35/v36 columns. */
function po_require_schema(PDO $pdo): void
{
    try {
        $chk = ops_schema_check($pdo, true);
    } catch (Throwable $e) {
        error_log('[pending-orders:schema-check] ' . $e->getMessage());
        throw $e;      // classified by the exception handler above
    }
    if ($chk['ok']) ops_backfill_refund_links($pdo);   // v38: one-time, idempotent, non-fatal link of past refunds to their orders
    if (!$chk['ok']) {
        json_error('The database schema is incomplete (' . implode(', ', array_slice($chk['missing'], 0, 8)) . '). Run database/migration_v37_ops.sql, or give the database user ALTER privilege and reload.', 500,
            ['code' => 'SCHEMA_INCOMPLETE', 'missing' => $chk['missing']]);
    }
}

/* Settings-only view: needs no order tables, so the controls always load even if the order list cannot. */
if (request_method() === 'GET' && input_str('view') === 'config') {
    $schemaState = ['ok' => true, 'missing' => [], 'repaired' => []];
    try { $schemaState = ops_schema_check($pdo, true); } catch (Throwable $e) { error_log('[pending-orders:config] ' . $e->getMessage()); $schemaState = ['ok' => false, 'missing' => ['schema check failed (see server log)'], 'repaired' => []]; }
    $lastRun = (int)get_setting('refund_controller_last_run', '0');
    json_ok([
        'controller' => ['auto_enabled' => rc_auto_enabled(), 'window_seconds' => rc_window_seconds(), 'min_seconds' => RC_MIN_WINDOW, 'max_seconds' => RC_MAX_WINDOW,
                         'last_run' => $lastRun > 0 ? date('Y-m-d H:i:s', $lastRun) : null],
        'schema' => $schemaState,
    ]);
}

function po_provider(array $r): array
{
    if (($r['plan_source'] ?? 'local') !== 'api') return ['key' => 'local', 'label' => 'Local stock'];
    $k = provider_normalize($r['provider_key'] ?? null);
    return ['key' => $k, 'label' => provider_info($k)['label']];
}

function po_eligibility(string $state): string
{
    return [
        'refund_pending' => 'Eligible - verified failure, not refunded yet', 'refund_failed' => 'Eligible - last refund attempt failed',
        'auto_refunded' => 'Refunded', 'manual_refunded' => 'Refunded', 'manual_review' => 'Not decided - admin must review',
        'expired' => 'Not verified yet (expired is not a confirmed failure)', 'failed_unrefunded' => 'Failed - no refund recorded',
        'processing' => 'Being checked now', 'pending' => 'Inside the processing window', 'completed' => 'Not applicable (delivered)',
    ][$state] ?? 'n/a';
}

function po_row(array $r, ?array $last = null): array
{
    $overdue = max(0, (int)$r['overdue_seconds']);
    $state = (string)($r['ui_state'] ?? 'other');
    $labels = rc_state_labels();
    $refunded = in_array($state, ['auto_refunded', 'manual_refunded'], true);
    return [
        'id'            => (int)$r['id'],
        'order_ref'     => $r['order_ref'],
        'user_id'       => (int)$r['user_id'],
        'username'      => $r['username'],
        'product'       => $r['product_name'],
        'duration'      => $r['duration'],
        'quantity'      => (int)$r['quantity'],
        'amount'        => (float)$r['total_price'],
        'created_at'    => $r['created_at'],
        'provider'      => po_provider($r),
        'expires_at'    => $r['expires_at'],
        'expired'       => (int)$r['is_expired'] === 1,
        'overdue_seconds' => $overdue,
        'api_status'    => $r['last_api_status'] !== null && $r['last_api_status'] !== '' ? $r['last_api_status'] : 'not checked yet',
        'last_checked_at' => $r['last_checked_at'],
        'attempts'      => (int)$r['check_attempts'],
        'refund_status' => $r['refund_status'] !== null && $r['refund_status'] !== '' ? $r['refund_status'] : 'none',
        'key_count'     => (int)$r['key_count'],
        'upstream_id'   => (string)($r['upstream_order_id'] ?? ''),
        'failure_note'  => $r['failure_reason'],
        // v38: order status and refund status stay separate; `state` is only the derived one-per-order label
        'order_status'  => $r['status'],
        'state'         => $state,
        'state_label'   => $labels[$state] ?? 'Other',
        'eligibility'   => po_eligibility($state),
        'refund' => [
            'done'      => $refunded,
            'amount'    => $refunded && $r['refund_amount'] !== null ? (float)$r['refund_amount'] : null,
            'method'    => $refunded ? (($r['refund_method'] ?? '') === 'manual' ? 'manual' : 'automatic') : null,
            'at'        => $refunded ? $r['refunded_at'] : null,
            'ledger_id' => $refunded && $r['refund_ledger_id'] !== null ? (int)$r['refund_ledger_id'] : null,
        ],
        'last_decision' => $last,
    ];
}

/** Latest controller decision per order for one page of orders (one query, not one per row). */
function po_last_decisions(PDO $pdo, array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids), static fn($i) => $i > 0));
    if (!$ids) return [];
    $in = implode(',', $ids);   // integers only
    $out = [];
    try {
        $q = $pdo->query("SELECT l.order_id, l.decision, l.result, l.reason, l.actor, l.created_at, l.prev_status, l.new_status, l.api_status
                          FROM order_refund_log l WHERE l.id IN (SELECT MAX(id) FROM order_refund_log WHERE order_id IN ({$in}) GROUP BY order_id)");
        foreach ($q->fetchAll() as $r) {
            $out[(int)$r['order_id']] = ['decision' => $r['decision'], 'result' => $r['result'], 'reason' => $r['reason'], 'actor' => $r['actor'],
                                         'at' => $r['created_at'], 'prev' => $r['prev_status'], 'next' => $r['new_status'], 'api' => $r['api_status']];
        }
    } catch (Throwable $e) { error_log('[pending-orders:last-decisions] ' . $e->getMessage()); }
    return $out;
}

function po_date(string $v): ?string
{
    return (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) ? $v : null;
}

$W = rc_window_seconds();
$STATE = rc_state_sql();
$BASE_SELECT =
    "SELECT o.id, o.order_ref, o.user_id, o.total_price, o.quantity, o.created_at, o.provider_key, o.plan_source, o.status, o.upstream_order_id,
            o.refund_status, o.refund_method, o.last_api_status, o.last_checked_at, o.check_attempts, o.failure_reason,
            u.username, p.name AS product_name, pl.duration,
            (o.created_at + INTERVAL {$W} SECOND) AS expires_at,
            (o.created_at < (NOW() - INTERVAL {$W} SECOND)) AS is_expired,
            (TIMESTAMPDIFF(SECOND, (o.created_at + INTERVAL {$W} SECOND), NOW())) AS overdue_seconds,
            (SELECT COUNT(*) FROM order_keys k WHERE k.order_id = o.id) AS key_count,
            o.refund_amount, o.refund_ledger_id, o.refunded_at, ({$STATE}) AS ui_state
     FROM orders o
     JOIN users u ON u.id = o.user_id
     JOIN products p ON p.id = o.product_id
     JOIN product_plans pl ON pl.id = o.plan_id";

/* ------------------------------ GET ------------------------------ */
if (request_method() === 'GET') {
    po_require_schema($pdo);
    rc_maybe_run($pdo);   // fallback when cron is not set up yet (throttled to once / 2 min)

    /* v38: Controller decision history (filter by order, date, decision type; paginated; rows are never deleted) */
    if (input_str('view') === 'decisions') {
        $types = ['wait', 'retry', 'recheck', 'review', 'blocked', 'eligible', 'manual', 'completed'];
        $dw = []; $dp = [];
        $oq = trim(input_str('order'));
        if ($oq !== '') {
            $dp[':o1'] = '%' . addcslashes($oq, '\\%_') . '%';
            if (ctype_digit($oq)) { $dw[] = '(l.order_id = :o2 OR l.order_ref LIKE :o1)'; $dp[':o2'] = (int)$oq; }
            else $dw[] = 'l.order_ref LIKE :o1';
        }
        $dt = input_str('decision');
        if ($dt !== '' && in_array($dt, $types, true)) { $dw[] = 'l.decision = :dt'; $dp[':dt'] = $dt; }
        $f = po_date(input_str('from')); $t = po_date(input_str('to'));
        if ($f) { $dw[] = 'l.created_at >= :f'; $dp[':f'] = $f . ' 00:00:00'; }
        if ($t) { $dw[] = 'l.created_at < DATE_ADD(:t, INTERVAL 1 DAY)'; $dp[':t'] = $t . ' 00:00:00'; }
        $dwh = $dw ? ' WHERE ' . implode(' AND ', $dw) : '';
        $dpage = max(1, input_int('page', 1)); $dper = 15;
        $cc = $pdo->prepare('SELECT COUNT(*) FROM order_refund_log l' . $dwh); $cc->execute($dp);
        $dtotal = (int)$cc->fetchColumn(); $dpages = max(1, (int)ceil($dtotal / $dper)); $dpage = min($dpage, $dpages);
        $dq = $pdo->prepare('SELECT l.id, l.order_id, l.order_ref, l.decision, l.result, l.reason, l.actor, l.amount, l.created_at, l.prev_status, l.new_status, l.api_status
                             FROM order_refund_log l' . $dwh . ' ORDER BY l.id DESC LIMIT ' . $dper . ' OFFSET ' . (($dpage - 1) * $dper));
        $dq->execute($dp);
        json_ok(['decisions' => $dq->fetchAll(), 'page' => $dpage, 'pages' => $dpages, 'total' => $dtotal, 'types' => $types]);
    }

    $id = input_int('id');
    if ($id > 0) {
        $q = $pdo->prepare($BASE_SELECT . ' WHERE o.id = :id LIMIT 1');
        $q->execute([':id' => $id]);
        $r = $q->fetch();
        if (!$r) json_error('Order not found.', 404);

        $evRow = $r + ['is_expired' => $r['is_expired']];
        $ev = rc_collect_evidence($pdo, $evRow);
        $dec = rc_decide($ev);
        $lg = $pdo->prepare('SELECT id, decision, result, reason, actor, amount, created_at, prev_status, new_status, api_status FROM order_refund_log WHERE order_id = :o ORDER BY id DESC LIMIT 25');
        $lg->execute([':o' => $id]);
        json_ok([
            'order'    => po_row($r) + ['status' => $r['status'], 'refund_method' => $r['refund_method']],
            'evidence' => [
                'keys_stored'        => $ev['order_keys'] + $ev['delivered_keys'],
                'wallet_charges'     => $ev['purchase_rows'],
                'wallet_refunds'     => $ev['refund_rows'],
                'completion_records' => $ev['audit_success'],
                'failure_records'    => $ev['audit_failed'],
                'uncertain_records'  => $ev['audit_uncertain'],
                'provider_status'    => $ev['provider']['state'],
                'provider_detail'    => $ev['provider']['detail'],
                'callbacks'          => 'No provider documents a callback/webhook; none can be received.',
            ],
            'rule'     => ['decision' => $dec['decision'], 'api_status' => $dec['api_status'], 'reason' => $dec['reason']],
            'log'      => $lg->fetchAll(),
            'can_refund' => $r['status'] === 'pending' && (int)$r['is_expired'] === 1 && $ev['order_keys'] === 0 && $ev['delivered_keys'] === 0
                            && $ev['refund_rows'] === 0 && $ev['purchase_rows'] > 0,
        ]);
    }

    $filter = input_str('filter', 'all');
    $qtext  = trim((string)input_param('q', ''));
    $page   = max(1, input_int('page', 1));
    $per    = 20;
    $groups = rc_state_groups();

    $where = []; $params = [];
    $legacyOrder = true;                                            // legacy filters keep their original ordering (expired first, oldest first)
    if ($filter === 'every') {                                      // ALL ORDERS: every status, newest first
        $legacyOrder = false;
    } elseif ($filter === 'expired') {
        $where[] = "o.status = 'pending'"; $where[] = "o.created_at < (NOW() - INTERVAL {$W} SECOND)";
    } elseif ($filter === 'active') {
        $where[] = "o.status = 'pending'"; $where[] = "o.created_at >= (NOW() - INTERVAL {$W} SECOND)";
    } elseif ($filter === 'review') {
        $where[] = "o.status = 'pending'"; $where[] = "o.refund_status IN ('review','blocked','failed','eligible')";
    } elseif ($filter !== 'all' && isset($groups[$filter])) {       // v38 state filters
        $in = implode(',', array_map(static fn($x) => "'" . $x . "'", $groups[$filter]));   // fixed internal list, never user input
        $where[] = "({$STATE}) IN ({$in})";
        $legacyOrder = false;
    } else {                                                        // 'all' (default) = every order still pending
        $filter = 'all';
        $where[] = "o.status = 'pending'";
    }
    if ($qtext !== '') {
        $like = '%' . addcslashes($qtext, '\\%_') . '%';
        $parts = ['o.order_ref LIKE :q1', 'u.username LIKE :q2', 'p.name LIKE :q3', 'CAST(o.id AS CHAR) = :q4'];
        $params += [':q1' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $qtext];
        if (ctype_digit($qtext)) { $parts[] = 'o.user_id = :q5'; $params[':q5'] = (int)$qtext; }   // search by user ID
        $where[] = '(' . implode(' OR ', $parts) . ')';
    }
    $dFrom = po_date(input_str('from')); $dTo = po_date(input_str('to'));
    if ($dFrom) { $where[] = 'o.created_at >= :d1'; $params[':d1'] = $dFrom . ' 00:00:00'; }
    if ($dTo)   { $where[] = 'o.created_at < DATE_ADD(:d2, INTERVAL 1 DAY)'; $params[':d2'] = $dTo . ' 00:00:00'; }
    $w = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $c = $pdo->prepare('SELECT COUNT(*) FROM orders o JOIN users u ON u.id = o.user_id JOIN products p ON p.id = o.product_id' . $w);
    $c->execute($params);
    $total = (int)$c->fetchColumn();
    $pages = max(1, (int)ceil($total / $per));
    $page  = min($page, $pages);

    $order = $legacyOrder ? 'is_expired DESC, o.created_at ASC' : 'o.id DESC';
    $l = $pdo->prepare($BASE_SELECT . $w . ' ORDER BY ' . $order . ' LIMIT ' . (int)$per . ' OFFSET ' . (int)(($page - 1) * $per));
    $l->execute($params);
    $pageRows = $l->fetchAll();
    $lastDec = po_last_decisions($pdo, array_column($pageRows, 'id'));

    // One count per mutually-exclusive state: each order is counted exactly once (the groups below are sums of those states).
    $states = array_fill_keys(array_keys(rc_state_labels()), 0);
    foreach ($pdo->query("SELECT ({$STATE}) AS st, COUNT(*) AS c FROM orders o GROUP BY st")->fetchAll() as $x) { $states[$x['st']] = (int)$x['c']; }
    $groupCounts = ['every' => array_sum($states)];
    foreach ($groups as $gk => $list) { $n = 0; foreach ($list as $st) $n += $states[$st] ?? 0; $groupCounts[$gk] = $n; }
    $groupCounts['all'] = $groupCounts['pending_open'];

    $counts = rc_counts($pdo);
    $last = (int)get_setting('refund_controller_last_run', '0');
    $alerts = ['review' => 0, 'refund_failed' => 0, 'api_errors' => 0];
    try {
        foreach ($pdo->query("SELECT alert_type, COUNT(*) AS c FROM admin_alerts WHERE status = 'open' AND alert_type IN ('refund_review','refund_failed','provider_unreachable','uncertain_order') GROUP BY alert_type")->fetchAll() as $a) {
            if ($a['alert_type'] === 'refund_review')  $alerts['review'] += (int)$a['c'];
            if ($a['alert_type'] === 'uncertain_order') $alerts['review'] += (int)$a['c'];
            if ($a['alert_type'] === 'refund_failed')  $alerts['refund_failed'] += (int)$a['c'];
            if ($a['alert_type'] === 'provider_unreachable') $alerts['api_errors'] += (int)$a['c'];
        }
    } catch (Throwable $e) { error_log('[pending-orders:alerts] ' . $e->getMessage()); }

    $recent = []; $recentError = false;
    try {
        $recent = $pdo->query("SELECT order_ref, decision, result, reason, actor, amount, created_at, prev_status, new_status, api_status FROM order_refund_log ORDER BY id DESC LIMIT 12")->fetchAll();
    } catch (Throwable $e) { $recentError = true; error_log('[pending-orders:recent] ' . $e->getMessage()); }

    json_ok([
        'orders' => array_map(static fn(array $r): array => po_row($r, $lastDec[(int)$r['id']] ?? null), $pageRows),
        'filter' => $filter, 'states' => $states, 'state_labels' => rc_state_labels(), 'group_counts' => $groupCounts,
        'page' => $page, 'pages' => $pages, 'total' => $total,
        'counts' => $counts,
        'alerts' => $alerts,
        'controller' => [
            'auto_enabled'   => rc_auto_enabled(),
            'window_seconds' => $W,
            'last_run'       => $last > 0 ? date('Y-m-d H:i:s', $last) : null,
            'stale'          => $counts['expired'] > 0 && ($last === 0 || (time() - $last) > 900),   // expired orders exist but nothing ran for 15 min
            'last_summary'   => json_decode((string)get_setting('refund_controller_last_summary', ''), true) ?: null,
        ],
        'recent' => $recent,
        'recent_error' => $recentError,
    ]);
}

/* ------------------------------ POST ----------------------------- */
csrf_verify();
$action = input_str('action');

if ($action === 'save_config') {
    $secs = input_int('window_seconds', $W);
    if ($secs < RC_MIN_WINDOW || $secs > RC_MAX_WINDOW) json_error('Processing window must be between ' . RC_MIN_WINDOW . ' and ' . RC_MAX_WINDOW . ' seconds.', 422);
    $auto = input_int('auto', rc_auto_enabled() ? 1 : 0) === 1;
    set_setting('order_pending_window_seconds', (string)$secs);
    set_setting('refund_controller_auto', $auto ? '1' : '0');
    audit_log('refund_controller.config', 'success', ['entity_type' => 'setting', 'reason' => 'Window ' . $secs . 's, automatic refunds ' . ($auto ? 'ON' : 'OFF')]);
    json_ok(['window_seconds' => $secs, 'auto_enabled' => $auto]);
}

po_require_schema($pdo);   // everything below touches orders / order_refund_log

if ($action === 'run_now') {
    $s = rc_run($pdo, 'admin:' . $adminId);
    audit_log('refund_controller.run', 'info', ['entity_type' => 'controller', 'reason' => 'Manual run: ' . json_encode($s)]);
    if (!empty($s['skipped'])) json_ok(['summary' => $s, 'message' => 'Another controller run is already in progress.']);
    json_ok(['summary' => $s, 'message' => sprintf('Checked %d expired order(s): %d refunded, %d need review, %d blocked.', $s['checked'], $s['refunded'], $s['review'], $s['blocked'])]);
}

$id = input_int('id');
if ($id <= 0) json_error('Invalid order.', 422);

if ($action === 'recheck') {
    $r = rc_process_order($pdo, $id, 'admin:' . $adminId, true);
    try {   // every admin re-check is part of the decision history (v38)
        $lo = $pdo->prepare('SELECT * FROM orders WHERE id = :id'); $lo->execute([':id' => $id]);
        if ($row = $lo->fetch()) rc_log($pdo, $row, 'recheck', (string)$r['result'], (string)$r['message'], [], 'admin:' . $adminId, null, rc_snapshot((string)$row['status'], $row['refund_status'] ?? null), 'admin re-check');
    } catch (Throwable $e) { error_log('[pending-orders:recheck-log] ' . $e->getMessage()); }
    json_ok(['decision' => $r['decision'], 'result' => $r['result'], 'message' => $r['message']]);
}

if ($action === 'manual_refund') {
    $res = rc_manual_refund($pdo, $id, $adminId, input_str('reason'));
    if (!$res['ok']) json_error($res['message'], in_array($res['code'], ['REFUND_FAILED'], true) ? 500 : 409, ['code' => $res['code']]);
    json_ok(['refunded' => true, 'message' => $res['message']]);
}

if ($action === 'complete_order') {
    $rawKeys = input_param('keys', []);
    if (is_string($rawKeys)) $rawKeys = preg_split('/\r?\n/', $rawKeys) ?: [];
    $keys = array_values(array_filter(array_map(static fn($k) => trim((string)$k), is_array($rawKeys) ? $rawKeys : []), static fn($k) => $k !== ''));
    if (!$keys) json_error('Paste at least one key.', 422);
    if (count($keys) > 100) json_error('Too many keys.', 422);
    try {
        $pdo->beginTransaction();
        $lk = $pdo->prepare('SELECT user_id, order_ref, status FROM orders WHERE id = :id FOR UPDATE');
        $lk->execute([':id' => $id]);
        $o = $lk->fetch();
        if (!$o) { $pdo->rollBack(); json_error('Order not found.', 404); }
        if ($o['status'] !== 'pending') { $pdo->rollBack(); json_error('This order is no longer pending.', 409); }
        $ik = $pdo->prepare('INSERT INTO order_keys (order_id, external_key, delivered_at) VALUES (:o, :k, NOW())');
        foreach ($keys as $k) $ik->execute([':o' => $id, ':k' => $k]);
        $pdo->prepare("UPDATE orders SET status = 'completed', failure_reason = NULL, refund_status = NULL WHERE id = :id AND status = 'pending'")->execute([':id' => $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[pending-orders:complete] ' . $e->getMessage());
        json_error('Could not complete the order. Nothing was changed.', 500);
    }
    audit_log('order.admin_completed', 'success', ['entity_type' => 'order', 'entity_id' => $id, 'order_ref' => $o['order_ref'], 'user_id' => (int)$o['user_id'], 'actor_id' => $adminId, 'reason' => count($keys) . ' key(s) attached from Pending Orders (no wallet change)']);
    rc_resolve_order_alerts($id, 'Order completed with key by admin');
    $lo = $pdo->prepare('SELECT * FROM orders WHERE id = :id'); $lo->execute([':id' => $id]);
    if ($row = $lo->fetch()) rc_log($pdo, $row, 'completed', 'completed', 'Admin attached ' . count($keys) . ' key(s)', ['keys' => count($keys)], 'admin:' . $adminId);
    json_ok(['completed' => true]);
}

json_error('Unknown action.', 400);
