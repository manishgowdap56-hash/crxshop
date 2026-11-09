<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_admin();

/*
 * Admin > ALL ORDERS (read-only).
 * Reads the EXISTING orders / order_keys / product_keys / users / products / product_plans tables; nothing is copied, created or changed.
 *   GET (no view)           -> paginated, filtered list + global summary
 *   GET view=order&id=N     -> full stored detail of one order
 *   GET view=user&user_id=N -> one user's details + totals (loaded only when the admin asks for it)
 * Every value reaches SQL through bound parameters. Provider is NEVER contacted: only stored data is shown.
 */
if (request_method() !== 'GET') json_error('Method not allowed.', 405);

$pdo = db();
$view = input_str('view');

/** Escape LIKE wildcards so user input is matched literally. */
function ao_like(string $s): string { return addcslashes($s, '\\%_'); }

/** Provider shown for an order: stored provider_key for API-fulfilled plans, "Local stock" otherwise. */
function ao_provider(array $r): array
{
    if (($r['plan_source'] ?? 'local') !== 'api') return ['key' => 'local', 'number' => 0, 'label' => 'Local stock'];
    $key = provider_normalize($r['provider_key'] ?? null);
    $idx = array_search($key, provider_keys(), true);
    return ['key' => $key, 'number' => $idx === false ? 0 : $idx + 1, 'label' => provider_info($key)['label']];
}

/** All keys delivered for the given order ids in ONE query (no N+1). */
function ao_keys(PDO $pdo, array $ids): array
{
    $out = [];
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) return $out;
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare(
        "SELECT ok.order_id, COALESCE(ok.external_key, pk.key_value) AS k
         FROM order_keys ok LEFT JOIN product_keys pk ON pk.id = ok.product_key_id
         WHERE ok.order_id IN ($in) ORDER BY ok.id"
    );
    $st->execute($ids);
    foreach ($st->fetchAll() as $r) {
        if ($r['k'] !== null && $r['k'] !== '') $out[(int)$r['order_id']][] = $r['k'];
    }
    return $out;
}

function ao_row(array $r, array $keys): array
{
    $prov = ao_provider($r);
    return [
        'id'             => (int)$r['id'],
        'order_ref'      => $r['order_ref'],
        'source'         => $r['source'] === 'api' ? 'api' : 'website',
        'provider'       => $prov,
        'user_id'        => (int)$r['user_id'],
        'username'       => $r['username'],
        'account_status' => (int)$r['banned'] === 1 ? 'banned' : 'active',
        'user_role'      => $r['role'],
        'product'        => $r['product_name'],
        'duration'       => $r['duration'],
        'quantity'       => (int)$r['quantity'],
        'unit_price'     => (float)$r['unit_price'],
        'total_price'    => (float)$r['total_price'],
        'status'         => $r['status'],
        'created_at'     => $r['created_at'],
        'keys'           => $keys,
        // v58: same failure reason the customer sees (OUT OF STOCK only when confirmed) + the stored provider text (admin only)
        'failure_code'   => order_failure_code($r),
        'failure_label'  => order_failure_label(order_failure_code($r)),
        'failure_reason' => ($r['status'] === 'failed' || $r['status'] === 'pending') && $r['failure_reason'] !== null && $r['failure_reason'] !== '' ? (string)$r['failure_reason'] : null,
    ];
}

$base = 'FROM orders o
         JOIN users u          ON u.id  = o.user_id
         JOIN products p       ON p.id  = o.product_id
         JOIN product_plans pl ON pl.id = o.plan_id';
$cols = 'o.id, o.order_ref, o.user_id, o.quantity, o.unit_price, o.total_price, o.source, o.plan_source, o.provider_key,
         o.upstream_order_id, o.status, o.failure_reason, o.created_at, u.username, u.banned, u.role, p.name AS product_name, pl.duration';
if (ops_failure_code_column($pdo)) $cols .= ', o.failure_code';   // v58: structured reason (OUT_OF_STOCK); older rows fall back to failure_reason

