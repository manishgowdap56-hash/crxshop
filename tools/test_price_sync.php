<?php
declare(strict_types=1);
/*
 * v55 SERVER-SIDE TEST of the Price Sync (needs PHP + MySQL, and a database you may write to: use STAGING, not production).
 *   php tools/test_price_sync.php
 * It creates ONE temporary category / product / 6 variants with provider ids 990001-990006, feeds the service a FAKE catalog through the CLI-only
 * RP_TEST_CATALOG hook (no provider call, no token), checks the results and deletes everything it created.
 * NOT executed by the author: the build sandbox had no PHP and no MySQL. Nothing below has been run.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('RP_TEST_MODE', true);
require_once __DIR__ . '/../includes/bootstrap.php';
$pdo = db(); $fail = 0;
function t(string $name, bool $ok, string $extra = ''): void { global $fail; echo ($ok ? '[PASS] ' : '[FAIL] ') . $name . ($ok || $extra === '' ? '' : '  ' . $extra) . "\n"; if (!$ok) $fail++; }
PriceSyncService::ensureSchema($pdo); PriceSyncService::ensurePriceSchema($pdo);

$pdo->prepare("INSERT INTO categories (name, slug, sort_order, enabled) VALUES ('ZZ_PRICE_SYNC_TEST', 'zz-price-sync-test', 9999, 0)")->execute();
$cat = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO products (category_id, name, enabled, maintenance) VALUES (:c, 'ZZ PRICE SYNC TEST', 0, 1)")->execute([':c' => $cat]);
$pid = (int)$pdo->lastInsertId();
$plans = [];   // provider id => [stored price, duration]
foreach ([990001 => ['29.99', 'W1'], 990002 => ['999.00', 'W2'], 990003 => ['105.00', 'W3'], 990004 => ['77.00', 'W4'], 990005 => ['88.00', 'W5'], 990006 => ['66.00', 'W6']] as $id => [$price, $dur]) {
    $pdo->prepare("INSERT INTO product_plans (product_id, duration, price, source, provider_variant_id, provider_key, price_manual) VALUES (:p, :d, :pr, 'api', :v, :k, 1)")
        ->execute([':p' => $pid, ':d' => $dur, ':pr' => $price, ':v' => (string)$id, ':k' => PriceSyncService::PROVIDER]);
    $plans[$id] = (int)$pdo->lastInsertId();
}
$price = static function (int $id) use ($pdo, $plans): string { $q = $pdo->prepare('SELECT price FROM product_plans WHERE id = :i'); $q->execute([':i' => $plans[$id]]); return (string)$q->fetchColumn(); };
$cards = static function (int $id) use ($pdo, $plans): int { $q = $pdo->prepare('SELECT COUNT(*) FROM provider_price_history WHERE plan_id = :i'); $q->execute([':i' => $plans[$id]]); return (int)$q->fetchColumn(); };

$GLOBALS['RP_TEST_CATALOG'] = static function (array $params): array {
    return ['ok' => true, 'data' => ['products' => [
        ['id' => 990001, 'name' => 'T 1', 'price' => '1.00'],          // wrong stored 29.99  -> 30.00
        ['id' => 990002, 'name' => 'T 2', 'price' => '2.00'],          // admin custom 999.00 -> 60.00
        ['id' => 990003, 'name' => 'T 3', 'price' => '3.50'],          // already 105.00
        ['id' => 990004, 'name' => 'T 4', 'price' => '0'],             // zero  -> keep 77.00
        ['id' => 990005, 'name' => 'T 5', 'price' => 'oops'],          // invalid -> keep 88.00
        // 990006 missing from the catalog -> mapping error, keep 66.00
    ]]];
};
$r = PriceSyncService::tick($pdo, 'test', true, 1);
t('cycle ran', in_array($r['status'], ['ok', 'partial'], true), $r['status'] . ' ' . $r['message']);
t('$1 -> 30.00 (wrong price corrected)', $price(990001) === '30.00', $price(990001));
t('$2 -> 60.00 (admin custom price corrected)', $price(990002) === '60.00', $price(990002));
t('$3.50 -> 105.00 already correct, unchanged', $price(990003) === '105.00');
t('zero provider price keeps stored price', $price(990004) === '77.00');
t('invalid provider price keeps stored price', $price(990005) === '88.00');
t('variant missing from catalog keeps stored price', $price(990006) === '66.00');
t('price_manual cleared on corrected rows', (int)$pdo->query('SELECT price_manual FROM product_plans WHERE id = ' . $plans[990002])->fetchColumn() === 0);
t('2 history cards written (corrected only)', $cards(990001) === 1 && $cards(990002) === 1 && $cards(990003) === 0 && $cards(990004) === 0);
t('counts: 2 updated, 1 already correct, 2 invalid, 1 mapping error', $r['counts']['updated'] === 2 && $r['counts']['already_correct'] === 1 && $r['counts']['invalid_price'] === 2 && $r['counts']['mapping_errors'] >= 1, json_encode($r['counts']));
$r2 = PriceSyncService::tick($pdo, 'test', true, 1);
t('second cycle: nothing to correct, no duplicate cards', $r2['counts']['updated'] === 0 && $cards(990001) === 1 && $cards(990002) === 1);

$pdo->prepare('UPDATE product_plans SET price = 5.00 WHERE id = :i')->execute([':i' => $plans[990003]]);      // break a price again
$GLOBALS['RP_TEST_CATALOG'] = static function (array $params): array { throw new RuntimeException('simulated provider outage'); };
$r3 = PriceSyncService::tick($pdo, 'test', true, 1);
t('provider outage: cycle failed, nothing changed', $r3['status'] === 'failed' && $price(990003) === '5.00' && $price(990001) === '30.00', $r3['status'] . ' ' . $price(990003));

$GLOBALS['RP_TEST_CATALOG'] = static function (array $params): array { return ['ok' => true, 'data' => ['products' => [['id' => 990003, 'name' => 'T 3', 'price' => '3.50']]]]; };
$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
$other = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$other->query("SELECT GET_LOCK('" . PriceSyncService::LOCK . "', 0)")->fetchColumn();            // another worker is mid-cycle
$r4 = PriceSyncService::tick($pdo, 'test', true, 1);
t('overlapping cycle is skipped while another holds the lock', $r4['status'] === 'skipped' && $price(990003) === '5.00', $r4['status']);
$other->query("SELECT RELEASE_LOCK('" . PriceSyncService::LOCK . "')")->fetchColumn();
$r5 = PriceSyncService::tick($pdo, 'test', true, 1);
t('after the lock is free the price is corrected (3.50 x 30 = 105.00)', $price(990003) === '105.00', $price(990003));

// the storefront / purchase code reads product_plans.price directly: the corrected value is what api/products.php returns
$q = $pdo->prepare('SELECT price FROM product_plans WHERE id = :i'); $q->execute([':i' => $plans[990001]]);
t('storefront source column holds the corrected price', (string)$q->fetchColumn() === '30.00');

$pdo->prepare('DELETE FROM provider_price_history WHERE product_id = :p')->execute([':p' => $pid]);
$pdo->prepare('DELETE FROM sync_logs WHERE product_id = :p')->execute([':p' => $pid]);
$pdo->prepare('DELETE FROM products WHERE id = :p')->execute([':p' => $pid]);          // plans cascade
$pdo->prepare('DELETE FROM categories WHERE id = :c')->execute([':c' => $cat]);
echo $fail === 0 ? "ALL PASSED\n" : "FAILED: $fail\n";
exit($fail === 0 ? 0 : 1);
