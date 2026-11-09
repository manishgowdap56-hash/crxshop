<?php
declare(strict_types=1);

/*
 * Operations module: audit log, admin alerts, exactly-once refunds, small server-side policy helpers.
 *
 *  - Nothing here ever throws into a customer request: audit/alert writes are best-effort and swallow their own errors.
 *  - Nothing here ever stores passwords, API tokens, Authorization headers or session ids (ops_sanitize redacts them).
 *  - Customers never see technical text: they get ORDER_FAILED_MESSAGE. Details go to audit_logs / admin_alerts (admin only).
 */

const ORDER_FAILED_MESSAGE = 'Order Failed. Your order could not be completed. Please try placing a new order.';
// v39: shown ONLY after the refund transaction has committed (see order_settle_failure()).
const ORDER_FAILED_REFUNDED_MESSAGE = 'ORDER FAILED — AMOUNT REFUNDED TO WALLET';
// Refund transaction failed (database error): the wallet was NOT credited, the order stays recoverable; the customer is told the truth.
const ORDER_FAILED_REFUND_PENDING_MESSAGE = 'ORDER FAILED — your refund could not be completed yet. Your wallet has not been credited; it will be retried automatically.';
// Provider gave no clear answer (timeout etc.): not a confirmed failure, not a success. Payment is held until the outcome is verified.
const ORDER_VERIFYING_MESSAGE = 'Your order is being verified with the provider. Your payment is held and will be refunded automatically if no key was generated.';

/** Redact secrets, strip markup/control characters, collapse whitespace, cap the length. */
function ops_sanitize(?string $s, int $max = 500): string
{
    $s = (string)$s;
    if ($s === '') return '';
    $s = strip_tags($s);
    $s = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', ' ', $s);
    $patterns = [
        '/\bBearer\s+[A-Za-z0-9._\-~+\/=]+/i'                                              => 'Bearer [redacted]',
        '/\b(?:psk|ldk|sk|pk)_[A-Za-z0-9]{8,}\b/i'                                          => '[redacted-token]',
        '/\b(authorization|x-master-key|api[_\-]?key|master[_\-]?key|token|password|passwd|secret|session(?:id)?|phpsessid)\b\s*[=:]\s*[^\s,;&"\']+/i' => '$1=[redacted]',
        '/\b[A-Fa-f0-9]{32,}\b/'                                                            => '[redacted-hex]',
        '/\b[A-Za-z0-9_\-]{40,}\b/'                                                         => '[redacted-long]',
    ];
    foreach ($patterns as $re => $rep) $s = (string)preg_replace($re, $rep, $s);
    $s = trim((string)preg_replace('/\s+/', ' ', $s));
    return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
}

/**
 * Append one audit row. $result: success | failure | info | denied.
 * $ctx: entity_type, entity_id, order_ref, user_id (affected user), reason, actor_id (defaults to the logged-in user).
 * Security events are throttled (same event + IP, 60 s) so an attack cannot flood the table.
 */
function audit_log(string $event, string $result = 'success', array $ctx = []): void
{
    try {
        $pdo = db();
        $actor = $ctx['actor_id'] ?? null;
        $label = null;
        if ($actor === null) {
            $u = function_exists('current_user') ? current_user() : null;
            if ($u) { $actor = (int)$u['id']; $label = (string)($u['username'] ?? ''); }
        }
        $ip = function_exists('client_ip') ? substr(client_ip(), 0, 45) : null;
        if (strpos($event, 'security.') === 0) {
            $q = $pdo->prepare('SELECT 1 FROM audit_logs WHERE event_type = :e AND ip = :ip AND created_at > (NOW() - INTERVAL 60 SECOND) LIMIT 1');
            $q->execute([':e' => $event, ':ip' => $ip]);
            if ($q->fetchColumn()) return;
        }
        $pdo->prepare(
            'INSERT INTO audit_logs (created_at, actor_user_id, actor_label, event_type, result, entity_type, entity_id, order_ref, user_id, reason, ip)
             VALUES (NOW(), :a, :l, :e, :r, :et, :ei, :o, :u, :why, :ip)'
        )->execute([
            ':a'   => $actor !== null ? (int)$actor : null,
            ':l'   => $label !== null ? substr(ops_sanitize($label, 60), 0, 60) : null,
            ':e'   => substr($event, 0, 60),
            ':r'   => in_array($result, ['success', 'failure', 'info', 'denied'], true) ? $result : 'info',
            ':et'  => isset($ctx['entity_type']) ? substr((string)$ctx['entity_type'], 0, 30) : null,
            ':ei'  => isset($ctx['entity_id']) ? substr((string)$ctx['entity_id'], 0, 40) : null,
            ':o'   => isset($ctx['order_ref']) ? substr((string)$ctx['order_ref'], 0, 40) : null,
            ':u'   => isset($ctx['user_id']) ? (int)$ctx['user_id'] : null,
            ':why' => ops_sanitize($ctx['reason'] ?? '', 500) ?: null,
            ':ip'  => $ip,
        ]);
    } catch (Throwable $e) {
        error_log('[audit_log] ' . $e->getMessage());
    }
}

