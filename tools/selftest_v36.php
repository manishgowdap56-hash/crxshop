<?php
declare(strict_types=1);
/**
 * v36 self-test (CLI). Run on STAGING:  php tools/selftest_v36.php
 * Read-only: it never writes to the database and never moves money.
 * Covers the pure refund rules (every safety branch), the maintenance path rules and the finance day boundaries.
 * The DB-level scenarios (concurrency, real refunds) are in README_V36.md "Manual test checklist".
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/bootstrap.php';

$fail = 0; $n = 0;
function t(string $name, bool $ok): void { global $fail, $n; $n++; if (!$ok) $fail++; echo ($ok ? 'PASS  ' : 'FAIL  ') . $name . "\n"; }

$base = [
    'order_id' => 1, 'order_ref' => 'T1', 'status' => 'pending', 'plan_source' => 'api', 'provider_key' => 'keypanel', 'upstream_id' => '',
    'failure_reason' => '', 'attempts' => 0, 'expired' => true, 'order_keys' => 0, 'delivered_keys' => 0, 'purchase_rows' => 1, 'refund_rows' => 0,
    'audit_ok' => true, 'audit_failed' => 0, 'audit_uncertain' => 0, 'audit_success' => 0, 'provider' => ['state' => 'unsupported', 'detail' => 'x'],
];
$d = fn(array $o) => rc_decide(array_replace($base, $o))['decision'];

t('completed order is never touched',                $d(['status' => 'completed']) === 'settled');
t('inside window: no action',                        $d(['expired' => false]) === 'not_expired');
t('delivered keys => blocked (never refund)',        $d(['order_keys' => 1, 'audit_failed' => 1]) === 'blocked');
t('product_keys delivered => blocked',               $d(['delivered_keys' => 1]) === 'blocked');
t('completion record => blocked',                    $d(['audit_success' => 1, 'audit_failed' => 1]) === 'blocked');
t('refund row exists => blocked (no duplicate)',     $d(['refund_rows' => 1, 'audit_failed' => 1]) === 'blocked');
t('never charged => review, not refund',             $d(['purchase_rows' => 0, 'audit_failed' => 1]) === 'review');
t('provider order id present => review',             $d(['upstream_id' => 'U1', 'audit_failed' => 1]) === 'review');
t('provider says completed => review (no refund)',   $d(['provider' => ['state' => 'completed', 'detail' => '']]) === 'review');
t('provider says failed => eligible',                $d(['provider' => ['state' => 'failed', 'detail' => '']]) === 'eligible');
t('recorded confirmed failure => eligible',          $d(['audit_failed' => 1]) === 'eligible');
t('recorded failure but UNCERTAIN marker => review', $d(['audit_failed' => 1, 'failure_reason' => 'UNCERTAIN: timeout']) === 'review');
t('audit unreadable => cannot prove failure',        $d(['audit_failed' => 1, 'audit_ok' => false]) === 'review');
t('ambiguous provider (unsupported) => review',      $d([]) === 'review');
t('provider unavailable, early => retry',            $d(['provider' => ['state' => 'unavailable', 'detail' => 'off']]) === 'retry');
t('provider unavailable, retries used => review',    $d(['provider' => ['state' => 'unavailable', 'detail' => 'off'], 'attempts' => RC_MAX_RETRIES]) === 'review');
t('provider pending, early => wait',                 $d(['provider' => ['state' => 'pending', 'detail' => '']]) === 'wait');
t('local stock pending, no keys => eligible',        $d(['plan_source' => 'local', 'provider' => ['state' => 'not_applicable', 'detail' => '']]) === 'eligible');

t('maintenance: admin API path exempt',              maintenance_path_exempt('api/admin/pending-orders.php'));
t('maintenance: purchase.php NOT exempt',            !maintenance_path_exempt('api/purchase.php'));
t('maintenance: purchase-api.php NOT exempt',        !maintenance_path_exempt('api/purchase-api.php'));
t('maintenance: gateway NOT exempt',                 !maintenance_path_exempt('api/api/reseller_gateway.php'));
t('maintenance: store.php NOT exempt',               !maintenance_path_exempt('store.php'));
t('maintenance: deposits-status exempt',             maintenance_path_exempt('api/deposits-status.php'));

$b = fin_day_bounds(db());
t('finance: day is exactly 24h (no DST in Asia/Kolkata)', strtotime($b['end'] . ' UTC') - strtotime($b['start'] . ' UTC') === 86400);
$f = fin_today(db());
t('finance: no query errors', empty($f['errors']));
t('finance: withdrawals reported unsupported (not faked)', $f['withdrawals']['supported'] === false && $f['withdrawals']['total'] === null);

echo "\n$n tests, $fail failed\n";
exit($fail ? 1 : 0);
