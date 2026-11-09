<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

/* ------------------------------------------------------------------ */
/*  Auth                                                              */
/* ------------------------------------------------------------------ */

$apiKey = $_SERVER['HTTP_X_MASTER_KEY'] ?? '';
if ($apiKey === '') {
    $apiKey = (string)input_param('api_key', '');
}
$apiKey = trim($apiKey);

if ($apiKey === '' || strpos($apiKey, 'psk_') !== 0) {
    json_error('Missing or malformed API key.', 401);
}

$stmt = db()->prepare('SELECT id, username, role, balance, banned FROM users WHERE api_token = :t LIMIT 1');
$stmt->execute([':t' => $apiKey]);
$user = $stmt->fetch();
if (!$user) json_error('Invalid API key.', 401);
if ((int)$user['banned'] === 1) json_error('Account is banned.', 403);
// v62: the key only works while the owner is a Reseller (or Admin). Revoking Reseller access disables the key at once; the key itself is kept.
if (!dual_can_use_api(db(), (int)$user['id'])) json_error('API access is available to Resellers only.', 403, ['code' => 'RESELLER_ONLY']);

$userId = (int)$user['id'];
$action = strtolower(trim((string)input_param('action', '')));
if ($action === 'order') $action = 'buy';

/* ------------------------------------------------------------------ */
/*  Rate limit: max 60 API calls/min/user                             */
/* ------------------------------------------------------------------ */

try {
    db()->prepare('DELETE FROM api_logs WHERE created_at < (NOW() - INTERVAL 1 HOUR)')->execute();
    $rl = db()->prepare('SELECT COUNT(*) FROM api_logs WHERE user_id = :u AND created_at > (NOW() - INTERVAL 1 MINUTE)');
    $rl->execute([':u' => $userId]);
    if ((int)$rl->fetchColumn() > 60) {
        json_error('Rate limit exceeded. Try again in a minute.', 429);
    }
} catch (Throwable $e) { /* non-fatal */ }

/* ------------------------------------------------------------------ */
/*  Dispatch                                                          */
/* ------------------------------------------------------------------ */

function log_api(int $userId, string $action, string $status, ?string $message = null, ?string $variant = null, ?int $qty = null): void {
    try {
        db()->prepare(
            'INSERT INTO api_logs (user_id, action, variant_id, quantity, status, message, ip, created_at)
             VALUES (:u, :a, :v, :q, :s, :m, :ip, NOW())'
        )->execute([
            ':u'  => $userId,
            ':a'  => $action,
            ':v'  => $variant,
            ':q'  => $qty,
            ':s'  => $status,
            ':m'  => $message,
            ':ip' => client_ip(),
        ]);
    } catch (Throwable $e) {}
}

try {
    switch ($action) {
        case 'balance':  handle_balance($userId, $user); break;
        case 'variants': handle_variants($userId, $user); break;
        case 'buy':      handle_buy($userId, $user); break;
        case 'status':   handle_status($userId, $user); break;
        case 'history':  handle_history($userId, $user); break;
        default:
            log_api($userId, $action ?: 'unknown', 'error', 'Unknown action');
            json_error('Unknown action. Allowed: balance, variants, buy, status, history.', 400);
    }
} catch (Throwable $e) {
    error_log('[reseller_gateway] ' . $e->getMessage());
    json_error('Internal error.', 500);
}

/* ================================================================== */
/*  Handlers                                                          */
/* ================================================================== */

function handle_balance(int $userId, array $user): void {
    log_api($userId, 'balance', 'ok');
    json_ok([
        'username'       => $user['username'],
        'is_reseller'    => true,
        'wallet_balance' => (float)$user['balance'],
    ]);
}