/**
 * Raise (or update) an admin alert. One OPEN alert per dedupe_key: repeated incidents bump `occurrences` / `last_seen`
 * instead of creating duplicates (enforced atomically by the UNIQUE open_key). Resolved alerts free the key.
 */
function alert_raise(array $a): void
{
    try {
        $key = substr((string)($a['dedupe_key'] ?? ''), 0, 120);
        if ($key === '') return;
        db()->prepare(
            "INSERT INTO admin_alerts (open_key, dedupe_key, alert_type, severity, status, product_id, product_name, plan_id, duration,
                                       order_id, order_ref, provider_key, provider_label, stock_source, reason, recommended_action,
                                       occurrences, first_seen, last_seen)
             VALUES (:ok, :dk, :t, :sev, 'open', :pid, :pn, :pl, :dur, :oid, :oref, :pk, :plabel, :src, :why, :act, 1, NOW(), NOW())
             ON DUPLICATE KEY UPDATE occurrences = occurrences + 1, last_seen = NOW(), reason = VALUES(reason),
                                     order_id = COALESCE(VALUES(order_id), order_id), order_ref = COALESCE(VALUES(order_ref), order_ref)"
        )->execute([
            ':ok'    => $key,
            ':dk'    => $key,
            ':t'     => substr((string)($a['type'] ?? 'general'), 0, 40),
            ':sev'   => in_array(($a['severity'] ?? 'warning'), ['info', 'warning', 'critical'], true) ? $a['severity'] : 'warning',
            ':pid'   => isset($a['product_id']) ? (int)$a['product_id'] : null,
            ':pn'    => isset($a['product_name']) ? substr((string)$a['product_name'], 0, 120) : null,
            ':pl'    => isset($a['plan_id']) ? (int)$a['plan_id'] : null,
            ':dur'   => isset($a['duration']) ? substr((string)$a['duration'], 0, 60) : null,
            ':oid'   => isset($a['order_id']) ? (int)$a['order_id'] : null,
            ':oref'  => isset($a['order_ref']) ? substr((string)$a['order_ref'], 0, 40) : null,
            ':pk'    => isset($a['provider_key']) ? substr((string)$a['provider_key'], 0, 20) : null,
            ':plabel'=> isset($a['provider_label']) ? substr((string)$a['provider_label'], 0, 80) : null,
            ':src'   => in_array(($a['stock_source'] ?? null), ['local', 'api'], true) ? $a['stock_source'] : null,
            ':why'   => ops_sanitize($a['reason'] ?? '', 500),
            ':act'   => substr((string)($a['recommended_action'] ?? ''), 0, 500),
        ]);
    } catch (Throwable $e) {
        error_log('[alert_raise] ' . $e->getMessage());
    }
}

function alert_resolve_key(string $dedupeKey, string $note = 'Auto-resolved', ?int $adminId = null): void
{
    try {
        db()->prepare("UPDATE admin_alerts SET status = 'resolved', open_key = NULL, resolved_at = NOW(), resolved_by = :a, resolved_note = :n WHERE open_key = :k")
            ->execute([':a' => $adminId, ':n' => substr($note, 0, 255), ':k' => substr($dedupeKey, 0, 120)]);
    } catch (Throwable $e) {
        error_log('[alert_resolve_key] ' . $e->getMessage());
    }
}

