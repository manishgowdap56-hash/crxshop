<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_admin();

/*
 * Admin alerts (stock-outs, provider errors, failed/uncertain orders, refund failures, inconsistencies).
 *   GET  ?q=&status=open|resolved|all&type=&severity=&page=&id=   list / filter / search / detail
 *   POST action=resolve        id, note
 *   POST action=refund_order   id            (alert -> its order; refunds at most once)
 *   POST action=complete_order id, keys[]    (provider DID deliver: attach key(s) to a pending order)
 *   POST action=scan                          (run the consistency scan now)
 */
$pdo = db();
$adminId = (int)current_user()['id'];

function alert_row(array $r): array
{
    return [
        'id' => (int)$r['id'], 'type' => $r['alert_type'], 'severity' => $r['severity'], 'status' => $r['status'],
        'product_id' => $r['product_id'] !== null ? (int)$r['product_id'] : null, 'product_name' => $r['product_name'],
        'plan_id' => $r['plan_id'] !== null ? (int)$r['plan_id'] : null, 'duration' => $r['duration'],
        'order_id' => $r['order_id'] !== null ? (int)$r['order_id'] : null, 'order_ref' => $r['order_ref'],
        'provider' => $r['provider_label'], 'stock_source' => $r['stock_source'],
        'reason' => $r['reason'], 'recommended_action' => $r['recommended_action'],
        'occurrences' => (int)$r['occurrences'], 'first_seen' => $r['first_seen'], 'last_seen' => $r['last_seen'],
        'resolved_at' => $r['resolved_at'], 'resolved_note' => $r['resolved_note'],
    ];
}

