<?php
declare(strict_types=1);
/*
 * v48: READ-ONLY. Shows every product/plan row that carries a given provider service id (active AND archived), with source, state and order counts.
 *   php tools/diagnose_service.php 279 280 286
 * Changes nothing. NOT executed by the author (no PHP/DB in the build sandbox): run it on staging/production.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/bootstrap.php';
$pdo = db();
$ids = array_values(array_filter(array_slice($argv ?? [], 1), 'ctype_digit'));
if (!$ids) { fwrite(STDERR, "usage: php tools/diagnose_service.php <service_id> [...]\n"); exit(1); }
$q = $pdo->query("SELECT pl.id AS plan_id, pl.duration, pl.provider_variant_id, pl.provider_api_version, pl.provider_price, pl.price, pl.enabled AS plan_enabled,
                         p.id AS pid, p.name, p.source, p.enabled, p.maintenance, p.archived_at,
                         (SELECT COUNT(*) FROM orders o WHERE o.plan_id = pl.id) AS orders_n
                  FROM product_plans pl JOIN products p ON p.id = pl.product_id
                  WHERE pl.source = 'api' AND pl.provider_key = '" . PriceSyncService::PROVIDER . "'");
$rows = $q->fetchAll();
foreach ($ids as $want) {
    echo "== service #$want\n"; $n = 0;
    foreach ($rows as $r) {
        $v = licencedash_parse_variant((string)$r['provider_variant_id']);
        if ($v === null || (string)$v['product_id'] !== $want) continue;
        $n++;
        printf("  card #%d \"%s\" [%s] %s | plan #%d \"%s\" raw_id=%s api=%s usd=%s inr=%s plan:%s | orders=%d\n",
            $r['pid'], $r['name'], $r['source'], $r['archived_at'] !== null ? 'ARCHIVED' : ((int)$r['enabled'] === 1 ? ((int)$r['maintenance'] === 1 ? 'active/maintenance' : 'active/live') : 'active/disabled'),
            $r['plan_id'], $r['duration'], $r['provider_variant_id'], $r['provider_api_version'] ?: '-', $r['provider_price'] ?? '-', $r['price'], (int)$r['plan_enabled'] === 1 ? 'on' : 'off', (int)$r['orders_n']);
    }
    if ($n === 0) echo "  not mapped on any card\n";
    elseif ($n > 1) echo "  NOTE: $n rows carry this id; more than one ACTIVE row would be a duplicate mapping.\n";
}