function handle_variants(int $userId, array $user): void {
    $rows = db()->query(
        'SELECT pl.id AS variant_id, pl.duration AS validity, pl.price, pl.reseller_price, pl.source,
                p.name AS product,
                (SELECT COUNT(*) FROM product_keys k WHERE k.plan_id = pl.id AND k.status = "available") AS stock
         FROM product_plans pl JOIN products p ON p.id = pl.product_id
         WHERE pl.enabled = 1 AND p.enabled = 1 AND p.archived_at IS NULL AND COALESCE(pl.sync_removed, 0) = 0
         ORDER BY p.id, pl.sort_order, pl.id'
    )->fetchAll();

    $variants = [];
    foreach ($rows as $r) {
        $variants[] = [
            'variant_id' => (int)$r['variant_id'],
            'product'    => $r['product'],
            'variant'    => $r['validity'],   // v59: the SELECT aliases pl.duration AS validity; $r['duration'] was undefined
            'validity'   => $r['validity'],
            'price'      => dual_pick_price($r, dual_user_type(db(), $userId)),   // v61: category of the API key owner (DB role)
            'in_stock'   => $r['source'] === 'api' ? true : ((int)$r['stock'] > 0),
        ];
    }
    log_api($userId, 'variants', 'ok');
    json_ok(['variants' => $variants]);
}

/* ------------------------------------------------------------------ */
/*  v59: optional client idempotency (Idempotency-Key header or idempotency_key field)           */
/*  The same key from the same user never creates / charges a second order: the first order's    */
/*  current state is returned instead. Without a key the behaviour is unchanged.                 */
/* ------------------------------------------------------------------ */