if (request_method() === 'GET') {
    ops_scan_inconsistencies($pdo);

    $id = input_int('id');
    if ($id > 0) {
        $q = $pdo->prepare('SELECT * FROM admin_alerts WHERE id = :id LIMIT 1');
        $q->execute([':id' => $id]);
        $a = $q->fetch();
        if (!$a) json_error('Alert not found.', 404);
        $out = alert_row($a);
        $out['order'] = null;
        if ($a['order_id'] !== null) {
            $o = $pdo->prepare('SELECT o.id, o.order_ref, o.status, o.total_price, o.quantity, o.provider_key, o.failure_reason, o.created_at, u.username, o.user_id,
                                       (SELECT COUNT(*) FROM order_keys k WHERE k.order_id = o.id) AS key_count
                                FROM orders o JOIN users u ON u.id = o.user_id WHERE o.id = :id');
            $o->execute([':id' => (int)$a['order_id']]);
            $out['order'] = $o->fetch() ?: null;
        }
        json_ok(['alert' => $out]);
    }

    $status   = input_str('status', 'open');
    $type     = input_str('type', 'all');
    $severity = input_str('severity', 'all');
    $qtext    = trim((string)input_param('q', ''));
    $page     = max(1, input_int('page', 1));
    $per      = 20;

    $where = []; $params = [];
    if (in_array($status, ['open', 'resolved'], true)) { $where[] = 'status = :st'; $params[':st'] = $status; }
    if ($type !== 'all' && preg_match('/^[a-z_]{1,40}$/', $type)) { $where[] = 'alert_type = :t'; $params[':t'] = $type; }
    if (in_array($severity, ['info', 'warning', 'critical'], true)) { $where[] = 'severity = :sv'; $params[':sv'] = $severity; }
    if ($qtext !== '') {
        $where[] = '(product_name LIKE :q1 OR order_ref LIKE :q2 OR provider_label LIKE :q3 OR duration LIKE :q4 OR reason LIKE :q5 OR CAST(product_id AS CHAR) = :q6 OR CAST(order_id AS CHAR) = :q7)';
        $like = '%' . $qtext . '%';
        $params += [':q1' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like, ':q5' => $like, ':q6' => $qtext, ':q7' => $qtext];
    }
    $w = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $c = $pdo->prepare('SELECT COUNT(*) FROM admin_alerts' . $w);
    $c->execute($params);
    $total = (int)$c->fetchColumn();
    $pages = max(1, (int)ceil($total / $per));
    $page = min($page, $pages);

    $l = $pdo->prepare('SELECT * FROM admin_alerts' . $w . " ORDER BY (status = 'open') DESC, FIELD(severity,'critical','warning','info'), last_seen DESC LIMIT " . (int)$per . ' OFFSET ' . (int)(($page - 1) * $per));
    $l->execute($params);

    $counts = ['open' => 0, 'critical' => 0, 'resolved' => 0];
    foreach ($pdo->query("SELECT status, severity, COUNT(*) c FROM admin_alerts GROUP BY status, severity")->fetchAll() as $r) {
        if ($r['status'] === 'open') { $counts['open'] += (int)$r['c']; if ($r['severity'] === 'critical') $counts['critical'] += (int)$r['c']; }
        else $counts['resolved'] += (int)$r['c'];
    }
    json_ok(['alerts' => array_map('alert_row', $l->fetchAll()), 'page' => $page, 'pages' => $pages, 'total' => $total, 'counts' => $counts]);
}

csrf_verify();
$action = input_str('action');

if ($action === 'scan') {
    ops_scan_inconsistencies($pdo, true);
    json_ok();
}

$id = input_int('id');
if ($id <= 0) json_error('Invalid alert.', 422);
$q = $pdo->prepare('SELECT * FROM admin_alerts WHERE id = :id LIMIT 1');
$q->execute([':id' => $id]);
$alert = $q->fetch();
if (!$alert) json_error('Alert not found.', 404);

if ($action === 'resolve') {
    $note = mb_substr(trim((string)input_param('note', '')), 0, 255);
    $st = $pdo->prepare("UPDATE admin_alerts SET status = 'resolved', open_key = NULL, resolved_at = NOW(), resolved_by = :a, resolved_note = :n WHERE id = :id AND status = 'open'");
    $st->execute([':a' => $adminId, ':n' => $note !== '' ? $note : 'Resolved by admin', ':id' => $id]);
    if ($st->rowCount() !== 1) json_error('This alert is already resolved.', 409);
    audit_log('alert.resolved', 'success', ['entity_type' => 'alert', 'entity_id' => $id, 'reason' => $alert['alert_type'] . ($note !== '' ? ': ' . $note : '')]);
    json_ok();
}

if ($action === 'refund_order' || $action === 'complete_order') {
    if ($alert['order_id'] === null) json_error('This alert is not linked to an order.', 422);
    $oq = $pdo->prepare('SELECT id, order_ref, user_id, total_price, status FROM orders WHERE id = :id LIMIT 1');
    $oq->execute([':id' => (int)$alert['order_id']]);
    $ord = $oq->fetch();
    if (!$ord) json_error('Order not found.', 404);
    if ($ord['status'] !== 'pending') json_error('Only a pending order can be refunded or completed (this one is ' . $ord['status'] . ').', 409);

    if ($action === 'refund_order') {
        $res = order_refund_once($pdo, (int)$ord['id'], (int)$ord['user_id'], (float)$ord['total_price'], (string)$ord['order_ref'], 'Refunded by admin after review', 'manual');
        if ($res === 'refund_failed') json_error('The refund transaction failed. Nothing was changed.', 500);
        if ($res === 'already_settled') json_error('This order was already settled (no second refund was made).', 409);
        if ($res === 'no_charge') json_error('No wallet charge was found for this order, so nothing was refunded.', 409);
        audit_log('order.admin_refund', 'success', ['entity_type' => 'order', 'entity_id' => $ord['id'], 'order_ref' => $ord['order_ref'], 'user_id' => (int)$ord['user_id'], 'reason' => 'Refund approved by admin from alert #' . $id]);
        alert_resolve_key((string)$alert['dedupe_key'], 'Order refunded by admin', $adminId);
        json_ok(['refunded' => true]);
    }

    // complete_order: the provider DID generate the key(s); attach them and finish the order.
    $rawKeys = input_param('keys', []);
    if (is_string($rawKeys)) $rawKeys = preg_split('/\r?\n/', $rawKeys) ?: [];
    $keys = array_values(array_filter(array_map(static fn($k) => trim((string)$k), is_array($rawKeys) ? $rawKeys : []), static fn($k) => $k !== ''));
    if (!$keys) json_error('Paste at least one key.', 422);
    if (count($keys) > 100) json_error('Too many keys.', 422);
    try {
        $pdo->beginTransaction();
        $lk = $pdo->prepare('SELECT status FROM orders WHERE id = :id FOR UPDATE');
        $lk->execute([':id' => (int)$ord['id']]);
        if ($lk->fetchColumn() !== 'pending') { $pdo->rollBack(); json_error('This order is no longer pending.', 409); }
        $ik = $pdo->prepare('INSERT INTO order_keys (order_id, external_key, delivered_at) VALUES (:o, :k, NOW())');
        foreach ($keys as $k) $ik->execute([':o' => (int)$ord['id'], ':k' => $k]);
        $pdo->prepare("UPDATE orders SET status = 'completed', failure_reason = NULL WHERE id = :id AND status = 'pending'")->execute([':id' => (int)$ord['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[admin/alerts:complete] ' . $e->getMessage());
        json_error('Could not complete the order. Nothing was changed.', 500);
    }
    audit_log('order.admin_completed', 'success', ['entity_type' => 'order', 'entity_id' => $ord['id'], 'order_ref' => $ord['order_ref'], 'user_id' => (int)$ord['user_id'],
        'reason' => count($keys) . ' key(s) attached by admin from alert #' . $id . ' (no wallet change)']);
    alert_resolve_key((string)$alert['dedupe_key'], 'Order completed with key by admin', $adminId);
    json_ok(['completed' => true]);
}

json_error('Unknown action.', 400);
