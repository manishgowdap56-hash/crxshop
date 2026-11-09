<?php
declare(strict_types=1);

/*
 * v36 — Pending Orders + Refund Controller (rule-based, deterministic: no guessing, no AI calls).
 *
 * What it does for every order that is still 'pending' after the processing window has expired:
 *   1. gathers EVIDENCE from the real tables (orders, order_keys, product_keys, wallet_ledger, audit_logs, provider settings)
 *   2. decides with fixed rules (rc_decide) - the decision and its evidence are stored in order_refund_log
 *   3. refunds ONLY when failure is positively evidenced, through the EXISTING exactly-once helper order_refund_once()
 *      (row lock + pending->failed gate + wallet credit + ledger row in one DB transaction)
 *   4. sends everything uncertain to admin review (admin_alerts + Pending Orders page). It never refunds on a hunch.
 *
 * It NEVER refunds an order that has delivered keys, a completion record, a provider order id, or an existing refund.
 *
 * PROVIDER LIMITATION (important): none of the five configured providers documents a per-order status endpoint or a
 * callback/webhook (see includes/providers.php). rc_provider_status() therefore reports "unsupported" and the controller
 * relies on the evidence the website itself recorded at purchase time. If a provider later documents a status call, add it
 * inside rc_provider_status() - nothing else has to change.
 */

const RC_DEFAULT_WINDOW = 600;     // seconds an API order may stay pending (matches the existing 10-minute stuck-order scan)
const RC_MIN_WINDOW     = 60;
const RC_MAX_WINDOW     = 86400;
const RC_MAX_RETRIES    = 3;       // status re-checks before an unverifiable order goes to admin review
const RC_LOCK_NAME      = 'royalpanel_refund_controller';

/* ------------------------------------------------------------------ */
/*  Configuration                                                     */
/* ------------------------------------------------------------------ */

function rc_window_seconds(): int
{
    $v = (int)get_setting('order_pending_window_seconds', (string)RC_DEFAULT_WINDOW);
    if ($v <= 0) $v = RC_DEFAULT_WINDOW;
    return max(RC_MIN_WINDOW, min(RC_MAX_WINDOW, $v));
}

function rc_auto_enabled(): bool
{
    return get_setting('refund_controller_auto', '1') === '1';
}

/** SQL expression: the moment an order's processing window ends. $alias is a trusted literal, never user input. */
function rc_expiry_sql(string $alias = 'o'): string
{
    return '(' . $alias . '.created_at + INTERVAL ' . rc_window_seconds() . ' SECOND)';
}

/* ------------------------------------------------------------------ */
/*  v38: ONE mutually-exclusive display state per order                 */
/* ------------------------------------------------------------------ */

/**
 * Order status (orders.status) and refund status (orders.refund_status) are separate columns and stay separate. This expression
 * only DERIVES a single label from them so every order is counted exactly once. A refund is shown as refunded ONLY when the wallet
 * credit is recorded (orders.refund_ledger_id / refund_status='refunded', both written inside the refund transaction).
 * The same text is unit-tested by tools/test_order_states.py (placeholders swapped for SQLite equivalents).
 */
const RC_STATE_CASE_SQL = <<<'SQL'
CASE
  WHEN o.status = 'completed' THEN 'completed'
  WHEN o.status = 'pending' AND o.refund_ledger_id IS NOT NULL THEN 'manual_review'
  WHEN o.status IN ('failed','refunded') AND (o.refund_ledger_id IS NOT NULL OR o.refund_status = 'refunded')
       THEN (CASE WHEN o.refund_method = 'manual' THEN 'manual_refunded' ELSE 'auto_refunded' END)
  WHEN o.status IN ('failed','refunded') THEN 'failed_unrefunded'
  WHEN o.status = 'pending' AND o.refund_status = 'processing' AND NOT {STALE_CLAIM} THEN 'processing'
  WHEN o.status = 'pending' AND o.refund_status = 'failed' THEN 'refund_failed'
  WHEN o.status = 'pending' AND o.refund_status IN ('review','blocked') THEN 'manual_review'
  WHEN o.status = 'pending' AND o.refund_status = 'eligible' THEN 'refund_pending'
  WHEN o.status = 'pending' AND {EXPIRED} THEN 'expired'
  WHEN o.status = 'pending' THEN 'pending'
  ELSE 'other'
END
SQL;

/** The CASE expression for table alias `o` (trusted literal) with the current processing window. */
function rc_state_sql(): string
{
    $w = rc_window_seconds();
    return strtr(RC_STATE_CASE_SQL, [
        '{EXPIRED}'     => "o.created_at < (NOW() - INTERVAL {$w} SECOND)",
        '{STALE_CLAIM}' => "(o.last_checked_at IS NULL OR o.last_checked_at < (NOW() - INTERVAL 5 MINUTE))",
    ]);
}

