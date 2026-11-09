<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/access_keys.php';
require_admin();

/*
 * Admin-only management of one-time signup Access Keys.
 *   GET                         -> list (newest first) + counts
 *   POST action=generate        -> username          : new random key bound to that username
 *   POST action=revoke          -> id                : invalidate a key that is still unused
 * Validation / consumption at signup lives in api/auth-register.php (server-side, inside the signup transaction).
 */
$pdo = db();
if (!rp_table_exists($pdo, 'access_keys')) {
    json_error('Access key storage is not ready. Reload the page; if this persists, allow the database user to create tables or import database/schema.sql.', 503);
}

if (request_method() === 'GET') {
    $rows = $pdo->query(
        'SELECT id, access_key, username, status, used_at, revoked_at, created_at
         FROM access_keys ORDER BY id DESC LIMIT 100'
    )->fetchAll();
    $items = [];
    foreach ($rows as $r) {
        $active = ($r['status'] === 'active');
        $items[] = [
            'id'         => (int)$r['id'],
            // Full key only while it can still be used; consumed / revoked keys are shown masked.
            'key'        => $active ? access_key_format((string)$r['access_key']) : access_key_mask((string)$r['access_key']),
            'username'   => (string)$r['username'],
            'status'     => (string)$r['status'],
            'created_at' => (string)$r['created_at'],
            'used_at'    => $r['used_at'],
            'revoked_at' => $r['revoked_at'],
        ];
    }
    $counts = ['active' => 0, 'used' => 0, 'revoked' => 0];
    foreach ($pdo->query('SELECT status, COUNT(*) AS c FROM access_keys GROUP BY status')->fetchAll() as $c) {
        $counts[(string)$c['status']] = (int)$c['c'];
    }
    json_ok(['keys' => $items, 'counts' => $counts, 'required' => access_key_required()]);
}

if (request_method() !== 'POST') json_error('Method not allowed.', 405);
csrf_verify();

$admin  = require_admin();
$action = input_str('action');

if ($action === 'set_required') {
    // "Require Access Key for Registration" ON / OFF. Admin-only (require_admin above), CSRF-checked, stored in MySQL
    // settings (survives refresh / logout / restart) and read by BOTH the registration page and the registration endpoint.
    $on = filter_var(input_param('enabled', true), FILTER_VALIDATE_BOOLEAN);
    $before = access_key_required();
    set_setting('access_key_required', $on ? '1' : '0');
    if (access_key_required() !== $on) json_error('Could not save the setting. Please try again.', 500);   // read back to confirm
    audit_log('access_key.requirement_changed', 'success', [
        'entity_type' => 'setting', 'entity_id' => 'access_key_required', 'actor_id' => (int)$admin['id'],
        'reason' => 'Require Access Key for Registration: ' . ($before ? 'ON' : 'OFF') . ' → ' . ($on ? 'ON' : 'OFF'),
    ]);
    json_ok(['required' => $on]);
}

if ($action === 'generate') {
    $username = input_str('username');
    if (!preg_match('/^[A-Za-z0-9_]{3,40}$/', $username)) {
        json_error('Username must be 3–40 chars (letters, digits, underscore).', 422);
    }

    $u = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
    $u->execute([':u' => $username]);
    if ($u->fetch()) json_error('This username already exists, so it cannot sign up again. Choose a new username.', 409);

    $a = $pdo->prepare("SELECT id FROM access_keys WHERE username = :u AND status = 'active' LIMIT 1");
    $a->execute([':u' => $username]);
    if ($a->fetch()) json_error('This username already has an active Access Key. Revoke it first to generate a new one.', 409);

    $created = null;
    for ($i = 0; $i < 5 && $created === null; $i++) {
        $key = access_key_generate();
        try {
            $pdo->prepare(
                "INSERT INTO access_keys (access_key, username, status, created_by, created_at)
                 VALUES (:k, :u, 'active', :a, NOW())"
            )->execute([':k' => $key, ':u' => $username, ':a' => (int)$admin['id']]);
            $created = ['id' => (int)$pdo->lastInsertId(), 'key' => $key];
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'uniq_access_key') === false) {   // only a key collision may be retried
                error_log('[access-keys] ' . $e->getMessage());
                json_error('Could not generate the Access Key. Please try again.', 500);
            }
        }
    }
    if ($created === null) json_error('Could not generate a unique Access Key. Please try again.', 500);

    audit_log('access_key.generated', 'success', ['entity_type' => 'access_key', 'entity_id' => $created['id'], 'actor_id' => (int)$admin['id'], 'reason' => 'Key generated for username ' . $username]);
    json_ok(['id' => $created['id'], 'key' => access_key_format($created['key']), 'username' => $username]);
}

if ($action === 'revoke') {
    $id = input_int('id');
    if ($id <= 0) json_error('Invalid key.', 422);
    // Conditional UPDATE: a key that was just consumed by a signup (signup holds a row lock) can never be "revoked" over it.
    $st = $pdo->prepare("UPDATE access_keys SET status = 'revoked', revoked_at = NOW() WHERE id = :id AND status = 'active'");
    $st->execute([':id' => $id]);
    if ($st->rowCount() !== 1) json_error('This key is not active (already used or revoked).', 409);
    audit_log('access_key.revoked', 'success', ['entity_type' => 'access_key', 'entity_id' => $id, 'actor_id' => (int)$admin['id']]);
    json_ok(['id' => $id]);
}

json_error('Unknown action.', 400);
