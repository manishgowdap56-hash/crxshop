<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$user = require_user();
if (request_method() !== 'POST') json_error('POST required', 405);
maintenance_guard_orders('website');   // v36: no new order while maintenance is ON (server-side, every caller)
csrf_verify();

$planId   = input_int('plan_id');
$quantity = max(1, input_int('quantity', 1));
$source   = input_str('source'); // 'website' | 'api' (from user's own API)

if ($planId <= 0) json_error('Invalid plan.', 422);
if ($quantity > 20) json_error('Maximum 20 keys per order.', 422);

$pdo = db();

try {
    $pdo->beginTransaction();

    // Lock plan + product rows
    $planStmt = $pdo->prepare(
        'SELECT pl.id, pl.product_id, pl.duration, pl.price, pl.reseller_price, pl.source, pl.allow_quantity,
                pl.enabled AS plan_enabled, pl.provider_variant_id,
                p.name AS product_name, p.enabled AS product_enabled, p.maintenance, p.archived_at AS product_archived_at
         FROM product_plans pl
         JOIN products p ON p.id = pl.product_id
         WHERE pl.id = :id
         FOR UPDATE'
    );
    $planStmt->execute([':id' => $planId]);
    $plan = $planStmt->fetch();

    if (!$plan) throw new RuntimeException('Plan not found.');
    if ((int)$plan['plan_enabled'] !== 1 || (int)$plan['product_enabled'] !== 1 || $plan['product_archived_at'] !== null) {   // archived = never purchasable
        throw new RuntimeException('This plan is currently unavailable.');
    }
    if ((int)$plan['maintenance'] === 1) {
        throw new RuntimeException('This product is in maintenance mode.');
    }
    if ((int)($plan['allow_quantity'] ?? 1) !== 1 && $quantity !== 1) throw new RuntimeException('This product allows only 1 quantity per purchase.');
    if ($plan['source'] === 'api') {
        throw new RuntimeException('This plan must be purchased via the API channel.');
    }

    $unit  = dual_pick_price($plan, dual_user_type($pdo, (int)$user['id']));   // v61: authoritative price of the user's category (DB role), never a browser value
    if (purchase_price_mismatch($unit) !== null) throw new RuntimeException('PRICE_CHANGED');   // v53: nothing charged; the browser shows the real price
    $total = round($unit * $quantity, 2);

    // Lock user row
    $userStmt = $pdo->prepare('SELECT id, balance FROM users WHERE id = :id FOR UPDATE');
    $userStmt->execute([':id' => (int)$user['id']]);
    $userRow = $userStmt->fetch();
    if (!$userRow) throw new RuntimeException('Account error.');

    if ((float)$userRow['balance'] < $total) {
        throw new RuntimeException('Insufficient wallet balance.');
    }

    // Lock available keys
    $keyStmt = $pdo->prepare(
        'SELECT id, key_value FROM product_keys
         WHERE plan_id = :pid AND status = "available"
         ORDER BY id ASC
         LIMIT ' . (int)$quantity . '
         FOR UPDATE'
    );
    $keyStmt->execute([':pid' => $planId]);
    $keys = $keyStmt->fetchAll();

    if (count($keys) < $quantity) {
        throw new RuntimeException('OUT_OF_STOCK');
    }

    // Create order
    $ref = order_id('ORD');
    $ins = $pdo->prepare(
        'INSERT INTO orders (order_ref, user_id, product_id, plan_id, quantity, unit_price, total_price,
                             source, plan_source, status, created_at)
         VALUES (:r, :u, :p, :pl, :q, :up, :tp, :src, "local", "completed", NOW())'
    );
    $ins->execute([
        ':r'  => $ref,
        ':u'  => (int)$user['id'],
        ':p'  => (int)$plan['product_id'],
        ':pl' => $planId,
        ':q'  => $quantity,
        ':up' => $unit,
        ':tp' => $total,
        ':src'=> $source === 'api' ? 'api' : 'website',
    ]);
    $orderId = (int)$pdo->lastInsertId();

    // Deliver keys
    $deliverStmt  = $pdo->prepare('UPDATE product_keys SET status="delivered", order_id=:oid, delivered_at=NOW() WHERE id=:kid');
    $orderKeyStmt = $pdo->prepare('INSERT INTO order_keys (order_id, product_key_id, delivered_at) VALUES (:o, :k, NOW())');
    $delivered = [];
    foreach ($keys as $k) {
        $deliverStmt->execute([':oid' => $orderId, ':kid' => (int)$k['id']]);
        $orderKeyStmt->execute([':o' => $orderId, ':k' => (int)$k['id']]);
        $delivered[] = $k['key_value'];
    }

    // Deduct wallet + ledger
    $newBalance = round((float)$userRow['balance'] - $total, 2);
    $pdo->prepare('UPDATE users SET balance = :b WHERE id = :id')
        ->execute([':b' => $newBalance, ':id' => (int)$user['id']]);
    $pdo->prepare(
        'INSERT INTO wallet_ledger (user_id, amount, balance_after, type, reference_id, description, created_at)
         VALUES (:u, :a, :b, "purchase", :r, :d, NOW())'
    )->execute([
        ':u' => (int)$user['id'],
        ':a' => -$total,
        ':b' => $newBalance,
        ':r' => $ref,
        ':d' => 'Purchase: ' . $plan['product_name'] . ' / ' . $plan['duration'] . ' × ' . $quantity,
    ]);

    // Referral commission: credit the referrer if any, at the active percentage stored in the database
    credit_referral_commission($pdo, (int)$user['id'], $orderId, $total, 'website');   // shared implementation: includes/referral.php (active % from the database)

    $pdo->commit();

    audit_log('order.success', 'success', ['entity_type' => 'order', 'entity_id' => $orderId, 'order_ref' => $ref, 'user_id' => (int)$user['id'], 'actor_id' => (int)$user['id'],
        'reason' => 'Local stock: ' . $plan['product_name'] . ' / ' . $plan['duration'] . ' × ' . $quantity]);
    ops_check_local_stock($pdo, $planId);       // raises the "stock is zero" admin alert when this purchase took the last key

    json_ok([
        'order_ref'   => $ref,
        'delivered'   => $delivered,
        'product'     => $plan['product_name'],
        'duration'    => $plan['duration'],
        'total'       => $total,
        'balance'     => $newBalance,
    ]);
} catch (RuntimeException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $msg = $e->getMessage();
    if ($msg === 'OUT_OF_STOCK') {
        // The order was not created and nothing was charged (single transaction). Customer gets the standard friendly message.
        ops_record_order_failure($pdo, $planId, 0, '', 'local_stock', 'Local stock ran out while the customer was buying.', null);
        json_error(ORDER_FAILED_MESSAGE, 409, ['code' => 'ORDER_FAILED']);
    }
    if ($msg === 'PRICE_CHANGED') json_error('The price of this product has just changed. Please review the new price and confirm again.', 409, ['code' => 'PRICE_CHANGED', 'new_price' => (float)($unit ?? 0)]);
    if ($msg === 'Insufficient wallet balance.') json_error($msg, 400, ['code' => 'INSUFFICIENT_BALANCE']);
    json_error($msg, 400);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[purchase] ' . $e->getMessage());
    ops_record_order_failure($pdo, $planId, 0, '', 'rejected', 'Internal error during local purchase: ' . $e->getMessage(), null);
    json_error(ORDER_FAILED_MESSAGE, 500, ['code' => 'ORDER_FAILED']);
}