/** state => label shown on the page */
function rc_state_labels(): array
{
    return [
        'pending'           => 'Pending',
        'processing'        => 'Processing / Checking',
        'expired'           => 'Expired / Overdue',
        'refund_pending'    => 'Refund Pending',
        'refund_failed'     => 'Refund Failed',
        'manual_review'     => 'Manual Review Required',
        'auto_refunded'     => 'Auto Refunded',
        'manual_refunded'   => 'Manual Refund Completed',
        'failed_unrefunded' => 'Confirmed Failed - Not Refunded',
        'completed'         => 'Completed / Delivered',
        'other'             => 'Other',
    ];
}

/** Filter value => list of states (mutually exclusive states; groups may overlap but each order is counted once per state). */
function rc_state_groups(): array
{
    return [
        'pending_open'     => ['pending', 'processing', 'expired', 'refund_pending', 'refund_failed', 'manual_review'],
        'processing'       => ['processing'],
        'confirmed_failed' => ['refund_pending', 'refund_failed', 'failed_unrefunded', 'auto_refunded', 'manual_refunded'],
        'refund_eligible'  => ['refund_pending', 'refund_failed'],
        'auto_refunded'    => ['auto_refunded'],
        'manual_refunded'  => ['manual_refunded'],
        'not_refunded'     => ['expired', 'refund_pending', 'refund_failed', 'manual_review', 'failed_unrefunded'],
        'refund_failed'    => ['refund_failed'],
        'manual_review'    => ['manual_review'],
        'completed'        => ['completed'],
    ];
}

/* ------------------------------------------------------------------ */
/*  Provider status adapter                                           */
/* ------------------------------------------------------------------ */

/**
 * Latest provider-side status of an order.
 * Returns ['state' => not_applicable|unavailable|unsupported|completed|failed|pending|unknown, 'detail' => string]
 * Only completed / failed are ever treated as facts; everything else means "not verified".
 */
function rc_provider_status(array $o): array
{
    if ((string)($o['plan_source'] ?? 'local') !== 'api') {
        return ['state' => 'not_applicable', 'detail' => 'Local-stock order (no provider involved)'];
    }
    $prov = provider_normalize($o['provider_key'] ?? null);
    $info = provider_info($prov);
    if (!$info['enabled'])    return ['state' => 'unavailable', 'detail' => $info['label'] . ' is switched OFF'];
    if (!$info['configured']) return ['state' => 'unavailable', 'detail' => $info['label'] . ' is not configured'];
    return ['state' => 'unsupported', 'detail' => $info['label'] . ' has no documented order-status endpoint'];
}

/* ------------------------------------------------------------------ */
/*  Evidence                                                          */
/* ------------------------------------------------------------------ */

function rc_scalar(PDO $pdo, string $sql, array $params): int
{
    $q = $pdo->prepare($sql);
    $q->execute($params);
    return (int)$q->fetchColumn();
}

