<?php
declare(strict_types=1);
/*
 * v57 SERVER-SIDE TEST of Create Product name + duration -> provider id (needs PHP + MySQL; use STAGING). php tools/test_find_variant.php
 * Fake catalog through the CLI-only RP_TEST_CATALOG hook (no provider call). NOT executed by the author (no PHP in the build sandbox).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('RP_TEST_MODE', true);
require_once __DIR__ . '/../includes/bootstrap.php';
$pdo = db(); $fail = 0;
function t(string $n, bool $ok, string $x = ''): void { global $fail; echo ($ok ? '[PASS] ' : '[FAIL] ') . $n . ($ok || $x === '' ? '' : '  ' . $x) . "\n"; if (!$ok) $fail++; }
$GLOBALS['RP_TEST_CATALOG'] = static fn(array $p): array => ['ok' => true, 'data' => ['products' => [
    ['id' => 990201, 'name' => 'ZZMOD 1 Day', 'price' => '0.70'], ['id' => 990202, 'name' => 'ZZMOD 3 Days', 'price' => '1.50'], ['id' => 990203, 'name' => 'ZZMOD 7 Days', 'price' => '3.00'],
    ['id' => 990211, 'name' => 'ZZPC SILENT 1 Day', 'price' => '0.20'], ['id' => 990212, 'name' => 'ZZPC SILENT 10 Days', 'price' => '2.00'],
    ['id' => 990221, 'name' => 'ZZDUP', 'duration' => '7 Days', 'price' => '1'], ['id' => 990222, 'name' => 'ZZDUP', 'duration' => '7 Days', 'price' => '2'],
]]];
$f = static fn(string $n, string $d): array => PriceSyncService::findVariants($pdo, $n, $d);
$r = $f(' zzmod ', '7 days'); t('1/4 name+duration -> id 990203, 90.00', $r['status'] === 'match' && $r['candidates'][0]['id'] === '990203' && $r['candidates'][0]['inr'] === '90.00', json_encode($r));
t('2   duration change 3 Days -> 990202', $f('ZZMOD', '3 Days')['candidates'][0]['id'] === '990202');
t('    ZZPC SILENT 1 Day -> 990211 (not the 10-day id), 6.00', ($x = $f('ZZPC SILENT', '1 Day'))['candidates'][0]['id'] === '990211' && $x['candidates'][0]['inr'] === '6.00');
t('5   unknown name -> none, nothing guessed', $f('ZZMO', '7 Days')['status'] === 'none' && $f('ZZMO', '7 Days')['candidates'] === []);
t('5   ambiguous -> admin must choose', ($x = $f('ZZDUP', '7 Days'))['status'] === 'ambiguous' && count($x['candidates']) === 2);
t('    missing duration for that product -> none', $f('ZZMOD', '30 Days')['status'] === 'none');
$GLOBALS['RP_TEST_CATALOG'] = static function (array $p): array { throw new RuntimeException('simulated outage'); };
try { $f('ZZMOD', '7 Days'); t('provider outage raises 502', false); } catch (RuntimeException $e) { t('provider outage raises 502', $e->getCode() === 502, $e->getMessage()); }
echo $fail === 0 ? "ALL PASSED\n" : "FAILED: $fail\n"; exit($fail === 0 ? 0 : 1);
