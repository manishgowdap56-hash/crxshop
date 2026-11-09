<?php
declare(strict_types=1);
/**
 * ZapUPI verification self-test. RUN ON THE SERVER (CLI):   php tools/zapupi_selftest.php
 *
 * Runs the REAL production function zapupi_judge_order_status() / zapupi_unwrap_order_status() / zapupi_paise() against
 * fixtures that use the response layout ZapUPI documents for POST /order-status:
 *     { "status": "success", "data": { "order_id", "status": "Pending|Success|Failed", "amount", "pay_amount", "txn_id", "utr", "environment" } }
 * No HTTP call, no wallet or deposit write, no payment. Exit code 0 = every case behaved as expected, 1 = something is wrong.
 * (It loads the normal bootstrap, so it needs your config/database.php to work, exactly like the cron job.)
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/bootstrap.php';

$fail = 0; $n = 0;
$check = static function (string $name, bool $cond, string $detail = '') use (&$fail, &$n): void {
    $n++; if (!$cond) $fail++;
    echo ($cond ? 'PASS ' : 'FAIL ') . $name . ($cond ? '' : '   <-- ' . $detail) . "\n";
};

$orderId = 'ZP0123456789ABCDEF0123';
$dep = ['order_ref' => $orderId, 'gateway_txn_id' => 'ZAPUPI66f10bd3c1f28', 'amount' => '20.00', 'method' => 'zapupi', 'gateway' => 'zapupi'];
$local = 2000;   // paise
$paid = ['order_id' => $orderId, 'status' => 'Success', 'amount' => '20.00', 'pay_amount' => '20.00',
         'txn_id' => 'ZAPUPI66f10bd3c1f28', 'utr' => '443455455550', 'environment' => 'cashier', 'create_at' => '2026-11-08 08:33:55'];
$wrap = static fn(array $data, string $env = 'success'): array => ['status' => $env, 'data' => $data];
$judge = static fn(array $resp, bool $allowTest = false, ?array $d = null, ?int $lp = null): array =>
    zapupi_judge_order_status($d ?? $dep, $orderId, $lp ?? $local, $resp, $allowTest);

// ---- the exact bug: envelope "success" + nested data ------------------------------------------------
$r = $judge($wrap($paid));
$check('REAL LAYOUT: envelope success + data.status Success -> success', $r['result'] === 'success', $r['result'] . '/' . $r['reason']);
$check('  verified payload carries txn, utr, env, amount', ($r['verified']['utr'] ?? '') === '443455455550' && ($r['verified']['txn_id'] ?? '') === 'ZAPUPI66f10bd3c1f28' && ($r['verified']['amount_paise'] ?? 0) === 2000);
$r = $judge($wrap(['status' => 'Pending'] + $paid));
$check('data.status Pending -> pending (not verification_failed)', $r['result'] === 'pending' && $r['reason'] === 'provider_status_pending', $r['result'] . '/' . $r['reason']);
$r = $judge($wrap(['status' => 'Failed', 'utr' => ''] + $paid));
$check('data.status Failed -> failed', $r['result'] === 'failed', $r['result'] . '/' . $r['reason']);
$r = $judge(['status' => 'error', 'message' => 'Order not found']);
$check('error envelope (order unknown) -> pending, nothing verified', $r['result'] === 'pending' && $r['verified'] === null, $r['result'] . '/' . $r['reason']);
$r = $judge(['status' => 'success']);
$check('success envelope WITHOUT data and without order_id -> pending (never credits)', $r['result'] === 'pending' && $r['reason'] === 'provider_no_payment_data', $r['result'] . '/' . $r['reason']);
$r = $judge($paid);
$check('tolerance: flat layout that carries order_id -> success', $r['result'] === 'success', $r['result'] . '/' . $r['reason']);
$r = $judge(['status' => 'success', 'data' => [$paid]]);
$check('tolerance: data as a one-element list -> success', $r['result'] === 'success', $r['result'] . '/' . $r['reason']);
$r = $judge($wrap(['status' => 'SUCCESS'] + $paid));
$check('status matching is case-insensitive', $r['result'] === 'success', $r['result'] . '/' . $r['reason']);

// ---- strict matching -----------------------------------------------------------------------------------
$r = $judge($wrap(['order_id' => 'ZPOTHER'] + $paid));
$check('wrong order id -> verification_failed', $r['result'] === 'verification_failed' && $r['reason'] === 'order_id_mismatch', $r['result'] . '/' . $r['reason']);
$r = $judge($wrap(['order_id' => ''] + $paid));
$check('missing order id -> verification_failed', $r['result'] === 'verification_failed', $r['result'] . '/' . $r['reason']);
$r = $judge($wrap(['amount' => '19.00'] + $paid));
$check('amount mismatch -> verification_failed', $r['result'] === 'verification_failed' && $r['reason'] === 'amount_mismatch', $r['result'] . '/' . $r['reason']);
$r = $judge($wrap(['pay_amount' => '10.00'] + $paid));
$check('pay_amount lower than order -> verification_failed', $r['result'] === 'verification_failed', $r['result'] . '/' . $r['reason']);
$r = $judge($wrap(['pay_amount' => '20.37'] + $paid));
$check('pay_amount slightly higher -> success, order amount credited', $r['result'] === 'success' && ($r['verified']['amount_paise'] ?? 0) === 2000, $r['result'] . '/' . $r['reason']);
$r = $judge($wrap(['utr' => ''] + $paid));
$check('missing utr -> verification_failed', $r['result'] === 'verification_failed' && $r['reason'] === 'utr_missing', $r['result'] . '/' . $r['reason']);
$r = $judge($wrap(['txn_id' => 'ZAPUPIsomethingelse'] + $paid));
$check('txn id differs from the one stored at create -> verification_failed', $r['result'] === 'verification_failed' && $r['reason'] === 'txn_id_mismatch', $r['result'] . '/' . $r['reason']);
$r = $judge($wrap(['environment' => 'test'] + $paid));
$check('test environment on a live site -> test_ignored', $r['result'] === 'test_ignored', $r['result'] . '/' . $r['reason']);
$r = $judge($wrap(['txn_id' => 'DUMMY12345', 'environment' => 'cashier'] + $paid), false, ['gateway_txn_id' => ''] + $dep);
$check('DUMMY txn on a live site -> test_ignored', $r['result'] === 'test_ignored', $r['result'] . '/' . $r['reason']);
$r = $judge($wrap(['environment' => 'mystery'] + $paid));
$check('unknown environment -> verification_failed', $r['result'] === 'verification_failed' && $r['reason'] === 'environment_invalid', $r['result'] . '/' . $r['reason']);
foreach (['50.00' => 5000, '100.00' => 10000, '500.00' => 50000, '1000.00' => 100000, '20.5' => 2050] as $amt => $p) {
    $r = zapupi_judge_order_status(['gateway_txn_id' => ''] + $dep, $orderId, $p, $wrap(['amount' => $amt, 'pay_amount' => $amt] + $paid), false);
    $check('custom amount ' . $amt . ' verifies against local amount', $r['result'] === 'success', $r['result'] . '/' . $r['reason']);
}

// ---- helpers -----------------------------------------------------------------------------------------
$check('paise: 20 / "20.00" / "20.5" / 19.99', [zapupi_paise(20), zapupi_paise('20.00'), zapupi_paise('20.5'), zapupi_paise(19.99)] === [2000, 2000, 2050, 1999]);
$check('paise: rejects garbage', zapupi_paise('abc') === null && zapupi_paise('-5') === null && zapupi_paise('0.001') === null && zapupi_paise(null) === null);
$ref = 'ZP' . str_repeat('AB12', 5);
$save = [$_GET, $_SERVER['QUERY_STRING'] ?? null];
$_GET = ['order_id' => $ref]; $_SERVER['QUERY_STRING'] = 'order_id=' . $ref . '&utr=1';
$check('return URL: ?order_id= (ZapUPI documented form)', zapupi_order_from_request() === $ref);
$_GET = ['order' => $ref . '?order_id=' . $ref, 'utr' => '1']; $_SERVER['QUERY_STRING'] = 'order=' . $ref . '?order_id=' . $ref . '&utr=1';
$check('return URL: v68 ?order= mangled by a second "?"', zapupi_order_from_request() === $ref);
$_GET = []; $_SERVER['QUERY_STRING'] = '';
$check('return URL: nothing -> empty string (page falls back to latest own order)', zapupi_order_from_request() === '');
$_GET = $save[0]; if ($save[1] === null) unset($_SERVER['QUERY_STRING']); else $_SERVER['QUERY_STRING'] = $save[1];

echo "\n" . ($n - $fail) . '/' . $n . " passed\n";
exit($fail === 0 ? 0 : 1);