function gw_idem_ensure(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS api_idempotency (
        user_id INT UNSIGNED NOT NULL, idem_key VARCHAR(64) NOT NULL, order_ref VARCHAR(40) DEFAULT NULL,
        variant_id INT UNSIGNED DEFAULT NULL, quantity INT UNSIGNED DEFAULT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, idem_key), KEY idx_idem_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** The current, customer-safe state of one of this user's orders, in the same shape handle_buy answers with. null = not found. */
function gw_order_payload(int $userId, string $ref): ?array {
    $q = db()->prepare(
        'SELECT o.id, o.order_ref, o.status, o.total_price, o.failure_reason, o.failure_code, p.name AS product_name, pl.duration,
                GROUP_CONCAT(COALESCE(ok.external_key, pk.key_value) SEPARATOR "\n") AS keys_csv
         FROM orders o JOIN products p ON p.id = o.product_id JOIN product_plans pl ON pl.id = o.plan_id
         LEFT JOIN order_keys ok ON ok.order_id = o.id LEFT JOIN product_keys pk ON pk.id = ok.product_key_id
         WHERE o.user_id = :u AND o.order_ref = :r GROUP BY o.id LIMIT 1'
    );
    $q->execute([':u' => $userId, ':r' => $ref]);
    $r = $q->fetch();
    if (!$r) return null;
    $bal = db()->prepare('SELECT balance FROM users WHERE id = :id'); $bal->execute([':id' => $userId]);
    $keys = $r['keys_csv'] ? explode("\n", (string)$r['keys_csv']) : [];
    $st = (string)$r['status'];
    $base = ['order_id' => $r['order_ref'], 'product' => $r['product_name'], 'validity' => $r['duration'], 'balance' => (float)$bal->fetchColumn(), 'replayed' => true];
    if ($st === 'completed') return ['http' => 200, 'ok' => true] + $base + ['status' => 'COMPLETED', 'key' => $keys[0] ?? '', 'keys' => $keys];
    if ($st === 'failed' || $st === 'refunded') {
        $fc = order_failure_code($r);
        return ['http' => $fc === ORDER_FAILURE_OUT_OF_STOCK ? 409 : 502, 'ok' => false, 'error' => $fc === ORDER_FAILURE_OUT_OF_STOCK ? 'OUT OF STOCK' : 'Order failed.'] + $base
             + ['status' => 'FAILED', 'failure_code' => $fc !== '' ? $fc : 'REJECTED', 'reason' => $fc !== '' ? order_failure_label($fc) : 'REJECTED', 'refunded' => true];
    }
    return ['http' => 202, 'ok' => true] + $base + ['status' => 'PENDING', 'pending_review' => true, 'refunded' => false, 'delivered' => false, 'key' => '', 'keys' => []];
}

function handle_buy(int $userId, array $user): void {
    maintenance_guard_orders('reseller-api');   // v36: HTTP 503 {code: MAINTENANCE_MODE}; nothing is charged or created
    $variantId = (int)input_param('variant_id', 0);
    $quantity  = max(1, min(10, (int)input_param('quantity', 1)));

    if ($variantId <= 0) {
        log_api($userId, 'buy', 'error', 'Invalid variant_id');
        json_error('Invalid or missing variant_id.');
    }

    $pdo = db();
    ox_ensure_schema($pdo);   // v59: attempt table must exist BEFORE the wallet is charged

    // v59: optional idempotency key (same key = same order, never a second charge)
    $idemKey = trim((string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? input_param('idempotency_key', '')));
    if ($idemKey !== '') {
        if (!preg_match('/^[A-Za-z0-9._\-]{8,64}$/D', $idemKey)) json_error('idempotency_key must be 8-64 characters (letters, digits, . _ -).', 422);
        try {
            gw_idem_ensure($pdo);
            $pdo->prepare('DELETE FROM api_idempotency WHERE created_at < (NOW() - INTERVAL 2 DAY)')->execute();
            try {
                $pdo->prepare('INSERT INTO api_idempotency (user_id, idem_key, variant_id, quantity, created_at) VALUES (:u, :k, :v, :q, NOW())')
                    ->execute([':u' => $userId, ':k' => $idemKey, ':v' => $variantId, ':q' => $quantity]);
                // We own the key. If this request never creates an order (validation error, insufficient balance ...) the key is freed again.
                register_shutdown_function(static function () use ($pdo, $userId, $idemKey): void {
                    try { $pdo->prepare('DELETE FROM api_idempotency WHERE user_id = :u AND idem_key = :k AND order_ref IS NULL')->execute([':u' => $userId, ':k' => $idemKey]); } catch (Throwable $e) {}
                });
            } catch (PDOException $dup) {
                if ((int)($dup->errorInfo[1] ?? 0) !== 1062) throw $dup;
                $q = $pdo->prepare('SELECT order_ref, variant_id, quantity FROM api_idempotency WHERE user_id = :u AND idem_key = :k');
                $q->execute([':u' => $userId, ':k' => $idemKey]);
                $prev = $q->fetch();
                if ($prev && (int)$prev['variant_id'] !== $variantId) json_error('This idempotency_key was already used for a different request.', 409, ['code' => 'IDEMPOTENCY_KEY_REUSED']);
                if (!$prev || $prev['order_ref'] === null) json_error('A request with this idempotency_key is still being processed. Try again shortly or use action=status.', 409, ['code' => 'IDEMPOTENT_REQUEST_IN_PROGRESS']);
                $payload = gw_order_payload($userId, (string)$prev['order_ref']);
                if ($payload === null) json_error('Original order not found.', 404);
                $http = (int)$payload['http']; unset($payload['http']);
                json_response($payload, $http);
            }
        } catch (PDOException $e) {
            error_log('[api/buy:idempotency] ' . $e->getMessage());
            json_error('Internal error.', 500);   // fail closed: without the guard a retry could double-charge
        }
    }
    try {
        $pdo->beginTransaction();

        $planStmt = $pdo->prepare(
            'SELECT pl.id, pl.product_id, pl.duration, pl.price, pl.reseller_price, pl.source, pl.provider_variant_id, pl.provider_api_version, pl.provider_key,
                    pl.enabled AS plan_enabled, p.name AS product_name, p.enabled AS product_enabled, p.maintenance, p.archived_at AS product_archived_at
             FROM product_plans pl JOIN products p ON p.id = pl.product_id
             WHERE pl.id = :id FOR UPDATE'
        );
        $planStmt->execute([':id' => $variantId]);
        $plan = $planStmt->fetch();

        if (!$plan) throw new RuntimeException('Invalid variant_id.');
        if ((int)$plan['plan_enabled'] !== 1 || (int)$plan['product_enabled'] !== 1 || $plan['product_archived_at'] !== null) throw new RuntimeException('Variant is disabled.');   // archived = never purchasable
        if ((int)$plan['maintenance'] === 1) throw new RuntimeException('Variant is under maintenance.');
        if (function_exists('plan_is_removed') && plan_is_removed($pdo, $variantId)) throw new RuntimeException('Variant is disabled.');   // v59: removed by the provider = never purchasable
        if ($plan['source'] === 'api' && provider_normalize($plan['provider_key'] ?? null) === PROVIDER_PANELSTORE) {
            // Checked BEFORE the wallet is charged. Provider comes from the saved plan, never from the request.
            $ps = provider_info(PROVIDER_PANELSTORE);
            if (!$ps['enabled'] || !$ps['configured']) throw new RuntimeException('Variant is temporarily unavailable.');
            if ($quantity > $ps['max_quantity']) throw new RuntimeException('Maximum ' . $ps['max_quantity'] . ' key per order for this variant.');
        }

        if ($plan['source'] === 'api' && provider_normalize($plan['provider_key'] ?? null) === PROVIDER_BANTIBHAIYA) {
            // Provider 4: checked BEFORE the wallet is charged (provider comes from the saved plan, never from the request).
            $p4 = provider_info(PROVIDER_BANTIBHAIYA);
            if (!$p4['enabled'] || !$p4['configured']) throw new RuntimeException('Variant is temporarily unavailable.');
            if ($quantity > $p4['max_quantity']) throw new RuntimeException('Maximum ' . $p4['max_quantity'] . ' keys per order for this variant.');
        }

        if ($plan['source'] === 'api' && provider_normalize($plan['provider_key'] ?? null) === PROVIDER_VIPSELLER) {
            // Provider 6: checked BEFORE the wallet is charged (provider comes from the saved plan, never from the request).
            $p6 = provider_info(PROVIDER_VIPSELLER);
            if (!$p6['enabled'] || !$p6['configured']) throw new RuntimeException('Variant is temporarily unavailable.');
            if ($quantity > $p6['max_quantity']) throw new RuntimeException('Maximum ' . $p6['max_quantity'] . ' keys per order for this variant.');
        }

        $ldOpts = [];
        if ($plan['source'] === 'api' && provider_normalize($plan['provider_key'] ?? null) === PROVIDER_LICENCEDASH) {
            // Provider 5: checked BEFORE the wallet is charged (provider comes from the saved plan, never from the request).
            $p5 = provider_info(PROVIDER_LICENCEDASH);
            if (!$p5['enabled'] || !$p5['configured']) throw new RuntimeException('Variant is temporarily unavailable.');
            if ($quantity > $p5['max_quantity']) throw new RuntimeException('Maximum ' . $p5['max_quantity'] . ' keys per order for this variant.');
            $ld = licencedash_resolve(licencedash_plan_variant($plan), (string)input_param('api_version', ''), (string)input_param('android_id', ''));
            if ($ld['error'] !== null) throw new RuntimeException($ld['error']);
            $ldOpts = ['api_version' => $ld['use_v1'] ? 'v1' : 'v2', 'android_id' => $ld['android_id']];
        }

        $unit  = dual_pick_price($plan, dual_user_type($pdo, $userId));   // v61: category of the API key owner (DB role)
        $total = round($unit * $quantity, 2);

        $uStmt = $pdo->prepare('SELECT id, balance FROM users WHERE id = :id FOR UPDATE');
        $uStmt->execute([':id' => $userId]);
        $uRow = $uStmt->fetch();

        if ((float)$uRow['balance'] < $total) {
            $needed = round($total - (float)$uRow['balance'], 2);
            $pdo->rollBack();
            log_api($userId, 'buy', 'error', 'Insufficient balance', (string)$variantId, $quantity);
            json_error('Insufficient balance.', 400, [
                'required'  => $total,
                'available' => (float)$uRow['balance'],
                'shortfall' => $needed,
            ]);
        }

        $ref = order_id('API');
        $pdo->prepare(
            'INSERT INTO orders (order_ref, user_id, product_id, plan_id, quantity, unit_price, total_price,
                                 source, plan_source, provider_key, status, created_at)
             VALUES (:r, :u, :p, :pl, :q, :up, :tp, "api", :src, :prov, "pending", NOW())'
        )->execute([
            ':r'  => $ref,
            ':u'  => $userId,
            ':p'  => (int)$plan['product_id'],
            ':pl' => $variantId,
            ':q'  => $quantity,
            ':up' => $unit,
            ':tp' => $total,
            ':src'=> $plan['source'],
            ':prov'=> $plan['source'] === 'api' ? provider_normalize($plan['provider_key'] ?? null) : null,
        ]);
        $orderId = (int)$pdo->lastInsertId();
        if ($idemKey !== '') $pdo->prepare('UPDATE api_idempotency SET order_ref = :r WHERE user_id = :u AND idem_key = :k')->execute([':r' => $ref, ':u' => $userId, ':k' => $idemKey]);

        $newBal = round((float)$uRow['balance'] - $total, 2);
        $pdo->prepare('UPDATE users SET balance = :b WHERE id = :id')
            ->execute([':b' => $newBal, ':id' => $userId]);
        $pdo->prepare(
            'INSERT INTO wallet_ledger (user_id, amount, balance_after, type, reference_id, description, created_at)
             VALUES (:u, :a, :b, "purchase", :r, :d, NOW())'
        )->execute([
            ':u' => $userId, ':a' => -$total, ':b' => $newBal,
            ':r' => $ref, ':d' => 'API purchase: ' . $plan['product_name'] . ' × ' . $quantity,
        ]);

        $pdo->commit();
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        log_api($userId, 'buy', 'error', $e->getMessage(), (string)$variantId, $quantity);
        json_error($e->getMessage());
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[api/buy:tx] ' . $e->getMessage());
        log_api($userId, 'buy', 'error', 'Internal error', (string)$variantId, $quantity);
        json_error('Internal error.', 500);
    }

    // ---- Local stock delivery ----
    if ($plan['source'] === 'local') {
        try {
            $pdo->beginTransaction();

            $kStmt = $pdo->prepare(
                'SELECT id, key_value FROM product_keys
                 WHERE plan_id = :pid AND status = "available"
                 ORDER BY id ASC LIMIT ' . (int)$quantity . ' FOR UPDATE'
            );
            $kStmt->execute([':pid' => $variantId]);
            $keys = $kStmt->fetchAll();

            if (count($keys) < $quantity) {
                // v39: roll back the (read-only) stock transaction, then refund through the ONE idempotent refund function
                // (row lock + status gate + exact deducted amount + tracking columns) instead of a private inline refund.
                $pdo->rollBack();
                log_api($userId, 'buy', 'error', 'Out of stock', (string)$variantId, $quantity);
                $st = order_settle_failure($pdo, ['id' => (int)$orderId, 'order_ref' => $ref, 'user_id' => $userId, 'total' => $total, 'plan_id' => (int)$variantId],
                                           'local_stock', 'Local stock ran out during a reseller API purchase.', null);
                json_error('Out of stock.', 409, ['refunded' => $st['state'] === 'refunded', 'balance' => $st['balance'], 'status' => 'FAILED', 'failure_code' => ORDER_FAILURE_OUT_OF_STOCK, 'reason' => 'OUT OF STOCK']);
            }

            $upd = $pdo->prepare('UPDATE product_keys SET status="delivered", order_id=:o, delivered_at=NOW() WHERE id=:id');
            $okIns = $pdo->prepare('INSERT INTO order_keys (order_id, product_key_id, delivered_at) VALUES (:o, :k, NOW())');
            $out = [];
            foreach ($keys as $k) {
                $upd->execute([':o' => $orderId, ':id' => (int)$k['id']]);
                $okIns->execute([':o' => $orderId, ':k' => (int)$k['id']]);
                $out[] = $k['key_value'];
            }

            $pdo->prepare('UPDATE orders SET status = "completed" WHERE id = :id')->execute([':id' => $orderId]);
            credit_referral_commission($pdo, $userId, $orderId, $total, 'reseller');
            $pdo->commit();

            log_api($userId, 'buy', 'ok', null, (string)$variantId, $quantity);
            audit_log('order.success', 'success', ['entity_type' => 'order', 'entity_id' => $orderId, 'order_ref' => $ref, 'user_id' => $userId, 'actor_id' => $userId,
                'reason' => 'Reseller API (local stock): ' . $plan['product_name'] . ' / ' . $plan['duration'] . ' × ' . $quantity]);
            ops_check_local_stock($pdo, (int)$variantId);
            json_ok([
                'order_id'   => $ref,
                'key'        => $out[0] ?? '',
                'keys'       => $out,
                'orders'     => [$ref],
                'product'    => $plan['product_name'],
                'validity'   => $plan['duration'],
                'balance'    => (float)(db()->query('SELECT balance FROM users WHERE id = ' . (int)$userId)->fetchColumn()),
                'expires_at' => date('Y-m-d H:i:s', strtotime('+' . (int)$plan['duration'] . ' seconds')),
            ]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[api/buy:local] ' . $e->getMessage());
            log_api($userId, 'buy', 'error', 'Delivery failed', (string)$variantId, $quantity);
            json_error('Delivery failed.', 500);
        }
    }

    // ---- Upstream API delivery (v59) ----
    // The wallet is already deducted and the order is pending. From here the SHARED executor (includes/order_executor.php) does exactly what
    // the website checkout does: one provider call, the same 7-state classification, the same settlement (refund engine / key save / hold).
    $exec = order_execute_provider($pdo, ['id' => (int)$orderId, 'order_ref' => $ref, 'user_id' => $userId, 'total' => $total, 'plan_id' => (int)$variantId, 'quantity' => $quantity],
                                   $plan, 'reseller_api', $ldOpts);
    $bal = static function () use ($userId): float {
        $q = db()->prepare('SELECT balance FROM users WHERE id = :id'); $q->execute([':id' => $userId]); return (float)$q->fetchColumn();
    };

    if ($exec['state'] === OX_SUCCESS) {
        log_api($userId, 'buy', 'ok', null, (string)$variantId, $quantity);
        json_ok([
            'order_id'   => $ref,
            'status'     => 'COMPLETED',
            'key'        => $exec['keys'][0] ?? '',
            'keys'       => $exec['keys'],
            'orders'     => [$ref],
            'product'    => $plan['product_name'],
            'validity'   => $plan['duration'],
            'balance'    => $bal(),
            'expires_at' => null,
        ]);
    }

    if ($exec['settle'] === 'refunded') {
        $fc = (string)$exec['failure_code'];
        $oos = $fc === ORDER_FAILURE_OUT_OF_STOCK;
        log_api($userId, 'buy', 'error', $oos ? 'Out of stock (refunded)' : 'Provider rejected (refunded)', (string)$variantId, $quantity);
        json_error($oos ? 'OUT OF STOCK' : 'Order failed. Your amount has been refunded to your wallet.', $oos ? 409 : 502, [
            'order_id' => $ref, 'status' => 'FAILED', 'failure_code' => $fc !== '' ? $fc : 'REJECTED', 'reason' => $oos ? 'OUT OF STOCK' : 'REJECTED',
            'refunded' => true, 'refunded_amount' => $exec['refunded'], 'balance' => $exec['balance'], 'message' => ORDER_FAILED_REFUNDED_MESSAGE,
        ]);
    }
    if ($exec['settle'] === 'refund_error') {
        log_api($userId, 'buy', 'error', 'Refund pending', (string)$variantId, $quantity);
        json_error('Order failed; the refund could not be completed yet and will be retried automatically.', 502, [
            'order_id' => $ref, 'status' => 'PENDING', 'pending_review' => true, 'refunded' => false, 'balance' => $exec['balance'],
        ]);
    }
    if ($exec['settle'] === 'held') {
        // Outcome unknown (timeout / unreadable reply / accepted without key): NOT a failure and NOT a success. 202 + ok:true so a client does not
        // treat it as an error and re-send the buy (which would charge twice). The order is verified server-side; poll action=status.
        log_api($userId, 'buy', 'error', 'Pending verification', (string)$variantId, $quantity);
        json_response([
            'ok' => true, 'delivered' => false, 'order_id' => $ref, 'status' => 'PENDING', 'pending_review' => true, 'refunded' => false,
            'key' => '', 'keys' => [], 'product' => $plan['product_name'], 'validity' => $plan['duration'], 'balance' => $exec['balance'],
            'message' => 'Your order is being verified with the provider. Do not send it again. Check the result with action=status and this order_id.',
        ], 202);
    }
    log_api($userId, 'buy', 'error', 'Order failed', (string)$variantId, $quantity);
    json_error('Order failed.', 502, ['order_id' => $ref, 'status' => 'FAILED', 'refunded' => false]);
}

function handle_status(int $userId, array $user): void {
    $orderId = trim((string)input_param('order_id', ''));
    if ($orderId === '') json_error('Missing order_id.');

    $stmt = db()->prepare(
        'SELECT o.id, o.order_ref, o.status, o.quantity, o.total_price, o.created_at, o.upstream_order_id, o.failure_reason,
                p.name AS product_name, pl.duration,
                GROUP_CONCAT(COALESCE(ok.external_key, pk.key_value) SEPARATOR "\n") AS keys_csv
         FROM orders o
         JOIN products p       ON p.id = o.product_id
         JOIN product_plans pl ON pl.id = o.plan_id
         LEFT JOIN order_keys ok ON ok.order_id = o.id
         LEFT JOIN product_keys pk ON pk.id = ok.product_key_id
         WHERE o.user_id = :u AND o.order_ref = :r
         GROUP BY o.id LIMIT 1'
    );
    $stmt->execute([':u' => $userId, ':r' => $orderId]);
    $row = $stmt->fetch();
    if (!$row) json_error('Order not found.', 404);

    $keys = $row['keys_csv'] ? explode("\n", $row['keys_csv']) : [];
    log_api($userId, 'status', 'ok');

    json_ok([
        'order_id'    => $row['order_ref'],
        'status'      => $row['status'],
        'key'         => $keys[0] ?? '',
        'keys'        => $keys,
        'product_name'=> $row['product_name'],
        'plan_label'  => $row['duration'],
        'amount'      => (float)$row['total_price'],
        'created_at'  => $row['created_at'],
        'expires_at'  => null,
        'is_expired'  => false,
        'status_label'=> strtoupper((string)$row['status']),
        'pending_review' => ((string)$row['status'] === 'pending' && stripos((string)($row['failure_reason'] ?? ''), 'UNCERTAIN:') === 0),
        'failure_code'=> order_failure_code($row),
        'reason'      => order_failure_label(order_failure_code($row)),   // v58: 'OUT OF STOCK' only for a confirmed out-of-stock failure, else ''
    ]);
}

function handle_history(int $userId, array $user): void {
    $limit = max(1, min(100, (int)input_param('limit', 20)));

    $stmt = db()->prepare(
        'SELECT o.order_ref, o.status, o.total_price, o.created_at,
                p.name AS product_name, pl.duration
         FROM orders o
         JOIN products p       ON p.id = o.product_id
         JOIN product_plans pl ON pl.id = o.plan_id
         WHERE o.user_id = :u
         ORDER BY o.id DESC LIMIT ' . (int)$limit
    );
    $stmt->execute([':u' => $userId]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[] = [
            'order_id'    => $r['order_ref'],
            'status'      => $r['status'],
            'product_name'=> $r['product_name'],
            'plan_label'  => $r['duration'],
            'amount'      => (float)$r['total_price'],
            'created_at'  => $r['created_at'],
        ];
    }
    log_api($userId, 'history', 'ok');
    json_ok(['orders' => $out]);
}