/** Plan + product context for alerts/audit. Never throws. */
function ops_plan_context(PDO $pdo, int $planId): array
{
    try {
        $q = $pdo->prepare('SELECT pl.id AS plan_id, pl.product_id, pl.duration, pl.source, pl.provider_key, p.name AS product_name
                            FROM product_plans pl JOIN products p ON p.id = pl.product_id WHERE pl.id = :id LIMIT 1');
        $q->execute([':id' => $planId]);
        $r = $q->fetch();
        if ($r) {
            $prov = $r['source'] === 'api' ? provider_normalize($r['provider_key'] ?? null) : null;
            return [
                'plan_id' => (int)$r['plan_id'], 'product_id' => (int)$r['product_id'], 'product_name' => (string)$r['product_name'],
                'duration' => (string)$r['duration'], 'stock_source' => $r['source'] === 'api' ? 'api' : 'local',
                'provider_key' => $prov, 'provider_label' => $prov ? provider_info($prov)['label'] : 'Local stock',
            ];
        }
    } catch (Throwable $e) { /* fall through */ }
    return ['plan_id' => $planId, 'product_id' => null, 'product_name' => null, 'duration' => null, 'stock_source' => null, 'provider_key' => null, 'provider_label' => null];
}

/** Local stock watcher: raises "stock is zero" for a local plan, auto-resolves it once keys exist again. */
function ops_check_local_stock(PDO $pdo, int $planId): void
{
    try {
        $ctx = ops_plan_context($pdo, $planId);
        if (($ctx['stock_source'] ?? '') !== 'local') return;
        $n = $pdo->prepare("SELECT COUNT(*) FROM product_keys WHERE plan_id = :p AND status = 'available'");
        $n->execute([':p' => $planId]);
        $key = 'stock_zero:plan:' . $planId;
        if ((int)$n->fetchColumn() === 0) {
            alert_raise($ctx + [
                'dedupe_key' => $key, 'type' => 'stock_zero', 'severity' => 'warning',
                'reason' => 'Local stock for this duration has reached zero.',
                'recommended_action' => 'Add keys: Admin → Create Product → Key Add (or edit the product and paste keys into this duration).',
            ]);
        } else {
            alert_resolve_key($key, 'Stock available again');
        }
    } catch (Throwable $e) { error_log('[ops_check_local_stock] ' . $e->getMessage()); }
}

/**
 * true  = the failure happened BEFORE anything could have reached / been generated by the provider
 *         (configuration, local validation, DNS / connection refused): safe to refund immediately.
 * false = unknown outcome (timeout, empty / HTML / invalid reply after the request was sent): the provider may
 *         already have generated the key, so the wallet is NOT refunded blindly.
 */
function provider_failure_is_certain(string $message): bool
{
    return preg_match('/not configured|URL is invalid|cURL extension|Unknown provider endpoint|is (?:currently )?disabled|could not resolve|couldn.?t resolve|failed to connect|couldn.?t connect|connection refused|missing/i', $message) === 1;
}

/**
 * Only an explicit provider answer counts as "provider is out of stock" (never inferred from a timeout, an HTTP code or silence).
 * v58: underscores/hyphens are treated as spaces, so a provider error code such as OUT_OF_STOCK (which licencedash_error_message()
 * appends to the admin-side text as "[OUT_OF_STOCK]") is recognised. Before v58 the pattern needed literal spaces and missed it.
 */
function provider_message_is_stock(string $message): bool
{
    $m = preg_replace('/[_\-]+/', ' ', $message);
    return preg_match('/out of stock|insufficient stock|no stock|not enough stock|sold out|stock (?:is )?(?:empty|exhausted|unavailable|finished)/i', (string)$m) === 1;
}

/** Stable value stored in orders.failure_code and shown to customers / admins as "OUT OF STOCK". */
const ORDER_FAILURE_OUT_OF_STOCK = 'OUT_OF_STOCK';

/**
 * v58: did the provider DEFINITIVELY say "out of stock"? Looks only at what the provider itself answered:
 *   - $resp['error_code']  = the documented error.code of {"ok":false,"error":{"code":..,"message":..}} (licencedash_generate())
 *   - the provider's own error message text
 * A timeout, unreadable reply, 5xx, HTTP status number or any other error never returns OUT_OF_STOCK. Returns '' when not confirmed.
 */
function provider_failure_code(string $raw, array $resp = []): string
{
    $code = isset($resp['error_code']) && is_scalar($resp['error_code']) ? strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', trim((string)$resp['error_code']))) : '';
    if ($code === ORDER_FAILURE_OUT_OF_STOCK) return ORDER_FAILURE_OUT_OF_STOCK;
    if ($raw !== '' && provider_message_is_stock($raw)) return ORDER_FAILURE_OUT_OF_STOCK;
    return '';
}

/**
 * v58: the failure code of a stored order row. Uses orders.failure_code when present; for failed orders created before v58
 * (column empty) it is derived from the stored provider reason with the same rule. Never invents a code for pending/completed orders.
 */
function order_failure_code(array $row): string
{
    $c = strtoupper(trim((string)($row['failure_code'] ?? '')));
    if ($c === ORDER_FAILURE_OUT_OF_STOCK) return $c;
    if ((string)($row['status'] ?? '') === 'failed' && ($row['failure_reason'] ?? '') !== '' && stripos((string)$row['failure_reason'], 'UNCERTAIN:') !== 0
        && provider_message_is_stock((string)$row['failure_reason'])) {
        return ORDER_FAILURE_OUT_OF_STOCK;
    }
    return '';
}

/** Customer/admin-facing label for a failure code ('' = none). */
function order_failure_label(string $code): string
{
    return $code === ORDER_FAILURE_OUT_OF_STOCK ? 'OUT OF STOCK' : '';
}

/**
 * v58: make sure orders.failure_code exists (additive, cached per request). MUST run BEFORE a transaction is opened (ALTER commits).
 * Returns false when the column cannot be used; callers then simply skip it - money logic never depends on it.
 */
function ops_failure_code_column(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        if (!rp_column_exists($pdo, 'orders', 'failure_code')) rp_add_column($pdo, 'orders', 'failure_code', 'VARCHAR(32) DEFAULT NULL');
        $ok = rp_column_exists($pdo, 'orders', 'failure_code');
    } catch (Throwable $e) {
        error_log('[ops_failure_code_column] ' . $e->getMessage());
        $ok = false;
    }
    return $ok;
}