/* ------------------------------------------------------------------ order detail */
if ($view === 'order') {
    $id = input_int('id');
    if ($id <= 0) json_error('Invalid order.', 422);
    $st = $pdo->prepare("SELECT $cols $base WHERE o.id = :id LIMIT 1");
    $st->execute([':id' => $id]);
    $r = $st->fetch();
    if (!$r) json_error('Order not found.', 404);
    $keys = ao_keys($pdo, [$id]);
    $row = ao_row($r, $keys[$id] ?? []);
    $row['plan_source']       = $r['plan_source'];                       // local | api (how it was fulfilled)
    $row['upstream_order_id'] = $r['upstream_order_id'] !== null && $r['upstream_order_id'] !== '' ? $r['upstream_order_id'] : null;
    // v58: refund recorded in the wallet ledger for this order (read-only; null when none)
    $rf = $pdo->prepare("SELECT amount, created_at FROM wallet_ledger WHERE user_id = :u AND type = 'refund' AND reference_id = :r ORDER BY id ASC LIMIT 1");
    $rf->execute([':u' => (int)$r['user_id'], ':r' => $r['order_ref']]);
    $rfr = $rf->fetch();
    $row['refund'] = $rfr ? ['amount' => (float)$rfr['amount'], 'at' => $rfr['created_at']] : null;
    json_ok(['order' => $row]);
}

/* ------------------------------------------------------------------ user detail */
if ($view === 'user') {
    $uid = input_int('user_id');
    if ($uid <= 0) json_error('Invalid user.', 422);
    $st = $pdo->prepare('SELECT id, username, email, role, banned, balance, last_login_at, last_active_at, created_at FROM users WHERE id = :id LIMIT 1');
    $st->execute([':id' => $uid]);
    $u = $st->fetch();
    if (!$u) json_error('User not found.', 404);
    $t = $pdo->prepare(
        'SELECT COUNT(*) AS total_orders,
                SUM(status = "completed") AS ok_orders,
                SUM(status = "failed") AS failed_orders,
                SUM(status = "refunded") AS refunded_orders,
                SUM(status = "pending") AS pending_orders,
                COALESCE(SUM(CASE WHEN status = "completed" THEN quantity END), 0) AS keys_total,
                COALESCE(SUM(CASE WHEN status = "completed" THEN total_price END), 0) AS spent,
                SUM(status = "completed" AND source = "website") AS site_ok,
                SUM(status = "completed" AND source = "api") AS api_ok
         FROM orders WHERE user_id = :id'
    );
    $t->execute([':id' => $uid]);
    $s = $t->fetch();
    json_ok(['user' => [
        'id'             => (int)$u['id'],
        'username'       => $u['username'],
        'email'          => $u['email'],
        'role'           => $u['role'],
        'account_status' => (int)$u['banned'] === 1 ? 'banned' : 'active',
        'balance'        => (float)$u['balance'],
        'created_at'     => $u['created_at'],
        'last_login_at'  => $u['last_login_at'],
        'last_active_at' => $u['last_active_at'],
        'totals'         => [
            'orders'          => (int)$s['total_orders'],
            'successful'      => (int)$s['ok_orders'],
            'failed'          => (int)$s['failed_orders'],
            'refunded'        => (int)$s['refunded_orders'],
            'pending'         => (int)$s['pending_orders'],
            'keys'            => (int)$s['keys_total'],
            'spent'           => (float)$s['spent'],
            'website'         => (int)$s['site_ok'],
            'api'             => (int)$s['api_ok'],
        ],
    ]]);
}

/* ------------------------------------------------------------------ list */
$where = []; $params = [];

$source = input_str('source', 'all');
if ($source === 'website' || $source === 'api') { $where[] = 'o.source = :src'; $params[':src'] = $source; }

$status = input_str('status', 'all');
if (in_array($status, ['completed', 'pending', 'failed', 'refunded'], true)) { $where[] = 'o.status = :st'; $params[':st'] = $status; }

$provider = input_str('provider', 'all');
if ($provider === 'local') {
    $where[] = 'o.plan_source = "local"';
} elseif (in_array($provider, provider_keys(), true)) {
    $where[] = 'o.plan_source = "api"';
    if ($provider === PROVIDER_PRIMARY) { $where[] = '(o.provider_key = :pv OR o.provider_key IS NULL OR o.provider_key = "")'; }
    else { $where[] = 'o.provider_key = :pv'; }
    $params[':pv'] = $provider;
}

$qUser = input_str('username');
if ($qUser !== '') {
    $where[] = '(u.username LIKE :qu ESCAPE "\\\\" OR CAST(u.id AS CHAR) = :quid)';
    $params[':qu'] = '%' . ao_like($qUser) . '%'; $params[':quid'] = $qUser;
}
$qOrder = ltrim(input_str('order'), '#');
if ($qOrder !== '') {
    $w = '(o.order_ref = :qo OR o.order_ref LIKE :qop ESCAPE "\\\\"';
    $params[':qo'] = $qOrder; $params[':qop'] = ao_like($qOrder) . '%';
    if (ctype_digit($qOrder) && strlen($qOrder) <= 18) { $w .= ' OR o.id = :qoid'; $params[':qoid'] = (int)$qOrder; }
    $where[] = $w . ')';
}

