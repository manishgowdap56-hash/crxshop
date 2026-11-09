<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

if (request_method() !== 'POST') {
    json_error('POST required', 405);
}
csrf_verify();

$username = input_str('username');
$password = (string)input_param('password', '');
$adminOnly = filter_var(input_param('admin_only', false), FILTER_VALIDATE_BOOLEAN);
$userOnly = filter_var(input_param('user_only', false), FILTER_VALIDATE_BOOLEAN);

// Ensure the configured admin account exists in MySQL before authentication.
if ($username !== '' && hash_equals((string)ADMIN_BOOTSTRAP_USERNAME, $username)) {
    ensure_configured_admin();
}

if ($username === '' || $password === '') {
    json_error('Enter username and password.', 422);
}

$stmt = db()->prepare('SELECT * FROM users WHERE username = :u LIMIT 1');
$stmt->execute([':u' => $username]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password_hash'])) {
    json_error('Invalid username or password.', 401);
}
if ($adminOnly && (string)$user['role'] !== 'admin') {
    json_error('This account does not have admin access.', 403);
}
if ($userOnly && (string)$user['role'] === 'admin') {
    json_error('Use the admin login for an administrator account.', 403);
}
if ((int)$user['banned'] === 1) {
    json_error('This account is banned.', 403);
}

// Global maintenance blocks normal user sessions at the authentication boundary,
// not only at page navigation. Admin authentication remains available.
if (get_setting('maintenance_mode', '0') === '1' && (string)$user['role'] !== 'admin') {
    json_error('Website is currently under maintenance. Admin access only.', 403);
}

login_user($user);

json_ok([
    'user' => [
        'id'       => (int)$user['id'],
        'username' => $user['username'],
        'role'     => $user['role'],
        'balance'  => (float)$user['balance'],
    ],
    'redirect' => $user['role'] === 'admin' ? base_url('admin.php') : base_url('dashboard.php'),
]);