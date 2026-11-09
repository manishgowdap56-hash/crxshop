<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$user = require_user();

// Auto-expire stale pending deposits on read
try {
    // Same rule as the source: an unpaid payment expires when its window ends.
    // This only closes the row; it never credits the wallet.
    db()->prepare(
        'UPDATE deposits SET status = "expired", gateway_status = "EXPIRED", reviewed_at = COALESCE(reviewed_at, NOW())
         WHERE user_id = :uid AND status = "pending" AND expires_at IS NOT NULL AND expires_at < NOW()'
    )->execute([':uid' => (int)$user['id']]);
} catch (Throwable $e) {
    error_log('[deposits-list:expire] ' . $e->getMessage());
}

$stmt = db()->prepare(
    'SELECT id, order_ref, amount, status, method, gateway_status, admin_note, reviewed_at, expires_at, created_at
     FROM deposits
     WHERE user_id = :uid
     ORDER BY id DESC
     LIMIT 50'
);
$stmt->execute([':uid' => (int)$user['id']]);
$rows = $stmt->fetchAll();

$out = array_map(function ($r) {
    return [
        'id'         => (int)$r['id'],
        'order_ref'  => $r['order_ref'],
        'amount'     => (float)$r['amount'],
        'status'     => $r['status'],
        'method'     => $r['method'],
        'gateway_status' => $r['gateway_status'],
        'admin_note' => $r['admin_note'],
        'reviewed_at'=> $r['reviewed_at'],
        'expires_at' => $r['expires_at'],
        'created_at' => $r['created_at'],
    ];
}, $rows);

json_ok([
    'balance'  => (float)current_user()['balance'],
    'deposits' => $out,
]);