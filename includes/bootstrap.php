<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/paytm.php';
require_once __DIR__ . '/zapupi.php';       // v68: ZapUPI gateway (independent of Paytm)
require_once __DIR__ . '/providers.php';
require_once __DIR__ . '/ops.php';
require_once __DIR__ . '/order_executor.php';   // v59: ONE shared provider-order executor (website + reseller API)
require_once __DIR__ . '/maintenance_lock.php';
require_once __DIR__ . '/refund_controller.php';
require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/schema_compat.php';
require_once __DIR__ . '/referral.php';
require_once __DIR__ . '/workers.php';        // v60: worker locks + real heartbeats
require_once __DIR__ . '/order_review.php';   // v60: pending order review worker logic
require_once __DIR__ . '/price_policy.php';   // v59: custom price, price-increase grace period, duration lifecycle
require_once __DIR__ . '/price_sync.php';   // v55: automatic provider PRICE sync (price verification / correction only)
require_once __DIR__ . '/dual.php';         // v61: Normal User / Reseller account types, dual prices, independent rates, Reseller Membership
require_once __DIR__ . '/theme.php';        // v67: Master Frontend Control (theme storage + server-side head injection)

// Convert uncaught PHP fatal errors on API routes into JSON instead of an
// HTML error page. This keeps the frontend's fetch() contract intact and
// exposes a useful message in the API response.
register_shutdown_function(function (): void {
    $error = error_get_last();
    if (!$error) return;
    $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
    if (!in_array((int)$error['type'], $fatal, true)) return;

    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $isApi = strpos($uri, '/api/') !== false || stripos($accept, 'application/json') !== false;
    if (!$isApi || headers_sent()) return;

    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'error' => 'Server error. Check the database/schema configuration.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
});

// ---- Secure session boot -------------------------------------------
if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
              || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    // Keep the server-side session lifetime aligned with the persistent cookie.
    // Normal refreshes/navigation must not sign the user out.
    ini_set('session.gc_maxlifetime', (string)SESSION_LIFETIME);
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Refresh session timestamp to throttle fixation risk
if (!isset($_SESSION['_boot'])) {
    $_SESSION['_boot'] = time();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

// Repair additive schema gaps before pages/APIs access settings or legacy columns.
ensure_schema_compatibility();
pp_ensure_schema_if_needed(db());   // v59: additive price/lifecycle columns (one cheap check per request)
zapupi_ensure_schema_if_needed(db()); // v68: additive deposit columns + payment_credits + webhook log (one settings flag after first run)
dual_ensure_schema_if_needed(db()); // v61: account_type + reseller price columns + membership table (additive, idempotent)

require_once __DIR__ . '/auth.php';

// v36: whole-site maintenance lock. One authoritative setting; runs before any page/endpoint code for non-admins.
maintenance_global_gate();