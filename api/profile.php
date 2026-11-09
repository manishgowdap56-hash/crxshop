<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$user = require_user();
if (request_method() !== 'POST') json_error('POST required', 405);
csrf_verify();

$action = input_str('action');

if ($action === 'change-password') {
    $old     = (string)input_param('old_password', '');
    $new     = (string)input_param('new_password', '');
    $confirm = (string)input_param('confirm_password', '');

    if ($old === '' || $new === '' || $confirm === '') {
        json_error('All three fields are required.', 422);
    }
    if (strlen($new) < PASSWORD_MIN) {
        json_error('New password must be at least ' . PASSWORD_MIN . ' characters.', 422);
    }
    if ($new !== $confirm) {
        json_error('New passwords do not match.', 422);
    }
    if ($new === $old) {
        json_error('New password must differ from the old password.', 422);
    }

    $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => (int)$user['id']]);
    $hash = (string)$stmt->fetchColumn();

    if (!password_verify($old, $hash)) {
        json_error('Old password is incorrect.', 401);
    }

    $newHash = password_hash($new, PASSWORD_BCRYPT);
    db()->prepare('UPDATE users SET password_hash = :h, password_reset_at = NOW() WHERE id = :id')
        ->execute([':h' => $newHash, ':id' => (int)$user['id']]);

    // Regenerate session id for safety
    session_regenerate_id(true);

    json_ok(['message' => 'Password changed successfully.']);
}

json_error('Unknown action.', 400);