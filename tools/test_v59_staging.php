<?php
declare(strict_types=1);
/**
 * v59 STAGING test (CLI). NOT EXECUTED in the build sandbox (no PHP/MySQL there). Run on a STAGING copy only, never production:
 *   php tools/test_v59_staging.php --run
 * Uses the existing CLI-only provider test hook (RP_TEST_MODE + $GLOBALS['RP_TEST_PROVIDER']) so NO real provider is called.
 * Creates a throw-away user + order per scenario and prints PASS/FAIL. Requires provider 5 enabled+configured with dummy values and one api plan mapped to provider 5.
 */
if (PHP_SAPI !== 'cli' || !in_array('--run', $argv ?? [], true)) { fwrite(STDERR, "Refusing: CLI only, pass --run (staging!).\n"); exit(2); }
define('RP_TEST_MODE', true);
require_once __DIR__ . '/../includes/bootstrap.php';
$pdo = db(); ox_ensure_schema($pdo); pp_ensure_schema($pdo);
$plan = $pdo->query("SELECT pl.*, p.name AS product_name FROM product_plans pl JOIN products p ON p.id = pl.product_id WHERE pl.source='api' AND pl.provider_key='licencedashboard' LIMIT 1")->fetch();
if (!$plan) { fwrite(STDERR, "No provider-5 plan found.\n"); exit(2); }
$fails = 0; $n = 0;
$check = function (string $name, bool $ok) use (&$fails) { echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n"; if (!$ok) $fails++; };

function mk(PDO $pdo, array $plan, float $price): array {
    $u = 'v59t_' . bin2hex(random_bytes(4));
    $pdo->prepare("INSERT INTO users (username, email, password_hash, balance, role, created_at) VALUES (:u, :e, 'x', 100, 'user', NOW())")->execute([':u' => $u, ':e' => $u . '@t.invalid']);
    $uid = (int)$pdo->lastInsertId(); $ref = 'T59' . strtoupper(bin2hex(random_bytes(5)));
    $pdo->prepare("INSERT INTO orders (order_ref,user_id,product_id,plan_id,quantity,unit_price,total_price,source,plan_source,provider_key,status,created_at) VALUES (:r,:u,:p,:pl,1,:pr,:pr,'api','api','licencedashboard','pending',NOW())")
        ->execute([':r'=>$ref,':u'=>$uid,':p'=>$plan['product_id'],':pl'=>$plan['id'],':pr'=>$price]);
    $oid = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE users SET balance = balance - :t WHERE id = :u")->execute([':t'=>$price,':u'=>$uid]);
    $pdo->prepare("INSERT INTO wallet_ledger (user_id,amount,balance_after,type,reference_id,description,created_at) VALUES (:u,:a,:b,'purchase',:r,'test',NOW())")->execute([':u'=>$uid,':a'=>-$price,':b'=>100-$price,':r'=>$ref]);
    return ['id'=>$oid,'order_ref'=>$ref,'user_id'=>$uid,'total'=>$price,'plan_id'=>(int)$plan['id'],'quantity'=>1];
}
function bal(PDO $pdo, int $u): float { $q=$pdo->prepare('SELECT balance FROM users WHERE id=:i'); $q->execute([':i'=>$u]); return (float)$q->fetchColumn(); }
function st(PDO $pdo, int $o): string { $q=$pdo->prepare('SELECT status FROM orders WHERE id=:i'); $q->execute([':i'=>$o]); return (string)$q->fetchColumn(); }

$scen = [
  'success one key'      => [fn() => ['json'=>['ok'=>true,'data'=>['keys'=>['K1'],'order_id'=>'U1']],'http'=>200], OX_SUCCESS, 'completed', 90.0],
  'success many keys'    => [fn() => ['json'=>['ok'=>true,'data'=>['keys'=>['K1','K2']]],'http'=>200], OX_SUCCESS, 'completed', 90.0],
  'explicit reject'      => [fn() => ['json'=>['ok'=>false,'error'=>['message'=>'bad','code'=>'BAD']],'http'=>400], OX_CONFIRMED_REJECTED, 'failed', 100.0],
  'out of stock'         => [fn() => ['json'=>['ok'=>false,'error'=>['message'=>'Out of stock','code'=>'OUT_OF_STOCK']],'http'=>409], OX_CONFIRMED_OOS, 'failed', 100.0],
  'timeout'              => [function () { throw new RuntimeException('API provider timed out.'); }, OX_UNCERTAIN, 'pending', 90.0],
  'invalid reply'        => [function () { throw new RuntimeException('API provider returned an invalid response (HTTP 200).'); }, OX_INVALID_RESPONSE, 'pending', 90.0],
  'ok:true no key'       => [fn() => ['json'=>['ok'=>true,'data'=>['keys'=>[]]],'http'=>200], OX_UNCERTAIN, 'pending', 90.0],
];
foreach ($scen as $name => [$fn, $expState, $expOrder, $expBal]) {
    $o = mk($pdo, $plan, 10.0);
    $GLOBALS['RP_TEST_PROVIDER'] = $fn;
    foreach (['website', 'reseller_api'] as $ch) {
        if ($ch === 'reseller_api') { $o = mk($pdo, $plan, 10.0); }
        $r = order_execute_provider($pdo, $o, $plan, $ch, []);
        $stateOk = $r['state'] === $expState || ($expState === OX_UNCERTAIN && in_array($r['state'], [OX_UNCERTAIN, OX_SUCCESS_NO_KEY, OX_INVALID_RESPONSE], true));
        $check("$name [$ch] state", $stateOk);
        $check("$name [$ch] order=$expOrder", st($pdo, $o['id']) === $expOrder);
        $check("$name [$ch] balance=$expBal", abs(bal($pdo, $o['user_id']) - $expBal) < 0.005);
        $again = order_execute_provider($pdo, $o, $plan, $ch, []);
        $check("$name [$ch] second execution is NOT sent / no second effect", $again['settle'] === 'held' && abs(bal($pdo, $o['user_id']) - $expBal) < 0.005);
    }
}
echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit($fails ? 1 : 0);
