<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_admin();
csrf_verify();

$userId = input_int('user_id');
$action = input_str('action'); // add_balance | remove_balance | toggle_ban | toggle_role

if ($userId <= 0 && $action !== 'bulk_set_type') json_error('Invalid user.', 422);

$pdo = db();
$stmt = $pdo->prepare('SELECT id, username, role, banned, balance, api_enabled FROM users WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $userId]);
$target = $stmt->fetch();
if (!$target && $action !== 'bulk_set_type') json_error('User not found.', 404);

$adminId = (int)current_user()['id'];

try {
    switch ($action) {
        case 'add_balance':
        case 'remove_balance':
            $amount = round((float)input_param('amount', 0), 2);
            if ($amount <= 0) json_error('Amount must be positive.', 422);

            $pdo->beginTransaction();
            $lock = $pdo->prepare('SELECT balance FROM users WHERE id = :id FOR UPDATE');
            $lock->execute([':id' => $userId]);
            $oldBal = (float)$lock->fetchColumn();

            $delta = $action === 'add_balance' ? $amount : -$amount;
            if ($action === 'remove_balance' && $amount > $oldBal) {
                $pdo->rollBack();
                json_error('Remove amount cannot exceed the current balance.', 422);
            }
            $newBal = round($oldBal + $delta, 2);
            $pdo->prepare('UPDATE users SET balance = :b WHERE id = :id')
                ->execute([':b' => $newBal, ':id' => $userId]);
            $pdo->prepare(
                'INSERT INTO wallet_ledger (user_id, amount, balance_after, type, reference_id, description, created_at)
                 VALUES (:u, :a, :b, "admin_adjust", :r, :d, NOW())'
            )->execute([
                ':u' => $userId,
                ':a' => $delta,
                ':b' => $newBal,
                ':r' => 'ADMIN#' . $adminId,
                ':d' => $action === 'add_balance' ? 'Admin credit' : 'Admin debit',
            ]);
            $pdo->commit();
            audit_log('wallet.admin_adjust', 'success', ['entity_type' => 'user', 'entity_id' => $userId, 'user_id' => $userId, 'actor_id' => $adminId,
                'reason' => ($action === 'add_balance' ? 'Credit ' : 'Debit ') . number_format($amount, 2, '.', '') . ' (balance ' . number_format($oldBal, 2, '.', '') . ' → ' . number_format($newBal, 2, '.', '') . ')']);
            json_ok(['balance' => $newBal]);
            break;

        case 'toggle_api_enabled':
            // Server-side classification used by the Low Balance popup. Every account has an api_token, so the token alone
            // does not tell API users from normal users: this explicit flag does. It does NOT change what the API allows.
            $newFlag = (int)($target['api_enabled'] ?? 0) === 1 ? 0 : 1;
            $pdo->prepare('UPDATE users SET api_enabled = :f, lowbal_ack_at = NULL WHERE id = :id')->execute([':f' => $newFlag, ':id' => $userId]);
            audit_log('user.api_enabled_changed', 'success', ['entity_type' => 'user', 'entity_id' => $userId, 'user_id' => $userId, 'actor_id' => $adminId,
                'reason' => 'API-enabled: ' . ($newFlag ? 'ON' : 'OFF')]);
            json_ok(['api_enabled' => (bool)$newFlag]);
            break;

        case 'grant_reseller':
        case 'revoke_reseller':
            // v61: only an admin can promote / demote; role is stored in users.account_type, existing data untouched
            $res = dual_set_account_type($pdo, $userId, $action === 'grant_reseller' ? DUAL_RESELLER : DUAL_NORMAL, $adminId);
            if (!$res['ok']) json_error((string)$res['error'], 422);
            json_ok(['account_type' => $res['account_type']]);
            break;

        case 'bulk_set_type':
            // v61: promote / demote several users in one request (user_ids = comma separated ids, type = reseller|normal)
            $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)input_param('user_ids', ''))), static fn(int $i): bool => $i > 0)));
            $type = input_str('type') === 'reseller' ? DUAL_RESELLER : DUAL_NORMAL;
            if (!$ids || count($ids) > 200) json_error('Select between 1 and 200 users.', 422);
            $done = 0; $failed = [];
            foreach ($ids as $bid) {
                $res = dual_set_account_type($pdo, $bid, $type, $adminId, 'Bulk change');
                if ($res['ok']) $done++; else $failed[] = $bid;
            }
            json_ok(['changed' => $done, 'failed' => $failed]);
            break;

        case 'toggle_ban':
            $newBan = (int)$target['banned'] === 1 ? 0 : 1;
            $pdo->prepare('UPDATE users SET banned = :b WHERE id = :id')
                ->execute([':b' => $newBan, ':id' => $userId]);
            json_ok(['banned' => (bool)$newBan]);
            break;

        case 'toggle_role':
            if ($userId === $adminId) json_error('You cannot change your own role.', 400);
            $newRole = $target['role'] === 'admin' ? 'user' : 'admin';
            $pdo->prepare('UPDATE users SET role = :r WHERE id = :id')
                ->execute([':r' => $newRole, ':id' => $userId]);
            json_ok(['role' => $newRole]);
            break;

        default:
            json_error('Unknown action.', 400);
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[admin/users-update] ' . $e->getMessage());
    json_error('Operation failed.', 500);
}