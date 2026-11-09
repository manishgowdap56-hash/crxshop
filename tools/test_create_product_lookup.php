<?php
declare(strict_types=1);
/*
 * v56 SERVER-SIDE TEST of Create Product -> provider price lookup (needs PHP + MySQL; use STAGING).
 *   php tools/test_create_product_lookup.php
 * Feeds PriceSyncService::lookupVariant() a FAKE catalog through the CLI-only RP_TEST_CATALOG hook (no provider call, no token) and checks tests A-E;
 * then creates one temporary product plan with provider id 990101 and checks that the scheduled sync (same engine) recognises and prices it (test G).
 * NOT executed by the author: the build sandbox had no PHP, no MySQL, no network. Nothing below has been run.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('RP_TEST_MODE', true);
require_once __DIR__ . '/../includes/bootstrap.php';
$pdo = db(); $fail = 0;
function t(string $n, bool $ok, string $x = ''): void { global $fail; echo ($ok ? '[PASS] ' : '[FAIL] ') . $n . ($ok || $x === '' ? '' : '  ' . $x) . "\n"; if (!$ok) $fail++; }
PriceSyncService::ensureSchema($pdo); PriceSyncService::ensurePriceSchema($pdo);

$GLOBALS['RP_TEST_CATALOG'] = static fn(array $p): array => ['ok' => true, 'data' => ['products' => [
    ['id' => 990101, 'name' => 'Provider Name A 7 Days', 'price' => '1.50'],
    ['id' => 990102, 'name' => 'Provider Name B 30 Days', 'price' => '3.00'],
    ['id' => 990103, 'name' => 'Zero', 'price' => '0'],
    ['id' => 990104, 'name' => 'EUR', 'price' => '2.00', 'currency' => 'EUR'],
    ['id' => 990105, 'name' => 'INR', 'price' => '45.00', 'currency' => 'INR'],
    ['id' => 990106, 'name' => 'Dup', 'price' => '1.00'], ['id' => 990106, 'name' => 'Dup', 'price' => '2.00'],
]]];
$ok = static function (string $id) use ($pdo): array { return PriceSyncService::lookupVariant($pdo, $id, 1); };
$err = static function (string $id) use ($pdo): array { try { PriceSyncService::lookupVariant($pdo, $id, 1); return [0, '']; } catch (RuntimeException $e) { return [$e->getCode(), $e->getMessage()]; } };

$r = $ok('990101');  t('A  id lookup is independent of the local product name', $r['id'] === '990101' && $r['name'] === 'Provider Name A 7 Days');
t('B  $1.50 -> 45.00', $r['inr'] === '45.00' && $r['currency'] === 'USD' && $r['rate'] === '30', json_encode($r));
t('C  a different id refreshes: $3.00 -> 90.00', $ok('990102')['inr'] === '90.00');
t('INR price is used as is (not x30)', $ok('990105')['inr'] === '45.00' && $ok('990105')['currency'] === 'INR');
[$c, $m] = $err('999999'); t('D  unknown id -> 422, no price', $c === 422, "$c $m");
[$c, $m] = $err('abc');    t('D  non-numeric id -> 422', $c === 422, "$c $m");
[$c, $m] = $err('990103'); t('zero price refused', $c === 422, "$c $m");
[$c, $m] = $err('990104'); t('unsupported currency refused', $c === 422, "$c $m");
[$c, $m] = $err('990106'); t('id listed twice with different prices refused', $c === 422, "$c $m");
$GLOBALS['RP_TEST_CATALOG'] = static function (array $p): array { throw new RuntimeException('simulated provider outage'); };
[$c, $m] = $err('990101'); t('E  provider failure -> 502, no price claimed', $c === 502, "$c $m");

// G: a plan created exactly like Create Product saves it is picked up by the scheduled sync and priced by the same rule
$GLOBALS['RP_TEST_CATALOG'] = static fn(array $p): array => ['ok' => true, 'data' => ['products' => [['id' => 990101, 'name' => 'X', 'price' => '1.50']]]];
$pdo->prepare("INSERT INTO categories (name, slug, sort_order, enabled) VALUES ('ZZ_LOOKUP_TEST', 'zz-lookup-test', 9999, 0)")->execute(); $cat = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO products (category_id, name, enabled, maintenance) VALUES (:c, 'MY CUSTOM PRODUCT', 0, 1)")->execute([':c' => $cat]); $pid = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO product_plans (product_id, duration, price, source, provider_variant_id, provider_key, provider_api_version) VALUES (:p, '7 DAY', 10.00, 'api', '990101', :k, 'v2')")->execute([':p' => $pid, ':k' => PriceSyncService::PROVIDER]);
$plan = (int)$pdo->lastInsertId();
$r = PriceSyncService::tick($pdo, 'test', true, 1);
$p = $pdo->query('SELECT price FROM product_plans WHERE id = ' . $plan)->fetchColumn();
t('G  scheduled sync recognises the new plan and sets 45.00', (string)$p === '45.00', $r['status'] . ' ' . $r['message'] . ' price=' . $p);
$pdo->prepare('DELETE FROM provider_price_history WHERE product_id = :p')->execute([':p' => $pid]);
$pdo->prepare('DELETE FROM sync_logs WHERE product_id = :p')->execute([':p' => $pid]);
$pdo->prepare('DELETE FROM products WHERE id = :p')->execute([':p' => $pid]);
$pdo->prepare('DELETE FROM categories WHERE id = :c')->execute([':c' => $cat]);
echo $fail === 0 ? "ALL PASSED\n" : "FAILED: $fail\n"; exit($fail === 0 ? 0 : 1);
