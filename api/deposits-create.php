<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

/**
 * Create a Paytm UPI-QR deposit order.
 * Port of index.php `deposit_create_paytm`: server-side amount limits,
 * unique numeric order id per attempt, pending row stored in the database,
 * UPI QR generated for that order id. The wallet is NOT touched here.
 */

$user = require_user();
if (request_method() !== 'POST') json_error('POST required', 405);
csrf_verify();

// ---- v68: ZapUPI branch. Paytm code below is unchanged and only runs when gateway is not "zapupi". ----
if (strtolower(input_str('gateway', '')) === 'zapupi') {
    if (get_setting('maintenance_mode', '0') === '1' && !is_admin()) json_error('Deposits are temporarily disabled during maintenance.', 503);
    try {
        // The user comes ONLY from the authenticated session. The browser supplies just the amount.
        $zr = zapupi_create_deposit(db(), $user, input_param('amount', null));
        json_ok($zr);
    } catch (RuntimeException $ze) {
        $zc = (int)$ze->getCode();
        json_error($ze->getMessage(), in_array($zc, [422, 429, 502, 503], true) ? $zc : 502);
    } catch (Throwable $ze) {
        error_log('[deposits-create:zapupi] ' . $ze->getMessage());
        json_error('Could not create deposit order.', 502);
    }
}

// ---- Amount: validated server-side against the source limits ---------
$rawAmount = input_param('amount', 0);
if (is_array($rawAmount) || !is_numeric($rawAmount)) json_error('Invalid amount.', 422);
$amount = round((float)$rawAmount, 2);
$min = deposit_min();
$max = deposit_max();
if (!is_finite($amount) || $amount < $min || $amount > $max) {
    json_error('Deposit amount must be between ₹' . number_format($min, 2) . ' and ₹' . number_format($max, 2) . '.', 422);
}

if (get_setting('maintenance_mode', '0') === '1' && !is_admin()) {
    json_error('Deposits are temporarily disabled during maintenance.', 503);
}

$cfg = paytm_config();
if (!$cfg['enabled']) json_error('Paytm payment is not enabled by admin.', 503);
if ($cfg['mid'] === '' || $cfg['upi_id'] === '') json_error('Paytm payment is not configured yet.', 503);

$pdo = db();
$userId = (int)$user['id'];
$depositId = 0;

try {
    // ---- Fresh numeric order id for EVERY attempt (source: YmdHis + 5 random digits) ----
    $orderId = '';
    // NOTE: PDO here uses native prepares, so each placeholder name may appear only once.
    $check = $pdo->prepare('SELECT id FROM deposits WHERE gateway_order_id = :o1 OR order_ref = :o2 LIMIT 1');
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $candidate = date('YmdHis') . random_int(10000, 99999);
        $check->execute([':o1' => $candidate, ':o2' => $candidate]);
        if (!$check->fetchColumn()) { $orderId = $candidate; break; }
    }
    if ($orderId === '') throw new RuntimeException('Could not generate a unique payment order ID. Please try again.');

    // ---- Store the pending payment server-side ---------------------------
    // Expiry is computed by the database clock so created/expiry/verification
    // comparisons all use the same time source.
    $insert = $pdo->prepare(
        'INSERT INTO deposits (order_ref, user_id, amount, status, method, gateway_order_id, gateway_status, expires_at, created_at)
         VALUES (:ref, :u, :a, "pending", "paytm_qr", :g, "INITIATED", DATE_ADD(NOW(), INTERVAL ' . (int)PAYTM_PAYMENT_WINDOW . ' SECOND), NOW())'
    );
    $inserted = false;
    for ($i = 0; $i < 10; $i++) {
        try {
            $insert->execute([':ref' => $orderId, ':u' => $userId, ':a' => $amount, ':g' => $orderId]);
            $inserted = true;
            break;
        } catch (PDOException $dup) {
            // Another request may have won the same random id between SELECT and INSERT.
            if ((string)$dup->getCode() !== '23000' && stripos($dup->getMessage(), 'duplicate') === false) throw $dup;
            $orderId = date('YmdHis') . random_int(10000, 99999);
        }
    }
    if (!$inserted) throw new RuntimeException('Could not create a unique payment order. Please try again.');
    $depositId = (int)$pdo->lastInsertId();

    // ---- Build the payment (UPI QR) -------------------------------------
    $qr = paytm_create_dynamic_qr($orderId, $amount);
    $pdo->prepare('UPDATE deposits SET gateway_status = :s, gateway_response = :r WHERE id = :id AND user_id = :u')
        ->execute([
            ':s'  => 'QR_CREATED',
            ':r'  => json_encode(['created_at' => date('c')], JSON_UNESCAPED_SLASHES),
            ':id' => $depositId,
            ':u'  => $userId,
        ]);

    $row = $pdo->prepare('SELECT expires_at, GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), expires_at)) AS remaining FROM deposits WHERE id = :id');
    $row->execute([':id' => $depositId]);
    $meta = $row->fetch() ?: ['expires_at' => null, 'remaining' => PAYTM_PAYMENT_WINDOW];

    json_ok([
        'deposit' => [
            'id'         => $depositId,
            'order_ref'  => $orderId,
            'amount'     => $amount,
            'status'     => 'pending',
            'expires_at' => $meta['expires_at'],
            'expires_in' => (int)$meta['remaining'],
            'qr_image'   => $qr['image'],
            'qr_data'    => $qr['qr_data'],
        ],
    ]);
} catch (Throwable $e) {
    error_log('[deposits-create] ' . $e->getMessage());
    if ($depositId > 0) {
        try {
            $pdo->prepare('UPDATE deposits SET status = "rejected", gateway_status = "CREATE_FAILED", gateway_response = :r, reviewed_at = NOW() WHERE id = :id AND user_id = :u AND status = "pending"')
                ->execute([':r' => substr($e->getMessage(), 0, 500), ':id' => $depositId, ':u' => $userId]);
        } catch (Throwable $e2) {
            error_log('[deposits-create:fail-mark] ' . $e2->getMessage());
        }
    }
    // Our own RuntimeExceptions carry user-safe messages; DB/other errors stay generic.
    json_error($e instanceof RuntimeException ? $e->getMessage() : 'Could not create deposit order.', 502);
}
