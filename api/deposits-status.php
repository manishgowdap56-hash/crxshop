<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

/**
 * Verify a Paytm deposit and (only if Paytm confirms) credit the wallet once.
 * Port of index.php `deposit_status_paytm`.
 *
 * Trust model (same as the source):
 *  - The browser only supplies deposit_id. It never supplies an amount, a
 *    status or a transaction id, and nothing it sends can mark a payment paid.
 *  - Success is decided ONLY by this server asking Paytm (getTxnStatus with
 *    MID + the server-generated ORDERID) and receiving STATUS = TXN_SUCCESS.
 *  - The credited amount is the amount Paytm reports, not a client value.
 *  - Exactly-once: (1) approved rows short-circuit, (2) a Paytm txn id that
 *    is already approved on another deposit is rejected, (3) the deposit row
 *    and the user row are locked FOR UPDATE inside one DB transaction, and
 *    (4) the approve UPDATE is guarded by status <> 'approved'.
 */

$user = require_user();
if (request_method() !== 'GET') json_error('GET required', 405);

$id = input_int('deposit_id');
if ($id <= 0) json_error('Invalid deposit.', 422);

$pdo = db();
$userId = (int)$user['id'];

// The Paytm call below can take a while; release the session lock so the user's
// other requests are not blocked. Nothing in this script writes to the session.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

function dep_balance(PDO $pdo, int $userId): float
{
    $b = $pdo->prepare('SELECT balance FROM users WHERE id = :id');
    $b->execute([':id' => $userId]);
    return (float)$b->fetchColumn();
}

$st = $pdo->prepare(
    'SELECT d.*, TIMESTAMPDIFF(SECOND, d.created_at, NOW()) AS age_seconds
     FROM deposits d WHERE d.id = :id AND d.user_id = :u LIMIT 1'
);
$st->execute([':id' => $id, ':u' => $userId]);
$dep = $st->fetch();
if (!$dep) json_error('Deposit not found.', 404);

// ---- Already settled ----------------------------------------------------
if ($dep['status'] === 'approved') {
    json_ok(['deposit_id' => $id, 'status' => 'SUCCESS', 'balance' => dep_balance($pdo, $userId)]);
}
if ($dep['status'] === 'expired') {
    json_ok(['deposit_id' => $id, 'status' => 'EXPIRED', 'message' => 'Payment window expired. Please create a new payment.']);
}
// ---- v68: ZapUPI deposits are verified by the ZapUPI module (same atomic credit path as the webhook). ----
if (($dep['method'] ?? '') === 'zapupi') {
    $zs = zapupi_settle($pdo, $id, 'poll');
    if ($zs['state'] === 'SUCCESS') {
        json_ok(['deposit_id' => $id, 'status' => 'SUCCESS', 'balance' => dep_balance($pdo, $userId)]);
    }
    if ($zs['state'] === 'EXPIRED') json_ok(['deposit_id' => $id, 'status' => 'EXPIRED', 'message' => 'Payment window expired. Please create a new payment.']);
    if ($zs['state'] === 'FAILURE') json_ok(['deposit_id' => $id, 'status' => 'FAILURE', 'message' => 'Payment was not successful.']);
    json_ok(['deposit_id' => $id, 'status' => 'PENDING', 'message' => 'Payment status pending.']);
}
if ($dep['status'] !== 'pending' || empty($dep['gateway_order_id']) || $dep['method'] !== 'paytm_qr') {
    json_ok(['deposit_id' => $id, 'status' => 'FAILURE', 'message' => 'This payment is closed.']);
}