/**
 * Refund a failed order EXACTLY ONCE. The order row is locked and must still be 'pending'; the status flip to 'failed',
 * the wallet credit and the ledger row commit together or not at all. A second call (retry, double click, two code paths)
 * sees status <> 'pending' and does nothing.
 * Returns: 'refunded' | 'already_settled' | 'refund_failed' | 'no_charge' (nothing was deducted, so nothing is credited)
 * v58: $failureCode (e.g. OUT_OF_STOCK, only ever passed for a provider-CONFIRMED reason) is stored with the status flip, in the same transaction.
 */
function order_refund_once(PDO $pdo, int $orderId, int $userId, float $total, string $orderRef, string $reason, string $method = 'auto', string $failureCode = ''): string
{
    $useCode = ($failureCode !== '' && !$pdo->inTransaction()) ? ops_failure_code_column($pdo) : false;   // ALTER (if ever needed) happens BEFORE the transaction
    try {
        if ($pdo->inTransaction()) throw new RuntimeException('refund must run outside another transaction');
        $pdo->beginTransaction();
        $o = $pdo->prepare('SELECT status FROM orders WHERE id = :id AND user_id = :u FOR UPDATE');
        $o->execute([':id' => $orderId, ':u' => $userId]);
        $st = $o->fetchColumn();
        if ($st !== 'pending') { $pdo->rollBack(); return 'already_settled'; }

        $dup = $pdo->prepare("SELECT id FROM wallet_ledger WHERE user_id = :u AND type = 'refund' AND reference_id = :r LIMIT 1");
        $dup->execute([':u' => $userId, ':r' => $orderRef]);
        if ($dup->fetchColumn()) { $pdo->rollBack(); return 'already_settled'; }

        // v39: refund EXACTLY what was deducted for this order (the purchase ledger row), never a caller-supplied figure, and never
        // refund money that was not deducted (no purchase row = nothing to give back).
        $pq = $pdo->prepare("SELECT amount FROM wallet_ledger WHERE user_id = :u AND type = 'purchase' AND reference_id = :r ORDER BY id ASC LIMIT 1");
        $pq->execute([':u' => $userId, ':r' => $orderRef]);
        $charged = $pq->fetchColumn();
        if ($charged === false || $charged === null) { $pdo->rollBack(); return 'no_charge'; }
        $charged = round(abs((float)$charged), 2);
        if ($charged <= 0) { $pdo->rollBack(); return 'no_charge'; }
        if (abs($charged - $total) > 0.004) error_log('[order_refund_once] order ' . $orderRef . ': caller total ' . $total . ' differs from the amount deducted ' . $charged . '; refunding the deducted amount');
        $total = $charged;

        $ub = $pdo->prepare('SELECT balance FROM users WHERE id = :id FOR UPDATE');
        $ub->execute([':id' => $userId]);
        $cur = (float)$ub->fetchColumn();
        $new = round($cur + $total, 2);
        $pdo->prepare('UPDATE users SET balance = :b WHERE id = :id')->execute([':b' => $new, ':id' => $userId]);
        $pdo->prepare("UPDATE orders SET status = 'failed', failure_reason = :fr WHERE id = :id AND status = 'pending'")
            ->execute([':fr' => ops_sanitize($reason, 480), ':id' => $orderId]);
        $refundDesc = $failureCode === ORDER_FAILURE_OUT_OF_STOCK ? 'Refund: order failed - OUT OF STOCK' : 'Refund: order could not be completed';
        $pdo->prepare(
            "INSERT INTO wallet_ledger (user_id, amount, balance_after, type, reference_id, description, created_at)
             VALUES (:u, :a, :b, 'refund', :r, :d, NOW())"
        )->execute([':u' => $userId, ':a' => $total, ':b' => $new, ':r' => $orderRef, ':d' => $refundDesc]);
        $ledgerId = (int)$pdo->lastInsertId();
        // v38 tracking: record WHAT was credited, HOW (auto/manual) and WHEN, in the same transaction as the wallet credit, so the
        // Pending Orders page can only show "refunded" for a refund that really committed. Never allowed to block the refund itself
        // (a missing tracking column is logged and repaired by ops_schema_check()); a deadlock/lock timeout still aborts everything.
        try {
            $pdo->prepare("UPDATE orders SET refund_status = 'refunded', refund_method = :m, refunded_at = NOW(), refund_amount = :a, refund_ledger_id = :l WHERE id = :id")
                ->execute([':m' => ($method === 'manual' ? 'manual' : 'auto'), ':a' => $total, ':l' => $ledgerId, ':id' => $orderId]);
        } catch (Throwable $te) {
            $no = ($te instanceof PDOException && isset($te->errorInfo[1])) ? (int)$te->errorInfo[1] : 0;
            if ($no === 1213 || $no === 1205) throw $te;
            error_log('[order_refund_once:tracking] ' . $te->getMessage());
        }
        if ($useCode) {   // v58: the structured reason is best effort - a missing column never blocks the refund itself
            try {
                $pdo->prepare("UPDATE orders SET failure_code = :c WHERE id = :id AND status = 'failed'")->execute([':c' => substr($failureCode, 0, 32), ':id' => $orderId]);
            } catch (Throwable $te) {
                $no = ($te instanceof PDOException && isset($te->errorInfo[1])) ? (int)$te->errorInfo[1] : 0;
                if ($no === 1213 || $no === 1205) throw $te;
                error_log('[order_refund_once:failure_code] ' . $te->getMessage());
            }
        }
        $pdo->commit();
        audit_log('order.refund', 'success', ['entity_type' => 'order', 'entity_id' => $orderId, 'order_ref' => $orderRef, 'user_id' => $userId, 'reason' => 'Refunded ' . number_format($total, 2, '.', '')]);
        return 'refunded';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[order_refund_once] ' . $e->getMessage());
        audit_log('order.refund', 'failure', ['entity_type' => 'order', 'entity_id' => $orderId, 'order_ref' => $orderRef, 'user_id' => $userId, 'reason' => 'Refund transaction failed: ' . $e->getMessage()]);
        alert_raise([
            'dedupe_key' => 'refund_failed:order:' . $orderId, 'type' => 'refund_failed', 'severity' => 'critical',
            'order_id' => $orderId, 'order_ref' => $orderRef,
            'reason' => 'The automatic refund for a failed order could not be completed (' . ops_sanitize($e->getMessage(), 200) . ').',
            'recommended_action' => 'Open the alert and use "Refund order" (safe to repeat: it refunds at most once), or check the wallet ledger.',
        ]);
        return 'refund_failed';
    }
}

