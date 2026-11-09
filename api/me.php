<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$user = current_user();
if (!$user) {
    json_response(['ok' => false, 'error' => 'not_authenticated'], 401);
}

$stats = db()->prepare(
    'SELECT
        COALESCE(SUM(CASE WHEN DATE(created_at)=CURDATE() THEN 1 ELSE 0 END),0) AS orders_today,
        COUNT(*) AS total_orders,
        COALESCE(SUM(total_price),0) AS total_spent
     FROM orders WHERE user_id = :id AND status = "completed"'
);
$stats->execute([':id' => (int)$user['id']]);
$s = $stats->fetch() ?: ['orders_today'=>0,'total_orders'=>0,'total_spent'=>0];

json_ok([
    'user' => [
        'id'           => (int)$user['id'],
        'username'     => $user['username'],
        'role'         => $user['role'],
        'balance'      => (float)$user['balance'],
        'referral_code'=> $user['referral_code'],
    ],
    'stats' => [
        'orders_today' => (int)$s['orders_today'],
        'total_orders' => (int)$s['total_orders'],
        'total_spent'  => (float)$s['total_spent'],
    ],
]);