/** Everything the rules need. Reads only. */
function rc_collect_evidence(PDO $pdo, array $o): array
{
    $id  = (int)$o['id'];
    $ref = (string)$o['order_ref'];
    $uid = (int)$o['user_id'];

    $ev = [
        'order_id'       => $id,
        'order_ref'      => $ref,
        'status'         => (string)$o['status'],
        'plan_source'    => (string)($o['plan_source'] ?? 'local'),
        'provider_key'   => $o['provider_key'] ?? null,
        'upstream_id'    => trim((string)($o['upstream_order_id'] ?? '')),
        'failure_reason' => (string)($o['failure_reason'] ?? ''),
        'attempts'       => (int)($o['check_attempts'] ?? 0),
        'expired'        => false,
        'order_keys'     => 0,
        'delivered_keys' => 0,
        'purchase_rows'  => 0,
        'refund_rows'    => 0,
        'audit_ok'       => true,
        'audit_failed'   => 0,
        'audit_uncertain'=> 0,
        'audit_success'  => 0,
        'provider'       => ['state' => 'unknown', 'detail' => ''],
    ];

    // Expiry is decided by MySQL (same clock that wrote created_at), never by PHP, so a DB/PHP time-zone difference cannot matter.
    $ev['expired']        = (int)($o['is_expired'] ?? 0) === 1;
    $ev['order_keys']     = rc_scalar($pdo, 'SELECT COUNT(*) FROM order_keys WHERE order_id = :o', [':o' => $id]);
    $ev['delivered_keys'] = rc_scalar($pdo, "SELECT COUNT(*) FROM product_keys WHERE order_id = :o AND status = 'delivered'", [':o' => $id]);
    $ev['purchase_rows']  = rc_scalar($pdo, "SELECT COUNT(*) FROM wallet_ledger WHERE user_id = :u AND type = 'purchase' AND reference_id = :r", [':u' => $uid, ':r' => $ref]);
    $ev['refund_rows']    = rc_scalar($pdo, "SELECT COUNT(*) FROM wallet_ledger WHERE user_id = :u AND type = 'refund' AND reference_id = :r", [':u' => $uid, ':r' => $ref]);

    try {
        $q = $pdo->prepare("SELECT event_type, COUNT(*) AS c FROM audit_logs WHERE order_ref = :r AND event_type IN ('order.failed','order.uncertain','order.success') GROUP BY event_type");
        $q->execute([':r' => $ref]);
        foreach ($q->fetchAll() as $r) {
            if ($r['event_type'] === 'order.failed')    $ev['audit_failed']    = (int)$r['c'];
            if ($r['event_type'] === 'order.uncertain') $ev['audit_uncertain'] = (int)$r['c'];
            if ($r['event_type'] === 'order.success')   $ev['audit_success']   = (int)$r['c'];
        }
    } catch (Throwable $e) {
        $ev['audit_ok'] = false;      // cannot read the trail => no "confirmed failure" can be proven
        error_log('[rc_collect_evidence] ' . $e->getMessage());
    }

    // v59: keys parked by the shared executor (provider delivered, database save failed) or a recorded provider SUCCESS = never refundable.
    $ev['parked_keys'] = false;
    try {
        if (rp_table_exists($pdo, 'provider_order_attempts')) {
            $ev['parked_keys'] = rc_scalar($pdo, "SELECT COUNT(*) FROM provider_order_attempts WHERE order_id = :o AND (pending_keys IS NOT NULL OR state = 'SUCCESS')", [':o' => $id]) > 0;
        }
    } catch (Throwable $e) { $ev['audit_ok'] = false; error_log('[rc_collect_evidence:attempts] ' . $e->getMessage()); }

    $ev['provider'] = rc_provider_status($o);
    return $ev;
}

/* ------------------------------------------------------------------ */
/*  Rules (pure function: no I/O, unit-testable)                      */
/* ------------------------------------------------------------------ */

/**
 * @return array{decision:string, refund_status:?string, api_status:string, reason:string, severity:string}
 * decision: settled | not_expired | blocked | wait | retry | review | eligible
 */
