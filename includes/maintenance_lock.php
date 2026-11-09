<?php
declare(strict_types=1);

/*
 * v36 — Global maintenance lock (server-side).
 *
 * ONE authoritative switch: settings.maintenance_mode ('1' = ON). The website pages, the website JSON endpoints,
 * the reseller API and the admin toggle all read / write that same row. Nothing here depends on the browser.
 *
 * Enforcement layers (all server-side):
 *   1. maintenance_global_gate()   - called once from includes/bootstrap.php for EVERY web request. Non-admins are stopped
 *                                    before any page or endpoint code runs (direct URLs and direct POSTs included).
 *   2. maintenance_guard_orders()  - called explicitly at the top of every order-creating endpoint
 *                                    (api/purchase.php, api/purchase-api.php, reseller_gateway.php "buy"), so order
 *                                    creation stays blocked even if an endpoint is ever added to the exempt list.
 *
 * Deliberately still allowed while maintenance is ON:
 *   - any request from a signed-in admin (admin dashboard, settings, Pending Orders, refunds, financial reports)
 *   - api/admin/*  (each file still calls require_admin())
 *   - login / logout (non-admin logins are still rejected by api/auth-login.php)
 *   - api/deposits-status.php  (verifies a payment the user ALREADY made, so the wallet credit is never lost)
 *   - reseller API read-only actions: balance, variants, status, history  (order status checks keep working)
 *   - CLI jobs (cron/refund_controller.php) - they do not pass through the web gate
 */

const MAINTENANCE_MESSAGE     = 'The website is under maintenance. New orders are temporarily disabled. Please try again later.';
const MAINTENANCE_ERROR_CODE  = 'MAINTENANCE_MODE';
const MAINTENANCE_RETRY_AFTER = 300;

/** Authoritative read of the switch. Reads the row directly so a stale request cache can never hide a change. */
function maintenance_is_on(): bool
{
    try {
        $q = db()->prepare("SELECT setting_value FROM settings WHERE setting_key = 'maintenance_mode' LIMIT 1");
        $q->execute();
        return trim((string)$q->fetchColumn()) === '1';
    } catch (Throwable $e) {
        error_log('[maintenance_is_on] ' . $e->getMessage());
        return get_setting('maintenance_mode', '0') === '1';
    }
}

/** Machine-readable maintenance reply for every JSON caller: HTTP 503 + {ok:false, code:"MAINTENANCE_MODE", maintenance:true}. */
function maintenance_api_response(): void
{
    if (!headers_sent()) header('Retry-After: ' . MAINTENANCE_RETRY_AFTER);
    json_response([
        'ok'          => false,
        'error'       => MAINTENANCE_MESSAGE,
        'code'        => MAINTENANCE_ERROR_CODE,
        'maintenance' => true,
        'retry_after' => MAINTENANCE_RETRY_AFTER,
    ], 503);
}

/**
 * Order-creation guard. Blocks EVERYONE (admins too): no new order can be created while maintenance is ON.
 * Call it before any wallet / order / provider work.
 */
function maintenance_guard_orders(string $channel = 'order'): void
{
    if (!maintenance_is_on()) return;
    audit_log('security.order_blocked_maintenance', 'denied', ['reason' => 'New order refused during maintenance (' . $channel . ')']);
    maintenance_api_response();
}

/** Path of the running script relative to the site root, e.g. "api/purchase.php". */
function maintenance_request_path(): string
{
    $file = (string)($_SERVER['SCRIPT_FILENAME'] ?? '');
    $real = $file !== '' ? realpath($file) : false;
    $base = realpath(BASE_PATH);
    if ($real !== false && $base !== false && strpos($real, $base) === 0) {
        return ltrim(str_replace('\\', '/', substr($real, strlen($base))), '/');
    }
    return ltrim(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')), '/');
}

/** Scripts that must keep working for non-admins during maintenance (see the header comment for why). */
function maintenance_path_exempt(string $rel): bool
{
    static $exact = [
        'maintenance.php', 'login.php', 'logout.php', 'welcome.php', 'index.php', 'register.php',
        'api/auth-login.php', 'api/auth-logout.php', 'api/auth-register.php',
        'api/deposits-status.php',
        'api/zapupi-webhook.php',   // v68: a payment already made must still be verified/credited during maintenance
    ];
    if (in_array($rel, $exact, true)) return true;
    if (strpos($rel, 'api/admin/') === 0) return true;      // every file in there calls require_admin()
    return false;
}

/** Whole-site gate. Runs on every web request (called from bootstrap.php). */
function maintenance_global_gate(): void
{
    if (PHP_SAPI === 'cli') return;
    if (!maintenance_is_on()) return;
    if (function_exists('is_admin') && is_admin()) return;

    $rel = maintenance_request_path();

    // Reseller API: read-only actions (status checks, balance, history, catalogue) stay available; everything else is blocked.
    if ($rel === 'api/api/reseller_gateway.php') {
        $action = strtolower(trim((string)input_param('action', '')));
        if (in_array($action, ['balance', 'variants', 'status', 'history'], true)) return;
        maintenance_guard_orders('reseller-api');
        maintenance_api_response();
    }

    if (maintenance_path_exempt($rel)) return;

    if (strpos($rel, 'api/') === 0 || (function_exists('is_api_request') && is_api_request())) {
        audit_log('security.maintenance_blocked', 'denied', ['reason' => 'API request refused during maintenance: ' . substr($rel, 0, 100)]);
        maintenance_api_response();
    }

    // HTML page: the existing maintenance screen. Direct POSTs to a page also get 503.
    if (!in_array(request_method(), ['GET', 'HEAD'], true)) {
        http_response_code(503);
        header('Retry-After: ' . MAINTENANCE_RETRY_AFTER);
    }
    require BASE_PATH . '/maintenance.php';
    exit;
}