/**
 * v39: call the provider and CLASSIFY the answer (shared by purchase-api.php and the self-test, so the test exercises the real rules).
 * Returns ['kind' => 'success'|'rejected'|'no_key'|'uncertain', 'keys' => string[], 'raw' => string, 'resp' => array, 'upstream_id' => string]
 *   success   : provider answered ok with at least one real key
 *   rejected  : provider explicitly refused (out of stock, ok:false, HTTP 4xx) or the request could never have reached it (config)
 *   no_key    : provider answered "ok" but returned no key
 *   uncertain : timeout / unreadable reply / unexplained 5xx - the key may exist; NEVER refunded blindly, NEVER re-sent
 */
function provider_call_classified(string $provider, string $variantId, int $quantity, string $durationLabel, array $opts): array
{
    $out = ['kind' => 'rejected', 'keys' => [], 'raw' => '', 'resp' => [], 'upstream_id' => ''];
    try {
        $resp = provider_generate($provider, $variantId, $quantity, $durationLabel, $opts);
    } catch (Throwable $e) {
        $out['raw']  = $e->getMessage();
        $out['kind'] = provider_failure_is_certain($out['raw']) ? 'rejected' : 'uncertain';
        return $out;
    }
    if ((isset($resp['success']) && $resp['success'] === false) || (isset($resp['ok']) && !$resp['ok'])) {
        $e = $resp['error'] ?? 'upstream rejected order';
        $out['raw']  = is_scalar($e) ? (string)$e : 'upstream rejected order';
        $out['kind'] = 'rejected';
        $out['resp'] = $resp;
        return $out;
    }
    $keys = $resp['keys'] ?? (isset($resp['key']) ? [$resp['key']] : []);
    if (!is_array($keys)) $keys = [$keys];
    $keys = array_values(array_filter(array_map('strval', $keys), static fn($v) => $v !== ''));
    $out['upstream_id'] = (string)($resp['order_id'] ?? $resp['request_id'] ?? $resp['id'] ?? '');
    if (!$keys) { $out['raw'] = 'Provider returned no key.'; $out['kind'] = 'no_key'; return $out; }
    $out['kind'] = 'success';
    $out['keys'] = $keys;
    return $out;
}