function rc_decide(array $ev): array
{
    $out = static fn(string $d, ?string $rs, string $api, string $why, string $sev = 'warning'): array =>
        ['decision' => $d, 'refund_status' => $rs, 'api_status' => $api, 'reason' => $why, 'severity' => $sev];

    if ($ev['status'] !== 'pending') {
        return $out('settled', null, 'settled (' . $ev['status'] . ')', 'Order is no longer pending.', 'info');
    }
    if (!$ev['expired']) {
        return $out('not_expired', null, 'inside processing window', 'Processing window has not expired yet.', 'info');
    }

    // ---- hard blocks: money must NOT be refunded -------------------------------------------------
    if ($ev['order_keys'] > 0 || $ev['delivered_keys'] > 0) {
        return $out('blocked', 'blocked', 'delivered', 'Keys are recorded for this order: it was delivered. Refund is not permitted.', 'critical');
    }
    if (!empty($ev['parked_keys'])) {
        return $out('blocked', 'blocked', 'provider delivered', 'The provider confirmed success for this order (keys are parked or recorded). Refund is not permitted; the order is completed automatically.', 'critical');
    }
    if ($ev['audit_success'] > 0) {
        return $out('blocked', 'blocked', 'completion recorded', 'A completion record exists for this order. Refund is not permitted until an admin verifies it.', 'critical');
    }
    if ($ev['refund_rows'] > 0) {
        return $out('blocked', 'blocked', 'already refunded', 'A refund ledger entry already exists for this order. No second refund.', 'critical');
    }
    if ($ev['purchase_rows'] === 0) {
        return $out('review', 'review', 'no charge found', 'No wallet charge was found for this order, so there is nothing safe to refund. Admin must check.', 'critical');
    }
    if ($ev['upstream_id'] !== '') {
        return $out('review', 'review', 'provider accepted', 'The provider returned an order id, so the key may have been generated. Admin must verify.', 'critical');
    }

    // ---- provider status (only completed / failed are facts) --------------------------------------
    $ps = (string)$ev['provider']['state'];
    if ($ps === 'completed') {
        return $out('review', 'review', 'provider: completed', 'Provider reports the order completed but no key is stored. Attach the key (Complete order); do not refund.', 'critical');
    }
    if ($ps === 'failed') {
        return $out('eligible', 'eligible', 'provider: failed', 'Provider reports the order failed.', 'info');
    }

    // ---- confirmed failure recorded by the website at purchase time -------------------------------
    $uncertainMarked = strpos($ev['failure_reason'], 'UNCERTAIN:') === 0 || $ev['audit_uncertain'] > 0;
    if ($ev['audit_ok'] && $ev['audit_failed'] > 0 && !$uncertainMarked) {
        return $out('eligible', 'eligible', 'failed (recorded at purchase)', 'The purchase flow recorded a confirmed failure (provider rejected or request never sent) but the refund did not complete.', 'info');
    }

    // ---- local stock is delivered atomically: pending + no keys = never delivered ------------------
    if ($ev['plan_source'] !== 'api' && !$uncertainMarked) {
        return $out('eligible', 'eligible', 'local delivery incomplete', 'Local-stock delivery is atomic; the order is pending with no key delivered.', 'info');
    }

    // ---- status could not be verified --------------------------------------------------------------
    if ($ps === 'pending') {
        if ($ev['attempts'] < RC_MAX_RETRIES) return $out('wait', null, 'provider: pending', 'Provider still processing; will re-check.', 'info');
        return $out('review', 'review', 'provider: pending', 'Provider still reports pending after ' . RC_MAX_RETRIES . ' checks. Admin must decide.');
    }
    if ($ps === 'unavailable' || $ps === 'unknown') {
        if ($ev['attempts'] < RC_MAX_RETRIES) return $out('retry', null, 'provider unavailable: ' . $ev['provider']['detail'], 'Provider status unavailable; will re-check.', 'info');
        return $out('review', 'review', 'provider unavailable', 'Provider status could not be verified after ' . RC_MAX_RETRIES . ' checks (' . $ev['provider']['detail'] . '). No unsafe refund was made.');
    }
    $why = $uncertainMarked
        ? 'The provider gave no clear answer when the order was placed (timeout / unreadable reply); the key may exist. ' . $ev['provider']['detail'] . '.'
        : 'No evidence proves the order failed or completed. ' . $ev['provider']['detail'] . '.';
    return $out('review', 'review', $uncertainMarked ? 'uncertain (no clear provider reply)' : 'unverified', $why);
}

/* ------------------------------------------------------------------ */
/*  Logging                                                           */
/* ------------------------------------------------------------------ */

/**
 * Append one row to the decision log (history is never deleted or rewritten). Best effort: never throws into the caller.
 * $prev / $new: "<order status>/<refund status>" before and after the decision; $api: provider/API result summary.
 */
function rc_log(PDO $pdo, array $o, string $decision, string $result, string $reason, array $evidence, string $actor, ?string $prev = null, ?string $new = null, ?string $api = null): void
{
    $clean = [];
    foreach ($evidence as $k => $v) {
        $clean[$k] = is_array($v) ? array_map(static fn($x) => is_string($x) ? ops_sanitize($x, 160) : $x, $v) : (is_string($v) ? ops_sanitize($v, 160) : $v);
    }
    $base = [
        ':o' => (int)$o['id'], ':r' => (string)$o['order_ref'], ':u' => (int)$o['user_id'], ':a' => (float)$o['total_price'],
        ':d' => substr($decision, 0, 24), ':res' => substr($result, 0, 24), ':why' => ops_sanitize($reason, 500),
        ':ev' => substr((string)json_encode($clean, JSON_UNESCAPED_UNICODE), 0, 3000), ':act' => substr($actor, 0, 40),
    ];
    try {
        $pdo->prepare(
            'INSERT INTO order_refund_log (order_id, order_ref, user_id, amount, decision, result, reason, evidence, actor, prev_status, new_status, api_status, created_at)
             VALUES (:o, :r, :u, :a, :d, :res, :why, :ev, :act, :ps, :ns, :api, NOW())'
        )->execute($base + [':ps' => $prev !== null ? substr($prev, 0, 40) : null, ':ns' => $new !== null ? substr($new, 0, 40) : null, ':api' => $api !== null ? ops_sanitize($api, 80) : null]);
        return;
    } catch (Throwable $e) {
        error_log('[rc_log] extended insert failed, retrying without v38 columns: ' . $e->getMessage());
    }
    try {   // the log must never be lost because a new column is missing
        $pdo->prepare(
            'INSERT INTO order_refund_log (order_id, order_ref, user_id, amount, decision, result, reason, evidence, actor, created_at)
             VALUES (:o, :r, :u, :a, :d, :res, :why, :ev, :act, NOW())'
        )->execute($base);
    } catch (Throwable $e) {
        error_log('[rc_log] ' . $e->getMessage());
    }
}

