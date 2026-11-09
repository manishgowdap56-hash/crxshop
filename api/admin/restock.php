<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_admin();
csrf_verify();

$planId = input_int('plan_id');
$raw    = (string)input_param('keys', '');

if ($planId <= 0) json_error('Invalid plan.', 422);
if (trim($raw) === '') json_error('No keys provided.', 422);

$pdo = db();
$plan = $pdo->prepare(
    'SELECT pl.id, pl.product_id, pl.source FROM product_plans pl WHERE pl.id = :id LIMIT 1'
);
$plan->execute([':id' => $planId]);
$row = $plan->fetch();
if (!$row) json_error('Plan not found.', 404);
if ($row['source'] !== 'local') json_error('API plans do not use local key stock.', 422);

$keys = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $raw))));
if (!$keys) json_error('No valid keys found.', 422);

// Restock is intentionally all-or-nothing. This prevents the old INSERT IGNORE
// behavior where 5 submitted keys could silently become 4 because one key was
// duplicated. The admin sees an error and can correct the batch instead.
if (count($keys) !== count(array_unique($keys, SORT_STRING))) {
    json_error('Duplicate key found in the submitted batch. No keys were added.', 422);
}

$pdo->beginTransaction();
try {
    $check = $pdo->prepare('SELECT key_value FROM product_keys WHERE plan_id = :plan AND key_value = :key LIMIT 1');
    $ins = $pdo->prepare(
        'INSERT INTO product_keys (product_id, plan_id, key_value, status, created_at)
         VALUES (:p, :pl, :k, "available", NOW())'
    );

    foreach ($keys as $k) {
        $check->execute([':plan' => $planId, ':key' => $k]);
        if ($check->fetchColumn() !== false) {
            throw new RuntimeException('One or more submitted keys already exist in this duration.');
        }
        $ins->execute([':p' => (int)$row['product_id'], ':pl' => $planId, ':k' => $k]);
    }
    $added = count($keys);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($e instanceof RuntimeException) json_error($e->getMessage(), 422);
    error_log('[admin/restock] ' . $e->getMessage());
    json_error('Could not add keys.', 500);
}

ops_check_local_stock($pdo, $planId);   // resolves the "stock is zero" admin alert
json_ok(['added' => $added, 'total' => count($keys)]);