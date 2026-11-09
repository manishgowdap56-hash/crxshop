<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_admin();      // audit data is restricted to authorized admins; read-only endpoint

/*
 * GET ?view=audit  q, event (prefix, e.g. "order." / "wallet." / "security."), result, from, to, page
 * GET ?view=prices q, page         (price change history)
 * Rows never contain passwords, tokens, authorization headers or session ids (sanitized when written).
 */
if (request_method() !== 'GET') json_error('Method not allowed.', 405);
$pdo = db();
$view = input_str('view', 'audit');
$page = max(1, input_int('page', 1));
$per  = 25;
$qtext = trim((string)input_param('q', ''));

if ($view === 'prices') {
    $where = []; $params = [];
    if ($qtext !== '') {
        $where[] = '(product_name LIKE :q1 OR duration LIKE :q2 OR changed_by_name LIKE :q3 OR CAST(product_id AS CHAR) = :q4)';
        $params = [':q1' => '%' . $qtext . '%', ':q2' => '%' . $qtext . '%', ':q3' => '%' . $qtext . '%', ':q4' => $qtext];
    }
    $w = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $c = $pdo->prepare('SELECT COUNT(*) FROM price_changes' . $w); $c->execute($params);
    $total = (int)$c->fetchColumn(); $pages = max(1, (int)ceil($total / $per)); $page = min($page, $pages);
    $l = $pdo->prepare('SELECT id, product_id, plan_id, product_name, duration, old_price, new_price, changed_by_name, created_at FROM price_changes' . $w
        . ' ORDER BY id DESC LIMIT ' . (int)$per . ' OFFSET ' . (int)(($page - 1) * $per));
    $l->execute($params);
    json_ok(['rows' => array_map(static fn($r) => [
        'id' => (int)$r['id'], 'product_id' => (int)$r['product_id'], 'plan_id' => (int)$r['plan_id'], 'product' => $r['product_name'], 'duration' => $r['duration'],
        'old_price' => (float)$r['old_price'], 'new_price' => (float)$r['new_price'],
        'direction' => (float)$r['new_price'] > (float)$r['old_price'] ? 'increased' : 'decreased',
        'admin' => $r['changed_by_name'], 'at' => $r['created_at'],
    ], $l->fetchAll()), 'page' => $page, 'pages' => $pages, 'total' => $total]);
}

$where = []; $params = [];
$event = input_str('event', '');
if ($event !== '' && preg_match('/^[a-z_.]{1,40}$/', $event)) { $where[] = 'event_type LIKE :ev'; $params[':ev'] = $event . '%'; }
$result = input_str('result', 'all');
if (in_array($result, ['success', 'failure', 'info', 'denied'], true)) { $where[] = 'result = :rs'; $params[':rs'] = $result; }
$from = input_str('from', ''); $to = input_str('to', '');
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where[] = 'created_at >= :f'; $params[':f'] = $from . ' 00:00:00'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $where[] = 'created_at <= :t'; $params[':t'] = $to . ' 23:59:59'; }
if ($qtext !== '') {
    $where[] = '(event_type LIKE :q1 OR order_ref LIKE :q2 OR reason LIKE :q3 OR entity_id = :q4 OR actor_label LIKE :q5)';
    $params += [':q1' => '%' . $qtext . '%', ':q2' => '%' . $qtext . '%', ':q3' => '%' . $qtext . '%', ':q4' => $qtext, ':q5' => '%' . $qtext . '%'];
}
$w = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$c = $pdo->prepare('SELECT COUNT(*) FROM audit_logs' . $w); $c->execute($params);
$total = (int)$c->fetchColumn(); $pages = max(1, (int)ceil($total / $per)); $page = min($page, $pages);
$l = $pdo->prepare('SELECT id, created_at, actor_user_id, actor_label, event_type, result, entity_type, entity_id, order_ref, user_id, reason, ip FROM audit_logs' . $w
    . ' ORDER BY id DESC LIMIT ' . (int)$per . ' OFFSET ' . (int)(($page - 1) * $per));
$l->execute($params);
json_ok(['rows' => $l->fetchAll(), 'page' => $page, 'pages' => $pages, 'total' => $total]);
