<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_admin();

/*
 * Admin deposit monitoring.
 *   GET  ?filter=pending|approved|rejected|expired|all  q  from  to  page  id(detail)
 *   POST action=reconcile id        verify with Paytm (server to server) and credit AT MOST ONCE if paid but not credited
 *   POST action=approve|reject id   legacy manual deposits only (Paytm deposits can never be approved by hand)
 *
 * Only whitelisted, non-secret gateway fields are ever shown. The submitted UTR / a screenshot / a browser flag is never proof:
 * the only proof is Paytm's own answer to getTxnStatus for the server-generated order id.
 */
$pdo = db();
$method = request_method();

/** Whitelisted fields from the stored Paytm status JSON (searches the usual wrappers, depth <= 3). */
function dep_gw_fields(?string $json): array
{
    $want = ['STATUS' => 'status', 'TXNID' => 'txn_id', 'BANKTXNID' => 'bank_ref', 'ORDERID' => 'order_id', 'TXNAMOUNT' => 'amount',
             'PAYMENTMODE' => 'payment_mode', 'RESPCODE' => 'resp_code', 'RESPMSG' => 'resp_msg', 'TXNDATE' => 'txn_date', 'BANKNAME' => 'bank_name', 'GATEWAYNAME' => 'gateway_name'];
    $out = array_fill_keys(array_values($want), null);
    $data = $json ? json_decode($json, true) : null;
    if (!is_array($data)) return $out;
    $walk = function (array $a, int $depth) use (&$walk, &$out, $want): void {
        foreach ($a as $k => $v) {
            if (is_array($v)) { if ($depth < 3) $walk($v, $depth + 1); continue; }
            $uk = strtoupper((string)$k);
            if (isset($want[$uk]) && $out[$want[$uk]] === null && is_scalar($v) && (string)$v !== '') $out[$want[$uk]] = mb_substr((string)$v, 0, 120);
        }
    };
    $walk($data, 0);
    return $out;
}

function dep_row(array $r, bool $detail = false): array
{
    $gw = dep_gw_fields($r['gateway_response'] ?? null);
    $credited = $r['ledger_id'] !== null;
    $verified = ($r['gateway_status'] ?? '') === 'TXN_SUCCESS' || $gw['status'] === 'TXN_SUCCESS';
    $isPaytm = ($r['method'] ?? '') === 'paytm_qr';
    $isZap = ($r['method'] ?? '') === 'zapupi';                    // v68
    if ($isZap) $verified = ($r['credit_status'] ?? '') === 'success' || ($r['gateway_status'] ?? '') === 'SUCCESS';
    $row = [
        'id' => (int)$r['id'], 'order_ref' => $r['order_ref'], 'user_id' => (int)$r['user_id'], 'username' => $r['username'],
        'amount' => (float)$r['amount'], 'status' => $r['status'], 'method' => $r['method'] ?: 'manual',
        'created_at' => $r['created_at'], 'updated_at' => $r['reviewed_at'] ?: $r['created_at'], 'expires_at' => $r['expires_at'],
        'gateway_order_id' => $r['gateway_order_id'], 'gateway_txn_id' => $r['gateway_txn_id'], 'gateway_status' => $r['gateway_status'],
        'utr' => $isZap ? ($r['zp_utr'] ?: null) : $gw['bank_ref'],   // Paytm: BANKTXNID. ZapUPI: utr returned by the verified Order Status
        'gateway' => $isZap ? 'zapupi' : ($isPaytm ? 'paytm' : 'manual'),
        'credit_status' => $r['credit_status'] ?? null, 'environment' => $r['environment'] ?? null, 'paid_at' => $r['paid_at'] ?? null,
        'verified' => $verified,
        'wallet_credited' => $credited,
        'ledger_ref' => $credited ? 'DEP#' . $r['id'] : null,
        'proof_url' => $r['proof_path'] ? base_url($r['proof_path']) : null,
        'admin_note' => $r['admin_note'], 'reviewer' => $r['reviewer'] ?? null,
        // "Paid but not credited": Paytm confirmed it, the wallet ledger has no credit.
        'credit_missing' => $verified && !$credited,
        'can_reconcile' => ($isPaytm || $isZap) && !empty($r['gateway_order_id']) && $r['status'] !== 'approved' && !$credited && !($isZap && $r['status'] !== 'pending'),
        'pending_reason' => null,
    ];
    if ($r['status'] === 'approved' && !$credited) $row['pending_reason'] = 'Marked approved but no wallet ledger credit was found: manual review required.';
    elseif ($r['status'] === 'pending') $row['pending_reason'] = $isZap ? ('Waiting for ZapUPI confirmation' . (($r['credit_status'] ?? '') === 'verification_failed' ? ' (last verification failed: ' . ($r['gateway_status'] ?? '') . ')' : '') . '.') : 'Waiting for payment confirmation from Paytm.';
    elseif ($r['status'] === 'expired') $row['pending_reason'] = 'Payment window closed before Paytm confirmed a payment' . ($gw['resp_msg'] ? ' (' . $gw['resp_msg'] . ')' : '') . '.';
    elseif ($r['status'] === 'rejected') $row['pending_reason'] = $r['admin_note'] ?: 'Rejected.';
    if ($detail) $row['gateway'] = $gw;
    return $row;
}

