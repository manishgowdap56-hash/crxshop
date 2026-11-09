<?php
declare(strict_types=1);
/**
 * Provider 5 (LICENCE DASHBOARD) + per-duration ON/OFF verification helper. CLI only, never reachable over HTTP.
 *
 *   php tools/verify_licencedashboard.php                       -> settings, logic self-tests, duration ON/OFF invariants (sends nothing)
 *   php tools/verify_licencedashboard.php --balance             -> + GET balance.php (read-only, no key is generated)
 *   php tools/verify_licencedashboard.php --catalog             -> + GET catalog.php (read-only), prints the real structure
 *   php tools/verify_licencedashboard.php --generate 76 1       -> places ONE REAL generate.php order v2 (costs provider balance!)
 *   php tools/verify_licencedashboard.php --generate 1 1 --v1 ANDROIDID   -> ONE REAL device-bound order
 *
 * Secrets are never printed. Keys returned by --generate are shown only as length (first 4 chars masked rest).
 * It does not touch the wallet, orders or products.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/bootstrap.php';

$fail = 0;
$check = static function (string $name, bool $cond) use (&$fail): void {
    echo ($cond ? '  PASS  ' : '  FAIL  ') . $name . "\n";
    if (!$cond) $fail++;
};

$i = provider_info(PROVIDER_LICENCEDASH);
echo "== Provider 5 settings ==\n";
echo 'label:      ' . $i['label'] . "\n";
echo 'enabled:    ' . ($i['enabled'] ? 'yes' : 'NO') . "\n";
echo 'configured: ' . ($i['configured'] ? 'yes (url + token saved)' : 'NO') . "\n";
echo 'url:        ' . get_setting('api5_endpoint', '') . "\n";
echo 'max qty:    ' . $i['max_quantity'] . " (website limit, not the provider's)\n";
$n = (int)db()->query("SELECT COUNT(*) FROM product_plans WHERE source='api' AND provider_key='" . PROVIDER_LICENCEDASH . "'")->fetchColumn();
echo "plans mapped to Provider 5: $n\n";
foreach (db()->query("SELECT p.name, pl.duration, pl.provider_variant_id, pl.enabled FROM product_plans pl JOIN products p ON p.id=pl.product_id WHERE pl.source='api' AND pl.provider_key='" . PROVIDER_LICENCEDASH . "' ORDER BY p.id, pl.id LIMIT 30") as $r) {
    echo '  - ' . $r['name'] . ' / ' . $r['duration'] . '  product_id=' . $r['provider_variant_id'] . '  status=' . ((int)$r['enabled'] === 1 ? 'ON' : 'OFF') . "\n";
}

echo "\n== v1 / v2 / both decision logic (no network) ==\n";
$check('registry has 5 providers, provider 5 is last', provider_keys() === ['primary', 'keypanel', 'panelstore', 'bantibhaiya', 'licencedashboard']);
$check('unknown provider key still means primary', provider_normalize('nope') === PROVIDER_PRIMARY && provider_normalize(null) === PROVIDER_PRIMARY);
$check('"76" parses as product 76, mode v2', licencedash_parse_variant('76') === ['product_id' => 76, 'mode' => 'v2']);
$check('"76|v1" parses as v1', (licencedash_parse_variant('76|v1')['mode'] ?? '') === 'v1');
$check('"76|BOTH" parses as both', (licencedash_parse_variant('76|BOTH')['mode'] ?? '') === 'both');
$check('garbage / zero / letters are rejected', licencedash_parse_variant('abc') === null && licencedash_parse_variant('0') === null && licencedash_parse_variant('76|v3') === null && licencedash_parse_variant('') === null);
$r = licencedash_resolve('76', '', '');
$check('v2: no android_id needed, v1 not used', $r['error'] === null && $r['use_v1'] === false && $r['android_id'] === '');
$r = licencedash_resolve('76', 'v1', 'abc123');
$check('v2 product: v1 cannot be forced, android_id never sent', $r['error'] === null && $r['use_v1'] === false && $r['android_id'] === '');
$r = licencedash_resolve('1|v1', '', '');
$check('v1 WITHOUT android_id is rejected', $r['error'] !== null && $r['use_v1'] === true);
$r = licencedash_resolve('1|v1', '', "  abc123def456 ");
$check('v1 with android_id: v1 + trimmed id', $r['error'] === null && $r['use_v1'] === true && $r['android_id'] === 'abc123def456');
$r = licencedash_resolve('1|v1', '', 'has space');
$check('v1 with invalid android_id is rejected', $r['error'] !== null);
$r = licencedash_resolve('1|both', '', 'abc123');
$check('both: defaults to v2 unless v1 explicitly selected', $r['error'] === null && $r['use_v1'] === false);
$r = licencedash_resolve('1|both', 'v1', '');
$check('both + explicit v1 without android_id is rejected', $r['error'] !== null);
$r = licencedash_resolve('1|both', 'v1', 'abc123def456');
$check('both + explicit v1 + android_id: v1', $r['error'] === null && $r['use_v1'] === true);
$r = licencedash_resolve('76', 'v9', '');
$check('invalid api_version is rejected', $r['error'] !== null);
$check('success needs ok===true AND real keys (shape check)', licencedash_extract_keys(['ok' => true, 'data' => ['keys' => ['AAA-111', ' ', 'BBB-222']]]) === ['AAA-111', 'BBB-222']
    && licencedash_extract_keys(['ok' => true, 'data' => ['keys' => []]]) === []
    && licencedash_extract_keys(['ok' => true]) === []);
$check('error message read from error.message', licencedash_error_message(['ok' => false, 'error' => ['message' => 'Insufficient balance']], 400) === 'Insufficient balance');

echo "\n== Per-duration ON/OFF (database) ==\n";
$pdo = db();
$hasCol = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='product_plans' AND COLUMN_NAME='enabled'")->fetchColumn() === 1;
$check('product_plans.enabled column exists', $hasCol);
$tot = (int)$pdo->query('SELECT COUNT(*) FROM product_plans')->fetchColumn();
$off = (int)$pdo->query('SELECT COUNT(*) FROM product_plans WHERE enabled = 0')->fetchColumn();
echo "plans total: $tot   currently OFF: $off\n";
// The exact filter used by api/products.php, api/api-variants.php and the gateway variants list:
$shown = (int)$pdo->query('SELECT COUNT(*) FROM product_plans pl WHERE pl.enabled = 1')->fetchColumn();
$check('Store catalog filter returns exactly the ON plans (' . $shown . ' of ' . $tot . ')', $shown === $tot - $off);
$leak = (int)$pdo->query('SELECT COUNT(*) FROM product_plans pl JOIN products p ON p.id = pl.product_id WHERE pl.enabled = 0 AND p.enabled = 1')->fetchColumn();
echo "OFF plans whose product stays visible in the Store: $leak\n";

foreach (['--balance', '--catalog'] as $flag) {
    if (!in_array($flag, $argv, true)) continue;
    echo "\n== $flag (read-only) ==\n";
    if (!$i['configured']) { echo "Provider 5 is not configured.\n"; $fail++; continue; }
    try {
        $ep = $flag === '--balance' ? 'balance.php' : 'catalog.php';
        $res = licencedash_request($ep, [], 'GET', 20);
        echo 'HTTP ' . $res['http'] . '  ok=' . var_export($res['json']['ok'] ?? null, true) . "\n";
        $check($ep . ' answered with ok === true', ($res['json']['ok'] ?? null) === true);
        $js = (string)json_encode($res['json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        echo mb_substr($js, 0, 3000) . (mb_strlen($js) > 3000 ? "\n... (truncated)\n" : "\n");
    } catch (Throwable $e) { echo 'FAILED: ' . $e->getMessage() . "\n"; $fail++; }
}

$gi = array_search('--generate', $argv, true);
if ($gi !== false) {
    $pid = (int)($argv[$gi + 1] ?? 0); $qty = (int)($argv[$gi + 2] ?? 1);
    $vi = array_search('--v1', $argv, true);
    $android = $vi !== false ? (string)($argv[$vi + 1] ?? '') : '';
    echo "\n== --generate (REAL ORDER at the provider) ==\n";
    if ($pid < 1 || $qty < 1) { fwrite(STDERR, "usage: --generate PRODUCT_ID QTY [--v1 ANDROID_ID]\n"); exit(2); }
    if (!$i['configured']) { fwrite(STDERR, "Provider 5 is not configured.\n"); exit(2); }
    $variant = $pid . ($vi !== false ? '|v1' : '');
    $out = licencedash_generate($variant, $qty, ['api_version' => $vi !== false ? 'v1' : 'v2', 'android_id' => $android, 'idempotency_key' => 'verify-' . bin2hex(random_bytes(6))]);
    echo 'ok: ' . var_export($out['ok'], true) . "\n";
    if ($out['ok']) {
        echo 'keys returned: ' . count($out['keys']) . "\n";
        foreach ($out['keys'] as $k) echo '  - length ' . strlen($k) . '  ' . substr($k, 0, 4) . str_repeat('*', max(0, strlen($k) - 4)) . "\n";
        $check('a real key was returned', count($out['keys']) >= 1);
    } else {
        echo 'error: ' . $out['error'] . "\n";
    }
}

echo "\n" . ($fail === 0 ? "ALL CHECKS PASSED\n" : "$fail CHECK(S) FAILED\n");
exit($fail === 0 ? 0 : 1);
