<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_admin();

$q = trim((string)input_param('q', ''));
$pdo = db();
// v61: filter Normal Users / Resellers (whitelisted literal, never interpolated from input)
$typeIn = (string)input_param('type', '');
$typeSql = $typeIn === 'reseller' ? " AND account_type = 'reseller'" : ($typeIn === 'normal' ? " AND account_type = 'normal'" : '');

if ($q !== '') {
    $stmt = $pdo->prepare(
        'SELECT id, username, role, banned, balance, api_enabled, referral_code, referred_by,
                last_login_at, last_active_at, created_at, account_type, reseller_since, reseller_source
         FROM users
         WHERE (username LIKE :q OR CAST(id AS CHAR) = :exact)' . $typeSql . '
         ORDER BY id DESC LIMIT 200'
    );
    $stmt->execute([':q' => '%' . $q . '%', ':exact' => $q]);
} else {
    $stmt = $pdo->query(
        'SELECT id, username, role, banned, balance, api_enabled, referral_code, referred_by,
                last_login_at, last_active_at, created_at, account_type, reseller_since, reseller_source
         FROM users WHERE 1=1' . $typeSql . ' ORDER BY id DESC LIMIT 200'
    );
}

$rows = $stmt->fetchAll();

// Per-user order stats
$out = [];
foreach ($rows as $r) {
    $s = $pdo->prepare(
        'SELECT
            COUNT(*) AS total_orders,
            COALESCE(SUM(total_price),0) AS total_spent,
            COALESCE(SUM(quantity),0) AS total_keys,
            SUM(CASE WHEN source="website" THEN 1 ELSE 0 END) AS site_orders,
            SUM(CASE WHEN source="api" THEN 1 ELSE 0 END) AS api_orders
         FROM orders WHERE user_id = :id AND status = "completed"'
    );
    $s->execute([':id' => (int)$r['id']]);
    $st = $s->fetch();

    $mem = $pdo->prepare("SELECT source, payment_method, amount_deducted, wallet_ref, payment_status, price_due, price_paid, payment_ref, gateway_txn_id, activated_at, revoked_at, created_at FROM reseller_memberships WHERE user_id = :id ORDER BY id DESC LIMIT 10");
    $mem->execute([':id' => (int)$r['id']]);
    $out[] = [
        'account_type'    => (string)$r['account_type'] === 'reseller' ? 'reseller' : 'normal',
        'reseller_since'  => $r['reseller_since'],
        'reseller_source' => $r['reseller_source'],
        'membership_history' => $mem->fetchAll(),
        'id'           => (int)$r['id'],
        'username'     => $r['username'],
        'role'         => $r['role'],
        'banned'       => (int)$r['banned'] === 1,
        'api_enabled'  => (int)($r['api_enabled'] ?? 0) === 1,
        'balance'      => (float)$r['balance'],
        'referral_code'=> $r['referral_code'],
        'referred_by'  => $r['referred_by'] ? (int)$r['referred_by'] : null,
        'last_login_at'=> $r['last_login_at'],
        'last_active_at'=> $r['last_active_at'],
        'created_at'   => $r['created_at'],
        'stats' => [
            'total_orders' => (int)$st['total_orders'],
            'total_spent'  => (float)$st['total_spent'],
            'total_keys'   => (int)$st['total_keys'],
            'site_orders'  => (int)$st['site_orders'],
            'api_orders'   => (int)$st['api_orders'],
        ],
    ];
}

json_ok(['users' => $out]);