<?php
declare(strict_types=1);

/*
 * v59 - SHARED PROVIDER ORDER EXECUTOR
 *
 * ONE code path for "order is charged + pending -> call provider -> classify -> settle" used by BOTH
 *   - website checkout          (api/purchase-api.php)
 *   - external Reseller API buy (api/api/reseller_gateway.php)
 * so that the same provider answer always produces the same order state, wallet effect and customer message.
 *
 * Seven states (OX_STATES). Only the first three can ever change money:
 *   SUCCESS                  provider confirmed + real key(s)          -> order COMPLETED, keys saved, referral once
 *   CONFIRMED_REJECTED       provider explicitly refused               -> FAILED + exact refund (committed first)
 *   CONFIRMED_OUT_OF_STOCK   provider explicitly said out of stock     -> FAILED + exact refund, failure_code OUT_OF_STOCK
 *   UNCERTAIN                timeout / connection lost / 5xx / ended   -> order stays PENDING (held), NEVER refunded, NEVER re-sent
 *   SUCCESS_WITHOUT_KEY      provider accepted but returned no key     -> held exactly like UNCERTAIN (the key may exist)
 *   INVALID_RESPONSE         empty / unreadable / unknown structure    -> held exactly like UNCERTAIN
 *   INTERNAL_ERROR           OUR code/database failed after the send   -> held (before the send it is a safe refund)
 *
 * Duplicate-order protection: provider_order_attempts has UNIQUE(order_id). A provider request is only ever sent after that row has been
 * INSERTed; a second execution for the same order finds the row and refuses to send again.
 * Nothing here invents provider fields: it only consumes what provider_call_classified() / the existing provider adapters return.
 */

const OX_SUCCESS             = 'SUCCESS';
const OX_CONFIRMED_REJECTED  = 'CONFIRMED_REJECTED';
const OX_CONFIRMED_OOS       = 'CONFIRMED_OUT_OF_STOCK';
const OX_UNCERTAIN           = 'UNCERTAIN';
const OX_SUCCESS_NO_KEY      = 'SUCCESS_WITHOUT_KEY';
const OX_INVALID_RESPONSE    = 'INVALID_RESPONSE';
const OX_INTERNAL_ERROR      = 'INTERNAL_ERROR';

function ox_states(): array
{
    return [OX_SUCCESS, OX_CONFIRMED_REJECTED, OX_CONFIRMED_OOS, OX_UNCERTAIN, OX_SUCCESS_NO_KEY, OX_INVALID_RESPONSE, OX_INTERNAL_ERROR];
}

/* ------------------------------------------------------------------ */
/*  Schema (additive, repeatable)                                      */
/* ------------------------------------------------------------------ */

/** Creates provider_order_attempts when missing. MUST run outside a transaction, BEFORE the wallet is charged. Never drops anything. */
function ox_ensure_schema(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        if ($pdo->inTransaction()) throw new RuntimeException('ox_ensure_schema must run outside a transaction');
        $pdo->exec("CREATE TABLE IF NOT EXISTS provider_order_attempts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT UNSIGNED NOT NULL,
            order_ref VARCHAR(40) NOT NULL,
            channel VARCHAR(16) NOT NULL DEFAULT 'website',
            provider_key VARCHAR(20) DEFAULT NULL,
            provider_variant_id VARCHAR(60) DEFAULT NULL,
            idempotency_key VARCHAR(64) DEFAULT NULL,
            state VARCHAR(24) NOT NULL DEFAULT 'SENDING',
            http_code SMALLINT UNSIGNED DEFAULT NULL,
            response_time_ms INT UNSIGNED DEFAULT NULL,
            upstream_order_id VARCHAR(60) DEFAULT NULL,
            error_message VARCHAR(500) DEFAULT NULL,
            response_snapshot TEXT DEFAULT NULL,
            pending_keys MEDIUMTEXT DEFAULT NULL,
            referral_pending TINYINT(1) NOT NULL DEFAULT 0,
            settle_result VARCHAR(20) DEFAULT NULL,
            attempts INT UNSIGNED NOT NULL DEFAULT 1,
            last_reconcile_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_poa_order (order_id),
            KEY idx_poa_state (state, created_at),
            KEY idx_poa_ref (order_ref)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $ok = true;
    } catch (Throwable $e) {
        error_log('[ox_ensure_schema] ' . $e->getMessage());
        $ok = false;
    }
    return $ok;
}