/**
 * v39: THE single place that turns a provider failure into a final order state. Used by the website purchase endpoint and the
 * reseller gateway so both behave identically.
 *
 *   $kind: 'rejected' | 'no_key'  = CONFIRMED failure (provider said no / returned no key)  -> FAILED + immediate full refund
 *          'uncertain'            = timeout / unreadable reply: the key may exist            -> stays reconciling, NEVER refunded blindly
 *   $o: ['id','order_ref','user_id','total','plan_id']
 *
 * Returns ['state' => 'refunded' | 'refund_error' | 'reconcile' | 'settled', 'refunded' => float, 'balance' => float|null, 'failure_code' => 'OUT_OF_STOCK'|'']
 *   refunded      : the refund transaction COMMITTED (wallet credited, ledger row written, order FAILED) - only now may the UI say "refunded"
 *   refund_error  : confirmed failure but the refund could not be committed (DB error). Nothing was credited; order stays recoverable
 *   reconcile     : outcome unknown; order marked UNCERTAIN for verification
 *   settled       : the order was already settled by someone else (completed / refunded earlier); nothing changed here
 */
function order_settle_failure(PDO $pdo, array $o, string $kind, string $raw, ?string $provider, array $resp = []): array
{
    $orderId = (int)$o['id']; $ref = (string)$o['order_ref']; $uid = (int)$o['user_id']; $total = (float)$o['total'];
    $balanceOf = static function () use ($pdo, $uid): ?float {
        try { $q = $pdo->prepare('SELECT balance FROM users WHERE id = :id'); $q->execute([':id' => $uid]); $v = $q->fetchColumn(); return $v === false ? null : (float)$v; }
        catch (Throwable $e) { return null; }
    };

    ops_record_order_failure($pdo, (int)$o['plan_id'], $orderId, $ref, $kind, $raw, $provider, $resp);   // admin-only details (alerts + audit)

    // v58: OUT OF STOCK is set ONLY for a failure the provider (or our own local inventory) explicitly confirmed. 'uncertain' never qualifies.
    $failureCode = '';
    if ($kind === 'rejected' || $kind === 'local_stock') {
        $failureCode = ($kind === 'local_stock') ? ORDER_FAILURE_OUT_OF_STOCK : provider_failure_code($raw, $resp);
    }

    if ($kind === 'uncertain') {
        try {
            $pdo->prepare("UPDATE orders SET failure_reason = :r WHERE id = :id AND status = 'pending'")
                ->execute([':r' => ops_sanitize('UNCERTAIN: ' . $raw, 480), ':id' => $orderId]);
        } catch (Throwable $e) { error_log('[order_settle_failure:uncertain] ' . $e->getMessage()); }
        return ['state' => 'reconcile', 'refunded' => 0.0, 'balance' => $balanceOf(), 'failure_code' => ''];
    }

    // Confirmed failure: refund NOW, in this request. order_refund_once is idempotent, so one immediate retry after a transient
    // database error (deadlock / lock wait) cannot double-credit.
    $res = order_refund_once($pdo, $orderId, $uid, $total, $ref, $raw, 'auto', $failureCode);
    if ($res === 'refund_failed') { usleep(300000); $res = order_refund_once($pdo, $orderId, $uid, $total, $ref, $raw, 'auto', $failureCode); }

    if ($res === 'refunded') return ['state' => 'refunded', 'refunded' => $total, 'balance' => $balanceOf(), 'failure_code' => $failureCode];

    if ($res === 'already_settled') {
        // Another request/cron already settled it. Report "refunded" only if the refund really exists in the ledger.
        try {
            $q = $pdo->prepare("SELECT amount FROM wallet_ledger WHERE user_id = :u AND type = 'refund' AND reference_id = :r ORDER BY id ASC LIMIT 1");
            $q->execute([':u' => $uid, ':r' => $ref]);
            $amt = $q->fetchColumn();
            if ($amt !== false) return ['state' => 'refunded', 'refunded' => round((float)$amt, 2), 'balance' => $balanceOf(), 'failure_code' => $failureCode];
        } catch (Throwable $e) { error_log('[order_settle_failure:lookup] ' . $e->getMessage()); }
        return ['state' => 'settled', 'refunded' => 0.0, 'balance' => $balanceOf(), 'failure_code' => ''];
    }

    if ($res === 'no_charge') return ['state' => 'settled', 'refunded' => 0.0, 'balance' => $balanceOf(), 'failure_code' => ''];

    // refund_failed twice: nothing was credited. Mark the order refund-eligible so the Pending Orders page / controller retry it.
    try { $pdo->prepare("UPDATE orders SET refund_status = 'eligible', last_checked_at = NOW() WHERE id = :id AND status = 'pending'")->execute([':id' => $orderId]); }
    catch (Throwable $e) { error_log('[order_settle_failure:mark] ' . $e->getMessage()); }
    return ['state' => 'refund_error', 'refunded' => 0.0, 'balance' => $balanceOf(), 'failure_code' => $failureCode];
}

