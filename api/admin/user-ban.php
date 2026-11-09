<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_admin();

/*
 * Explicit BAN / UNBAN (sets users.banned to a stated value, so a double tap or a stale screen can never flip it the wrong way).
 * Uses the SAME column the existing system already enforces server-side: includes/auth.php logs a banned user out on their next request,
 * login and the reseller gateway reject banned accounts. Orders, keys and history are never touched.
 * POST + CSRF only (no GET state change).
 */
if (request_method() !== 'POST') json_error('Method not allowed.', 405);
csrf_verify();

$userId = input_int('user_id');
$rawBan = input_param('banned', null);
if ($rawBan === null || $rawBan === '') json_error('Invalid request.', 422);   // a missing value must never be read as "unban"
$banned = filter_var($rawBan, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
if ($userId <= 0 || $banned === null) json_error('Invalid request.', 422);

$adminId = (int)current_user()['id'];
if ($userId === $adminId) json_error('You cannot ban or unban your own account.', 400);

$pdo = db();
$st = $pdo->prepare('SELECT id, username, role, banned FROM users WHERE id = :id LIMIT 1');
$st->execute([':id' => $userId]);
$u = $st->fetch();
if (!$u) json_error('User not found.', 404);
if ($u['role'] === 'admin') json_error('Admin accounts cannot be banned from this page.', 403);

$new = $banned ? 1 : 0;
if ((int)$u['banned'] !== $new) {
    $pdo->prepare('UPDATE users SET banned = :b WHERE id = :id')->execute([':b' => $new, ':id' => $userId]);
    // The project has no admin-audit table; a server log line records who did what (no new table created).
    error_log('[admin-audit] admin#' . $adminId . ' ' . ($new ? 'BANNED' : 'UNBANNED') . ' user#' . $userId . ' (' . $u['username'] . ') at ' . date('c'));
}
json_ok(['user_id' => $userId, 'account_status' => $new ? 'banned' : 'active']);