/* ------------------------------------------------------------------ */
/*  Pure helpers                                                       */
/* ------------------------------------------------------------------ */

/**
 * Provider JSON -> short, safe text for the admin log. License keys are masked (only a count is kept); secrets are redacted by ops_sanitize.
 */
function ox_snapshot(?array $json, array $meta = []): string
{
    $mask = static function ($v) use (&$mask) {
        if (!is_array($v)) return $v;
        $out = [];
        foreach ($v as $k => $item) {
            $kl = is_string($k) ? strtolower($k) : '';
            if (in_array($kl, ['key', 'keys', 'license_key', 'license_keys', 'license', 'licenses', 'value', 'serial', 'serials'], true)) {
                $out[$k] = is_array($item) ? '[' . count($item) . ' item(s) hidden]' : '[hidden]';
            } else {
                $out[$k] = $mask($item);
            }
        }
        return $out;
    };
    if ($json !== null) {
        $txt = (string)json_encode($mask($json), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return ops_sanitize($txt, 1500);
    }
    // Not JSON: keep only a very short head, and only when it is clearly an error page (never a body that could hold a key).
    $head = (string)($meta['head'] ?? '');
    $http = (int)($meta['http'] ?? 0);
    if ($head !== '' && ($head[0] === '<' || $http >= 400)) return ops_sanitize('non-JSON reply: ' . mb_substr($head, 0, 120), 200);
    return $head === '' ? 'empty reply' : 'non-JSON reply (' . mb_strlen($head) . '+ chars)';
}

/**
 * Map the result of provider_call_classified() to one of the seven states. Pure.
 * $cls: ['kind'=>success|rejected|no_key|uncertain, 'raw'=>string, 'resp'=>array]
 */
function ox_classify(array $cls): string
{
    $kind = (string)($cls['kind'] ?? 'uncertain');
    $raw  = (string)($cls['raw'] ?? '');
    $resp = is_array($cls['resp'] ?? null) ? $cls['resp'] : [];
    if ($kind === 'success') return OX_SUCCESS;
    if ($kind === 'rejected') return provider_failure_code($raw, $resp) === ORDER_FAILURE_OUT_OF_STOCK ? OX_CONFIRMED_OOS : OX_CONFIRMED_REJECTED;
    if ($kind === 'no_key') return OX_SUCCESS_NO_KEY;
    // uncertain: tell "we could not understand the reply" from "no reply at all" (both are held identically)
    if (preg_match('/empty response|invalid response|unrecognised response|HTML instead of JSON|unreadable/i', $raw)) return OX_INVALID_RESPONSE;
    return OX_UNCERTAIN;
}

/** true when the state means "hold the order, do NOT refund, do NOT re-send". */
function ox_state_is_hold(string $state): bool
{
    return in_array($state, [OX_UNCERTAIN, OX_SUCCESS_NO_KEY, OX_INVALID_RESPONSE, OX_INTERNAL_ERROR], true);
}

/* ------------------------------------------------------------------ */
/*  Attempt row                                                        */
/* ------------------------------------------------------------------ */

/** Returns true when THIS call created the row (= this call may send the request). false = an attempt already exists: never send again. */
function ox_attempt_begin(PDO $pdo, array $o, string $channel, ?string $provider, string $variant, string $idem): bool
{
    try {
        $pdo->prepare(
            "INSERT INTO provider_order_attempts (order_id, order_ref, channel, provider_key, provider_variant_id, idempotency_key, state, created_at, updated_at)
             VALUES (:o, :r, :c, :p, :v, :i, 'SENDING', NOW(), NOW())"
        )->execute([':o' => (int)$o['id'], ':r' => (string)$o['order_ref'], ':c' => substr($channel, 0, 16), ':p' => $provider ? substr($provider, 0, 20) : null,
                    ':v' => substr($variant, 0, 60), ':i' => substr($idem, 0, 64)]);
        return true;
    } catch (Throwable $e) {
        $no = ($e instanceof PDOException && isset($e->errorInfo[1])) ? (int)$e->errorInfo[1] : 0;
        if ($no === 1062) return false;   // duplicate order_id: the provider was (or is being) called for this order already
        throw $e;
    }
}

function ox_attempt_update(PDO $pdo, int $orderId, array $f): void
{
    $allowed = ['state', 'http_code', 'response_time_ms', 'upstream_order_id', 'error_message', 'response_snapshot', 'pending_keys', 'referral_pending', 'settle_result', 'last_reconcile_at'];
    $set = []; $bind = [':__o' => $orderId];
    foreach ($f as $k => $v) {
        if (!in_array($k, $allowed, true)) continue;
        $set[] = $k . ' = :' . $k; $bind[':' . $k] = $v;
    }
    if (!$set) return;
    try {
        $pdo->prepare('UPDATE provider_order_attempts SET ' . implode(', ', $set) . ', updated_at = NOW() WHERE order_id = :__o')->execute($bind);
    } catch (Throwable $e) {
        error_log('[ox_attempt_update] ' . $e->getMessage());   // metadata only: money logic never depends on this row being written
    }
}

/* ------------------------------------------------------------------ */
/*  Success settlement                                                 */
/* ------------------------------------------------------------------ */

/**
 * Turn a PENDING order into COMPLETED with its keys. Idempotent and safe to repeat (used by the executor AND by recovery).
 * Returns 'completed' | 'already' (already completed, nothing changed) | 'conflict' (order is not pending/completed, e.g. refunded) | 'failed' (database error).
 * $referral: also credit the referral commission in the same transaction (the ledger/UNIQUE guards make it exactly-once).
 */
function ox_complete_order(PDO $pdo, int $orderId, int $userId, array $keys, string $upstreamId, string $channel, bool $referral): string
{
    try {
        if ($pdo->inTransaction()) throw new RuntimeException('ox_complete_order must run outside another transaction');
        $pdo->beginTransaction();
        $q = $pdo->prepare('SELECT status, total_price FROM orders WHERE id = :id AND user_id = :u FOR UPDATE');
        $q->execute([':id' => $orderId, ':u' => $userId]);
        $row = $q->fetch();
        if (!$row) { $pdo->rollBack(); return 'failed'; }
        if ((string)$row['status'] === 'completed') {
            // Already completed: make sure the keys exist, never insert them twice.
            $c = $pdo->prepare('SELECT COUNT(*) FROM order_keys WHERE order_id = :o'); $c->execute([':o' => $orderId]);
            if ((int)$c->fetchColumn() === 0 && $keys) {
                $ins = $pdo->prepare('INSERT INTO order_keys (order_id, external_key, delivered_at) VALUES (:o, :k, NOW())');
                foreach ($keys as $k) $ins->execute([':o' => $orderId, ':k' => (string)$k]);
                $pdo->commit();
                return 'completed';
            }
            $pdo->rollBack();
            return 'already';
        }
        if ((string)$row['status'] !== 'pending') { $pdo->rollBack(); return 'conflict'; }

        $pdo->prepare("UPDATE orders SET status = 'completed', upstream_order_id = :uo, failure_reason = NULL WHERE id = :id AND status = 'pending'")
            ->execute([':uo' => $upstreamId !== '' ? substr($upstreamId, 0, 60) : null, ':id' => $orderId]);
        $c = $pdo->prepare('SELECT COUNT(*) FROM order_keys WHERE order_id = :o'); $c->execute([':o' => $orderId]);
        if ((int)$c->fetchColumn() === 0) {
            $ins = $pdo->prepare('INSERT INTO order_keys (order_id, external_key, delivered_at) VALUES (:o, :k, NOW())');
            foreach ($keys as $k) $ins->execute([':o' => $orderId, ':k' => (string)$k]);
        }
        if ($referral) credit_referral_commission($pdo, $userId, $orderId, round((float)$row['total_price'], 2), $channel === 'reseller_api' ? 'reseller' : 'api');
        $pdo->commit();
        return 'completed';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[ox_complete_order] ' . $e->getMessage());
        return 'failed';
    }
}

/**
 * SUCCESS path. The provider already delivered the keys, so this NEVER refunds. Two attempts (full, then essential-only without the referral);
 * if both fail the keys are parked in provider_order_attempts.pending_keys and a critical alert is raised; ox_recover_stranded() finishes the order.
 * Returns ['settle'=>'completed'|'saved_late'|'stranded'|'conflict', ...]
 */
function ox_finalize_success(PDO $pdo, array $o, array $keys, string $upstreamId, string $channel): array
{
    $orderId = (int)$o['id']; $uid = (int)$o['user_id'];
    $r = ox_complete_order($pdo, $orderId, $uid, $keys, $upstreamId, $channel, true);
    if ($r === 'completed' || $r === 'already') {
        ox_attempt_update($pdo, $orderId, ['settle_result' => 'completed', 'pending_keys' => null]);
        return ['settle' => 'completed'];
    }
    if ($r === 'conflict') {
        alert_raise(ops_plan_context($pdo, (int)$o['plan_id']) + [
            'dedupe_key' => 'ox_key_conflict:' . $orderId, 'type' => 'order_inconsistent', 'severity' => 'critical', 'order_id' => $orderId, 'order_ref' => (string)$o['order_ref'],
            'reason' => 'The provider delivered keys but the order is no longer pending (it was settled by another process, e.g. refunded). Keys were NOT attached automatically.',
            'recommended_action' => 'Open the order, check the wallet ledger for a refund and decide manually (keys are in the provider history; upstream id ' . ops_sanitize($upstreamId, 60) . ').',
        ]);
        ox_attempt_update($pdo, $orderId, ['settle_result' => 'conflict']);
        return ['settle' => 'conflict'];
    }
    // second, essential-only attempt (no referral)
    usleep(200000);
    $r2 = ox_complete_order($pdo, $orderId, $uid, $keys, $upstreamId, $channel, false);
    if ($r2 === 'completed' || $r2 === 'already') {
        ox_attempt_update($pdo, $orderId, ['settle_result' => 'completed', 'referral_pending' => 1, 'pending_keys' => null]);
        alert_raise(ops_plan_context($pdo, (int)$o['plan_id']) + [
            'dedupe_key' => 'order_inconsistent:' . $orderId, 'type' => 'order_inconsistent', 'severity' => 'warning', 'order_id' => $orderId, 'order_ref' => (string)$o['order_ref'],
            'reason' => 'Keys were delivered; saving the order succeeded only on the second attempt. The referral commission (if any) is retried automatically.',
            'recommended_action' => 'No action needed unless the referral commission is still missing after the next refund-controller run.',
        ]);
        return ['settle' => 'saved_late'];
    }
    // Park the keys so they cannot be lost; recovery completes the order later.
    ox_attempt_update($pdo, $orderId, ['pending_keys' => (string)json_encode(array_values($keys)), 'upstream_order_id' => substr($upstreamId, 0, 60), 'settle_result' => 'stranded']);
    alert_raise(ops_plan_context($pdo, (int)$o['plan_id']) + [
        'dedupe_key' => 'order_inconsistent:' . $orderId, 'type' => 'order_inconsistent', 'severity' => 'critical', 'order_id' => $orderId, 'order_ref' => (string)$o['order_ref'],
        'reason' => 'The provider delivered the keys but the database could not save the order. The keys are parked safely and the order stays pending (it is NOT refunded).',
        'recommended_action' => 'The refund-controller cron completes it automatically. If it is still pending after a few minutes use "Complete order with key".',
    ]);
    return ['settle' => 'stranded'];
}

/**
 * Recovery (called from cron/refund_controller.php): finish orders whose keys were parked, and add the missing referral commission.
 * Idempotent. Never calls the provider.
 */
function ox_recover_stranded(PDO $pdo): array
{
    $out = ['completed' => 0, 'referral' => 0, 'failed' => 0];
    try {
        if (!ox_ensure_schema($pdo)) return $out;
        $rows = $pdo->query("SELECT a.order_id, a.channel, a.pending_keys, a.upstream_order_id, o.user_id, o.status
                             FROM provider_order_attempts a JOIN orders o ON o.id = a.order_id
                             WHERE a.pending_keys IS NOT NULL AND o.status = 'pending' ORDER BY a.id ASC LIMIT 50")->fetchAll();
        foreach ($rows as $r) {
            $keys = json_decode((string)$r['pending_keys'], true);
            if (!is_array($keys) || !$keys) { $out['failed']++; continue; }
            $res = ox_complete_order($pdo, (int)$r['order_id'], (int)$r['user_id'], array_values(array_map('strval', $keys)), (string)$r['upstream_order_id'], (string)$r['channel'], true);
            if ($res === 'completed' || $res === 'already') {
                ox_attempt_update($pdo, (int)$r['order_id'], ['pending_keys' => null, 'settle_result' => 'completed', 'last_reconcile_at' => date('Y-m-d H:i:s')]);
                alert_resolve_key('order_inconsistent:' . (int)$r['order_id'], 'Completed automatically from the parked keys');
                $out['completed']++;
            } else { $out['failed']++; }
        }
        $rows = $pdo->query("SELECT a.order_id, a.channel, o.user_id, o.total_price FROM provider_order_attempts a JOIN orders o ON o.id = a.order_id
                             WHERE a.referral_pending = 1 AND o.status = 'completed' ORDER BY a.id ASC LIMIT 50")->fetchAll();
        foreach ($rows as $r) {
            try {
                $pdo->beginTransaction();
                credit_referral_commission($pdo, (int)$r['user_id'], (int)$r['order_id'], round((float)$r['total_price'], 2), (string)$r['channel'] === 'reseller_api' ? 'reseller' : 'api');
                $pdo->commit();
                ox_attempt_update($pdo, (int)$r['order_id'], ['referral_pending' => 0]);
                $out['referral']++;
            } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log('[ox_recover:referral] ' . $e->getMessage()); }
        }
    } catch (Throwable $e) { error_log('[ox_recover_stranded] ' . $e->getMessage()); }
    return $out;
}

/* ------------------------------------------------------------------ */
/*  THE executor                                                       */
/* ------------------------------------------------------------------ */

/**
 * Execute the provider order for an ALREADY CHARGED, PENDING order and settle it. The caller creates the order + wallet deduction
 * (its own transaction, which is channel specific) and then calls this; everything after the charge is identical for both channels.
 *
 * $o      : ['id','order_ref','user_id','total','plan_id','quantity']
 * $plan   : row with provider_key, provider_variant_id, provider_api_version, duration
 * $channel: 'website' | 'reseller_api'
 * $opts   : provider extras (api_version, android_id) - the idempotency_key is always this order's own reference
 *
 * @return array{state:string, settle:string, keys:array, upstream_id:string, failure_code:string, refunded:float, balance:?float, message:string, http:?int, ms:int}
 *   settle: completed | saved_late | stranded | conflict          (SUCCESS)
 *           refunded | refund_error | settled                      (CONFIRMED_*)
 *           held                                                   (UNCERTAIN / SUCCESS_WITHOUT_KEY / INVALID_RESPONSE / INTERNAL_ERROR)
 */
function order_execute_provider(PDO $pdo, array $o, array $plan, string $channel, array $opts = []): array
{
    $orderId = (int)$o['id']; $ref = (string)$o['order_ref']; $uid = (int)$o['user_id']; $planId = (int)$o['plan_id'];
    $qty = max(1, (int)($o['quantity'] ?? 1));
    $provider = provider_normalize($plan['provider_key'] ?? null);
    $variant = $provider === PROVIDER_LICENCEDASH ? licencedash_plan_variant($plan) : (string)($plan['provider_variant_id'] ?? '');
    $balanceOf = static function () use ($pdo, $uid): ?float {
        try { $q = $pdo->prepare('SELECT balance FROM users WHERE id = :id'); $q->execute([':id' => $uid]); $v = $q->fetchColumn(); return $v === false ? null : (float)$v; }
        catch (Throwable $e) { return null; }
    };
    $result = static fn(string $state, string $settle, array $x = []): array => $x + [
        'state' => $state, 'settle' => $settle, 'keys' => [], 'upstream_id' => '', 'failure_code' => '', 'refunded' => 0.0, 'balance' => null, 'message' => '', 'http' => null, 'ms' => 0,
    ];

    // The request must run to its decision even if the client disconnects; an unexpected PHP death must never leave a silent PENDING order.
    ignore_user_abort(true);
    @set_time_limit(180);
    $settled = false;
    register_shutdown_function(static function () use ($pdo, $orderId, &$settled): void {
        if ($settled) return;
        try {
            $pdo->prepare("UPDATE orders SET failure_reason = 'UNCERTAIN: request ended before the provider outcome was recorded' WHERE id = :id AND status = 'pending' AND failure_reason IS NULL")->execute([':id' => $orderId]);
            $pdo->prepare("UPDATE provider_order_attempts SET state = 'UNCERTAIN', error_message = 'request ended before the provider outcome was recorded', updated_at = NOW() WHERE order_id = :id AND state = 'SENDING'")->execute([':id' => $orderId]);
        } catch (Throwable $e) { error_log('[order_execute_provider:shutdown] ' . $e->getMessage()); }
    });

    // 1) Record the attempt BEFORE anything is sent. Failure here = nothing was sent = safe to refund (INTERNAL_ERROR before the send).
    try {
        if (!ox_ensure_schema($pdo)) throw new RuntimeException('attempt table unavailable');
        $first = ox_attempt_begin($pdo, $o, $channel, $provider, $variant, $ref);
    } catch (Throwable $e) {
        $st = order_settle_failure($pdo, $o, 'rejected', 'Internal error before the provider request was sent: ' . $e->getMessage(), $provider);
        $settled = true;
        $settle = $st['state'] === 'refunded' ? 'refunded' : ($st['state'] === 'refund_error' ? 'refund_error' : 'settled');
        return $result(OX_INTERNAL_ERROR, $settle, ['refunded' => (float)$st['refunded'], 'balance' => $st['balance'], 'message' => 'Internal error before the provider request was sent.']);
    }
    if (!$first) {
        // The provider was already called for this order: NEVER send a second request.
        $settled = true;
        return $result(OX_UNCERTAIN, 'held', ['balance' => $balanceOf(), 'message' => 'An earlier request for this order already exists; it is being verified.']);
    }

    // 2) The one provider call + classification (same function for every channel).
    provider_call_meta_set(null);
    $t0 = microtime(true);
    $cls = provider_call_classified($provider, $variant, $qty, (string)($plan['duration'] ?? ''), $opts + ['idempotency_key' => $ref]);
    $ms = (int)round((microtime(true) - $t0) * 1000);
    $meta = provider_call_meta_get();
    $http = isset($meta['http']) && (int)$meta['http'] > 0 ? (int)$meta['http'] : null;
    $state = ox_classify($cls);
    $raw = (string)($cls['raw'] ?? '');
    $resp = is_array($cls['resp'] ?? null) ? $cls['resp'] : [];
    $keys = (array)($cls['keys'] ?? []);
    $upstream = substr((string)($cls['upstream_id'] ?? ''), 0, 60);
    $snap = ox_snapshot(isset($meta['json']) && is_array($meta['json']) ? $meta['json'] : null, $meta);
    $base = ['http' => $http, 'ms' => $ms, 'upstream_id' => $upstream];

    ox_attempt_update($pdo, $orderId, [
        'state' => $state, 'http_code' => $http, 'response_time_ms' => $ms, 'upstream_order_id' => $upstream !== '' ? $upstream : null,
        'error_message' => $raw !== '' ? ops_sanitize($raw, 480) : null, 'response_snapshot' => $snap,
    ]);

    try {
        // 3a) SUCCESS
        if ($state === OX_SUCCESS) {
            $fin = ox_finalize_success($pdo, $o, $keys, $upstream, $channel);
            $settled = true;
            alert_resolve_key('gen_fail:plan:' . $planId . ':' . $provider, 'Order succeeded again');
            audit_log('order.success', 'success', ['entity_type' => 'order', 'entity_id' => $orderId, 'order_ref' => $ref, 'user_id' => $uid, 'actor_id' => $uid,
                'reason' => ($channel === 'reseller_api' ? 'Reseller API' : 'Website') . ': provider ' . $provider . ' x' . $qty . ' (' . $ms . ' ms)']);
            return $result(OX_SUCCESS, $fin['settle'], $base + ['keys' => $keys, 'balance' => $balanceOf()]);
        }

        // 3b) CONFIRMED failure -> FAILED + refund (the shared engine commits the refund BEFORE the order becomes FAILED)
        if ($state === OX_CONFIRMED_OOS || $state === OX_CONFIRMED_REJECTED) {
            $st = order_settle_failure($pdo, $o, 'rejected', $raw !== '' ? $raw : 'upstream rejected order', $provider, $resp);
            $settled = true;
            $fc = (string)($st['failure_code'] ?? '');
            $settle = $st['state'] === 'refunded' ? 'refunded' : ($st['state'] === 'refund_error' ? 'refund_error' : 'settled');
            ox_attempt_update($pdo, $orderId, ['settle_result' => $settle]);
            return $result($state, $settle, $base + ['failure_code' => $fc, 'refunded' => (float)$st['refunded'], 'balance' => $st['balance'], 'message' => $raw]);
        }

        // 3c) everything else: HOLD. Not refunded, not re-sent. Reconciliation + admin alert.
        $why = $state === OX_SUCCESS_NO_KEY ? 'Provider accepted the order but returned no key.' : ($raw !== '' ? $raw : 'No clear provider answer.');
        $st = order_settle_failure($pdo, $o, 'uncertain', $why, $provider, $resp);
        $settled = true;
        ox_attempt_update($pdo, $orderId, ['settle_result' => 'held']);
        return $result($state, 'held', $base + ['balance' => $st['balance'], 'message' => $why]);
    } catch (Throwable $e) {
        // OUR code failed AFTER the provider was called: never refund (the key may exist). Hold + alert.
        error_log('[order_execute_provider] ' . $e->getMessage());
        $settled = true;
        try {
            $pdo->prepare("UPDATE orders SET failure_reason = :r WHERE id = :id AND status = 'pending'")->execute([':r' => ops_sanitize('UNCERTAIN: internal error after the provider call: ' . $e->getMessage(), 480), ':id' => $orderId]);
            ox_attempt_update($pdo, $orderId, ['state' => OX_INTERNAL_ERROR, 'error_message' => ops_sanitize($e->getMessage(), 480), 'settle_result' => 'held']);
            alert_raise(ops_plan_context($pdo, $planId) + ['dedupe_key' => 'uncertain_order:' . $orderId, 'type' => 'uncertain_order', 'severity' => 'critical', 'order_id' => $orderId, 'order_ref' => $ref,
                'reason' => 'An internal error happened after the provider request was sent (provider state ' . $state . '). The order was held, not refunded.',
                'recommended_action' => 'Check the provider history for this order, then use "Complete order with key" or "Refund order".']);
        } catch (Throwable $e2) { error_log('[order_execute_provider:hold] ' . $e2->getMessage()); }
        return $result(OX_INTERNAL_ERROR, 'held', $base + ['balance' => $balanceOf(), 'message' => 'Internal error after the provider request.']);
    }
}

/* ------------------------------------------------------------------ */
/*  Admin read side                                                    */
/* ------------------------------------------------------------------ */

/** API order monitor: counts + recent rows. Database only, no provider call. */
function ox_monitor(PDO $pdo, int $limit = 50): array
{
    $out = ['counts' => array_fill_keys(ox_states(), 0), 'rows' => [], 'pending_review' => 0, 'stranded' => 0, 'available' => false];
    try {
        if (!ox_ensure_schema($pdo)) return $out;
        $out['available'] = true;
        foreach ($pdo->query("SELECT state, COUNT(*) c FROM provider_order_attempts WHERE created_at > (NOW() - INTERVAL 7 DAY) GROUP BY state")->fetchAll() as $r) {
            if (isset($out['counts'][$r['state']])) $out['counts'][$r['state']] = (int)$r['c'];
        }
        $out['pending_review'] = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'pending' AND failure_reason LIKE 'UNCERTAIN:%'")->fetchColumn();
        $out['stranded'] = (int)$pdo->query('SELECT COUNT(*) FROM provider_order_attempts WHERE pending_keys IS NOT NULL')->fetchColumn();
        $q = $pdo->query("SELECT a.order_ref, a.channel, a.provider_key, a.state, a.http_code, a.response_time_ms, a.upstream_order_id, a.error_message, a.settle_result,
                                 a.last_reconcile_at, a.created_at, o.status AS order_status, o.refund_status, o.refund_amount, p.name AS product_name, pl.duration
                          FROM provider_order_attempts a JOIN orders o ON o.id = a.order_id
                          LEFT JOIN products p ON p.id = o.product_id LEFT JOIN product_plans pl ON pl.id = o.plan_id
                          ORDER BY a.id DESC LIMIT " . max(1, min(200, $limit)));
        $out['rows'] = $q->fetchAll();
    } catch (Throwable $e) { error_log('[ox_monitor] ' . $e->getMessage()); }
    return $out;
}
