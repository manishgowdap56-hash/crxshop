<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_admin();

$pdo = db();

$productId = input_int('product_id');
$where  = 'WHERE o.source = "website" AND o.status = "completed"';
$params = [];
if ($productId > 0) {
    $where .= ' AND o.product_id = :pid';
    $params[':pid'] = $productId;
}

$stmt = $pdo->prepare(
    "SELECT o.id, o.order_ref, o.quantity, o.unit_price, o.total_price, o.created_at,
            u.username, p.name AS product_name, pl.duration
     FROM orders o
     JOIN users u          ON u.id = o.user_id
     JOIN products p       ON p.id = o.product_id
     JOIN product_plans pl ON pl.id = o.plan_id
     $where
     ORDER BY o.id DESC LIMIT 500"
);
$stmt->execute($params);
$orders = $stmt->fetchAll();

// Summary
$siteOrders = (int)$pdo->query('SELECT COUNT(*) FROM orders WHERE source="website" AND status="completed"')->fetchColumn();
$siteKeys   = (int)$pdo->query('SELECT COALESCE(SUM(quantity),0) FROM orders WHERE source="website" AND status="completed"')->fetchColumn();
$siteValue  = (float)$pdo->query('SELECT COALESCE(SUM(total_price),0) FROM orders WHERE source="website" AND status="completed"')->fetchColumn();
$available  = (int)$pdo->query('SELECT COUNT(*) FROM product_keys WHERE status="available"')->fetchColumn();

// Local inventory
$inventory = $pdo->query(
    'SELECT c.name AS category_name, p.name AS product_name, pl.duration, pl.price,
            (SELECT COUNT(*) FROM product_keys k WHERE k.plan_id = pl.id AND k.status = "available") AS available,
            (SELECT COUNT(*) FROM product_keys k WHERE k.plan_id = pl.id AND k.status = "delivered") AS delivered
     FROM product_plans pl
     JOIN products p   ON p.id = pl.product_id
     JOIN categories c ON c.id = p.category_id
     WHERE pl.source = "local"
     ORDER BY c.sort_order, p.name, pl.sort_order'
)->fetchAll();

json_ok([
    'summary' => [
        'total_orders'   => $siteOrders,
        'keys_sold'      => $siteKeys,
        'sales_value'    => $siteValue,
        'available_stock'=> $available,
    ],
    'orders' => array_map(function ($r) {
        return [
            'id'          => (int)$r['id'],
            'order_ref'   => $r['order_ref'],
            'username'    => $r['username'],
            'product'     => $r['product_name'],
            'duration'    => $r['duration'],
            'quantity'    => (int)$r['quantity'],
            'unit_price'  => (float)$r['unit_price'],
            'total_price' => (float)$r['total_price'],
            'created_at'  => $r['created_at'],
        ];
    }, $orders),
    'inventory' => array_map(function ($r) {
        return [
            'category'   => $r['category_name'],
            'product'    => $r['product_name'],
            'duration'   => $r['duration'],
            'price'      => (float)$r['price'],
            'available'  => (int)$r['available'],
            'delivered'  => (int)$r['delivered'],
        ];
    }, $inventory),
]);