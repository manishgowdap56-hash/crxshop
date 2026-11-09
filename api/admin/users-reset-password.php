<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_admin();
csrf_verify();

$userId = input_int('user_id');
if ($userId <= 0) json_error('Invalid user.', 422);

// Generate a strong 12-char password
$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%';
$pwd = '';
for ($i = 0; $i < 12; $i++) $pwd .= $alphabet[random_int(0, strlen($alphabet) - 1)];

$hash = password_hash($pwd, PASSWORD_BCRYPT);

$pdo = db();
$stmt = $pdo->prepare('SELECT id, username FROM users WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $userId]);
$u = $stmt->fetch();
if (!$u) json_error('User not found.', 404);

$pdo->prepare('UPDATE users SET password_hash = :h, password_reset_at = NOW() WHERE id = :id')
    ->execute([':h' => $hash, ':id' => $userId]);

json_ok(['generated_password' => $pwd, 'username' => $u['username']]);