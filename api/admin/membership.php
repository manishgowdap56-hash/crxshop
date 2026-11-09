<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
$admin = require_admin();

/*
 * v61 - Admin > Reseller Membership (admin only, POST needs CSRF)
 *   GET                       settings + payment history (all purchases, admin grants/revokes)
 *   POST action=save          enabled (0|1), price (any custom INR amount > 0), description, button (0|1)
 */
$pdo = db();
$adminId = (int)$admin['id'];
header('Cache-Control: no-store');

if (request_method() === 'GET') {
    $hist = $pdo->query("SELECT m.id, m.user_id, u.username, m.source, m.payment_status, m.price_due, m.price_paid, m.payment_ref, m.gateway_txn_id, m.gateway_status,
                                m.payment_method, m.amount_deducted, m.wallet_ref, m.wallet_ledger_id, m.balance_before, m.balance_after, m.activated_at, m.revoked_at, m.created_at, m.note
                         FROM reseller_memberships m JOIN users u ON u.id = m.user_id ORDER BY m.id DESC LIMIT 200")->fetchAll();
    json_ok(['config' => dual_membership_config($pdo), 'history' => $hist,
             'counts' => ['resellers' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE account_type = 'reseller'")->fetchColumn(),
                          'normal' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE account_type = 'normal'")->fetchColumn()]]);
}

csrf_verify();
if (input_str('action') !== 'save') json_error('Unknown action.', 422);
$price = input_str('price', '');
$r = dual_membership_save($pdo, input_int('enabled', 0) === 1, $price === '' ? null : $price, input_str('description', ''), input_int('button', 1) === 1, $adminId);
if (!$r['ok']) json_error((string)$r['error'], 422);
json_ok(['config' => dual_membership_config($pdo)]);
