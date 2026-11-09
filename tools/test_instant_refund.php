<?php
declare(strict_types=1);
/*
 * v39 instant-refund self-test.   Run on STAGING:   php tools/test_instant_refund.php --run
 *
 * - CLI only. Uses a MOCKED Licence Dashboard (no network, no provider spend, no real purchase).
 * - TEST 6 briefly RENAMES the wallet_ledger table (restored in a finally block) to force a refund-transaction error: use a STAGING copy only.
 * - Writes ONLY its own throw-away rows (user "rftest_*", category/product "RFTEST ...") and deletes them at the end.
 * - It drives the REAL production functions: provider_call_classified() -> licencedash_generate() -> order_settle_failure()
 *   -> order_refund_once(), against the REAL MySQL schema. Do NOT point it at a database you cannot afford to touch rows in.
 * - NOT executed by the author (the build sandbox has no PHP/MySQL). Run it before going live.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
if (!in_array('--run', $argv ?? [], true)) { echo "Add --run to execute (writes and then deletes its own test rows).\n"; exit(0); }

define('RP_TEST_MODE', true);
require_once __DIR__ . '/../includes/bootstrap.php';

$pdo = db();
$fail = 0;
function t(string $name, bool $ok, string $extra = ''): void { global $fail; echo ($ok ? '[PASS] ' : '[FAIL] ') . $name . ($extra !== '' ? '  ' . $extra : '') . "\n"; if (!$ok) $fail++; }
function bal(PDO $pdo, int $uid): float { $q = $pdo->prepare('SELECT balance FROM users WHERE id=:i'); $q->execute([':i' => $uid]); return (float)$q->fetchColumn(); }
function ord_row(PDO $pdo, int $oid): array { $q = $pdo->prepare('SELECT * FROM orders WHERE id=:i'); $q->execute([':i' => $oid]); return $q->fetch() ?: []; }
function ledger_n(PDO $pdo, int $uid, string $type, string $ref): int { $q = $pdo->prepare('SELECT COUNT(*) FROM wallet_ledger WHERE user_id=:u AND type=:t AND reference_id=:r'); $q->execute([':u' => $uid, ':t' => $type, ':r' => $ref]); return (int)$q->fetchColumn(); }

$tag = substr(bin2hex(random_bytes(4)), 0, 8);
$ids = ['user' => 0, 'cat' => 0, 'prod' => 0, 'plan' => 0];
try {
    // ---- fixtures ----
    $pdo->prepare("INSERT INTO users (username, email, password_hash, balance) VALUES (:u, :e, 'x', 100.00)")->execute([':u' => "rftest_$tag", ':e' => "rftest_$tag@example.invalid"]);
    $ids['user'] = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO categories (name, slug) VALUES (:n, :s)")->execute([':n' => "RFTEST $tag", ':s' => "rftest-$tag"]);
    $ids['cat'] = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO products (category_id, name) VALUES (:c, :n)")->execute([':c' => $ids['cat'], ':n' => "RFTEST $tag"]);
    $ids['prod'] = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO product_plans (product_id, duration, price, source, provider_variant_id, provider_key) VALUES (:p, '1 Day', 10.00, 'api', '76', 'licencedashboard')")->execute([':p' => $ids['prod']]);
    $ids['plan'] = (int)$pdo->lastInsertId();
    $uid = $ids['user']; $planId = $ids['plan']; $prodId = $ids['prod'];

    // Same charge as api/purchase-api.php: pending order + wallet deduction + purchase ledger row, in one transaction.
    $charge = function (int $qty) use ($pdo, $uid, $planId, $prodId): array {
        $total = round(10.00 * $qty, 2); $ref = order_id('API');
        $pdo->beginTransaction();
        $b = (float)$pdo->query('SELECT balance FROM users WHERE id=' . (int)$uid . ' FOR UPDATE')->fetchColumn();
        $pdo->prepare("INSERT INTO orders (order_ref,user_id,product_id,plan_id,quantity,unit_price,total_price,source,plan_source,provider_key,status,created_at) VALUES (:r,:u,:p,:pl,:q,10,:t,'website','api','licencedashboard','pending',NOW())")
            ->execute([':r' => $ref, ':u' => $uid, ':p' => $prodId, ':pl' => $planId, ':q' => $qty, ':t' => $total]);
        $oid = (int)$pdo->lastInsertId();
        $nb = round($b - $total, 2);
        $pdo->prepare('UPDATE users SET balance=:b WHERE id=:i')->execute([':b' => $nb, ':i' => $uid]);
        $pdo->prepare("INSERT INTO wallet_ledger (user_id,amount,balance_after,type,reference_id,description,created_at) VALUES (:u,:a,:b,'purchase',:r,'rftest',NOW())")
            ->execute([':u' => $uid, ':a' => -$total, ':b' => $nb, ':r' => $ref]);
        $pdo->commit();
        return ['id' => $oid, 'order_ref' => $ref, 'user_id' => $uid, 'total' => $total, 'plan_id' => $planId];
    };
    // Same sequence as purchase-api.php after the charge.
    $run = function (array $o, callable $mock, int $qty = 1) use ($pdo, $planId): array {
        $GLOBALS['RP_TEST_PROVIDER'] = $mock;
        $cls = provider_call_classified(PROVIDER_LICENCEDASH, '76', $qty, '1 Day', ['idempotency_key' => $o['order_ref']]);
        if ($cls['kind'] === 'success') {
            $pdo->prepare("UPDATE orders SET status='completed', upstream_order_id=:u WHERE id=:i")->execute([':u' => $cls['upstream_id'], ':i' => $o['id']]);
            foreach ($cls['keys'] as $k) $pdo->prepare('INSERT INTO order_keys (order_id, external_key, delivered_at) VALUES (:o,:k,NOW())')->execute([':o' => $o['id'], ':k' => $k]);
            return ['cls' => $cls, 'settle' => ['state' => 'success']];
        }
        return ['cls' => $cls, 'settle' => order_settle_failure($pdo, $o, $cls['kind'], $cls['raw'] ?: 'rejected', PROVIDER_LICENCEDASH, $cls['resp'])];
    };

    $oos  = fn(array $p): array => ['json' => ['ok' => false, 'error' => ['code' => 'OUT_OF_STOCK', 'message' => 'Selected variation is out of stock']], 'http' => 409];

    // TEST 1: out of stock -> FAILED + full refund, message only after commit
    $start = bal($pdo, $uid); $o = $charge(1);
    t('T1 wallet charged 10.00', abs(bal($pdo, $uid) - ($start - 10.0)) < 0.001);
    $r = $run($o, $oos);
    $row = ord_row($pdo, $o['id']);
    t('T1 classified as rejected (not uncertain)', $r['cls']['kind'] === 'rejected', $r['cls']['kind']);
    t('T1 settle state = refunded', $r['settle']['state'] === 'refunded');
    t('T1 order FAILED (not pending)', ($row['status'] ?? '') === 'failed', (string)($row['status'] ?? ''));
    t('T1 wallet back to start', abs(bal($pdo, $uid) - $start) < 0.001);
    t('T1 exactly one refund ledger row', ledger_n($pdo, $uid, 'refund', $o['order_ref']) === 1);
    t('T1 refund tracking columns set', !empty($row['refund_ledger_id']) && ($row['refund_status'] ?? '') === 'refunded');
    t('T1 no key stored', (int)$pdo->query('SELECT COUNT(*) FROM order_keys WHERE order_id=' . (int)$o['id'])->fetchColumn() === 0);

    // TEST 2: explicit rejection (HTTP 422, bad request) and 4xx with HTML / empty body
    foreach ([
        'explicit ok:false 422'  => fn(array $p): array => ['json' => ['ok' => false, 'error' => ['message' => 'Rejected']], 'http' => 422],
        '4xx unparsed body'      => fn(array $p): array => ['json' => ['ok' => false, 'error' => ['message' => 'Provider rejected the request (HTTP 409).']], 'http' => 409, 'unparsed' => true],
    ] as $label => $mock) {
        $start = bal($pdo, $uid); $o = $charge(1); $r = $run($o, $mock); $row = ord_row($pdo, $o['id']);
        t("T2 [$label] refunded + FAILED", $r['settle']['state'] === 'refunded' && ($row['status'] ?? '') === 'failed' && abs(bal($pdo, $uid) - $start) < 0.001);
    }

    // TEST 3: success -> completed, key delivered, no refund
    $start = bal($pdo, $uid); $o = $charge(1);
    $r = $run($o, fn(array $p): array => ['json' => ['ok' => true, 'data' => ['keys' => ['AAAA-BBBB'], 'order_id' => 'LD-1']], 'http' => 200]);
    $row = ord_row($pdo, $o['id']);
    t('T3 completed', ($row['status'] ?? '') === 'completed');
    t('T3 key stored', (int)$pdo->query('SELECT COUNT(*) FROM order_keys WHERE order_id=' . (int)$o['id'])->fetchColumn() === 1);
    t('T3 no refund, 10.00 stays charged', ledger_n($pdo, $uid, 'refund', $o['order_ref']) === 0 && abs(bal($pdo, $uid) - ($start - 10.0)) < 0.001);
    $okOrder = $o;

    // TEST 4: multi-item order -> entire amount refunded
    $start = bal($pdo, $uid); $o = $charge(3);
    t('T4 charged 30.00', abs(bal($pdo, $uid) - ($start - 30.0)) < 0.001);
    $r = $run($o, $oos, 3);
    t('T4 full 30.00 refunded', $r['settle']['state'] === 'refunded' && abs($r['settle']['refunded'] - 30.0) < 0.001 && abs(bal($pdo, $uid) - $start) < 0.001);
    $t4 = $o;

    // TEST 5: same failure processed repeatedly -> one credit
    $after = bal($pdo, $uid);
    $again1 = order_settle_failure($pdo, $o, 'rejected', 'again', PROVIDER_LICENCEDASH);
    $again2 = order_refund_once($pdo, $o['id'], $uid, $o['total'], $o['order_ref'], 'again');
    t('T5 repeated settle: still one refund row, balance unchanged', ledger_n($pdo, $uid, 'refund', $o['order_ref']) === 1 && abs(bal($pdo, $uid) - $after) < 0.001 && $again2 === 'already_settled', $again1['state'] . '/' . $again2);

    // TEST 6: refund transaction error -> no false success, nothing credited, recoverable
    $start = bal($pdo, $uid); $o = $charge(1); $afterCharge = bal($pdo, $uid);
    $pdo->exec('ALTER TABLE wallet_ledger RENAME TO wallet_ledger_rftest_bak'); // force the refund transaction to fail
    $broken = null;
    try { $broken = $run($o, $oos); } finally { $pdo->exec('ALTER TABLE wallet_ledger_rftest_bak RENAME TO wallet_ledger'); }
    $row = ord_row($pdo, $o['id']);
    t('T6 NOT reported as refunded', ($broken['settle']['state'] ?? '') === 'refund_error', (string)($broken['settle']['state'] ?? ''));
    t('T6 wallet untouched, order still recoverable (pending)', abs(bal($pdo, $uid) - $afterCharge) < 0.001 && ($row['status'] ?? '') === 'pending');
    $rec = order_refund_once($pdo, $o['id'], $uid, $o['total'], $o['order_ref'], 'recovery');
    t('T6 recovery refunds exactly once', $rec === 'refunded' && abs(bal($pdo, $uid) - $start) < 0.001 && ledger_n($pdo, $uid, 'refund', $o['order_ref']) === 1);

    // TEST 7: timeout after possibly processing -> NOT refunded, held for reconciliation
    $start = bal($pdo, $uid); $o = $charge(1);
    $r = $run($o, function (array $p): array { throw new RuntimeException('API provider timed out.'); });
    $row = ord_row($pdo, $o['id']);
    t('T7 timeout = uncertain', $r['cls']['kind'] === 'uncertain');
    t('T7 not refunded, still pending, marked UNCERTAIN', $r['settle']['state'] === 'reconcile' && ($row['status'] ?? '') === 'pending' && strpos((string)($row['failure_reason'] ?? ''), 'UNCERTAIN:') === 0 && ledger_n($pdo, $uid, 'refund', $o['order_ref']) === 0);
    $r5 = $run($charge(1), fn(array $p): array => ['json' => ['message' => 'Bad gateway'], 'http' => 502]);
    t('T7 unexplained 5xx (no explicit ok:false) = uncertain, not refunded', $r5['cls']['kind'] === 'uncertain' && $r5['settle']['state'] === 'reconcile', $r5['cls']['kind']);

    // TEST 8: controller / refund function look at already-settled orders -> no second refund
    $before = bal($pdo, $uid);
    $res = rc_process_order($pdo, $o['id'], 'cron');   // the uncertain order from T7 (still pending, inside window): must not refund
    t('T8 controller does not refund an uncertain order', ledger_n($pdo, $uid, 'refund', $o['order_ref']) === 0 && abs(bal($pdo, $uid) - $before) < 0.001, $res['decision'] ?? '');
    $sum = rc_run($pdo, 'cron');
    t('T8 cron run leaves the already-refunded T4 order with exactly one refund', ledger_n($pdo, $uid, 'refund', $t4['order_ref']) === 1, 'cron refunded=' . ($sum['refunded'] ?? '?'));
    t('T8 completed order never refunded', order_refund_once($pdo, $okOrder['id'], $uid, $okOrder['total'], $okOrder['order_ref'], 'x') === 'already_settled' && ledger_n($pdo, $uid, 'refund', $okOrder['order_ref']) === 0);

    // Extra: failure before the wallet was charged -> nothing to refund
    $pdo->prepare("INSERT INTO orders (order_ref,user_id,product_id,plan_id,quantity,unit_price,total_price,source,plan_source,provider_key,status,created_at) VALUES ('RFTEST-NOCHARGE-$tag',:u,:p,:pl,1,10,10,'website','api','licencedashboard','pending',NOW())")
        ->execute([':u' => $uid, ':p' => $prodId, ':pl' => $planId]);
    $ncId = (int)$pdo->lastInsertId(); $b0 = bal($pdo, $uid);
    t('no purchase ledger row => no refund', order_refund_once($pdo, $ncId, $uid, 10.0, "RFTEST-NOCHARGE-$tag", 'x') === 'no_charge' && abs(bal($pdo, $uid) - $b0) < 0.001);
} catch (Throwable $e) {
    $fail++;
    echo '[ERROR] ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    try { $pdo->exec('ALTER TABLE wallet_ledger_rftest_bak RENAME TO wallet_ledger'); } catch (Throwable $x) { /* table was not renamed */ }
} finally {
    // cleanup of throw-away rows only
    try {
        if ($ids['user'] > 0) {
            $pdo->prepare('DELETE FROM order_refund_log WHERE user_id = :u')->execute([':u' => $ids['user']]);
            $pdo->prepare("DELETE FROM audit_logs WHERE user_id = :u")->execute([':u' => $ids['user']]);
            $pdo->prepare("DELETE FROM admin_alerts WHERE plan_id = :p")->execute([':p' => $ids['plan']]);
            $pdo->prepare('DELETE FROM orders WHERE user_id = :u')->execute([':u' => $ids['user']]);
            $pdo->prepare('DELETE FROM users WHERE id = :u')->execute([':u' => $ids['user']]);
        }
        if ($ids['plan'] > 0) $pdo->prepare('DELETE FROM product_plans WHERE id = :p')->execute([':p' => $ids['plan']]);
        if ($ids['prod'] > 0) $pdo->prepare('DELETE FROM products WHERE id = :p')->execute([':p' => $ids['prod']]);
        if ($ids['cat'] > 0)  $pdo->prepare('DELETE FROM categories WHERE id = :c')->execute([':c' => $ids['cat']]);
    } catch (Throwable $e) { echo '[WARN] cleanup: ' . $e->getMessage() . "\n"; }
}
echo $fail === 0 ? "\nALL PASSED\n" : "\n{$fail} FAILED\n";
exit($fail === 0 ? 0 : 1);