const DEP_SELECT = "SELECT d.id, d.order_ref, d.user_id, d.amount, d.status, d.proof_path, d.admin_note, d.method, d.gateway_order_id, d.gateway_txn_id,
                           d.gateway_status, d.gateway_response, d.reviewed_at, d.expires_at, d.created_at, d.gateway, d.utr AS zp_utr, d.environment, d.credit_status, d.paid_at, d.pay_amount,
                           u.username, r.username AS reviewer,
                           (SELECT l.id FROM wallet_ledger l WHERE l.type = 'deposit' AND l.reference_id = CONCAT('DEP#', d.id) LIMIT 1) AS ledger_id
                    FROM deposits d JOIN users u ON u.id = d.user_id LEFT JOIN users r ON r.id = d.reviewed_by";

if ($method === 'GET') {
    // Auto-expire stale pending (closes the row only; never credits).
    $pdo->prepare('UPDATE deposits SET status = "expired", gateway_status = "EXPIRED", reviewed_at = COALESCE(reviewed_at, NOW())
                   WHERE status = "pending" AND expires_at IS NOT NULL AND expires_at < NOW()')->execute();

    $id = input_int('id');
    if ($id > 0) {
        $st = $pdo->prepare(DEP_SELECT . ' WHERE d.id = :id LIMIT 1');
        $st->execute([':id' => $id]);
        $r = $st->fetch();
        if (!$r) json_error('Deposit not found.', 404);
        json_ok(['deposit' => dep_row($r, true)]);
    }

    $filter = input_str('filter', 'all');
    if (!in_array($filter, ['pending', 'approved', 'rejected', 'expired', 'all', 'credit_missing', 'verification_failed'], true)) $filter = 'all';
    $gwFilter = input_str('gateway', 'all');                      // v68: all | paytm | zapupi
    if (!in_array($gwFilter, ['all', 'paytm', 'zapupi'], true)) $gwFilter = 'all';
    $qtext = trim((string)input_param('q', ''));
    $from = input_str('from', ''); $to = input_str('to', '');
    $page = max(1, input_int('page', 1));
    $per = 20;

    $where = []; $params = [];
    if (in_array($filter, ['pending', 'approved', 'rejected', 'expired'], true)) { $where[] = 'd.status = :s'; $params[':s'] = $filter; }
    if ($filter === 'credit_missing') {
        $where[] = "(d.gateway_status = 'TXN_SUCCESS' AND NOT EXISTS (SELECT 1 FROM wallet_ledger l2 WHERE l2.type = 'deposit' AND l2.reference_id = CONCAT('DEP#', d.id)))";
    }
    if ($filter === 'verification_failed') $where[] = "d.credit_status = 'verification_failed'";
    if ($gwFilter === 'paytm')  $where[] = "d.method = 'paytm_qr'";
    if ($gwFilter === 'zapupi') $where[] = "d.method = 'zapupi'";
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where[] = 'd.created_at >= :f'; $params[':f'] = $from . ' 00:00:00'; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $where[] = 'd.created_at <= :t'; $params[':t'] = $to . ' 23:59:59'; }
    if ($qtext !== '') {
        $like = '%' . $qtext . '%';
        $where[] = '(u.username LIKE :q1 OR d.order_ref LIKE :q2 OR d.gateway_order_id LIKE :q3 OR d.gateway_txn_id LIKE :q4
                     OR CAST(d.id AS CHAR) = :q5 OR CAST(d.user_id AS CHAR) = :q6 OR d.gateway_response LIKE :q7 OR d.utr LIKE :q8)';
        $params += [':q1' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like, ':q5' => $qtext, ':q6' => $qtext, ':q7' => $like, ':q8' => $like];
    }
    $w = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $c = $pdo->prepare('SELECT COUNT(*) FROM deposits d JOIN users u ON u.id = d.user_id' . $w);
    $c->execute($params);
    $total = (int)$c->fetchColumn();
    $pages = max(1, (int)ceil($total / $per));
    $page = min($page, $pages);

    $l = $pdo->prepare(DEP_SELECT . $w . ' ORDER BY d.id DESC LIMIT ' . (int)$per . ' OFFSET ' . (int)(($page - 1) * $per));
    $l->execute($params);
    $rows = $l->fetchAll();

    $pendingCount  = (int)$pdo->query('SELECT COUNT(*) FROM deposits WHERE status="pending"')->fetchColumn();
    $approvedToday = (int)$pdo->query('SELECT COUNT(*) FROM deposits WHERE status="approved" AND DATE(reviewed_at)=CURDATE()')->fetchColumn();
    $totalCredited = (float)$pdo->query('SELECT COALESCE(SUM(amount),0) FROM deposits WHERE status="approved"')->fetchColumn();
    $rejectedCount = (int)$pdo->query('SELECT COUNT(*) FROM deposits WHERE status="rejected"')->fetchColumn();
    $missing = (int)$pdo->query("SELECT COUNT(*) FROM deposits d WHERE d.gateway_status = 'TXN_SUCCESS' AND NOT EXISTS (SELECT 1 FROM wallet_ledger l WHERE l.type = 'deposit' AND l.reference_id = CONCAT('DEP#', d.id))")->fetchColumn();

    json_ok([
        'summary' => ['pending' => $pendingCount, 'approved_today' => $approvedToday, 'total_credited' => $totalCredited, 'rejected' => $rejectedCount, 'credit_missing' => $missing],
        'deposits' => array_map('dep_row', $rows),
        'page' => $page, 'pages' => $pages, 'total' => $total,
    ]);
}

