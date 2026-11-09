<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
$user = require_user();

$date = input_str('date');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}

$sql = 'SELECT o.order_ref, o.source, o.quantity, o.total_price, o.created_at,
               p.name AS product_name, pl.duration,
               GROUP_CONCAT(COALESCE(ok.external_key, pk.key_value) ORDER BY ok.id SEPARATOR "\n") AS keys_csv
        FROM orders o
        JOIN products p       ON p.id = o.product_id
        JOIN product_plans pl ON pl.id = o.plan_id
        LEFT JOIN order_keys ok ON ok.order_id = o.id
        LEFT JOIN product_keys pk ON pk.id = ok.product_key_id
        WHERE o.user_id = :uid
          AND DATE(o.created_at) = :d
          AND o.status = "completed"
        GROUP BY o.id
        ORDER BY o.created_at DESC';

$stmt = db()->prepare($sql);
$stmt->execute([':uid' => (int)$user['id'], ':d' => $date]);
$rows = $stmt->fetchAll();

$apiCount = 0; $siteCount = 0; $items = [];
foreach ($rows as $r) {
    $keyList = $r['keys_csv'] !== null && $r['keys_csv'] !== '' ? explode("\n", $r['keys_csv']) : [];
    $perKey = count($keyList) > 0 ? round((float)$r['total_price'] / count($keyList), 2) : (float)$r['total_price'];
    foreach ($keyList as $k) {
        $src = $r['source'] === 'api' ? 'api' : 'site';
        if ($src === 'api') $apiCount++; else $siteCount++;
        $items[] = [
            'product'   => $r['product_name'],
            'duration'  => $r['duration'],
            'key'       => $k,
            'price'     => $perKey,
            'source'    => $src,
            'purchased' => $r['created_at'],
            'order_ref' => $r['order_ref'],
        ];
    }
}

// v58: FAILED orders of the same day (wallet already refunded or refund pending). Read-only; counts above are unchanged (keys only).
// "OUT OF STOCK" is shown ONLY when the failure was confirmed by the provider (orders.failure_code, or the stored provider reason for older rows).
try {
    $pdoH = db();
    $codeCol = ops_failure_code_column($pdoH) ? ', o.failure_code' : '';
    $fs = $pdoH->prepare(
        'SELECT o.order_ref, o.source, o.quantity, o.total_price, o.created_at, o.status, o.failure_reason' . $codeCol . ',
                p.name AS product_name, pl.duration,
                (SELECT l.amount FROM wallet_ledger l WHERE l.user_id = o.user_id AND l.type = "refund" AND l.reference_id = o.order_ref ORDER BY l.id ASC LIMIT 1) AS refunded_amount
         FROM orders o
         JOIN products p       ON p.id = o.product_id
         JOIN product_plans pl ON pl.id = o.plan_id
         WHERE o.user_id = :uid AND DATE(o.created_at) = :d AND o.status = "failed"
         ORDER BY o.created_at DESC'
    );
    $fs->execute([':uid' => (int)$user['id'], ':d' => $date]);
    foreach ($fs->fetchAll() as $f) {
        $items[] = [
            'kind'      => 'failed',
            'product'   => $f['product_name'],
            'duration'  => $f['duration'],
            'key'       => '',
            'price'     => (float)$f['total_price'],
            'source'    => $f['source'] === 'api' ? 'api' : 'site',
            'purchased' => $f['created_at'],
            'order_ref' => $f['order_ref'],
            'status'    => 'FAILED',
            'reason'    => order_failure_label(order_failure_code($f)),   // 'OUT OF STOCK' or ''
            'refunded'  => $f['refunded_amount'] !== null ? round((float)$f['refunded_amount'], 2) : null,
        ];
    }
} catch (Throwable $e) {
    error_log('[mykeys:failed] ' . $e->getMessage());   // the key history itself must never break because of this extra list
}

json_ok([
    'date'       => $date,
    'api_count'  => $apiCount,
    'site_count' => $siteCount,
    'total'      => count($items),
    'items'      => $items,
]);