/** "<order status>/<refund status>" snapshot used in the decision log. */
function rc_snapshot(string $status, ?string $refundStatus): string
{
    return $status . '/' . (($refundStatus === null || $refundStatus === '') ? 'none' : $refundStatus);
}

/** Resolve the controller's open alerts for an order that is settled now. */
function rc_resolve_order_alerts(int $orderId, string $note): void
{
    foreach (['rc_review:' . $orderId, 'refund_failed:order:' . $orderId, 'order_stuck:' . $orderId, 'uncertain_order:' . $orderId] as $k) {
        alert_resolve_key($k, $note);
    }
}

/* ------------------------------------------------------------------ */
/*  Refund execution (single order)                                   */
/* ------------------------------------------------------------------ */

/**
 * Claim the order so only one worker refunds it at a time. A stale claim (crashed worker) is re-claimable after 5 minutes.
 * This is IN ADDITION to the row lock + status gate inside order_refund_once(), which is what truly guarantees exactly-once.
 */
function rc_claim(PDO $pdo, int $orderId): bool
{
    try {
        $st = $pdo->prepare(
            "UPDATE orders SET refund_status = 'processing', last_checked_at = NOW()
             WHERE id = :id AND status = 'pending'
               AND (refund_status IS NULL OR refund_status <> 'processing' OR last_checked_at IS NULL OR last_checked_at < (NOW() - INTERVAL 5 MINUTE))"
        );
        $st->execute([':id' => $orderId]);
        return $st->rowCount() === 1;
    } catch (Throwable $e) {
        error_log('[rc_claim] ' . $e->getMessage());
        return false;
    }
}

/** Mark the outcome on the order row. Best effort (columns come from the v36 migration). */
function rc_mark(PDO $pdo, int $orderId, ?string $refundStatus, ?string $method = null, bool $refundedNow = false): void
{
    try {
        $sql = 'UPDATE orders SET refund_status = :rs, last_checked_at = NOW()';
        $p = [':rs' => $refundStatus, ':id' => $orderId];
        if ($method !== null)  { $sql .= ', refund_method = :m'; $p[':m'] = $method; }
        if ($refundedNow)      { $sql .= ', refunded_at = NOW()'; }
        $pdo->prepare($sql . ' WHERE id = :id')->execute($p);
    } catch (Throwable $e) {
        error_log('[rc_mark] ' . $e->getMessage());
    }
}

/**
 * Do the refund. Returns 'refunded' | 'already_settled' | 'refund_failed' | 'busy'.
 * $method: 'auto' | 'manual'.
 */
function rc_execute_refund(PDO $pdo, array $o, string $method, string $reasonText): string
{
    $id = (int)$o['id'];
    if (!rc_claim($pdo, $id)) return 'busy';
    $res = order_refund_once($pdo, $id, (int)$o['user_id'], (float)$o['total_price'], (string)$o['order_ref'], $reasonText, $method);
    if ($res === 'refunded') {
        rc_mark($pdo, $id, 'refunded', $method, true);
        rc_resolve_order_alerts($id, $method === 'auto' ? 'Auto-refunded by the Refund Controller' : 'Refunded by admin');
    } elseif ($res === 'refund_failed') {
        rc_mark($pdo, $id, 'failed');            // stays pending -> the next run retries safely (idempotent)
    } else {
        // Someone else settled it first. Leave refund_status as the winner wrote it, just release the claim.
        try { $pdo->prepare("UPDATE orders SET refund_status = NULL WHERE id = :id AND status = 'pending' AND refund_status = 'processing'")->execute([':id' => $id]); } catch (Throwable $e) { /* best effort */ }
    }
    return $res;
}

/* ------------------------------------------------------------------ */
/*  Process one order                                                 */
/* ------------------------------------------------------------------ */

/**
 * Evaluate one order and act on the decision.
 * @return array{decision:string, result:string, message:string}
 */