/**
 * Record everything about a failed provider order for admins (alerts + audit). The customer sees ORDER_FAILED_MESSAGE only.
 * $kind: rejected | uncertain | no_key | local_stock
 */
function ops_record_order_failure(PDO $pdo, int $planId, int $orderId, string $orderRef, string $kind, string $rawReason, ?string $provider, array $resp = []): void
{
    $ctx = ops_plan_context($pdo, $planId);
    $reason = ops_sanitize($rawReason, 400);
    $provLabel = $provider ? provider_info($provider)['label'] : ($ctx['provider_label'] ?? null);
    $base = $ctx + ['order_id' => $orderId ?: null, 'order_ref' => $orderRef ?: null, 'provider_key' => $provider ?: ($ctx['provider_key'] ?? null), 'provider_label' => $provLabel];

    audit_log('order.' . ($kind === 'uncertain' ? 'uncertain' : 'failed'), 'failure', [
        'entity_type' => 'order', 'entity_id' => $orderId ?: null, 'order_ref' => $orderRef ?: null, 'reason' => $kind . ': ' . $reason,
    ]);

    if ($kind === 'local_stock') {
        ops_check_local_stock($pdo, $planId);
        return;
    }
    if ($kind === 'uncertain') {
        alert_raise($base + [
            'dedupe_key' => 'uncertain_order:' . $orderId, 'type' => 'uncertain_order', 'severity' => 'critical',
            'reason' => 'The provider did not give a clear answer (' . $reason . '). The wallet was NOT refunded because the key may already have been generated.',
            'recommended_action' => 'Check the provider (Provider 5: API Settings → Recent Keys) for this order. If a key exists: use "Complete order with key". If not: use "Refund order".',
        ]);
        audit_log('provider.unreachable', 'failure', ['entity_type' => 'provider', 'entity_id' => $provider, 'order_ref' => $orderRef ?: null, 'reason' => $reason]);
        try {
            $c = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE event_type = 'provider.unreachable' AND entity_id = :p AND created_at > (NOW() - INTERVAL 15 MINUTE)");
            $c->execute([':p' => (string)$provider]);
            if ((int)$c->fetchColumn() >= 3) {
                alert_raise([
                    'dedupe_key' => 'provider_unreachable:' . $provider, 'type' => 'provider_unreachable', 'severity' => 'critical',
                    'provider_key' => $provider, 'provider_label' => $provLabel,
                    'reason' => 'The provider timed out or was unreachable at least 3 times in the last 15 minutes.',
                    'recommended_action' => 'Check the provider status and your API token/URL (API Settings → Test Connection). Consider switching affected durations OFF until it recovers.',
                ]);
            }
        } catch (Throwable $e) { /* best effort */ }
        return;
    }

    // Explicit provider rejection / no key.
    if (provider_message_is_stock($rawReason)) {
        alert_raise($base + [
            'dedupe_key' => 'provider_stock:plan:' . $planId, 'type' => 'provider_stock', 'severity' => 'warning',
            'reason' => 'The provider reported that this product is out of stock: ' . $reason,
            'recommended_action' => 'Restock at the provider, map the duration to another provider, or switch the duration OFF.',
        ]);
    } elseif (!empty($resp['shortfall'])) {
        alert_raise($base + [
            'dedupe_key' => 'provider_balance:' . $provider, 'type' => 'provider_balance', 'severity' => 'critical',
            'reason' => 'The provider rejected orders because of its own balance/funds: ' . $reason,
            'recommended_action' => 'Top up your balance at the provider (API Settings → Check Balance).',
        ]);
    }
    alert_raise($base + [
        'dedupe_key' => 'gen_fail:plan:' . $planId . ':' . (string)$provider, 'type' => 'generation_failed', 'severity' => 'warning',
        'reason' => 'Key generation failed: ' . $reason,
        'recommended_action' => 'Open the related order for details. If it keeps failing, test the provider connection or switch this duration OFF.',
    ]);
}