// Key search: indexed prefix match first (full key or the start of it); only when that finds nothing, a bounded "contains" match.
$qKey = input_str('key');
if ($qKey !== '') {
    if (mb_strlen($qKey) > 255) json_error('Key search is too long.', 422);
    $find = static function (PDO $pdo, string $pattern): array {
        $st = $pdo->prepare(
            '(SELECT ok.order_id FROM order_keys ok WHERE ok.external_key LIKE :p1 ESCAPE "\\\\" LIMIT 300)
             UNION
             (SELECT ok.order_id FROM product_keys pk JOIN order_keys ok ON ok.product_key_id = pk.id WHERE pk.key_value LIKE :p2 ESCAPE "\\\\" LIMIT 300)'
        );
        $st->execute([':p1' => $pattern, ':p2' => $pattern]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    };
    $ids = $find($pdo, ao_like($qKey) . '%');
    if (!$ids && mb_strlen($qKey) >= 3) $ids = $find($pdo, '%' . ao_like($qKey) . '%');
    if (!$ids) { $where[] = '1 = 0'; }
    else {
        $ph = [];
        foreach ($ids as $i => $oid) { $ph[] = ':k' . $i; $params[':k' . $i] = $oid; }
        $where[] = 'o.id IN (' . implode(',', $ph) . ')';
    }
}

$date = input_str('date', 'all');
switch ($date) {
    case 'today':     $where[] = 'o.created_at >= CURDATE()'; break;
    case 'yesterday': $where[] = 'o.created_at >= (CURDATE() - INTERVAL 1 DAY) AND o.created_at < CURDATE()'; break;
    case '7d':        $where[] = 'o.created_at >= (NOW() - INTERVAL 7 DAY)'; break;
    case '30d':       $where[] = 'o.created_at >= (NOW() - INTERVAL 30 DAY)'; break;
    case 'custom':
        $from = input_str('from'); $to = input_str('to');
        if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where[] = 'o.created_at >= :df'; $params[':df'] = $from . ' 00:00:00'; }
        if ($to !== ''   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $where[] = 'o.created_at < (:dt + INTERVAL 1 DAY)'; $params[':dt'] = $to; }
        break;
}

$sort = input_str('sort', 'newest');
$orderBy = ['newest' => 'o.id DESC', 'oldest' => 'o.id ASC', 'highest' => 'o.total_price DESC, o.id DESC', 'lowest' => 'o.total_price ASC, o.id DESC'][$sort] ?? 'o.id DESC';

$perPage = max(10, min(100, input_int('per_page', 25)));
$page    = max(1, input_int('page', 1));
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$cnt = $pdo->prepare("SELECT COUNT(*) $base $whereSql");
$cnt->execute($params);
$total = (int)$cnt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
if ($page > $pages) $page = $pages;
$offset = ($page - 1) * $perPage;

$st = $pdo->prepare("SELECT $cols $base $whereSql ORDER BY $orderBy LIMIT $perPage OFFSET $offset");   // ints only, cast above
$st->execute($params);
$rows = $st->fetchAll();
$keys = ao_keys($pdo, array_column($rows, 'id'));

$sum = $pdo->query(
    'SELECT COUNT(*) AS total, SUM(source = "website") AS site, SUM(source = "api") AS api,
            SUM(status = "completed") AS completed, SUM(status = "pending") AS pending,
            SUM(status = "failed") AS failed, SUM(status = "refunded") AS refunded FROM orders'
)->fetch();

json_ok([
    'orders'     => array_map(fn($r) => ao_row($r, $keys[(int)$r['id']] ?? []), $rows),
    'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'pages' => $pages,
                     'from' => $total ? $offset + 1 : 0, 'to' => min($total, $offset + $perPage)],
    'summary'    => ['total' => (int)$sum['total'], 'website' => (int)$sum['site'], 'api' => (int)$sum['api'],
                     'completed' => (int)$sum['completed'], 'pending' => (int)$sum['pending'],
                     'failed' => (int)$sum['failed'], 'refunded' => (int)$sum['refunded']],
    'providers'  => array_map(fn($k) => ['key' => $k, 'number' => array_search($k, provider_keys(), true) + 1, 'label' => provider_info($k)['label']], provider_keys()),
]);
