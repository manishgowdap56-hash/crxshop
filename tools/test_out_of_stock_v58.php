<?php
declare(strict_types=1);
/*
 * v58 out-of-stock self-test.   Run on STAGING:   php tools/test_out_of_stock_v58.php --run
 * CLI only, MOCKED Licence Dashboard (no network, no provider spend). Writes only its own throw-away rows (user "oostest_*") and deletes them.
 * Drives the REAL functions: provider_call_classified() -> licencedash_generate() -> order_settle_failure() -> order_refund_once().
 * NOT executed by the author (the build sandbox has no PHP/MySQL).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
if (!in_array('--run', $argv ?? [], true)) { echo "Add --run to execute (writes and then deletes its own test rows).\n"; exit(0); }
define('RP_TEST_MODE', true);
require_once __DIR__ . '/../includes/bootstrap.php';

$pdo = db(); $fail = 0;
function t(string $n, bool $ok, string $x = ''): void { global $fail; echo ($ok ? '[PASS] ' : '[FAIL] ') . $n . ($x !== '' ? '  ' . $x : '') . "\n"; if (!$ok) $fail++; }
function bal(PDO $p, int $u): float { $q = $p->prepare('SELECT balance FROM users WHERE id=:i'); $q->execute([':i' => $u]); return (float)$q->fetchColumn(); }
function ord_row(PDO $p, int $o): array { $q = $p->prepare('SELECT * FROM orders WHERE id=:i'); $q->execute([':i' => $o]); return $q->fetch() ?: []; }
function led(PDO $p, int $u, string $t, string $r): array { $q = $p->prepare('SELECT * FROM wallet_ledger WHERE user_id=:u AND type=:t AND reference_id=:r'); $q->execute([':u' => $u, ':t' => $t, ':r' => $r]); return $q->fetchAll(); }

$tag = substr(bin2hex(random_bytes(4)), 0, 8); $ids = ['user' => 0, 'cat' => 0, 'prod' => 0, 'plan' => 0];
try {
    $pdo->prepare("INSERT INTO users (username, email, password_hash, balance) VALUES (:u,:e,'x',100.00)")->execute([':u' => "oostest_$tag", ':e' => "oostest_$tag@example.invalid"]); $ids['user'] = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO categories (name, slug) VALUES (:n,:s)")->execute([':n' => "OOSTEST $tag", ':s' => "oostest-$tag"]); $ids['cat'] = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO products (category_id, name) VALUES (:c,:n)")->execute([':c' => $ids['cat'], ':n' => "OOSTEST $tag"]); $ids['prod'] = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO product_plans (product_id, duration, price, source, provider_variant_id, provider_key) VALUES (:p,'1 Day',10.00,'api','76','licencedashboard')")->execute([':p' => $ids['prod']]); $ids['plan'] = (int)$pdo->lastInsertId();
    $uid = $ids['user']; $planId = $ids['plan']; $prodId = $ids['prod'];

    $charge = function (int $qty) use ($pdo, $uid, $planId, $prodId): array {
        $total = round(10.00 * $qty, 2); $ref = order_id('API');
        $pdo->beginTransaction();
        $b = (float)$pdo->query('SELECT balance FROM users WHERE id=' . (int)$uid . ' FOR UPDATE')->fetchColumn();
        $pdo->prepare("INSERT INTO orders (order_ref,user_id,product_id,plan_id,quantity,unit_price,total_price,source,plan_source,provider_key,status,created_at) VALUES (:r,:u,:p,:pl,:q,10,:t,'website','api','licencedashboard','pending',NOW())")
            ->execute([':r' => $ref, ':u' => $uid, ':p' => $prodId, ':pl' => $planId, ':q' => $qty, ':t' => $total]);
        $oid = (int)$pdo->lastInsertId(); $nb = round($b - $total, 2);
        $pdo->prepare('UPDATE users SET balance=:b WHERE id=:i')->execute([':b' => $nb, ':i' => $uid]);
        $pdo->prepare("INSERT INTO wallet_ledger (user_id,amount,balance_after,type,reference_id,description,created_at) VALUES (:u,:a,:b,'purchase',:r,'oostest',NOW())")->execute([':u' => $uid, ':a' => -$total, ':b' => $nb, ':r' => $ref]);
        $pdo->commit();
        return ['id' => $oid, 'order_ref' => $ref, 'user_id' => $uid, 'total' => $total, 'plan_id' => $planId];
    };
    $run = function (array $o, callable $mock) use ($pdo): array {   // same sequence as api/purchase-api.php after the charge
        $GLOBALS['RP_TEST_PROVIDER'] = $mock;
        $cls = provider_call_classified(PROVIDER_LICENCEDASH, '76', 1, '1 Day', ['idempotency_key' => $o['order_ref']]);
        if ($cls['kind'] === 'success') {
            $pdo->prepare("UPDATE orders SET status='completed', upstream_order_id=:u WHERE id=:i")->execute([':u' => $cls['upstream_id'], ':i' => $o['id']]);
            foreach ($cls['keys'] as $k) $pdo->prepare('INSERT INTO order_keys (order_id, external_key, delivered_at) VALUES (:o,:k,NOW())')->execute([':o' => $o['id'], ':k' => $k]);
            return ['cls' => $cls, 'settle' => ['state' => 'success']];
        }
        return ['cls' => $cls, 'settle' => order_settle_failure($pdo, $o, $cls['kind'], $cls['raw'] ?: 'rejected', PROVIDER_LICENCEDASH, $cls['resp'])];
    };

    // A) confirmed out of stock (code + message)
    $start = bal($pdo, $uid); $o = $charge(1);
    $r = $run($o, fn($p) => ['json' => ['ok' => false, 'error' => ['code' => 'OUT_OF_STOCK', 'message' => 'Selected variation is out of stock']], 'http' => 409]);
    $row = ord_row($pdo, $o['id']); $rf = led($pdo, $uid, 'refund', $o['order_ref']);
    t('A order FAILED', ($row['status'] ?? '') === 'failed');
    t('A failure_code OUT_OF_STOCK', ($row['failure_code'] ?? '') === 'OUT_OF_STOCK' && order_failure_label(order_failure_code($row)) === 'OUT OF STOCK');
    t('A full refund, one ledger row, balance restored', count($rf) === 1 && abs((float)$rf[0]['amount'] - 10.0) < 0.001 && abs(bal($pdo, $uid) - $start) < 0.001);
    t('A ledger description says OUT OF STOCK', count($rf) === 1 && stripos((string)$rf[0]['description'], 'OUT OF STOCK') !== false);
    t('A settle returns failure_code', ($r['settle']['failure_code'] ?? '') === 'OUT_OF_STOCK');

    // B) out of stock identified by the provider code alone (message has no stock wording)
    $start = bal($pdo, $uid); $o = $charge(1);
    $r = $run($o, fn($p) => ['json' => ['ok' => false, 'error' => ['code' => 'OUT_OF_STOCK', 'message' => 'Product unavailable']], 'http' => 409]);
    t('B code-only out of stock => FAILED + OUT_OF_STOCK + refunded', ($r['settle']['state'] ?? '') === 'refunded' && (ord_row($pdo, $o['id'])['failure_code'] ?? '') === 'OUT_OF_STOCK' && abs(bal($pdo, $uid) - $start) < 0.001);

    // C) other explicit refusal: refunded (provider said no) but NOT labelled OUT OF STOCK
    $start = bal($pdo, $uid); $o = $charge(1);
    $r = $run($o, fn($p) => ['json' => ['ok' => false, 'error' => ['code' => 'INVALID_PRODUCT', 'message' => 'Unknown product']], 'http' => 422]);
    $row = ord_row($pdo, $o['id']);
    t('C other refusal: FAILED + refunded, no OUT OF STOCK label', ($row['status'] ?? '') === 'failed' && empty($row['failure_code']) && order_failure_label(order_failure_code($row)) === '' && abs(bal($pdo, $uid) - $start) < 0.001);

    // D) success: never refunded
    $start = bal($pdo, $uid); $o = $charge(1);
    $r = $run($o, fn($p) => ['json' => ['ok' => true, 'data' => ['keys' => ['AAAA-BBBB'], 'order_id' => 'LD-1']], 'http' => 200]);
    t('D success: completed, key stored, no refund', (ord_row($pdo, $o['id'])['status'] ?? '') === 'completed' && !led($pdo, $uid, 'refund', $o['order_ref']) && abs(bal($pdo, $uid) - ($start - 10.0)) < 0.001);
    t('D refund attempt on a completed order is refused', order_refund_once($pdo, $o['id'], $uid, 10.0, $o['order_ref'], 'x', 'auto', 'OUT_OF_STOCK') === 'already_settled' && !led($pdo, $uid, 'refund', $o['order_ref']));

    // E) timeout / unknown / accepted-without-key: uncertain => NO refund, NO failure_code, order stays pending
    $cases = [
        'timeout'                 => function ($p) { throw new RuntimeException('API provider timed out.'); },
        'HTTP 200 unknown body'   => fn($p) => ['json' => ['foo' => 1], 'http' => 200],
        'ok:true but no key'      => fn($p) => ['json' => ['ok' => true, 'data' => ['keys' => []]], 'http' => 200],
        '5xx without ok:false'    => fn($p) => ['json' => ['message' => 'Bad gateway'], 'http' => 502],
        '5xx text mentioning stock but no ok:false' => fn($p) => ['json' => ['message' => 'out of stock?'], 'http' => 503],
    ];
    foreach ($cases as $label => $mock) {
        $start = bal($pdo, $uid); $o = $charge(1); $afterCharge = bal($pdo, $uid);
        $r = $run($o, $mock); $row = ord_row($pdo, $o['id']);
        t("E [$label] uncertain: pending, no refund, no failure_code", $r['cls']['kind'] === 'uncertain' && ($row['status'] ?? '') === 'pending' && empty($row['failure_code']) && !led($pdo, $uid, 'refund', $o['order_ref']) && abs(bal($pdo, $uid) - $afterCharge) < 0.001, $r['cls']['kind']);
    }

    // F) repeated callbacks / polling / cron on the same failed order: still exactly one refund
    $start = bal($pdo, $uid); $o = $charge(1);
    $oos = fn($p) => ['json' => ['ok' => false, 'error' => ['code' => 'OUT_OF_STOCK', 'message' => 'out of stock']], 'http' => 409];
    $run($o, $oos); $after = bal($pdo, $uid);
    for ($i = 0; $i < 5; $i++) { order_settle_failure($pdo, $o, 'rejected', 'out of stock', PROVIDER_LICENCEDASH, ['error_code' => 'OUT_OF_STOCK']); order_refund_once($pdo, $o['id'], $uid, $o['total'], $o['order_ref'], 'again', 'manual', 'OUT_OF_STOCK'); }
    rc_run($pdo, 'cron'); rc_process_order($pdo, $o['id'], 'cron');
    t('F five repeats + cron: one refund row, balance unchanged', count(led($pdo, $uid, 'refund', $o['order_ref'])) === 1 && abs(bal($pdo, $uid) - $after) < 0.001 && abs($after - $start) < 0.001);

    // G) user history API shows FAILED + OUT OF STOCK (simulate the query used by api/mykeys.php)
    $q = $pdo->prepare("SELECT o.status, o.failure_reason, o.failure_code FROM orders o WHERE o.user_id=:u AND o.status='failed' AND o.failure_code='OUT_OF_STOCK'"); $q->execute([':u' => $uid]);
    t('G failed out-of-stock orders are selectable for the history page', count($q->fetchAll()) >= 2);
} catch (Throwable $e) {
    $fail++; echo '[ERROR] ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
} finally {
    try {
        if ($ids['user'] > 0) {
            $pdo->prepare('DELETE FROM order_refund_log WHERE user_id = :u')->execute([':u' => $ids['user']]);
            $pdo->prepare('DELETE FROM audit_logs WHERE user_id = :u')->execute([':u' => $ids['user']]);
            $pdo->prepare('DELETE FROM admin_alerts WHERE plan_id = :p')->execute([':p' => $ids['plan']]);
            $pdo->prepare('DELETE FROM orders WHERE user_id = :u')->execute([':u' => $ids['user']]);
            $pdo->prepare('DELETE FROM users WHERE id = :u')->execute([':u' => $ids['user']]);
        }
        if ($ids['plan'] > 0) $pdo->prepare('DELETE FROM product_plans WHERE id = :i')->execute([':i' => $ids['plan']]);
        if ($ids['prod'] > 0) $pdo->prepare('DELETE FROM products WHERE id = :i')->execute([':i' => $ids['prod']]);
        if ($ids['cat'] > 0)  $pdo->prepare('DELETE FROM categories WHERE id = :i')->execute([':i' => $ids['cat']]);
    } catch (Throwable $e) { echo '[cleanup] ' . $e->getMessage() . "\n"; }
}
echo $fail === 0 ? "ALL PASSED\n" : "$fail FAILED\n"; exit($fail === 0 ? 0 : 1);
