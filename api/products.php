<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
$__u = require_user();
$__cat = dual_user_type(db(), (int)$__u['id']);   // v61: price category from the DATABASE role of the authenticated user (never from the request)
// v53: prices must never be served from a browser / proxy / CDN cache. The authoritative price is always the database row.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$cats = db()->query(
    'SELECT id, name FROM categories WHERE enabled = 1 ORDER BY sort_order, name'
)->fetchAll();

$prodsStmt = db()->query(
    'SELECT p.id, p.category_id, p.name, p.maintenance, p.description, p.image_path, p.video_path, p.features
     FROM products p
     WHERE p.enabled = 1 AND p.archived_at IS NULL
     ORDER BY p.sort_order ASC, p.id ASC'
);
$products = $prodsStmt->fetchAll();

$plansStmt = db()->query(
    'SELECT pl.id, pl.product_id, pl.duration, pl.price, pl.reseller_price, pl.source, pl.provider_variant_id, pl.provider_api_version, pl.provider_key, pl.allow_quantity,
            (SELECT COUNT(*) FROM product_keys k WHERE k.plan_id = pl.id AND k.status = "available") AS stock
     FROM product_plans pl
     WHERE pl.enabled = 1
     ORDER BY pl.sort_order, pl.id'
);
$plans = $plansStmt->fetchAll();

$plansByProduct = [];
foreach ($plans as $pl) {
    $plansByProduct[(int)$pl['product_id']][] = [
        'id'          => (int)$pl['id'],
        'duration'    => $pl['duration'],
        'price'       => dual_pick_price($pl, $__cat),   // v61: Normal price for normal users, Reseller price for resellers - the other one is never sent
        'source'      => $pl['source'],
        'stock'       => $pl['source'] === 'api' ? null : (int)$pl['stock'],
        'has_variant' => $pl['source'] === 'api' ? ($pl['provider_variant_id'] !== null && $pl['provider_variant_id'] !== '') : true,
        'allow_quantity' => (int)($pl['allow_quantity'] ?? 1) === 1,
        // Device-bound (v1) Licence Dashboard plans need an Android ID at checkout. Plan-level flag only: no provider data is exposed.
        'needs_android_id' => $pl['source'] === 'api'
            && provider_normalize($pl['provider_key'] ?? null) === PROVIDER_LICENCEDASH
            && (licencedash_parse_variant(licencedash_plan_variant($pl))['mode'] ?? '') === 'v1',
    ];
}

$prodsByCat = [];
foreach ($products as $pr) {
    $features = product_features_decode($pr['features'] ?? '');
    $legacyDesc = trim((string)($pr['description'] ?? ''));
    if (!$features && $legacyDesc !== '') {
        $features = product_features_clean([['header' => '', 'items' => preg_split('/\r?\n/', $legacyDesc)]]);
    }
    $prodsByCat[(int)$pr['category_id']][] = [
        'id'          => (int)$pr['id'],
        'name'        => $pr['name'],
        'maintenance' => (int)$pr['maintenance'] === 1,
        'image_url'   => product_image_url($pr['image_path'] ?? ''),
        'video_url'   => product_video_url($pr['video_path'] ?? ''),
        'features'    => $features,
        'plans'       => $plansByProduct[(int)$pr['id']] ?? [],
    ];
}

$out = [];
foreach ($cats as $c) {
    $out[] = [
        'id'       => (int)$c['id'],
        'name'     => $c['name'],
        'products' => $prodsByCat[(int)$c['id']] ?? [],
    ];
}

json_ok([
    'categories' => $out,
    'links'      => [
        'update_file' => store_link_setting('store_update_link'),
        'feedback'    => store_link_setting('store_feedback_link'),
    ],
    'balance'    => (float)current_user()['balance'],
]);