csrf_verify();

$action = input_str('action');
$id = input_int('id');
if ($id <= 0) json_error('Invalid deposit.', 422);
$adminId = (int)current_user()['id'];

$stmt = $pdo->prepare('SELECT id, user_id, amount, status, method, gateway_order_id, gateway_txn_id FROM deposits WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $id]);
$dep = $stmt->fetch();
if (!$dep) json_error('Deposit not found.', 404);

/* ------------------------------------------------------------------ */
/*  v68: ZapUPI. Verify with ZapUPI and credit AT MOST ONCE through the  */
/*  same atomic function the webhook uses. There is no manual credit.   */
/* ------------------------------------------------------------------ */
if (($dep['method'] ?? '') === 'zapupi') {
    if ($action !== 'reconcile') json_error('ZapUPI deposits are confirmed automatically after ZapUPI verification and cannot be approved or rejected manually.', 409);
    if ($dep['status'] === 'approved') json_error('This deposit is already credited. Nothing was changed.', 409);
    audit_log('deposit.reconcile', 'info', ['entity_type' => 'deposit', 'entity_id' => $id, 'user_id' => (int)$dep['user_id'], 'actor_id' => $adminId, 'reason' => 'Admin requested ZapUPI verification']);
    $zr = zapupi_settle($pdo, $id, 'admin');
    if ($zr['state'] === 'SUCCESS') {
        $zb = $pdo->prepare('SELECT balance FROM users WHERE id = :id');
        $zb->execute([':id' => (int)$dep['user_id']]);
        json_ok(['verified' => true, 'credited' => true, 'balance' => (float)$zb->fetchColumn(), 'amount' => (float)$dep['amount'],
                 'message' => 'ZapUPI confirmed the payment. ₹' . number_format((float)$dep['amount'], 2, '.', '') . ' is credited (exactly once).']);
    }
    $why = ['throttled' => 'Checked a moment ago; try again in a few seconds.', 'provider_unreachable' => 'ZapUPI could not be reached.',
            'provider_failed' => 'ZapUPI reports this payment as failed.', 'test_payment' => 'This is a TEST payment; it is never credited.'][$zr['reason']] ?? ('Verification result: ' . $zr['reason'] . '.');
    json_ok(['verified' => false, 'credited' => false, 'message' => $why . ' Nothing was credited.']);
}

/* ------------------------------------------------------------------ */
/*  Reconcile: paid at Paytm but wallet not credited                    */
/* ------------------------------------------------------------------ */
if ($action === 'reconcile') {
    if (($dep['method'] ?? '') !== 'paytm_qr' || empty($dep['gateway_order_id'])) json_error('Only Paytm deposits can be reconciled.', 409);
    if ($dep['status'] === 'approved') json_error('This deposit is already approved. If its wallet credit is missing it needs manual review; it is not credited again here.', 409);

    // 1) The ledger is checked first: a credit may already exist even if the status looks wrong.
    $lg = $pdo->prepare("SELECT id FROM wallet_ledger WHERE type = 'deposit' AND reference_id = :r LIMIT 1");
    $lg->execute([':r' => 'DEP#' . $id]);
    if ($lg->fetchColumn()) json_error('This deposit already has a wallet credit. Nothing was changed.', 409);

    // 2) Independent verification: Paytm itself, server to server. Nothing from the browser is used.
    try {
        $res = paytm_get_qr_status((string)$dep['gateway_order_id']);
    } catch (Throwable $e) {
        audit_log('deposit.reconcile', 'failure', ['entity_type' => 'deposit', 'entity_id' => $id, 'user_id' => (int)$dep['user_id'], 'reason' => 'Paytm verification request failed: ' . $e->getMessage()]);
        json_error('Paytm could not be reached. Nothing was credited. Please retry shortly.', 502);
    }
    [$status, $txnAmount, $txnId, $resultMsg] = paytm_parse_status($res);
    $responseJson = json_encode($res, JSON_UNESCAPED_SLASHES);
    if ($status !== 'TXN_SUCCESS' || $txnAmount <= 0) {
        audit_log('deposit.reconcile', 'info', ['entity_type' => 'deposit', 'entity_id' => $id, 'user_id' => (int)$dep['user_id'], 'reason' => 'Paytm did not confirm payment (status ' . $status . '). Nothing credited.']);
        json_ok(['verified' => false, 'credited' => false, 'message' => 'Paytm does not confirm a successful payment for this deposit (status: ' . $status . '). Nothing was credited.']);
    }
    $creditAmount = round($txnAmount, 2);
    $creditTxnId = $txnId !== '' ? $txnId : ('ORDER:' . (string)$dep['gateway_order_id']);

    // 3) The same Paytm transaction must not already be credited on another deposit.
    $dup = $pdo->prepare('SELECT id FROM deposits WHERE gateway_txn_id = :t AND status = "approved" AND id <> :id LIMIT 1');
    $dup->execute([':t' => $creditTxnId, ':id' => $id]);
    if ($dup->fetchColumn()) {
        audit_log('deposit.reconcile', 'denied', ['entity_type' => 'deposit', 'entity_id' => $id, 'user_id' => (int)$dep['user_id'], 'reason' => 'Paytm transaction already credited on another deposit']);
        json_error('This Paytm transaction has already been credited on another deposit.', 409);
    }

    // 4) Credit exactly once: row locks + status guard + ledger re-check, all in one transaction.
    try {
        $pdo->beginTransaction();
        $lock = $pdo->prepare('SELECT status, amount FROM deposits WHERE id = :id FOR UPDATE');
        $lock->execute([':id' => $id]);
        $locked = $lock->fetch();
        if (!$locked || $locked['status'] === 'approved') { $pdo->rollBack(); json_error('This deposit was already credited.', 409); }
        $lg2 = $pdo->prepare("SELECT id FROM wallet_ledger WHERE type = 'deposit' AND reference_id = :r LIMIT 1");
        $lg2->execute([':r' => 'DEP#' . $id]);
        if ($lg2->fetchColumn()) { $pdo->rollBack(); json_error('This deposit already has a wallet credit. Nothing was changed.', 409); }

        $ub = $pdo->prepare('SELECT balance FROM users WHERE id = :id FOR UPDATE');
        $ub->execute([':id' => (int)$dep['user_id']]);
        $old = (float)$ub->fetchColumn();
        $new = round($old + $creditAmount, 2);
        $pdo->prepare('UPDATE users SET balance = :b WHERE id = :id')->execute([':b' => $new, ':id' => (int)$dep['user_id']]);

        $desc = 'Paytm deposit ' . $dep['gateway_order_id'] . ' (reconciled by admin)';
        $note = 'Reconciled by admin #' . $adminId . ' after Paytm confirmed TXN_SUCCESS';
        if (abs($creditAmount - (float)$locked['amount']) > 0.009) {
            $note .= '. Requested ₹' . number_format((float)$locked['amount'], 2, '.', '') . '; actual paid ₹' . number_format($creditAmount, 2, '.', '');
        }
        $pdo->prepare('INSERT INTO wallet_ledger (user_id, amount, balance_after, type, reference_id, description, created_at)
                       VALUES (:u, :a, :b, "deposit", :r, :d, NOW())')
            ->execute([':u' => (int)$dep['user_id'], ':a' => $creditAmount, ':b' => $new, ':r' => 'DEP#' . $id, ':d' => $desc]);

        $upd = $pdo->prepare('UPDATE deposits SET status = "approved", amount = :amt, gateway_status = "TXN_SUCCESS", gateway_txn_id = :t,
                                     gateway_response = :resp, admin_note = :note, reviewed_by = :a, reviewed_at = NOW()
                              WHERE id = :id AND status <> "approved"');
        $upd->execute([':amt' => $creditAmount, ':t' => $creditTxnId, ':resp' => $responseJson, ':note' => $note, ':a' => $adminId, ':id' => $id]);
        if ($upd->rowCount() !== 1) throw new RuntimeException('Deposit state changed during reconciliation.');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[admin/deposits:reconcile] ' . $e->getMessage());
        audit_log('deposit.reconcile', 'failure', ['entity_type' => 'deposit', 'entity_id' => $id, 'user_id' => (int)$dep['user_id'], 'reason' => 'Credit transaction failed: ' . $e->getMessage()]);
        json_error('Could not credit the wallet safely. Nothing was changed.', 500);
    }
    audit_log('deposit.reconciled', 'success', ['entity_type' => 'deposit', 'entity_id' => $id, 'user_id' => (int)$dep['user_id'],
        'reason' => 'Credited ' . number_format($creditAmount, 2, '.', '') . ' after Paytm verification (ref DEP#' . $id . ')']);
    alert_resolve_key('deposit_no_ledger:' . $id, 'Reconciled', $adminId);
    json_ok(['verified' => true, 'credited' => true, 'balance' => $new, 'amount' => $creditAmount,
             'message' => 'Paytm confirmed the payment. ₹' . number_format($creditAmount, 2, '.', '') . ' was credited to the wallet.']);
}

// Paytm deposits are settled only by server-side Paytm verification. A manual
// approve/reject would bypass that check (and could double-credit), so it is refused.
if (($dep['method'] ?? '') === 'paytm_qr') {
    json_error('Paytm deposits are confirmed automatically after Paytm verification and cannot be reviewed manually.', 409);
}
if ($dep['status'] !== 'pending') json_error('Only pending deposits can be reviewed.', 409);

try {
    if ($action === 'approve') {
        $pdo->beginTransaction();
        // Double-check inside tx with row lock to prevent double approval
        $lock = $pdo->prepare('SELECT status FROM deposits WHERE id = :id FOR UPDATE');
        $lock->execute([':id' => $id]);
        if ($lock->fetchColumn() !== 'pending') {
            $pdo->rollBack();
            json_error('Deposit was already reviewed.', 409);
        }

        $userLock = $pdo->prepare('SELECT balance FROM users WHERE id = :id FOR UPDATE');
        $userLock->execute([':id' => (int)$dep['user_id']]);
        $oldBal = (float)$userLock->fetchColumn();
        $newBal = round($oldBal + (float)$dep['amount'], 2);

        $pdo->prepare('UPDATE users SET balance = :b WHERE id = :id')
            ->execute([':b' => $newBal, ':id' => (int)$dep['user_id']]);
        $pdo->prepare(
            'INSERT INTO wallet_ledger (user_id, amount, balance_after, type, reference_id, description, created_at)
             VALUES (:u, :a, :b, "deposit", :r, :d, NOW())'
        )->execute([
            ':u' => (int)$dep['user_id'],
            ':a' => (float)$dep['amount'],
            ':b' => $newBal,
            ':r' => 'DEP#' . $id,
            ':d' => 'Manual deposit approval by admin',
        ]);
        $pdo->prepare('UPDATE deposits SET status = "approved", reviewed_by = :a, reviewed_at = NOW() WHERE id = :id')
            ->execute([':a' => $adminId, ':id' => $id]);
        $pdo->commit();
        audit_log('deposit.manual_approved', 'success', ['entity_type' => 'deposit', 'entity_id' => $id, 'user_id' => (int)$dep['user_id'], 'actor_id' => $adminId,
            'reason' => 'Legacy manual deposit approved, credited ' . number_format((float)$dep['amount'], 2, '.', '')]);
        json_ok(['balance' => $newBal]);
    }

    if ($action === 'reject') {
        $note = trim((string)input_param('note', ''));
        $pdo->prepare('UPDATE deposits SET status = "rejected", admin_note = :n, reviewed_by = :a, reviewed_at = NOW() WHERE id = :id')
            ->execute([':n' => $note, ':a' => $adminId, ':id' => $id]);
        audit_log('deposit.manual_rejected', 'success', ['entity_type' => 'deposit', 'entity_id' => $id, 'user_id' => (int)$dep['user_id'], 'actor_id' => $adminId, 'reason' => $note]);
        json_ok();
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[admin/deposits] ' . $e->getMessage());
    json_error('Operation failed.', 500);
}

json_error('Unknown action.', 400);