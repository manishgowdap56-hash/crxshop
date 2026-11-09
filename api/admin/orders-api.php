<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_admin();

$pdo = db();

$stmt = $pdo->query(
    'SELECT o.id, o.order_ref, o.upstream_order_id, o.provider_key, o.quantity, o.total_price, o.created_at, o.status,
            u.username, p.name AS product_name, pl.duration, pl.provider_variant_id
     FROM orders o
     JOIN users u          ON u.id = o.user_id
     JOIN products p       ON p.id = o.product_id
     JOIN product_plans pl ON pl.id = o.plan_id
     WHERE o.source = "api"
     ORDER BY o.id DESC LIMIT 500'
);
$orders = $stmt->fetchAll();

$totalOrders = (int)$pdo->query('SELECT COUNT(*) FROM orders WHERE source="api"')->fetchColumn();
$keysSold    = (int)$pdo->query('SELECT COALESCE(SUM(quantity),0) FROM orders WHERE source="api" AND status="completed"')->fetchColumn();
$salesValue  = (float)$pdo->query('SELECT COALESCE(SUM(total_price),0) FROM orders WHERE source="api" AND status="completed"')->fetchColumn();
$apiUsers    = (int)$pdo->query('SELECT COUNT(DISTINCT user_id) FROM api_logs')->fetchColumn();

json_ok([
    'summary' => [
        'total_orders' => $totalOrders,
        'keys_sold'    => $keysSold,
        'sales_value'  => $salesValue,
        'api_users'    => $apiUsers,
    ],
    'orders' => array_map(function ($r) {
        return [
            'id'                 => (int)$r['id'],
            'order_ref'          => $r['order_ref'],
            'username'           => $r['username'],
            'product'            => $r['product_name'],
            'duration'           => $r['duration'],
            'variant_id'         => $r['provider_variant_id'],
            'quantity'           => (int)$r['quantity'],
            'total_price'        => (float)$r['total_price'],
            'status'             => $r['status'],
            'upstream_order_id'  => $r['upstream_order_id'],
            'provider'           => provider_info(provider_normalize($r['provider_key'] ?? null))['label'],
            'created_at'         => $r['created_at'],
        ];
    }, $orders),
]);