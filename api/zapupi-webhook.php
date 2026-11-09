<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

/**
 * ZapUPI webhook receiver  (register https://YOUR-SITE/api/zapupi-webhook.php as the webhook URL).
 *
 * No session, no CSRF (ZapUPI is a server, not a logged-in browser). The body is untrusted: it only triggers a
 * server-side Order Status verification; the wallet is credited exclusively by zapupi_credit_verified().
 * The ZapUPI documentation supplied with this project specifies NO signature/auth header, so none is invented.
 */

// Webhooks carry no browser session: release it immediately.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

header('Cache-Control: no-store');
if (request_method() !== 'POST') {
    json_response(['status' => 'error', 'message' => 'POST required'], 405);
}

// Read at most 16 KB + 1 byte of the raw JSON body.
$raw = (string)file_get_contents('php://input', false, null, 0, 16385);

try {
    [$http, $body] = zapupi_handle_webhook(db(), $raw);
} catch (Throwable $e) {
    error_log('[zapupi-webhook] ' . $e->getMessage());
    $http = 503;                       // transient: let the provider retry, cron reconciliation is the safety net
    $body = ['status' => 'retry'];
}
json_response($body, $http);
