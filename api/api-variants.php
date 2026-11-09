<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
$__u = require_user();
dual_require_api_access($__u, false);   // v62: the API page's variant list is Reseller / Admin only
$__cat = dual_user_type(db(), (int)$__u['id']);   // v61
// v55: prices must never come from a browser / proxy / CDN cache. The authoritative price is always the database row.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$stmt = db()->query(
    'SELECT pl.id AS variant_id, pl.duration, pl.price, pl.reseller_price, pl.source, pl.allow_quantity,
            p.name AS product_name, c.name AS category_name,
            (SELECT COUNT(*) FROM product_keys k WHERE k.plan_id = pl.id AND k.status = "available") AS stock
     FROM product_plans pl
     JOIN products p   ON p.id = pl.product_id
     JOIN categories c ON c.id = p.category_id
     WHERE pl.enabled = 1 AND p.enabled = 1
     ORDER BY c.sort_order, c.name, p.sort_order, p.name, pl.sort_order, pl.id'
);
$rows = $stmt->fetchAll();

$grouped = [];
foreach ($rows as $r) {
    $key = $r['category_name'] . '||' . $r['product_name'];
    if (!isset($grouped[$key])) {
        $grouped[$key] = [
            'category' => $r['category_name'],
            'product'  => $r['product_name'],
            'variants' => [],
        ];
    }
    $inStock = $r['source'] === 'api' ? true : ((int)$r['stock'] > 0);
    $grouped[$key]['variants'][] = [
        'variant_id' => (int)$r['variant_id'],
        'duration'   => $r['duration'],
        'price'      => dual_pick_price($r, $__cat),
        'source'     => $r['source'],
        'in_stock'   => $inStock,
        'allow_quantity' => (int)($r['allow_quantity'] ?? 1) === 1,
    ];
}

json_ok(['groups' => array_values($grouped)]);