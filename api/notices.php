<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

/*
 * User notices (shown by assets/js/notices.js):
 *   1) LOW BALANCE - ONLY for accounts the DATABASE flags as API-enabled (users.api_enabled = 1), balance < 20.00,
 *      and not acknowledged within the cooldown. The balance, the API flag and the acknowledgement all come from MySQL.
 *      Nothing the browser sends (balance, role, flags) is read. Unverifiable classification = no popup.
 *   2) PRICE CHANGES - the latest recorded change per plan that the user has not acknowledged, shown only while the
 *      recorded new price still equals the plan's actual current price.
 * GET            -> what to show
 * POST ack_low   -> record the low-balance acknowledgement for THIS user
 * POST ack_price -> change_id : record the acknowledgement of one price announcement for THIS user
 */
const LOW_BALANCE_THRESHOLD = 20.00;
const LOW_BALANCE_COOLDOWN_HOURS = 24;

$user = require_user();
$pdo  = db();
$uid  = (int)$user['id'];

if (request_method() === 'POST') {
    csrf_verify();
    $action = input_str('action');
    if ($action === 'ack_low') {
        // Only meaningful for an API-enabled account; harmless otherwise.
        $pdo->prepare('UPDATE users SET lowbal_ack_at = NOW() WHERE id = :id AND api_enabled = 1')->execute([':id' => $uid]);
        json_ok();
    }
    if ($action === 'ack_price') {
        $cid = input_int('change_id');
        if ($cid <= 0) json_error('Invalid announcement.', 422);
        $q = $pdo->prepare('SELECT id FROM price_changes WHERE id = :id LIMIT 1');
        $q->execute([':id' => $cid]);
        if (!$q->fetchColumn()) json_error('Announcement not found.', 404);
        $pdo->prepare('INSERT IGNORE INTO price_change_acks (user_id, change_id, acked_at) VALUES (:u, :c, NOW())')
            ->execute([':u' => $uid, ':c' => $cid]);
        json_ok();
    }
    json_error('Unknown action.', 400);
}

$out = ['low_balance' => ['show' => false, 'balance' => 0.0], 'price_changes' => []];

try {
    // Fresh values straight from the database.
    $st = $pdo->prepare('SELECT balance, api_enabled, lowbal_ack_at, role, created_at,
                                (lowbal_ack_at IS NOT NULL AND lowbal_ack_at > (NOW() - INTERVAL ' . (int)LOW_BALANCE_COOLDOWN_HOURS . ' HOUR)) AS ack_recent
                         FROM users WHERE id = :id LIMIT 1');
    $st->execute([':id' => $uid]);
    $row = $st->fetch();
    if ($row) {
        $balance = round((float)$row['balance'], 2);
        $out['low_balance']['balance'] = $balance;

        if ((int)$row['api_enabled'] === 1 && $row['role'] !== 'admin') {
            if ($balance >= LOW_BALANCE_THRESHOLD) {
                // Recovered: forget the acknowledgement so a future drop warns again.
                if ($row['lowbal_ack_at'] !== null) {
                    $pdo->prepare('UPDATE users SET lowbal_ack_at = NULL WHERE id = :id')->execute([':id' => $uid]);
                }
            } elseif ((int)$row['ack_recent'] !== 1) {
                $out['low_balance']['show'] = true;
            }
        }

        // v61: the price-change announcements describe the NORMAL USER price, so a reseller never receives them (no Normal price is exposed to a reseller).
        if ($row['role'] !== 'admin' && dual_user_type($pdo, $uid) !== DUAL_RESELLER) {
            $pc = $pdo->prepare(
                'SELECT pc.id, pc.product_name, pc.duration, pc.old_price, pc.new_price, pc.created_at
                 FROM price_changes pc
                 JOIN product_plans pl ON pl.id = pc.plan_id
                 JOIN products p ON p.id = pc.product_id
                 LEFT JOIN price_change_acks a ON a.change_id = pc.id AND a.user_id = :u
                 WHERE a.change_id IS NULL
                   AND pc.created_at >= GREATEST(CAST(:since AS DATETIME), NOW() - INTERVAL 30 DAY)
                   AND pl.enabled = 1 AND p.enabled = 1 AND p.archived_at IS NULL
                   AND pc.new_price = pl.price
                   AND pc.id = (SELECT MAX(x.id) FROM price_changes x WHERE x.plan_id = pc.plan_id)
                 ORDER BY pc.id DESC LIMIT 5'
            );
            $pc->execute([':u' => $uid, ':since' => (string)$row['created_at']]);
            foreach ($pc->fetchAll() as $c) {
                $old = (float)$c['old_price']; $new = (float)$c['new_price'];
                $out['price_changes'][] = [
                    'id'        => (int)$c['id'],
                    'product'   => (string)$c['product_name'],
                    'duration'  => (string)$c['duration'],
                    'old_price' => $old,
                    'new_price' => $new,
                    'direction' => $new > $old ? 'increased' : 'decreased',
                    'at'        => (string)$c['created_at'],
                ];
            }
        }
    }
} catch (Throwable $e) {
    // Never block a page over a notice. Classification failure = no popup.
    error_log('[notices] ' . $e->getMessage());
    $out = ['low_balance' => ['show' => false, 'balance' => 0.0], 'price_changes' => []];
}

json_ok($out);
