<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/reseller_api.php';

$user = require_user();
if (request_method() !== 'POST') json_error('POST required', 405);
maintenance_guard_orders('website-api-plan');   // v36: no new order while maintenance is ON (server-side, every caller)
csrf_verify();

$planId   = input_int('plan_id');
$quantity = max(1, input_int('quantity', 1));
$source   = input_str('source', 'website');

if ($planId <= 0) json_error('Invalid plan.', 422);
if ($quantity > 20) json_error('Maximum 20 keys per API order.', 422);

$pdo = db();

$planStmt = $pdo->prepare(
    'SELECT pl.id, pl.product_id, pl.duration, pl.price, pl.reseller_price, pl.source, pl.provider_variant_id, pl.provider_api_version, pl.provider_key, pl.allow_quantity,
            pl.enabled AS plan_enabled, p.name AS product_name, p.enabled AS product_enabled, p.maintenance, p.archived_at AS product_archived_at
     FROM product_plans pl JOIN products p ON p.id = pl.product_id
     WHERE pl.id = :id LIMIT 1'
);
$planStmt->execute([':id' => $planId]);
$plan = $planStmt->fetch();
if (!$plan) json_error('Plan not found.', 404);
if ((int)$plan['plan_enabled'] !== 1 || (int)$plan['product_enabled'] !== 1 || $plan['product_archived_at'] !== null) json_error('Plan unavailable.', 400);   // archived = never purchasable
if ((int)$plan['maintenance'] === 1) json_error('This product is in maintenance mode.', 400);
if (function_exists('plan_is_removed') && plan_is_removed($pdo, $planId)) json_error('Plan unavailable.', 400);   // v59: duration removed by the provider (archived, never purchasable)
if ($plan['source'] !== 'api') json_error('This plan is not an API plan.', 400);
if (empty($plan['provider_variant_id'])) {
    ops_record_order_failure($pdo, $planId, 0, '', 'rejected', 'Duration has no provider product/variant ID configured.', provider_normalize($plan['provider_key'] ?? null));
    json_error(ORDER_FAILED_MESSAGE, 500, ['code' => 'ORDER_FAILED']);
}
if ((int)($plan['allow_quantity'] ?? 1) !== 1 && $quantity !== 1) json_error('This product allows only 1 quantity per purchase.', 422);
// Provider routing (server-side): the provider is whatever the admin saved on this plan.
// Plans created before multi-provider support are 'primary' = the existing provider.
$provider = provider_normalize($plan['provider_key'] ?? null);
$provInfo = provider_info($provider);
if (!$provInfo['enabled']) {
    ops_record_order_failure($pdo, $planId, 0, '', 'rejected', 'Provider is switched OFF but this duration still points to it.', $provider);
    json_error(ORDER_FAILED_MESSAGE, 503, ['code' => 'ORDER_FAILED']);
}
if ($provider !== PROVIDER_PRIMARY) {
    if (!$provInfo['configured']) {
        ops_record_order_failure($pdo, $planId, 0, '', 'rejected', 'Provider is enabled but not configured (URL/token missing).', $provider);
        json_error(ORDER_FAILED_MESSAGE, 503, ['code' => 'ORDER_FAILED']);
    }
    if ($quantity > $provInfo['max_quantity']) json_error('Maximum ' . $provInfo['max_quantity'] . ' keys per order for this product.', 422);
}

// Provider 5 (LICENCE DASHBOARD): decide v1 / v2 and validate the android_id BEFORE any wallet charge.
// A device-bound (v1) request without an android_id is rejected here, so nothing is charged or sent.
$providerOpts = [];
if ($provider === PROVIDER_LICENCEDASH) {
    $ld = licencedash_resolve(licencedash_plan_variant($plan), input_str('api_version'), input_str('android_id'));
    if ($ld['error'] !== null) json_error($ld['error'], 422);
    $providerOpts = ['api_version' => $ld['use_v1'] ? 'v1' : 'v2', 'android_id' => $ld['android_id']];
}

$unit  = dual_pick_price($plan, dual_user_type($pdo, (int)$user['id']));   // v61: authoritative price of the user's category (DB role)
if (purchase_price_mismatch($unit) !== null) json_error('The price of this product has just changed. Please review the new price and confirm again.', 409, ['code' => 'PRICE_CHANGED', 'new_price' => $unit]);   // v53: before any charge
$total = round($unit * $quantity, 2);

