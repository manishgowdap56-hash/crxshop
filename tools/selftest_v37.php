<?php
declare(strict_types=1);
/*
 * v37 self-test. Run on STAGING:  php tools/selftest_v37.php
 * Read-only against the database (it never writes, never credits, never changes a setting).
 * NOT executed by the author (no PHP/MySQL in the build sandbox) - run it before production.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../includes/bootstrap.php';

$fail = 0;
function t(string $name, bool $ok, string $extra = ''): void { global $fail; echo ($ok ? '[PASS] ' : '[FAIL] ') . $name . ($extra !== '' ? '  ' . $extra : '') . "\n"; if (!$ok) $fail++; }

// 1) formula: amount x percent / 100, 2 decimals
foreach ([[100, 1, 1.00], [100, 2, 2.00], [100, 3, 3.00], [100, 5, 5.00], [100, 10, 10.00], [199.99, 2.5, 5.00], [0, 5, 0.0], [100, 0, 0.0]] as [$a, $p, $e]) {
    $g = referral_compute((float)$a, (float)$p);
    t("compute {$p}% of {$a} = {$e}", abs($g - $e) < 0.0001, "got {$g}");
}
// 2) percentage validation
foreach (['1' => true, '10' => true, '2.5' => true, '0.1' => true, '25' => true, '0' => false, '26' => false, '-1' => false, 'abc' => false, '' => false, '3.123' => false, '1e1' => false] as $in => $ok) {
    t("parse '{$in}' " . ($ok ? 'accepted' : 'rejected'), referral_parse_percent((string)$in)[0] === $ok);
}
// 3) read-only DB checks
$pdo = db();
$chk = ops_schema_check($pdo, false);
t('orders/order_refund_log schema complete (no repair attempted)', $chk['ok'], $chk['ok'] ? '' : implode(', ', $chk['missing']));
t('referral_commissions table exists', rp_table_exists($pdo, 'referral_commissions'));
t('active percentage is valid', referral_percent_valid(referral_percent()), 'active=' . referral_percent_label());
if (rp_table_exists($pdo, 'referral_commissions')) {
    $dups = (int)$pdo->query('SELECT COUNT(*) FROM (SELECT order_id FROM referral_commissions GROUP BY order_id HAVING COUNT(*) > 1) x')->fetchColumn();
    t('no duplicate commission per order', $dups === 0);
    $bad = (int)$pdo->query("SELECT COUNT(*) FROM referral_commissions WHERE status IN ('credited','reversed') AND ABS(commission - ROUND(eligible_amount * percent / 100, 2)) > 0.011 AND channel <> 'legacy'")->fetchColumn();
    t('every non-legacy row: commission = eligible x percent / 100', $bad === 0, $bad ? "{$bad} row(s) differ" : '');
    $led = (float)$pdo->query("SELECT COALESCE(SUM(commission),0) FROM referral_commissions WHERE status IN ('credited','reversed')")->fetchColumn();
    $ev  = (float)$pdo->query('SELECT COALESCE(SUM(amount),0) FROM referral_events')->fetchColumn();
    t('ledger credited total equals referral_events total', abs($led - $ev) < 0.01, "ledger={$led} events={$ev}");
}
// 4) v38 refund tracking (read-only)
$dupRef = (int)$pdo->query("SELECT COUNT(*) FROM (SELECT user_id, reference_id FROM wallet_ledger WHERE type = 'refund' GROUP BY user_id, reference_id HAVING COUNT(*) > 1) x")->fetchColumn();
t('no order was refunded twice in the wallet ledger', $dupRef === 0, $dupRef ? "{$dupRef} duplicated refund reference(s)" : '');
$unlinked = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE refund_status = 'refunded' AND refund_ledger_id IS NULL")->fetchColumn();
t("every order shown as refunded has its wallet ledger link", $unlinked === 0, $unlinked ? "{$unlinked} order(s) without link" : '');
$pendRef = (int)$pdo->query("SELECT COUNT(*) FROM orders o WHERE o.status = 'pending' AND EXISTS (SELECT 1 FROM wallet_ledger l WHERE l.user_id = o.user_id AND l.type = 'refund' AND l.reference_id = o.order_ref)")->fetchColumn();
t('no PENDING order already has a refund row (would need manual review)', $pendRef === 0, $pendRef ? "{$pendRef} order(s)" : '');
echo $fail === 0 ? "\nALL PASSED\n" : "\n{$fail} FAILED\n";
exit($fail === 0 ? 0 : 1);