// ---- Server-side payment window (source: 180 seconds) -------------------
if ((int)$dep['age_seconds'] > PAYTM_PAYMENT_WINDOW) {
    $pdo->prepare('UPDATE deposits SET status = "expired", gateway_status = "EXPIRED", reviewed_at = NOW()
                   WHERE id = :id AND user_id = :u AND status = "pending"')
        ->execute([':id' => $id, ':u' => $userId]);
    json_ok(['deposit_id' => $id, 'status' => 'EXPIRED', 'message' => 'Payment window expired. Please create a new payment.']);
}

// ---- Ask Paytm (server to server) ---------------------------------------
try {
    $res = paytm_get_qr_status((string)$dep['gateway_order_id']);
} catch (Throwable $e) {
    error_log('[deposits-status] ' . $e->getMessage());
    json_error('Paytm status check failed. Please retry shortly.', 502);
}
[$status, $txnAmount, $txnId, $resultMsg] = paytm_parse_status($res);
$responseJson = json_encode($res, JSON_UNESCAPED_SLASHES);

// ---- Confirmed by Paytm: credit exactly once ----------------------------
if ($status === 'TXN_SUCCESS') {
    if ($txnAmount <= 0) json_error('Paytm reported success without a valid transaction amount.', 502);

    $creditAmount = round($txnAmount, 2);
    // Some Paytm/UPI responses omit TXNID: fall back to the unique order id (as the source does).
    $creditTxnId = $txnId !== '' ? $txnId : ('ORDER:' . (string)$dep['gateway_order_id']);

    $dup = $pdo->prepare('SELECT id FROM deposits WHERE gateway_txn_id = :t AND status = "approved" AND id <> :id LIMIT 1');
    $dup->execute([':t' => $creditTxnId, ':id' => $id]);
    if ($dup->fetchColumn()) json_error('This Paytm transaction has already been credited.', 409);

    try {
        $pdo->beginTransaction();

        $lock = $pdo->prepare('SELECT status, amount FROM deposits WHERE id = :id AND user_id = :u FOR UPDATE');
        $lock->execute([':id' => $id, ':u' => $userId]);
        $locked = $lock->fetch();
        if (!$locked) {
            $pdo->rollBack();
            json_error('Deposit not found.', 404);
        }
        if ($locked['status'] === 'approved') {          // another request already credited it
            $pdo->commit();
            json_ok(['deposit_id' => $id, 'status' => 'SUCCESS', 'balance' => dep_balance($pdo, $userId)]);
        }

        $ub = $pdo->prepare('SELECT balance FROM users WHERE id = :id FOR UPDATE');
        $ub->execute([':id' => $userId]);
        $old = (float)$ub->fetchColumn();
        $new = round($old + $creditAmount, 2);

        $pdo->prepare('UPDATE users SET balance = :b WHERE id = :id')->execute([':b' => $new, ':id' => $userId]);

        $desc = 'Paytm deposit ' . $dep['gateway_order_id'];
        $note = null;
        if (abs($creditAmount - (float)$locked['amount']) > 0.009) {
            $note = 'Requested ₹' . number_format((float)$locked['amount'], 2, '.', '')
                  . '; actual paid ₹' . number_format($creditAmount, 2, '.', '');
            $desc .= ' (actual paid ₹' . number_format($creditAmount, 2, '.', '') . ')';
        }
        $pdo->prepare(
            'INSERT INTO wallet_ledger (user_id, amount, balance_after, type, reference_id, description, created_at)
             VALUES (:u, :a, :b, "deposit", :r, :d, NOW())'
        )->execute([':u' => $userId, ':a' => $creditAmount, ':b' => $new, ':r' => 'DEP#' . $id, ':d' => $desc]);

        // deposits.amount always equals what was credited (admin totals sum this column).
        $upd = $pdo->prepare(
            'UPDATE deposits
             SET status = "approved", amount = :amt, gateway_status = "TXN_SUCCESS", gateway_txn_id = :t,
                 gateway_response = :resp, admin_note = COALESCE(:note, admin_note), reviewed_at = NOW()
             WHERE id = :id AND user_id = :u AND status <> "approved"'
        );
        $upd->execute([
            ':amt'  => $creditAmount,
            ':t'    => $creditTxnId,
            ':resp' => $responseJson,
            ':note' => $note,
            ':id'   => $id,
            ':u'    => $userId,
        ]);
        if ($upd->rowCount() !== 1) throw new RuntimeException('Deposit state changed during credit.');

        $pdo->commit();
        audit_log('deposit.credited', 'success', ['entity_type' => 'deposit', 'entity_id' => $id, 'user_id' => $userId, 'actor_id' => $userId,
            'reason' => 'Paytm verified TXN_SUCCESS, credited ' . number_format($creditAmount, 2, '.', '') . ' (ref DEP#' . $id . ')']);
        json_ok(['deposit_id' => $id, 'status' => 'SUCCESS', 'balance' => $new, 'txn_id' => $creditTxnId]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[deposits-status:credit] ' . $e->getMessage());
        audit_log('deposit.credited', 'failure', ['entity_type' => 'deposit', 'entity_id' => $id, 'user_id' => $userId, 'actor_id' => $userId, 'reason' => 'Paytm confirmed payment but crediting failed: ' . $e->getMessage()]);
        alert_raise([
            'dedupe_key' => 'deposit_credit_failed:' . $id, 'type' => 'wallet_inconsistent', 'severity' => 'critical', 'order_ref' => (string)$dep['order_ref'],
            'reason' => 'Paytm confirmed deposit #' . $id . ' but the wallet credit transaction failed.',
            'recommended_action' => 'Open Wallet & Deposits → deposit #' . $id . ' → "Verify with Paytm & credit" (credits at most once).',
        ]);
        json_error('Could not credit wallet safely.', 500);
    }
}

// ---- Not (yet) successful: nothing is credited --------------------------
// Source behaviour: TXN_FAILURE (e.g. "Invalid Order Id" right after creation)
// does NOT reject the deposit; it keeps polling until the 180s window ends.
$txnOrNull = $txnId !== '' ? $txnId : null;
$gwStatus  = $status === 'TXN_FAILURE' ? 'TXN_FAILURE_PENDING' : substr($status, 0, 32);
$pdo->prepare('UPDATE deposits SET gateway_status = :s, gateway_txn_id = :t, gateway_response = :r
               WHERE id = :id AND user_id = :u AND status = "pending"')
    ->execute([':s' => $gwStatus, ':t' => $txnOrNull, ':r' => $responseJson, ':id' => $id, ':u' => $userId]);

json_ok([
    'deposit_id' => $id,
    'status'     => 'PENDING',
    'message'    => $status === 'TXN_FAILURE' ? ($resultMsg ?: 'Payment status pending.') : 'Payment status pending.',
]);
