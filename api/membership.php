<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

/*
 * v62 - "BECOME A RESELLER" (user side), paid with the WEBSITE WALLET. No QR, no Paytm, no external gateway.
 *   GET  ?action=status     membership settings + this user's role / wallet balance (all from the database)
 *   POST action=buy         deduct the membership price from the wallet and activate Reseller in ONE transaction (CSRF protected)
 * The browser supplies nothing but the click: price, balance, role and user id all come from the server.
 */
$user = require_user();
$pdo = db();
$uid = (int)$user['id'];                       // from the authenticated session user, never from the request
header('Cache-Control: no-store, no-cache, must-revalidate');
$action = input_str('action', 'status');

if ($action === 'status') {
    json_ok(['membership' => dual_membership_status_for_user($pdo, $uid)]);
}
if ($action === 'buy') {
    if (request_method() !== 'POST') json_error('POST required', 405);
    csrf_verify();
    if (get_setting('maintenance_mode', '0') === '1' && !is_admin()) json_error('Purchases are temporarily disabled during maintenance.', 503);
    $r = dual_membership_purchase($pdo, $uid);
    if (!$r['ok']) {
        if (!empty($r['insufficient'])) {
            json_error('Insufficient wallet balance.', 402, ['insufficient' => true, 'price' => $r['price'], 'balance' => $r['balance'], 'needed' => $r['needed'], 'deposit_url' => base_url('deposit.php')]);
        }
        json_error((string)$r['error'], (int)($r['code'] ?? 400));
    }
    json_ok(['membership' => dual_membership_status_for_user($pdo, $uid), 'deducted' => $r['deducted'], 'balance_after' => $r['balance_after']]);
}
json_error('Unknown action.', 422);