/** true only when the account is explicitly flagged API-enabled in the database. Any doubt = false (fail closed). */
function api_user_is_enabled(PDO $pdo, int $userId): bool
{
    try {
        $q = $pdo->prepare('SELECT api_enabled FROM users WHERE id = :id LIMIT 1');
        $q->execute([':id' => $userId]);
        return (int)$q->fetchColumn() === 1;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Is an Access Key required to register? Default and every uncertainty = REQUIRED (never silently bypassed).
 * Reads the settings table directly (not the request cache) so a read failure is detected instead of hidden.
 */
function access_key_required(): bool
{
    try {
        $q = db()->prepare("SELECT setting_value FROM settings WHERE setting_key = 'access_key_required' LIMIT 1");
        $q->execute();
        $v = $q->fetchColumn();
        if ($v === false) return true;                 // never configured = keep the existing behaviour (required)
        return trim((string)$v) !== '0';
    } catch (Throwable $e) {
        error_log('[access_key_required] ' . $e->getMessage());
        return true;                                   // cannot read safely = required
    }
}

/**
 * Light-weight consistency scan (runs at most every 5 minutes, triggered when an admin opens Alerts).
 * Read-only detection: it raises alerts, it never changes money or orders.
 */
function ops_scan_inconsistencies(PDO $pdo, bool $force = false): void
{
    try {
        $last = (int)get_setting('ops_last_scan', '0');
        if (!$force && $last > 0 && (time() - $last) < 300) return;
        set_setting('ops_last_scan', (string)time());

        // 1) Orders stuck in "pending" for more than 10 minutes (uncertain provider results are alerted separately).
        foreach ($pdo->query("SELECT id, order_ref, plan_id, failure_reason FROM orders
                              WHERE status = 'pending' AND created_at < (NOW() - INTERVAL 10 MINUTE)
                                AND (failure_reason IS NULL OR failure_reason NOT LIKE 'UNCERTAIN:%')
                              ORDER BY id DESC LIMIT 50")->fetchAll() as $o) {
            alert_raise(ops_plan_context($pdo, (int)$o['plan_id']) + [
                'dedupe_key' => 'order_stuck:' . $o['id'], 'type' => 'order_inconsistent', 'severity' => 'warning',
                'order_id' => (int)$o['id'], 'order_ref' => $o['order_ref'],
                'reason' => 'Order has been pending for more than 10 minutes (wallet charged, no key delivered, no refund).',
                'recommended_action' => 'Check the provider for this order; then use "Complete order with key" or "Refund order" on this alert.',
            ]);
        }
        // 2) Deposits marked approved without a wallet ledger entry.
        foreach ($pdo->query("SELECT d.id, d.order_ref FROM deposits d
                              WHERE d.status = 'approved'
                                AND NOT EXISTS (SELECT 1 FROM wallet_ledger l WHERE l.type = 'deposit' AND l.reference_id = CONCAT('DEP#', d.id))
                              ORDER BY d.id DESC LIMIT 50")->fetchAll() as $d) {
            alert_raise([
                'dedupe_key' => 'deposit_no_ledger:' . $d['id'], 'type' => 'wallet_inconsistent', 'severity' => 'critical',
                'order_ref' => $d['order_ref'],
                'reason' => 'Deposit #' . $d['id'] . ' is approved but has no wallet ledger credit entry.',
                'recommended_action' => 'Open Wallet & Deposits → deposit #' . $d['id'] . ' → review. Do not credit again without checking the ledger.',
            ]);
        }
        // 3) Negative wallet balances.
        foreach ($pdo->query("SELECT id, username, balance FROM users WHERE balance < 0 ORDER BY id DESC LIMIT 50")->fetchAll() as $u) {
            alert_raise([
                'dedupe_key' => 'negative_balance:' . $u['id'], 'type' => 'wallet_inconsistent', 'severity' => 'critical',
                'reason' => 'User #' . $u['id'] . ' (' . $u['username'] . ') has a negative wallet balance (' . number_format((float)$u['balance'], 2, '.', '') . ').',
                'recommended_action' => 'Review the user\'s wallet ledger and recent orders.',
            ]);
        }
        // 4) Completed orders (last 7 days) that have no delivered key at all.
        foreach ($pdo->query("SELECT o.id, o.order_ref, o.plan_id FROM orders o
                              WHERE o.status = 'completed' AND o.created_at > (NOW() - INTERVAL 7 DAY)
                                AND NOT EXISTS (SELECT 1 FROM order_keys k WHERE k.order_id = o.id)
                              ORDER BY o.id DESC LIMIT 50")->fetchAll() as $o) {
            alert_raise(ops_plan_context($pdo, (int)$o['plan_id']) + [
                'dedupe_key' => 'order_no_keys:' . $o['id'], 'type' => 'order_inconsistent', 'severity' => 'critical',
                'order_id' => (int)$o['id'], 'order_ref' => $o['order_ref'],
                'reason' => 'Order is completed but has no delivered key stored.',
                'recommended_action' => 'Check the customer\'s My Keys and the provider history; attach the key or refund as appropriate.',
            ]);
        }
    } catch (Throwable $e) {
        error_log('[ops_scan] ' . $e->getMessage());
    }
}
