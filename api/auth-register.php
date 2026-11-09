<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/access_keys.php';

if (request_method() !== 'POST') json_error('POST required', 405);
csrf_verify();

if (get_setting('maintenance_mode', '0') === '1') {
    json_error('Website is currently under maintenance. Registration is temporarily disabled.', 403);
}

$username = input_str('username');
$password = (string)input_param('password', '');
$confirm  = (string)input_param('confirm', '');
$refCode  = input_str('ref');
$accessKeyRaw = input_str('access_key');

if (!preg_match('/^[A-Za-z0-9_]{3,40}$/', $username)) {
    json_error('Username must be 3–40 chars (letters, digits, underscore).', 422);
}
if (strlen($password) < PASSWORD_MIN) {
    json_error('Password must be at least ' . PASSWORD_MIN . ' characters.', 422);
}
if ($password !== $confirm) {
    json_error('Passwords do not match.', 422);
}
// Admin switch (Settings -> Access Key -> "Require Access Key for Registration"). Read ONCE, server-side, from the same
// MySQL setting the registration page uses. Default and any read problem = REQUIRED, so it can never be bypassed by accident
// and a direct API call cannot skip it: this endpoint decides, not the form.
$keyRequired = access_key_required();
$accessKey = null;
if ($keyRequired) {
    // The real check (bound username + unused) runs server-side inside the signup transaction below;
    // here we only reject empty / impossible values early.
    if ($accessKeyRaw === '') {
        audit_log('security.access_key_missing', 'denied', ['entity_type' => 'registration', 'reason' => 'Registration attempt without an Access Key']);
        json_error('Access Key is required.', 422);
    }
    $accessKey = access_key_normalize($accessKeyRaw);
    if ($accessKey === null) {
        audit_log('security.access_key_rejected', 'denied', ['entity_type' => 'registration', 'reason' => 'Malformed Access Key']);
        json_error(ACCESS_KEY_REJECTED_MESSAGE, 403);
    }
}

$pdo = db();

// Schema bootstrap runs from includes/bootstrap.php. Refuse cleanly if the
// hosting database is still unavailable rather than allowing a raw PDO
// exception to become a non-JSON browser response.
if (!rp_table_exists($pdo, 'users')) {
    json_error('Database schema is not installed. Please import database/schema.sql or allow the application database user to create tables.', 503);
}

// Fail CLOSED: never allow a signup without the Access Key table (no bypass if the table is missing).
if ($keyRequired && !rp_table_exists($pdo, 'access_keys')) {
    json_error('Registration is temporarily unavailable. Please contact the admin.', 503);
}

// Unique check
$dup = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
$dup->execute([':u' => $username]);
if ($dup->fetch()) {
    json_error('Username already taken.', 409);
}

// Referral lookup
$referrerId = null;
if ($refCode !== '') {
    $r = $pdo->prepare('SELECT id FROM users WHERE referral_code = :c LIMIT 1');
    $r->execute([':c' => $refCode]);
    $row = $r->fetch();
    if ($row) $referrerId = (int)$row['id'];
}

$hash = password_hash($password, PASSWORD_BCRYPT);

// New user's own referral code (unique, retry a few times)
$myCode = null;
for ($i = 0; $i < 5; $i++) {
    $candidate = 'KP-' . preg_replace('/[^A-Za-z0-9]/', '', strtoupper($username));
    $candidate = substr($candidate, 0, 12);
    $candidate .= '-' . strtoupper(bin2hex(random_bytes(2)));
    $chk = $pdo->prepare('SELECT id FROM users WHERE referral_code = :c LIMIT 1');
    $chk->execute([':c' => $candidate]);
    if (!$chk->fetch()) { $myCode = $candidate; break; }
}
if ($myCode === null) $myCode = 'KP-' . strtoupper(bin2hex(random_bytes(5)));

$apiToken = 'psk_' . bin2hex(random_bytes(24));

try {
    $pdo->beginTransaction();

    // Lock the key row for the whole transaction. A concurrent signup using the same key blocks here until this
    // transaction commits or rolls back, then sees status = 'used' and is rejected: one key can never create two accounts.
    // Username must match the one the admin bound to the key (wrong username + valid key is rejected the same way).
    $accessKeyId = 0;
    if ($keyRequired) {
        $kq = $pdo->prepare('SELECT id, status FROM access_keys WHERE access_key = :k AND username = :u LIMIT 1 FOR UPDATE');
        $kq->execute([':k' => $accessKey, ':u' => $username]);
        $keyRow = $kq->fetch();
        if (!$keyRow || $keyRow['status'] !== 'active') {
            throw new AccessKeyRejected();
        }
        $accessKeyId = (int)$keyRow['id'];
    }

    $ins = $pdo->prepare(
        'INSERT INTO users (username, password_hash, role, banned, balance, api_token, referral_code, referred_by, last_active_at, created_at)
         VALUES (:u, :p, "user", 0, 0, :t, :rc, :rb, NOW(), NOW())'
    );
    $ins->execute([
        ':u'  => $username,
        ':p'  => $hash,
        ':t'  => $apiToken,
        ':rc' => $myCode,
        ':rb' => $referrerId,
    ]);
    $uid = (int)$pdo->lastInsertId();

    // Consume the key in the SAME transaction as the user insert (all-or-nothing). The status guard plus the
    // row count make the one-time rule hold even if the lock above were ever bypassed.
    if ($keyRequired) {
        $use = $pdo->prepare("UPDATE access_keys SET status = 'used', used_by_user_id = :uid, used_at = NOW() WHERE id = :id AND status = 'active'");
        $use->execute([':uid' => $uid, ':id' => $accessKeyId]);
        if ($use->rowCount() !== 1) {
            throw new AccessKeyRejected();
        }
    }

    if ($referrerId) {
        $pdo->prepare('INSERT INTO referrals (referrer_id, referred_id, active, created_at)
                       VALUES (:r, :u, 0, NOW())')
            ->execute([':r' => $referrerId, ':u' => $uid]);
    }

    $pdo->commit();
    if ($keyRequired) audit_log('access_key.used', 'success', ['entity_type' => 'access_key', 'entity_id' => $accessKeyId, 'user_id' => $uid, 'reason' => 'Key consumed by successful registration']);
} catch (AccessKeyRejected $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();      // nothing was created, the key stays untouched
    audit_log('security.access_key_rejected', 'denied', ['entity_type' => 'registration', 'reason' => 'Access Key invalid, used, or not bound to this username']);
    json_error(ACCESS_KEY_REJECTED_MESSAGE, 403);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();      // user insert failed: the key is NOT consumed
    if (strpos($e->getMessage(), 'uniq_username') !== false) {
        json_error('Username already taken.', 409);   // lost a race with another signup for the same username
    }
    error_log('[register] ' . $e->getMessage());
    json_error('Registration failed. Please try again.', 500);
}

// Auto-login
$row = $pdo->prepare('SELECT * FROM users WHERE id = :id');
$row->execute([':id' => $uid]);
$user = $row->fetch();
login_user($user);

json_ok([
    'redirect' => base_url('dashboard.php'),
    'user' => [
        'id'       => $uid,
        'username' => $username,
        'role'     => 'user',
    ],
]);