function rc_process_order(PDO $pdo, int $orderId, string $actor = 'cron', bool $allowAuto = true): array
{
    $q = $pdo->prepare('SELECT o.*, u.username, (o.created_at < (NOW() - INTERVAL ' . rc_window_seconds() . ' SECOND)) AS is_expired
                        FROM orders o JOIN users u ON u.id = o.user_id WHERE o.id = :id LIMIT 1');
    $q->execute([':id' => $orderId]);
    $o = $q->fetch();
    if (!$o) return ['decision' => 'settled', 'result' => 'missing', 'message' => 'Order not found.'];

    $prevRs = (string)($o['refund_status'] ?? '');
    $ev = rc_collect_evidence($pdo, $o);
    $d  = rc_decide($ev);

    if ($d['decision'] === 'settled' || $d['decision'] === 'not_expired') {
        return ['decision' => $d['decision'], 'result' => 'none', 'message' => $d['reason']];
    }

    // Track the check on the order row (latest API status, attempts, review flag). Never overwrite a live 'processing' claim.
    try {
        $pdo->prepare(
            "UPDATE orders SET last_api_status = :s, last_checked_at = NOW(), check_attempts = LEAST(check_attempts + 1, 60000),
                    refund_status = CASE WHEN refund_status = 'processing' THEN refund_status ELSE :rs END
             WHERE id = :id AND status = 'pending'"
        )->execute([':s' => substr($d['api_status'], 0, 80), ':rs' => $d['refund_status'], ':id' => $orderId]);
    } catch (Throwable $e) {
        error_log('[rc_process_order:track] ' . $e->getMessage());
    }

    $changed = $prevRs !== (string)$d['refund_status'];

    $prevSnap = rc_snapshot((string)$o['status'], $prevRs);
    if ($d['decision'] === 'wait' || $d['decision'] === 'retry') {
        rc_log($pdo, $o, $d['decision'], 'rechecking', $d['reason'], $ev, $actor, $prevSnap, rc_snapshot('pending', $prevRs), $d['api_status']);   // bounded: at most RC_MAX_RETRIES rows per order
        return ['decision' => $d['decision'], 'result' => 'rechecking', 'message' => $d['reason']];
    }

    if ($d['decision'] === 'blocked' || $d['decision'] === 'review') {
        if ($changed) {
            rc_log($pdo, $o, $d['decision'], 'no_refund', $d['reason'], $ev, $actor, $prevSnap, rc_snapshot('pending', $d['refund_status']), $d['api_status']);
            audit_log('order.refund_review', 'info', ['entity_type' => 'order', 'entity_id' => $orderId, 'order_ref' => $o['order_ref'], 'user_id' => (int)$o['user_id'], 'reason' => $d['api_status'] . ': ' . $d['reason']]);
            alert_raise(ops_plan_context($pdo, (int)$o['plan_id']) + [
                'dedupe_key' => 'rc_review:' . $orderId, 'type' => 'refund_review', 'severity' => $d['severity'] === 'critical' ? 'critical' : 'warning',
                'order_id' => $orderId, 'order_ref' => (string)$o['order_ref'],
                'provider_key' => $o['provider_key'] ?? null,
                'reason' => 'Expired pending order needs review (' . $d['api_status'] . '). ' . $d['reason'],
                'recommended_action' => 'Open Pending Orders → this order. If the provider delivered a key use "Complete order with key"; if it did not, use "Manual refund".',
            ]);
        }
        return ['decision' => $d['decision'], 'result' => 'no_refund', 'message' => $d['reason']];
    }

    // ---- eligible ----
    if (!$allowAuto || !rc_auto_enabled()) {
        if ($changed) {
            rc_log($pdo, $o, 'eligible', 'not_executed', $d['reason'] . ' Automatic refunds are OFF.', $ev, $actor, $prevSnap, rc_snapshot('pending', 'eligible'), $d['api_status']);
            alert_raise(ops_plan_context($pdo, (int)$o['plan_id']) + [
                'dedupe_key' => 'rc_review:' . $orderId, 'type' => 'refund_review', 'severity' => 'warning',
                'order_id' => $orderId, 'order_ref' => (string)$o['order_ref'],
                'reason' => 'Order is eligible for refund but automatic refunds are OFF. ' . $d['reason'],
                'recommended_action' => 'Open Pending Orders and refund it manually, or switch automatic refunds ON.',
            ]);
        }
        return ['decision' => 'eligible', 'result' => 'not_executed', 'message' => 'Eligible, automatic refunds are OFF.'];
    }

    $res = rc_execute_refund($pdo, $o, 'auto', 'Auto refund: ' . $d['reason']);
    $afterSnap = $res === 'refunded' ? rc_snapshot('failed', 'refunded') : ($res === 'refund_failed' ? rc_snapshot('pending', 'failed') : rc_snapshot('pending', $d['refund_status']));
    rc_log($pdo, $o, 'eligible', $res, $d['reason'], $ev, $actor, $prevSnap, $afterSnap, $d['api_status']);
    if ($res === 'refunded') {
        audit_log('order.auto_refund', 'success', ['entity_type' => 'order', 'entity_id' => $orderId, 'order_ref' => $o['order_ref'], 'user_id' => (int)$o['user_id'], 'actor_id' => 0, 'reason' => 'Controller refunded ' . number_format((float)$o['total_price'], 2, '.', '') . ': ' . $d['reason']]);
    } elseif ($res === 'refund_failed') {
        audit_log('order.auto_refund', 'failure', ['entity_type' => 'order', 'entity_id' => $orderId, 'order_ref' => $o['order_ref'], 'user_id' => (int)$o['user_id'], 'actor_id' => 0, 'reason' => 'Automatic refund transaction failed; it will be retried safely on the next run.']);
    }
    return ['decision' => 'eligible', 'result' => $res, 'message' => $d['reason']];
}

/* ------------------------------------------------------------------ */
/*  Run over all expired pending orders                               */
/* ------------------------------------------------------------------ */

/**
 * One controller pass. Safe to run from cron every minute and from the web at the same time:
 * a named DB lock keeps passes from overlapping, and each refund is claimed + row-locked.
 */
function rc_run(PDO $pdo, string $actor = 'cron', int $limit = 200): array
{
    $sum = ['checked' => 0, 'refunded' => 0, 'review' => 0, 'blocked' => 0, 'retry' => 0, 'refund_failed' => 0, 'not_executed' => 0, 'skipped' => false];

    // v38: cheap self-heal so a missing tracking column can never silently disable the controller (cron calls this every minute).
    try { ops_schema_check($pdo, true); } catch (Throwable $e) { error_log('[rc_run] schema check: ' . $e->getMessage()); }

    $gotLock = true;
    try {
        $gotLock = ((int)$pdo->query("SELECT GET_LOCK('" . RC_LOCK_NAME . "', 0)")->fetchColumn() === 1);
    } catch (Throwable $e) {
        error_log('[rc_run] GET_LOCK unavailable, relying on row claims: ' . $e->getMessage());
    }
    if (!$gotLock) { $sum['skipped'] = true; return $sum; }

    try {
        $ids = $pdo->query(
            "SELECT o.id FROM orders o WHERE o.status = 'pending' AND o.created_at < (NOW() - INTERVAL " . rc_window_seconds() . " SECOND)
             ORDER BY o.id ASC LIMIT " . max(1, min(1000, $limit))
        )->fetchAll(PDO::FETCH_COLUMN);

        foreach ($ids as $id) {
            try {
                $r = rc_process_order($pdo, (int)$id, $actor, true);
            } catch (Throwable $e) {
                error_log('[rc_run] order ' . $id . ': ' . $e->getMessage());
                continue;
            }
            $sum['checked']++;
            if ($r['result'] === 'refunded')          $sum['refunded']++;
            elseif ($r['result'] === 'refund_failed') $sum['refund_failed']++;
            elseif ($r['result'] === 'not_executed')  $sum['not_executed']++;
            elseif ($r['decision'] === 'review')      $sum['review']++;
            elseif ($r['decision'] === 'blocked')     $sum['blocked']++;
            elseif ($r['decision'] === 'retry' || $r['decision'] === 'wait') $sum['retry']++;
        }

        // Close controller alerts whose order was settled some other way (admin completed it, refunded from Alerts, ...).
        foreach ($pdo->query("SELECT open_key FROM admin_alerts WHERE status = 'open' AND open_key LIKE 'rc\\_review:%'")->fetchAll(PDO::FETCH_COLUMN) as $k) {
            $oid = (int)substr((string)$k, strlen('rc_review:'));
            $st = $pdo->prepare('SELECT status FROM orders WHERE id = :id');
            $st->execute([':id' => $oid]);
            $s = $st->fetchColumn();
            if ($s !== false && $s !== 'pending') alert_resolve_key((string)$k, 'Order settled (' . $s . ')');
        }

        set_setting('refund_controller_last_run', (string)time());
        set_setting('refund_controller_last_summary', (string)json_encode($sum));
    } finally {
        try { $pdo->query("SELECT RELEASE_LOCK('" . RC_LOCK_NAME . "')")->fetchColumn(); } catch (Throwable $e) { /* ignore */ }
    }
    return $sum;
}

/** Fallback trigger for hosts where cron is not set up yet: runs at most every 2 minutes, only from admin pages. */
function rc_maybe_run(PDO $pdo): void
{
    $last = (int)get_setting('refund_controller_last_run', '0');
    if ($last > 0 && (time() - $last) < 120) return;
    try { rc_run($pdo, 'admin-page'); } catch (Throwable $e) { error_log('[rc_maybe_run] ' . $e->getMessage()); }
}

/* ------------------------------------------------------------------ */
/*  Manual refund (authorised admin)                                  */
/* ------------------------------------------------------------------ */

/** @return array{ok:bool, code:string, message:string} */
function rc_manual_refund(PDO $pdo, int $orderId, int $adminId, string $reason): array
{
    $reason = trim($reason);
    if (mb_strlen($reason) < 3) return ['ok' => false, 'code' => 'REASON_REQUIRED', 'message' => 'Enter a reason (at least 3 characters).'];

    $q = $pdo->prepare('SELECT o.*, u.username, (o.created_at < (NOW() - INTERVAL ' . rc_window_seconds() . ' SECOND)) AS is_expired,
                               (o.created_at + INTERVAL ' . rc_window_seconds() . ' SECOND) AS expires_at
                        FROM orders o JOIN users u ON u.id = o.user_id WHERE o.id = :id LIMIT 1');
    $q->execute([':id' => $orderId]);
    $o = $q->fetch();
    if (!$o) return ['ok' => false, 'code' => 'NOT_FOUND', 'message' => 'Order not found.'];
    if ($o['status'] !== 'pending') return ['ok' => false, 'code' => 'NOT_PENDING', 'message' => 'Only a pending order can be refunded (this one is ' . $o['status'] . ').'];

    $ev = rc_collect_evidence($pdo, $o);
    if (!$ev['expired']) {
        return ['ok' => false, 'code' => 'NOT_EXPIRED', 'message' => 'This order is still inside its processing window (ends ' . $o['expires_at'] . '). A key may still arrive.'];
    }
    if ($ev['order_keys'] > 0 || $ev['delivered_keys'] > 0) return ['ok' => false, 'code' => 'DELIVERED', 'message' => 'Keys are recorded for this order: it was delivered and cannot be refunded.'];
    if ($ev['refund_rows'] > 0) return ['ok' => false, 'code' => 'ALREADY_REFUNDED', 'message' => 'A refund already exists for this order.'];
    if ($ev['purchase_rows'] === 0) return ['ok' => false, 'code' => 'NOT_CHARGED', 'message' => 'No wallet charge exists for this order, so there is nothing to refund.'];

    $res = rc_execute_refund($pdo, $o, 'manual', 'Manual refund by admin: ' . $reason);
    $actor = 'admin:' . $adminId;
    rc_log($pdo, $o, 'manual', $res, $reason, $ev, $actor, rc_snapshot('pending', (string)($o['refund_status'] ?? '')), $res === 'refunded' ? rc_snapshot('failed', 'refunded') : rc_snapshot('pending', $res === 'refund_failed' ? 'failed' : (string)($o['refund_status'] ?? '')), 'manual refund by admin');
    if ($res === 'refunded') {
        audit_log('order.admin_refund', 'success', ['entity_type' => 'order', 'entity_id' => $orderId, 'order_ref' => $o['order_ref'], 'user_id' => (int)$o['user_id'], 'actor_id' => $adminId, 'reason' => 'Manual refund ' . number_format((float)$o['total_price'], 2, '.', '') . ': ' . $reason]);
        return ['ok' => true, 'code' => 'REFUNDED', 'message' => 'Refunded ' . money($o['total_price']) . ' to ' . $o['username'] . '.'];
    }
    if ($res === 'busy')            return ['ok' => false, 'code' => 'BUSY', 'message' => 'Another refund for this order is in progress. Try again in a moment.'];
    if ($res === 'already_settled') return ['ok' => false, 'code' => 'ALREADY_SETTLED', 'message' => 'This order was settled a moment ago. No second refund was made.'];
    return ['ok' => false, 'code' => 'REFUND_FAILED', 'message' => 'The refund transaction failed. Nothing was changed; it is safe to try again.'];
}

/* ------------------------------------------------------------------ */
/*  Counters                                                          */
/* ------------------------------------------------------------------ */

/** @return array{pending:int, expired:int, review:int, refund_failed:int} */
function rc_counts(PDO $pdo): array
{
    $w = rc_window_seconds();
    $r = $pdo->query(
        "SELECT COUNT(*) AS pending,
                COALESCE(SUM(created_at < (NOW() - INTERVAL {$w} SECOND)), 0) AS expired,
                COALESCE(SUM(refund_status IN ('review','blocked')), 0) AS review,
                COALESCE(SUM(refund_status = 'failed'), 0) AS refund_failed
         FROM orders WHERE status = 'pending'"
    )->fetch();
    return ['pending' => (int)$r['pending'], 'expired' => (int)$r['expired'], 'review' => (int)$r['review'], 'refund_failed' => (int)$r['refund_failed']];
}