ox_ensure_schema($pdo);   // v59: attempt table must exist BEFORE the wallet is charged (DDL cannot run inside the transaction)
// 1) Deduct wallet & create order as pending within a transaction, then call upstream.
try {
    $pdo->beginTransaction();

    $uStmt = $pdo->prepare('SELECT id, balance FROM users WHERE id = :id FOR UPDATE');
    $uStmt->execute([':id' => (int)$user['id']]);
    $uRow = $uStmt->fetch();
    if (!$uRow || (float)$uRow['balance'] < $total) {
        throw new RuntimeException('Insufficient wallet balance.');
    }

    $ref = order_id('API');
    $pdo->prepare(
        'INSERT INTO orders (order_ref, user_id, product_id, plan_id, quantity, unit_price, total_price,
                             source, plan_source, provider_key, status, created_at)
         VALUES (:r, :u, :p, :pl, :q, :up, :tp, :src, "api", :prov, "pending", NOW())'
    )->execute([
        ':r'  => $ref,
        ':u'  => (int)$user['id'],
        ':p'  => (int)$plan['product_id'],
        ':pl' => $planId,
        ':q'  => $quantity,
        ':up' => $unit,
        ':tp' => $total,
        ':src'=> $source === 'api' ? 'api' : 'website',
        ':prov'=> $provider,
    ]);
    $orderId = (int)$pdo->lastInsertId();

    $newBalance = round((float)$uRow['balance'] - $total, 2);
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
        ':d' => 'API purchase: ' . $plan['product_name'] . ' / ' . $plan['duration'] . ' × ' . $quantity,
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[purchase-api:tx] ' . $e->getMessage());
    if ($e->getMessage() === 'Insufficient wallet balance.') json_error('Insufficient wallet balance.', 400, ['code' => 'INSUFFICIENT_BALANCE']);
    ops_record_order_failure($pdo, $planId, 0, '', 'rejected', 'Could not start purchase: ' . $e->getMessage(), $provider);
    json_error(ORDER_FAILED_MESSAGE, 500, ['code' => 'ORDER_FAILED']);
}

// v59: from here on the order is charged + PENDING. Everything after the charge is done by the SHARED executor (includes/order_executor.php),
// the very same code the external Reseller API uses: one provider call, one classification, one settlement.
$exec = order_execute_provider($pdo, ['id' => $orderId, 'order_ref' => $ref, 'user_id' => (int)$user['id'], 'total' => $total, 'plan_id' => $planId, 'quantity' => $quantity],
                               $plan, 'website', $providerOpts);

if ($exec['state'] === OX_SUCCESS) {
    // Return the authoritative post-purchase balance so the UI can update immediately without requiring a page reload.
    $balanceStmt = $pdo->prepare('SELECT balance FROM users WHERE id = :id');
    $balanceStmt->execute([':id' => (int)$user['id']]);
    json_ok([
        'order_ref'   => $ref,
        'delivered'   => $exec['keys'],
        'product'     => $plan['product_name'],
        'duration'    => $plan['duration'],
        'total'       => $total,
        'balance'     => (float)$balanceStmt->fetchColumn(),
        'upstream_order_id' => $exec['upstream_id'],
    ]);
}

// Customers never see provider / SQL / PHP details.
if ($exec['settle'] === 'refunded') {
    $fc = (string)$exec['failure_code'];   // OUT_OF_STOCK only when the provider confirmed it
    json_error(ORDER_FAILED_REFUNDED_MESSAGE, 409, ['code' => 'ORDER_FAILED_REFUNDED', 'order_ref' => $ref, 'refunded' => true,
               'refunded_amount' => $exec['refunded'], 'balance' => $exec['balance'],
               'status' => 'FAILED', 'failure_code' => $fc, 'reason' => order_failure_label($fc)]);
}
if ($exec['settle'] === 'refund_error') {
    json_error(ORDER_FAILED_REFUND_PENDING_MESSAGE, 502, ['code' => 'ORDER_FAILED_REFUND_PENDING', 'order_ref' => $ref, 'refunded' => false, 'balance' => $exec['balance']]);
}
if ($exec['settle'] === 'held') {
    json_error(ORDER_VERIFYING_MESSAGE, 202, ['code' => 'ORDER_PENDING_VERIFICATION', 'order_ref' => $ref, 'status' => 'PENDING', 'pending_review' => true, 'refunded' => false, 'balance' => $exec['balance']]);
}
json_error(ORDER_FAILED_MESSAGE, 502, ['code' => 'ORDER_FAILED', 'order_ref' => $ref]);
