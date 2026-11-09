<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$user = require_user();
$pdo = db();

$code = (string)$user['referral_code'];
if ($code === '') {
    // backfill for any legacy user that somehow lacks a code
    $code = 'KP-' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $user['username']));
    $code = substr($code, 0, 12) . '-' . strtoupper(bin2hex(random_bytes(2)));
    $pdo->prepare('UPDATE users SET referral_code = :c WHERE id = :id')
        ->execute([':c' => $code, ':id' => (int)$user['id']]);
}

$total = (int)$pdo->query('SELECT COUNT(*) FROM referrals WHERE referrer_id = ' . (int)$user['id'])->fetchColumn();
$activeStmt = $pdo->prepare('SELECT COUNT(*) FROM referrals WHERE referrer_id = :r AND active = 1');
$activeStmt->execute([':r' => (int)$user['id']]);
$activeCount = (int)$activeStmt->fetchColumn();

$earnedStmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM referral_events WHERE referrer_id = :r');
$earnedStmt->execute([':r' => (int)$user['id']]);
$earned = (float)$earnedStmt->fetchColumn();

$networkStmt = $pdo->prepare(
    'SELECT u.username, r.active, r.total_earned, r.created_at
     FROM referrals r JOIN users u ON u.id = r.referred_id
     WHERE r.referrer_id = :r
     ORDER BY r.id DESC LIMIT 100'
);
$networkStmt->execute([':r' => (int)$user['id']]);
$network = array_map(function ($r) {
    return [
        'username' => $r['username'],
        'active'   => (int)$r['active'] === 1,
        'earned'   => (float)$r['total_earned'],
        'joined'   => $r['created_at'],
    ];
}, $networkStmt->fetchAll());

$base = base_url('');
$link = rtrim($base, '/') . '/register.php?ref=' . urlencode($code);

json_ok([
    'code'          => $code,
    'link'          => $link,
    'commission_pct'=> referral_percent(),
    'total'         => $total,
    'active'        => $activeCount,
    'earned'        => $earned,
    'network'       => $network,
]);