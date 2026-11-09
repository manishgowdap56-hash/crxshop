<?php
declare(strict_types=1);

/* ------------------------------------------------------------------ */
/*  Current user                                                      */
/* ------------------------------------------------------------------ */

function current_user(): ?array
{
    static $cached = false;
    static $user = null;

    if ($cached) return $user;

    $uid = $_SESSION['user_id'] ?? null;
    if (!$uid) {
        $cached = true;
        return null;
    }

    try {
        $stmt = db()->prepare(
            'SELECT id, username, role, banned, balance, api_token,
                    referral_code, referred_by, created_at, last_login_at,
                    last_active_at
             FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => (int)$uid]);
        $row = $stmt->fetch();

        if (!$row) {
            logout_user();
            $cached = true;
            return null;
        }

        if ((int)$row['banned'] === 1) {
            logout_user();
            $cached = true;
            return null;
        }

        // Keep the authenticated session across normal page refreshes and
        // browser restarts until the user explicitly logs out or the
        // browser clears the session cookie. last_active_at is retained
        // only for activity/reporting; it is NOT an auto-logout timer.

        // Refresh activity on every authenticated request.
        db()->prepare('UPDATE users SET last_active_at = NOW() WHERE id = :id')
           ->execute([':id' => (int)$row['id']]);

        $row['last_active_at'] = date('Y-m-d H:i:s');
        $user = $row;
    } catch (Throwable $e) {
        error_log('[current_user] ' . $e->getMessage());
        $user = null;
    }
    $cached = true;
    return $user;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function is_admin(): bool
{
    $u = current_user();
    return $u !== null && $u['role'] === 'admin';
}

/* ------------------------------------------------------------------ */
/*  Guards                                                            */
/* ------------------------------------------------------------------ */

function require_user(): array
{
    $u = current_user();
    if (!$u) {
        if (is_api_request()) {
            json_error('Authentication required', 401);
        }
        redirect('login.php');
    }
    return $u;
}

function require_admin(): array
{
    $u = current_user();
    if (!$u || $u['role'] !== 'admin') {
        audit_log('security.admin_access_denied', 'denied', ['reason' => ($u ? 'Signed-in non-admin' : 'Not signed in') . ' requested ' . substr((string)($_SERVER['REQUEST_URI'] ?? ''), 0, 120),
                                                             'user_id' => $u ? (int)$u['id'] : null]);
        if (is_api_request()) {
            json_error('Admin access required', 403);
        }
        redirect('login.php');
    }
    return $u;
}

function is_api_request(): bool
{
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (strpos($uri, '/api/') !== false) return true;
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    if (stripos($accept, 'application/json') !== false) return true;
    return false;
}

/* ------------------------------------------------------------------ */
/*  CSRF                                                              */
/* ------------------------------------------------------------------ */

function csrf_token(): string
{
    if (empty($_SESSION[CSRF_KEY])) {
        $_SESSION[CSRF_KEY] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_KEY];
}

function csrf_field(): string
{
    return '<input type="hidden" name="' . CSRF_KEY . '" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): void
{
    if (in_array(request_method(), ['GET', 'HEAD', 'OPTIONS'], true)) return;

    $sent = $_SERVER['HTTP_X_CSRF_TOKEN']
          ?? (string)input_param(CSRF_KEY, '');

    $expected = $_SESSION[CSRF_KEY] ?? '';
    if ($expected === '' || !hash_equals($expected, (string)$sent)) {
        audit_log('security.csrf_failed', 'denied', ['reason' => 'Invalid CSRF token on ' . substr((string)($_SERVER['REQUEST_URI'] ?? ''), 0, 120)]);
        if (is_api_request()) {
            json_error('Invalid CSRF token', 419);
        }
        http_response_code(419);
        exit('Session expired. Please reload and try again.');
    }
}

/* ------------------------------------------------------------------ */
/*  Login / logout                                                    */
/* ------------------------------------------------------------------ */

function login_user(array $row): void
{
    session_regenerate_id(true);
    $_SESSION['user_id']  = (int)$row['id'];
    $_SESSION['username'] = (string)$row['username'];
    $_SESSION['role']     = (string)$row['role'];
    $_SESSION['logged_at'] = time();

    // refresh csrf
    $_SESSION[CSRF_KEY] = bin2hex(random_bytes(32));

    db()->prepare('UPDATE users SET last_login_at = NOW(), last_active_at = NOW() WHERE id = :id')
       ->execute([':id' => (int)$row['id']]);
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/* ------------------------------------------------------------------ */
/*  Admin account bootstrap (only run once via CLI or setup)          */
/* ------------------------------------------------------------------ */

function ensure_first_admin(string $username, string $password): bool
{
    $existing = db()->query("SELECT id FROM users WHERE role='admin' LIMIT 1")->fetch();
    if ($existing) return false;

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = db()->prepare(
        'INSERT INTO users (username, password_hash, role, banned, balance, referral_code, created_at)
         VALUES (:u, :p, "admin", 0, 0, :rc, NOW())'
    );
    $stmt->execute([
        ':u'  => $username,
        ':p'  => $hash,
        ':rc' => 'KP-ADMIN-' . strtoupper(bin2hex(random_bytes(3))),
    ]);
    return true;
}

/**
 * Create/synchronize the configured bootstrap admin in MySQL.
 * Passwords are stored only as bcrypt hashes.
 */
function ensure_configured_admin(): void
{
    $username = trim((string)ADMIN_BOOTSTRAP_USERNAME);
    $password = (string)ADMIN_BOOTSTRAP_PASSWORD;

    if ($username === '' || $password === '') return;

    $pdo = db();
    $stmt = $pdo->prepare('SELECT id, role, password_hash FROM users WHERE username = :u LIMIT 1');
    $stmt->execute([':u' => $username]);
    $row = $stmt->fetch();

    if (!$row) {
        $ins = $pdo->prepare(
            'INSERT INTO users
                (username, password_hash, role, banned, balance, referral_code, created_at, last_active_at)
             VALUES (:u, :p, "admin", 0, 0, :rc, NOW(), NOW())'
        );
        $ins->execute([
            ':u'  => $username,
            ':p'  => password_hash($password, PASSWORD_BCRYPT),
            ':rc' => 'KP-ADMIN-' . strtoupper(bin2hex(random_bytes(3))),
        ]);
        return;
    }

    if ((string)$row['role'] !== 'admin' || !password_verify($password, (string)$row['password_hash'])) {
        $upd = $pdo->prepare(
            'UPDATE users
             SET password_hash = :p, role = "admin", banned = 0
             WHERE id = :id'
        );
        $upd->execute([
            ':p' => password_hash($password, PASSWORD_BCRYPT),
            ':id' => (int)$row['id'],
        ]);
    }
}
