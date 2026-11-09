<?php
declare(strict_types=1);
/* v61 STAGING TEST (NOT RUN by the author: no PHP/MySQL was available). Run on a COPY of the site:  php tools/test_v61_staging.php --run
 * Uses only the local DB; makes no Paytm / provider call. Creates and removes its own test users. */
if (PHP_SAPI !== 'cli' || !in_array('--run', $argv ?? [], true)) { fwrite(STDERR, "CLI only: php tools/test_v61_staging.php --run (STAGING COPY)\n"); exit(2); }
require_once __DIR__ . '/../includes/bootstrap.php';
$pdo = db(); $pass = 0; $fail = 0;
function t(string $n, bool $c): void { global $pass, $fail; if ($c) { $pass++; echo "PASS $n\n"; } else { $fail++; echo "FAIL $n\n"; } }
dual_ensure_schema($pdo); dual_ensure_schema($pdo);
$u = 'v61t' . random_int(1000, 9999);
$pdo->prepare("INSERT INTO users (username,password_hash,role,banned,balance,api_token,referral_code,created_at) VALUES (:u,'x','user',0,0,:t,:r,NOW())")->execute([':u' => $u, ':t' => 'psk_' . bin2hex(random_bytes(8)), ':r' => substr($u, 0, 12)]);
$id = (int)$pdo->lastInsertId();
t('new user is normal', dual_user_type($pdo, $id) === 'normal');
t('grant reseller', dual_set_account_type($pdo, $id, 'reseller', 1)['ok'] && dual_user_type($pdo, $id) === 'reseller');
dual_ensure_schema($pdo); pp_ensure_schema($pdo);
t('re-running migration keeps reseller', dual_user_type($pdo, $id) === 'reseller');
t('revoke reseller', dual_set_account_type($pdo, $id, 'normal', 1)['ok'] && dual_user_type($pdo, $id) === 'normal');
t('price pick', dual_pick_price(['price' => 500, 'reseller_price' => 400], 'reseller') === 400.0 && dual_pick_price(['price' => 500, 'reseller_price' => 400], 'normal') === 500.0);
$sync0 = pp_setting($pdo, 'sync_usd_inr_rate', ''); $norm0 = pp_setting($pdo, 'normal_usd_inr_rate', '');
pp_put_setting($pdo, 'normal_usd_inr_rate', '55');
t('changing normal rate leaves reseller rate', pp_setting($pdo, 'sync_usd_inr_rate', '') === $sync0);
pp_put_setting($pdo, 'normal_usd_inr_rate', $norm0 !== '' ? $norm0 : '30');
// ---- v62: wallet membership (local DB only; no Paytm, no QR) ----
pp_put_setting($pdo, 'reseller_membership_price', '400'); pp_put_setting($pdo, 'reseller_membership_enabled', '1'); pp_put_setting($pdo, 'reseller_membership_button', '1');
$pdo->prepare('UPDATE users SET balance = 100 WHERE id = :i')->execute([':i' => $id]);
$r = dual_membership_purchase($pdo, $id);
t('insufficient: refused, balance kept, still normal', !$r['ok'] && !empty($r['insufficient']) && (float)$pdo->query("SELECT balance FROM users WHERE id=$id")->fetchColumn() === 100.0 && dual_user_type($pdo, $id) === 'normal');
$pdo->prepare('UPDATE users SET balance = 1000 WHERE id = :i')->execute([':i' => $id]);
$r = dual_membership_purchase($pdo, $id);
t('purchase ok, 600 remains', $r['ok'] && (float)$pdo->query("SELECT balance FROM users WHERE id=$id")->fetchColumn() === 600.0 && dual_user_type($pdo, $id) === 'reseller');
$r2 = dual_membership_purchase($pdo, $id);
t('second purchase refused, not charged', !$r2['ok'] && (float)$pdo->query("SELECT balance FROM users WHERE id=$id")->fetchColumn() === 600.0);
t('one membership row + one ledger row', (int)$pdo->query("SELECT COUNT(*) FROM reseller_memberships WHERE user_id=$id AND payment_method='wallet'")->fetchColumn() === 1
   && (int)$pdo->query("SELECT COUNT(*) FROM wallet_ledger WHERE user_id=$id AND reference_id LIKE 'MEM#%'")->fetchColumn() === 1);
t('API access: reseller yes', dual_can_use_api($pdo, $id));
dual_set_account_type($pdo, $id, 'normal', 1);
t('API access: revoked no', !dual_can_use_api($pdo, $id));
pp_put_setting($pdo, 'reseller_membership_enabled', '0');
$pdo->prepare('UPDATE users SET balance = 1000 WHERE id = :i')->execute([':i' => $id]);
t('membership OFF: refused', !dual_membership_purchase($pdo, $id)['ok'] && dual_user_type($pdo, $id) === 'normal');
$pdo->prepare('DELETE FROM users WHERE id = :i')->execute([':i' => $id]);
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
