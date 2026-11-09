<?php
declare(strict_types=1);

/**
 * ZapUPI payment gateway (additional deposit method next to Paytm Business).
 *
 * SECURITY MODEL (read this before changing anything)
 *  - Nothing the browser, a redirect URL or a webhook body says is ever proof of payment.
 *    The ONLY proof is ZapUPI's own answer to POST /api/order-status, asked by THIS server.
 *  - Webhook authentication: the ZapUPI documentation supplied with this project defines NO signature or
 *    auth header. None is invented. A webhook is therefore only a "go and check" trigger; the wallet is
 *    credited exclusively from the server-side Order Status verification.
 *  - One payment = one local deposit row = one wallet credit. Enforced by: row lock (SELECT ... FOR UPDATE),
 *    a guarded UPDATE, and DATABASE UNIQUE keys on payment_credits(deposit_id) and payment_credits(gateway, provider_txn_id).
 *  - Money is compared as integer paise and added in SQL (balance = balance + DECIMAL string). No float wallet maths.
 *  - Test payments (environment "test" or txn_id starting "DUMMY") are never credited in production.
 *
 * Paytm code is NOT touched by this module. The two gateways only share the deposits table, wallet_ledger and users.balance.
 */

const ZAPUPI_DEFAULT_ENDPOINT   = 'https://pay.zapupi.com/api';
const ZAPUPI_HOST_SUFFIX        = 'zapupi.com';
const ZAPUPI_METHOD             = 'zapupi';          // deposits.method AND deposits.gateway
const ZAPUPI_MIN_STATUS_GAP_SEC = 3;                 // per-deposit throttle for provider status calls
const ZAPUPI_CRON_INTERVAL_MIN  = 5;                 // reconciliation: minimum minutes between checks of one deposit
const ZAPUPI_CRON_MAX_ATTEMPTS  = 48;                // reconciliation: stop after this many cron checks (about 4 h)
const ZAPUPI_CRON_MAX_AGE_HOURS = 48;                // reconciliation: ignore deposits older than this
const ZAPUPI_CREATE_PER_MINUTE  = 3;                 // order-creation rate limit per user
const ZAPUPI_MAX_OPEN_PENDING   = 5;                 // open (unpaid) ZapUPI orders per user
const ZAPUPI_WEBHOOK_PER_MINUTE = 120;               // webhook rate limit per IP

/* ================================================================== */
/*  Schema (additive + idempotent; never drops or rewrites data)        */
/* ================================================================== */

function zapupi_ensure_schema_if_needed(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        if (get_setting('zapupi_schema_v', '') === '1') return;

        // Extra columns on the EXISTING deposits table (legacy + Paytm rows keep NULLs).
        rp_add_column($pdo, 'deposits', 'gateway', 'VARCHAR(20) DEFAULT NULL');
        rp_add_column($pdo, 'deposits', 'pay_id', 'VARCHAR(64) DEFAULT NULL');
        rp_add_column($pdo, 'deposits', 'pay_amount', 'DECIMAL(12,2) DEFAULT NULL');
        rp_add_column($pdo, 'deposits', 'utr', 'VARCHAR(64) DEFAULT NULL');
        rp_add_column($pdo, 'deposits', 'environment', 'VARCHAR(20) DEFAULT NULL');
        rp_add_column($pdo, 'deposits', 'credit_status', 'VARCHAR(24) DEFAULT NULL');
        rp_add_column($pdo, 'deposits', 'paid_at', 'DATETIME DEFAULT NULL');
        rp_add_column($pdo, 'deposits', 'verify_attempts', 'INT UNSIGNED NOT NULL DEFAULT 0');
        rp_add_column($pdo, 'deposits', 'last_checked_at', 'DATETIME DEFAULT NULL');
        rp_add_column($pdo, 'deposits', 'updated_at', 'DATETIME DEFAULT NULL');
        rp_add_index($pdo, 'deposits', 'idx_dep_gateway_credit', '(`gateway`, `credit_status`, `created_at`)');

        // One credit record per deposit AND per provider transaction: the database itself refuses a second credit.
        $pdo->exec("CREATE TABLE IF NOT EXISTS payment_credits (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            deposit_id BIGINT UNSIGNED NOT NULL,
            gateway VARCHAR(20) NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            local_order_id VARCHAR(40) NOT NULL,
            provider_txn_id VARCHAR(64) NOT NULL,
            utr VARCHAR(64) DEFAULT NULL,
            amount DECIMAL(12,2) NOT NULL,
            source VARCHAR(20) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_pc_deposit (deposit_id),
            UNIQUE KEY uniq_pc_gateway_txn (gateway, provider_txn_id),
            KEY idx_pc_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Webhook diagnostics. No credentials, no mobile numbers, no raw bodies.
        $pdo->exec("CREATE TABLE IF NOT EXISTS zapupi_webhook_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ip VARCHAR(45) DEFAULT NULL,
            order_ref VARCHAR(64) DEFAULT NULL,
            txn_id VARCHAR(64) DEFAULT NULL,
            payload_status VARCHAR(20) DEFAULT NULL,
            environment VARCHAR(20) DEFAULT NULL,
            amount VARCHAR(20) DEFAULT NULL,
            outcome VARCHAR(40) NOT NULL,
            detail VARCHAR(255) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_zwe_order (order_ref),
            KEY idx_zwe_ip_time (ip, received_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES
            ('zapupi_enabled', 'off'),
            ('zapupi_api_endpoint', '" . ZAPUPI_DEFAULT_ENDPOINT . "'),
            ('zapupi_api_key', ''),
            ('zapupi_display_name', 'ZapUPI'),
            ('zapupi_min_amount', '10'),
            ('zapupi_max_amount', '100000'),
            ('zapupi_instructions', ''),
            ('zapupi_mode', 'production'),
            ('zapupi_last_api', '')
            ON DUPLICATE KEY UPDATE setting_key = setting_key");

        // Only remember "done" when every object really exists (rp_add_column swallows errors, e.g. missing ALTER privilege).
        $must = ['gateway', 'pay_id', 'pay_amount', 'utr', 'environment', 'credit_status', 'paid_at', 'verify_attempts', 'last_checked_at', 'updated_at'];
        foreach ($must as $col) {
            if (!rp_column_exists($pdo, 'deposits', $col)) { error_log('[zapupi_schema] deposits.' . $col . ' is missing; will retry'); return; }
        }
        if (!rp_table_exists($pdo, 'payment_credits') || !rp_table_exists($pdo, 'zapupi_webhook_events')) { error_log('[zapupi_schema] tables missing; will retry'); return; }
        set_setting('zapupi_schema_v', '1');
    } catch (Throwable $e) {
        error_log('[zapupi_schema] ' . $e->getMessage());
    }
}

/* ================================================================== */
/*  Configuration (the API key never leaves the server)                 */
/* ================================================================== */

/** Where the key comes from: 'env' (ZAPUPI_API_KEY, preferred), 'settings' (admin panel) or 'none'. */
function zapupi_key_source(): string
{
    $env = getenv('ZAPUPI_API_KEY');
    if (is_string($env) && trim($env) !== '') return 'env';
    return trim((string)get_setting('zapupi_api_key', '')) !== '' ? 'settings' : 'none';
}

function zapupi_api_key(): string
{
    $env = getenv('ZAPUPI_API_KEY');
    if (is_string($env) && trim($env) !== '') return trim($env);
    return trim((string)get_setting('zapupi_api_key', ''));
}

/** "••••ABCD" style mask for the admin UI. Short keys are fully masked. */
function zapupi_mask_key(string $key): string
{
    if ($key === '') return '';
    return strlen($key) >= 12 ? '••••••••' . substr($key, -4) : '••••••••';
}

function zapupi_endpoint_valid(string $url): bool
{
    return zapupi_https_zapupi_url($url);
}

function zapupi_config(): array
{
    $endpoint = rtrim(trim((string)get_setting('zapupi_api_endpoint', ZAPUPI_DEFAULT_ENDPOINT)), '/');
    if (!zapupi_endpoint_valid($endpoint)) $endpoint = ZAPUPI_DEFAULT_ENDPOINT;

    $minP = zapupi_paise((string)get_setting('zapupi_min_amount', '10'));
    if ($minP === null || $minP <= 0) $minP = 1000;
    $maxP = zapupi_paise((string)get_setting('zapupi_max_amount', '100000'));
    if ($maxP === null || $maxP < $minP) $maxP = max($minP, 10000000);

    $name = trim((string)get_setting('zapupi_display_name', 'ZapUPI'));
    if ($name === '') $name = 'ZapUPI';

    $mode = get_setting('zapupi_mode', 'production') === 'test' ? 'test' : 'production';

    return [
        'enabled'      => get_setting('zapupi_enabled', 'off') === 'on',
        'configured'   => zapupi_api_key() !== '',
        'endpoint'     => $endpoint,
        'name'         => mb_substr($name, 0, 40),
        'min_paise'    => $minP,
        'max_paise'    => $maxP,
        'instructions' => mb_substr((string)get_setting('zapupi_instructions', ''), 0, 500),
        'mode'         => $mode,
    ];
}

/** Gateway usable by customers right now (admin ON and key present). Paytm's switch is independent. */
function zapupi_available(): bool
{
    $c = zapupi_config();
    return $c['enabled'] && $c['configured'];
}

/**
 * Test payments may be credited ONLY when the admin switched the mode to "test" AND the site itself is not in
 * production (APP_ENV). On a live site this is always false, so test payments can never reach a real wallet.
 */
function zapupi_test_credit_allowed(): bool
{
    return zapupi_config()['mode'] === 'test' && defined('APP_ENV') && APP_ENV !== 'production';
}

/** Absolute https URL on this site (ZapUPI redirects/webhooks must be https). */
function zapupi_public_url(string $path): string
{
    $u = base_url($path);
    if (stripos($u, 'https://') !== 0) {
        throw new RuntimeException('The site must be served over HTTPS (set APP_URL to your https address) to use ZapUPI.');
    }
    return $u;
}

/* ================================================================== */
/*  Small safe helpers                                                  */
/* ================================================================== */

/** Scalar field of a decoded JSON object as a trimmed string ('' when absent / not scalar). */
function zapupi_s(array $a, string $k): string
{
    return (isset($a[$k]) && is_scalar($a[$k]) && !is_bool($a[$k])) ? trim((string)$a[$k]) : '';
}

/**
 * Money text/number -> integer paise, or null when it is not a clean amount with at most 2 decimals.
 * (No float arithmetic is ever applied to money; floats are only re-expressed as text.)
 */
function zapupi_paise($v): ?int
{
    if (is_int($v)) return $v >= 0 && $v <= 999999999 ? $v * 100 : null;
    if (is_float($v)) {
        if (!is_finite($v) || $v < 0 || $v > 999999999) return null;
        $v = number_format($v, 2, '.', '');
    }
    if (!is_string($v)) return null;
    $v = trim($v);
    if (!preg_match('/^(\d{1,9})(?:\.(\d{1,2}))?$/D', $v, $m)) return null;
    $frac = isset($m[2]) ? str_pad($m[2], 2, '0') : '00';
    return ((int)$m[1]) * 100 + (int)$frac;
}

function zapupi_fmt_paise(int $p): string
{
    return intdiv($p, 100) . '.' . str_pad((string)($p % 100), 2, '0', STR_PAD_LEFT);
}

/** https://<zapupi.com or *.zapupi.com>[:443]/... with no credentials in the URL. */
function zapupi_https_zapupi_url(string $url): bool
{
    if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7F]/', $url)) return false;
    $p = parse_url($url);
    if (!is_array($p) || strtolower((string)($p['scheme'] ?? '')) !== 'https') return false;
    if (isset($p['user']) || isset($p['pass'])) return false;
    if (isset($p['port']) && (int)$p['port'] !== 443) return false;
    $host = strtolower((string)($p['host'] ?? ''));
    if ($host === '' || !preg_match('/^[a-z0-9.-]+$/', $host)) return false;
    $suffix = '.' . ZAPUPI_HOST_SUFFIX;
    return $host === ZAPUPI_HOST_SUFFIX
        || (strlen($host) > strlen($suffix) && substr($host, -strlen($suffix)) === $suffix);
}

/** Whitelisted, secret-free view of a provider answer (for DB storage / admin display). Never contains zap_key, URLs or mobile numbers. */
function zapupi_safe_json(array $a): string
{
    $out = [];
    foreach (['status', 'message', 'order_id', 'txn_id', 'amount', 'pay_amount', 'utr', 'environment', 'pay_id', 'create_at'] as $k) {
        if (isset($a[$k]) && is_scalar($a[$k])) $out[$k] = mb_substr(ops_sanitize((string)$a[$k], 160), 0, 160);
    }
    return (string)json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function zapupi_is_test_payment(string $environment, string $txnId): bool
{
    return strtolower($environment) === 'test' || strncasecmp($txnId, 'DUMMY', 5) === 0;
}

/** Remember the latest provider interaction for the admin panel. Text is sanitised; the key can never appear here. */
function zapupi_record_api(string $action, bool $ok, int $http, string $message): void
{
    try {
        set_setting('zapupi_last_api', (string)json_encode([
            'at' => date('Y-m-d H:i:s'), 'action' => $action, 'ok' => $ok, 'http' => $http,
            'message' => ops_sanitize($message, 200),
        ], JSON_UNESCAPED_UNICODE));
    } catch (Throwable $e) {
        error_log('[zapupi_record_api] ' . $e->getMessage());
    }
}

/* ================================================================== */
/*  HTTP (TLS verified, timeouts, strict JSON)                          */
/* ================================================================== */

/**
 * POST JSON to the ZapUPI API. Returns ['http' => int, 'json' => array].
 * Throws RuntimeException (safe text, never containing the key) on transport errors or a non-JSON answer.
 * HTTP 200 alone proves nothing: callers must validate the JSON content.
 */
function zapupi_http_post(string $path, array $payload): array
{
    if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL extension is required for ZapUPI.');
    $cfg = zapupi_config();
    $url = $cfg['endpoint'] . '/' . ltrim($path, '/');
    if (!zapupi_https_zapupi_url($url)) throw new RuntimeException('ZapUPI endpoint is not an allowed HTTPS address.');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
    ]);
    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $err !== '') {
        throw new RuntimeException('ZapUPI connection failed' . ($err !== '' ? ': ' . ops_sanitize($err, 120) : '.'));
    }
    $json = json_decode((string)$raw, true);
    if (!is_array($json) || json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('ZapUPI returned an invalid (non-JSON) response (HTTP ' . $code . ').');
    }
    return ['http' => $code, 'json' => $json];
}

/* ================================================================== */
/*  Create order                                                        */
/* ================================================================== */

/** Validate a client-supplied amount against the ZapUPI limits. Returns paise or throws RuntimeException (422-style text). */
function zapupi_validate_amount($raw): int
{
    if (is_array($raw) || is_bool($raw) || $raw === null) throw new RuntimeException('Invalid amount.');
    $p = zapupi_paise(is_string($raw) ? $raw : (is_int($raw) || is_float($raw) ? $raw : ''));
    if ($p === null || $p <= 0) throw new RuntimeException('Invalid amount.');
    $c = zapupi_config();
    if ($p < $c['min_paise'] || $p > $c['max_paise']) {
        throw new RuntimeException('Deposit amount must be between ₹' . zapupi_fmt_paise($c['min_paise']) . ' and ₹' . zapupi_fmt_paise($c['max_paise']) . '.');
    }
    return $p;
}

/**
 * Create a local pending deposit, then the ZapUPI order. The user comes ONLY from the authenticated session.
 * Returns ['deposit' => [...]] with a server-validated payment_url. Throws RuntimeException with a user-safe message.
 * Code 429 = rate limited, 503 = unavailable, 422 = bad amount, 502 = provider problem.
 */
function zapupi_create_deposit(PDO $pdo, array $user, $rawAmount): array
{
    $cfg = zapupi_config();
    if (!$cfg['enabled'] || !$cfg['configured']) throw new RuntimeException($cfg['name'] . ' is not available right now.', 503);

    try { $paise = zapupi_validate_amount($rawAmount); }
    catch (RuntimeException $e) { throw new RuntimeException($e->getMessage(), 422); }

    $userId = (int)$user['id'];

    // ---- Rate limits (database based, per user) ----------------------------
    $q = $pdo->prepare('SELECT COUNT(*) FROM deposits WHERE user_id = :u AND method = :m AND created_at > (NOW() - INTERVAL 60 SECOND)');
    $q->execute([':u' => $userId, ':m' => ZAPUPI_METHOD]);
    if ((int)$q->fetchColumn() >= ZAPUPI_CREATE_PER_MINUTE) throw new RuntimeException('Too many payment attempts. Please wait a minute and try again.', 429);
    $q = $pdo->prepare('SELECT COUNT(*) FROM deposits WHERE user_id = :u AND method = :m AND status = "pending" AND created_at > (NOW() - INTERVAL 1 DAY)');
    $q->execute([':u' => $userId, ':m' => ZAPUPI_METHOD]);
    if ((int)$q->fetchColumn() >= ZAPUPI_MAX_OPEN_PENDING) throw new RuntimeException('You have several unpaid payments open. Please complete or wait for them before starting a new one.', 429);

    $successUrl = $failedUrl = $timeoutUrl = $webhookUrl = '';
    try {
        $webhookUrl = zapupi_public_url('api/zapupi-webhook.php');
    } catch (RuntimeException $e) {
        error_log('[zapupi_create] ' . $e->getMessage());
        zapupi_record_api('create-order', false, 0, $e->getMessage());
        throw new RuntimeException('Payment is temporarily unavailable.', 503);
    }

    $amountStr = zapupi_fmt_paise($paise);
    $lastErr = 'Could not create the payment order.';

    for ($attempt = 0; $attempt < 3; $attempt++) {
        // ---- Unique local order id: cryptographically random + DB UNIQUE keys ----
        $orderRef = 'ZP' . strtoupper(bin2hex(random_bytes(10)));
        try {
            $pdo->prepare(
                'INSERT INTO deposits (order_ref, user_id, amount, status, method, gateway, gateway_order_id, gateway_status, credit_status, expires_at, created_at, updated_at)
                 VALUES (:ref, :u, :a, "pending", :m, :g, :go, "INITIATED", "pending", NULL, NOW(), NOW())'
            )->execute([':ref' => $orderRef, ':u' => $userId, ':a' => $amountStr, ':m' => ZAPUPI_METHOD, ':g' => ZAPUPI_METHOD, ':go' => $orderRef]);
        } catch (PDOException $dup) {
            if ((string)$dup->getCode() === '23000') continue;       // astronomically unlikely id clash: new id
            throw $dup;
        }
        $depositId = (int)$pdo->lastInsertId();
        // ZapUPI appends ?order_id=<our order id>&utr=<utr> to these URLs itself, so they carry no query string of their own.
        // The pages also accept the old ?order= form and fall back to the user's latest ZapUPI order. Nothing in the URL is trusted.
        $successUrl = zapupi_public_url('payment-success.php');
        $failedUrl  = zapupi_public_url('payment-failed.php');
        $timeoutUrl = zapupi_public_url('payment-timeout.php');

        $failRow = static function (string $why) use ($pdo, $depositId, $userId): void {
            try {
                $pdo->prepare('UPDATE deposits SET status = "rejected", credit_status = "failed", gateway_status = "CREATE_FAILED", gateway_response = :r, reviewed_at = NOW(), updated_at = NOW()
                               WHERE id = :id AND user_id = :u AND status = "pending" AND (gateway_txn_id IS NULL)')
                    ->execute([':r' => mb_substr(ops_sanitize($why, 300), 0, 300), ':id' => $depositId, ':u' => $userId]);
            } catch (Throwable $e) { error_log('[zapupi_create:failrow] ' . $e->getMessage()); }
        };

        try {
            $res = zapupi_http_post('create-order', [
                'zap_key'         => zapupi_api_key(),
                'order_id'        => $orderRef,
                'amount'          => $amountStr,
                'remark'          => 'Deposit | User ' . $userId,
                'success_url'     => $successUrl,
                'failed_url'      => $failedUrl,
                'timeout_url'     => $timeoutUrl,
                'webhook_url'     => $webhookUrl,
            ]);
        } catch (RuntimeException $e) {
            $failRow($e->getMessage());
            zapupi_record_api('create-order', false, 0, $e->getMessage());
            error_log('[zapupi_create] ' . $e->getMessage());
            throw new RuntimeException('Could not reach the payment provider. Please try again.', 502);
        }

        $j = $res['json'];
        $status = strtolower(zapupi_s($j, 'status'));
        $msg = zapupi_s($j, 'message');

        // Duplicate order id (provider side): our id was random, so just try once more with a fresh one.
        if ($status !== 'success' && stripos($msg, 'already exists') !== false) {
            $failRow('Duplicate order id at provider');
            zapupi_record_api('create-order', false, $res['http'], 'Duplicate order id; retrying with a new id.');
            $lastErr = 'Please try again.';
            continue;
        }

        // Never trust HTTP 200 alone: validate status, identifiers and payment_url.
        $txnId = zapupi_s($j, 'txn_id');
        $payUrl = zapupi_s($j, 'payment_url');
        $okShape = $status === 'success'
            && zapupi_s($j, 'order_id') === $orderRef
            && preg_match('/^[A-Za-z0-9_\-]{4,64}$/D', $txnId) === 1
            && zapupi_https_zapupi_url($payUrl);
        if (!$okShape) {
            $failRow('Create-order response rejected: ' . ($msg !== '' ? $msg : 'invalid response'));
            zapupi_record_api('create-order', false, $res['http'], $status === 'success' ? 'Success response failed validation (order_id / txn_id / payment_url).' : ($msg !== '' ? $msg : 'Unexpected response.'));
            error_log('[zapupi_create] rejected response: ' . zapupi_safe_json($j));
            throw new RuntimeException('The payment provider could not create the order. Please try again later.', 502);
        }

        $payId = zapupi_s($j, 'pay_id');
        $env   = strtolower(zapupi_s($j, 'environment'));
        $pdo->prepare('UPDATE deposits SET gateway_txn_id = :t, pay_id = :p, environment = :e, gateway_status = "CREATED", gateway_response = :r, updated_at = NOW()
                       WHERE id = :id AND user_id = :u AND status = "pending"')
            ->execute([
                ':t' => $txnId, ':p' => $payId !== '' && strlen($payId) <= 64 ? $payId : null,
                ':e' => preg_match('/^[a-z]{1,20}$/D', $env) ? $env : null,
                ':r' => zapupi_safe_json($j), ':id' => $depositId, ':u' => $userId,
            ]);
        zapupi_record_api('create-order', true, $res['http'], 'Order created.');
        audit_log('deposit.created', 'info', ['entity_type' => 'deposit', 'entity_id' => $depositId, 'user_id' => $userId, 'actor_id' => $userId,
            'order_ref' => $orderRef, 'reason' => 'ZapUPI order created for ' . $amountStr]);

        return ['deposit' => [
            'id' => $depositId, 'order_ref' => $orderRef, 'amount' => (float)$amountStr, 'status' => 'pending',
            'gateway' => ZAPUPI_METHOD, 'payment_url' => $payUrl,
        ]];
    }
    throw new RuntimeException($lastErr, 502);
}

/* ================================================================== */
/*  Verification (the ONLY source of truth)                             */
/* ================================================================== */

/**
 * ZapUPI "order-status" answers with an ENVELOPE (per ZapUPI's published integration examples, zapupi.com/docs):
 *
 *   { "status": "success",                       <- the API CALL worked (NOT the payment state)
 *     "data": { "order_id": "...", "status": "Pending|Success|Failed",   <- the PAYMENT state
 *               "amount": "...", "pay_amount": "...", "txn_id": "...", "utr": "...", "environment": "cashier|zappay|test" } }
 *
 * v68 read these fields from the TOP level, found no order_id there and parked every real payment as
 * "order_id_missing" (credit_status = verification_failed) => the deposit stayed PENDING forever.
 *
 * Returns ['envelope' => string, 'data' => array|null, 'layout' => nested|flat|none].
 * "flat" (payment fields at the top level) is accepted only if the top level itself carries an order_id; in that
 * layout the top-level status IS the payment status. Anything else => data = null => nothing is verified.
 */
function zapupi_unwrap_order_status(array $j): array
{
    $envelope = strtolower(zapupi_s($j, 'status'));
    if (isset($j['data']) && is_array($j['data'])) {
        $d = $j['data'];
        if (isset($d[0]) && is_array($d[0])) $d = $d[0];                       // tolerate data: [ {...} ]
        return ['envelope' => $envelope, 'data' => $d, 'layout' => 'nested'];
    }
    if (zapupi_s($j, 'order_id') !== '') return ['envelope' => 'success', 'data' => $j, 'layout' => 'flat'];
    return ['envelope' => $envelope, 'data' => null, 'layout' => 'none'];
}

/** Minimum seconds between provider status calls for one deposit, per caller (webhook is the primary path; the rest are backups). */
function zapupi_status_gap_seconds(string $source): int
{
    switch ($source) {
        case 'webhook': return 1;                         // the provider itself is telling us: answer fast
        case 'admin':   return ZAPUPI_MIN_STATUS_GAP_SEC;
        case 'poll':    return 10;                        // browser polling (every 5 s) hits the provider at most every 10 s
        case 'page':    return 15;                        // result page load / refresh
        default:        return ZAPUPI_MIN_STATUS_GAP_SEC; // cron has its own (5 min) interval in the query
    }
}

/** Local order reference from a ZapUPI return URL. Accepts ?order_id=ZP.. (ZapUPI's documented parameter), ?order=ZP.. (v68 URLs) or either one mangled by a second "?". */
function zapupi_order_from_request(): string
{
    foreach (['order_id', 'order'] as $k) {
        if (isset($_GET[$k]) && is_string($_GET[$k]) && preg_match('/ZP[A-F0-9]{20}/', $_GET[$k], $m)) return $m[0];
    }
    $qs = rawurldecode((string)($_SERVER['QUERY_STRING'] ?? ''));
    return preg_match('/ZP[A-F0-9]{20}/', $qs, $m) ? $m[0] : '';
}

/**
 * verifyZapupiPayment($orderId) - centralised server-side verification.
 *
 * Steps: load local order -> must exist -> must be a ZapUPI deposit -> call POST /order-status -> unwrap the envelope ->
 * validate payment status, order id, amount, environment and identifiers -> return a verdict.
 * It NEVER writes to the database or wallet.
 *
 * A payment is accepted as paid ONLY if ALL of these hold (anything else stays pending / flagged, never credited):
 *   - the API call itself succeeded (envelope status "success", HTTP 2xx) and payment data is present
 *   - data.status equals "Success" (case-insensitive)
 *   - data.order_id equals our local id
 *   - txn_id present, not DUMMY*, and equal to the txn_id ZapUPI gave when the order was created (when we stored one)
 *   - utr present (bank reference number)
 *   - environment is cashier / zappay (test never credits in production)
 *   - data.amount equals the local amount; data.pay_amount (when present) is not LOWER than the local amount
 *
 * result: success | failed | pending | inconclusive | verification_failed | test_ignored | rejected
 */
function verifyZapupiPayment(string $orderId): array
{
    $pdo = db();
    $out = static function (string $result, string $reason, ?array $dep = null, ?array $v = null): array {
        return ['result' => $result, 'reason' => $reason, 'deposit' => $dep, 'verified' => $v];
    };

    if (!preg_match('/^[A-Za-z0-9_\-]{1,64}$/D', $orderId)) return $out('rejected', 'invalid_order_id');
    $st = $pdo->prepare('SELECT * FROM deposits WHERE order_ref = :o LIMIT 1');
    $st->execute([':o' => $orderId]);
    $dep = $st->fetch();
    if (!$dep) return $out('rejected', 'unknown_order');
    if (($dep['method'] ?? '') !== ZAPUPI_METHOD || ($dep['gateway'] ?? '') !== ZAPUPI_METHOD) return $out('rejected', 'wrong_gateway', $dep);

    $localPaise = zapupi_paise($dep['amount']);
    if ($localPaise === null || $localPaise <= 0) return $out('rejected', 'bad_local_amount', $dep);

    try {
        $res = zapupi_http_post('order-status', ['zap_key' => zapupi_api_key(), 'order_id' => $orderId]);
    } catch (RuntimeException $e) {
        zapupi_record_api('order-status', false, 0, $e->getMessage());
        return $out('inconclusive', 'provider_unreachable', $dep);       // timeout / network: NEVER assume success
    }
    $j = $res['json'];
    if ($res['http'] < 200 || $res['http'] >= 300) {
        zapupi_record_api('order-status', false, $res['http'], zapupi_s($j, 'message') ?: 'HTTP ' . $res['http']);
        return $out('inconclusive', 'provider_http_' . $res['http'], $dep);
    }

    $verdict = zapupi_judge_order_status($dep, $orderId, $localPaise, $j, zapupi_test_credit_allowed());
    if ($verdict['api_note'] !== '') zapupi_record_api('order-status', (bool)$verdict['api_ok'], $res['http'], (string)$verdict['api_note']);
    return $verdict;
}

/**
 * PURE decision function (no database, no HTTP, no clock): given the local deposit row and the decoded JSON that
 * POST /order-status returned, decide what that answer means. verifyZapupiPayment() is a thin wrapper around it, and
 * tools/zapupi_selftest.php runs it against fixtures. Returns the verifyZapupiPayment() shape plus 'api_ok' / 'api_note'.
 */
function zapupi_judge_order_status(array $dep, string $orderId, int $localPaise, array $j, bool $allowTest): array
{
    $apiOk = true; $apiNote = '';
    $out = static function (string $result, string $reason, ?array $depRow = null, ?array $v = null) use (&$apiOk, &$apiNote): array {
        return ['result' => $result, 'reason' => $reason, 'deposit' => $depRow, 'verified' => $v, 'api_ok' => $apiOk, 'api_note' => $apiNote];
    };

    $u = zapupi_unwrap_order_status($j);
    $d = $u['data'];
    $dep['_safe'] = zapupi_safe_json($d ?? $j);

    // The API call itself did not work (error envelope), or the reply has no payment data (e.g. order unknown to ZapUPI): not paid, not failed. Keep waiting.
    if ($u['envelope'] !== 'success') {
        $apiOk = false; $apiNote = 'Envelope status "' . ops_sanitize($u['envelope'], 30) . '": ' . (zapupi_s($j, 'message') ?: 'no message');
        return $out('pending', 'provider_envelope_' . ($u['envelope'] !== '' ? preg_replace('/[^a-z0-9_]/', '', $u['envelope']) : 'empty'), $dep);
    }
    if ($d === null) {
        $apiOk = false; $apiNote = 'Success envelope without payment data (unexpected layout).';
        return $out('pending', 'provider_no_payment_data', $dep);
    }

    $status   = strtolower(zapupi_s($d, 'status'));            // PAYMENT status: Pending | Success | Failed
    $retOrder = zapupi_s($d, 'order_id');
    $txn      = zapupi_s($d, 'txn_id');
    $env      = strtolower(zapupi_s($d, 'environment'));
    $utr      = zapupi_s($d, 'utr');
    $safe     = zapupi_safe_json($d);
    $apiNote  = 'Order ' . $orderId . ' -> payment status "' . ops_sanitize($status, 20) . '" (' . $u['layout'] . ' layout).';

    if ($retOrder !== '' && $retOrder !== $orderId) return $out('verification_failed', 'order_id_mismatch', $dep);

    if ($status === 'failed') {
        return $out('failed', 'provider_failed', $dep);
    }
    if ($status !== 'success') {
        // pending / created / anything undocumented: not paid, not failed. Keep waiting.
        return $out('pending', 'provider_status_' . ($status !== '' ? preg_replace('/[^a-z0-9_]/', '', $status) : 'empty'), $dep);
    }

    // ---- payment status says "Success": now every other fact must agree -------------------------
    if ($retOrder === '') return $out('verification_failed', 'order_id_missing', $dep);
    if ($txn === '' || !preg_match('/^[A-Za-z0-9_\-]{4,64}$/D', $txn)) return $out('verification_failed', 'txn_id_missing', $dep);
    if (zapupi_is_test_payment($env, $txn) && !$allowTest) return $out('test_ignored', 'test_payment', $dep);
    if (!in_array($env, ['cashier', 'zappay'], true) && !($allowTest && $env === 'test')) return $out('verification_failed', 'environment_invalid', $dep);
    $storedTxn = (string)($dep['gateway_txn_id'] ?? '');
    if ($storedTxn !== '' && $storedTxn !== $txn) return $out('verification_failed', 'txn_id_mismatch', $dep);
    if ($utr === '' || !preg_match('/^[A-Za-z0-9_\-]{4,64}$/D', $utr)) return $out('verification_failed', 'utr_missing', $dep);

    $amt = zapupi_paise(isset($d['amount']) ? $d['amount'] : null);
    if ($amt === null || $amt !== $localPaise) return $out('verification_failed', 'amount_mismatch', $dep);
    $payAmt = null;
    if (array_key_exists('pay_amount', $d) && $d['pay_amount'] !== '' && $d['pay_amount'] !== null) {
        $payAmt = zapupi_paise($d['pay_amount']);
        if ($payAmt === null || $payAmt < $localPaise) return $out('verification_failed', 'pay_amount_mismatch', $dep);
    }

    return $out('success', 'verified', $dep, [
        'order_id' => $orderId, 'txn_id' => $txn, 'utr' => $utr, 'environment' => $env,
        'amount_paise' => $amt, 'pay_paise' => $payAmt, 'safe_json' => $safe,
    ]);
}

/* ================================================================== */
/*  Atomic, idempotent wallet credit (shared by webhook, polling, cron, admin) */
/* ================================================================== */

/**
 * Credit the wallet for ONE verified ZapUPI payment, at most once, ever.
 * Everything below happens in one DB transaction:
 *   lock deposit row -> re-check gateway/state -> lock user row -> balance = balance + amount (SQL decimal)
 *   -> wallet_ledger row -> payment_credits row (UNIQUE deposit_id / UNIQUE gateway+txn) -> deposit marked approved.
 * Returns ['credited' => bool, 'already' => bool, 'balance' => ?string, 'reason' => string].
 */
function zapupi_credit_verified(PDO $pdo, int $depositId, array $v, string $source): array
{
    $amountStr = zapupi_fmt_paise((int)$v['amount_paise']);
    $res = ['credited' => false, 'already' => false, 'balance' => null, 'reason' => ''];
    try {
        $pdo->beginTransaction();

        $lk = $pdo->prepare('SELECT id, user_id, order_ref, amount, status, method, gateway, credit_status FROM deposits WHERE id = :id FOR UPDATE');
        $lk->execute([':id' => $depositId]);
        $d = $lk->fetch();
        if (!$d) { $pdo->rollBack(); $res['reason'] = 'not_found'; return $res; }
        if (($d['method'] ?? '') !== ZAPUPI_METHOD || ($d['gateway'] ?? '') !== ZAPUPI_METHOD) { $pdo->rollBack(); $res['reason'] = 'wrong_gateway'; return $res; }
        if ($d['status'] === 'approved' || $d['credit_status'] === 'success') { $pdo->rollBack(); $res['already'] = true; $res['reason'] = 'already_credited'; return $res; }

        $ex = $pdo->prepare('SELECT id FROM payment_credits WHERE deposit_id = :d LIMIT 1');
        $ex->execute([':d' => $depositId]);
        if ($ex->fetchColumn()) { $pdo->rollBack(); $res['already'] = true; $res['reason'] = 'credit_record_exists'; return $res; }

        // A payment the provider reported FAILED (or a closed order) is never silently re-opened: admin must look.
        if ($d['status'] !== 'pending') { $pdo->rollBack(); $res['reason'] = 'order_not_open'; return $res; }

        if (zapupi_paise($d['amount']) !== (int)$v['amount_paise']) { $pdo->rollBack(); $res['reason'] = 'amount_mismatch'; return $res; }

        $ul = $pdo->prepare('SELECT balance FROM users WHERE id = :id FOR UPDATE');
        $ul->execute([':id' => (int)$d['user_id']]);
        if ($ul->fetchColumn() === false) { $pdo->rollBack(); $res['reason'] = 'user_missing'; return $res; }

        // Decimal-safe: the addition is done by MySQL on DECIMAL, with the amount bound as a "123.45" string.
        $pdo->prepare('UPDATE users SET balance = balance + :a WHERE id = :id')->execute([':a' => $amountStr, ':id' => (int)$d['user_id']]);
        $nb = $pdo->prepare('SELECT balance FROM users WHERE id = :id');
        $nb->execute([':id' => (int)$d['user_id']]);
        $newBalance = (string)$nb->fetchColumn();

        $pdo->prepare('INSERT INTO wallet_ledger (user_id, amount, balance_after, type, reference_id, description, created_at)
                       VALUES (:u, :a, :b, "deposit", :r, :d, NOW())')
            ->execute([':u' => (int)$d['user_id'], ':a' => $amountStr, ':b' => $newBalance, ':r' => 'DEP#' . $depositId, ':d' => 'ZapUPI deposit ' . $d['order_ref']]);

        // The DATABASE refuses a second credit for this deposit or this provider transaction (SQLSTATE 23000).
        $pdo->prepare('INSERT INTO payment_credits (deposit_id, gateway, user_id, local_order_id, provider_txn_id, utr, amount, source, created_at)
                       VALUES (:d, :g, :u, :o, :t, :utr, :a, :s, NOW())')
            ->execute([':d' => $depositId, ':g' => ZAPUPI_METHOD, ':u' => (int)$d['user_id'], ':o' => $d['order_ref'], ':t' => $v['txn_id'],
                       ':utr' => $v['utr'], ':a' => $amountStr, ':s' => mb_substr($source, 0, 20)]);

        $upd = $pdo->prepare('UPDATE deposits SET status = "approved", credit_status = "success", gateway_status = "SUCCESS", gateway_txn_id = :t, utr = :utr,
                                     environment = :env, pay_amount = :pa, paid_at = NOW(), gateway_response = :r, reviewed_at = NOW(), updated_at = NOW()
                              WHERE id = :id AND status = "pending" AND (credit_status IS NULL OR credit_status <> "success")');
        $upd->execute([
            ':t' => $v['txn_id'], ':utr' => $v['utr'], ':env' => $v['environment'],
            ':pa' => $v['pay_paise'] !== null ? zapupi_fmt_paise((int)$v['pay_paise']) : null,
            ':r' => $v['safe_json'], ':id' => $depositId,
        ]);
        if ($upd->rowCount() !== 1) throw new RuntimeException('Deposit state changed during credit.');

        $pdo->commit();
        $res['credited'] = true; $res['balance'] = $newBalance; $res['reason'] = 'credited';
        audit_log('deposit.credited', 'success', ['entity_type' => 'deposit', 'entity_id' => $depositId, 'user_id' => (int)$d['user_id'], 'actor_id' => (int)$d['user_id'],
            'order_ref' => (string)$d['order_ref'], 'reason' => 'ZapUPI verified success via ' . $source . ', credited ' . $amountStr . ' (ref DEP#' . $depositId . ')']);
        alert_resolve_key('zapupi_credit_failed:' . $depositId, 'Credited');
        return $res;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ((string)$e->getCode() === '23000') {      // UNIQUE key hit: a concurrent request already credited this payment
            $res['already'] = true; $res['reason'] = 'duplicate_blocked_by_database';
            audit_log('deposit.duplicate_blocked', 'info', ['entity_type' => 'deposit', 'entity_id' => $depositId, 'reason' => 'ZapUPI duplicate credit blocked by unique key (' . $source . ')']);
            return $res;
        }
        error_log('[zapupi_credit] ' . $e->getMessage());
        zapupi_credit_failed_alert($depositId, $e->getMessage());
        $res['reason'] = 'db_error';
        return $res;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[zapupi_credit] ' . $e->getMessage());
        zapupi_credit_failed_alert($depositId, $e->getMessage());
        $res['reason'] = 'error';
        return $res;
    }
}

function zapupi_credit_failed_alert(int $depositId, string $why): void
{
    audit_log('deposit.credited', 'failure', ['entity_type' => 'deposit', 'entity_id' => $depositId, 'reason' => 'ZapUPI confirmed payment but the credit transaction failed: ' . $why]);
    alert_raise(['dedupe_key' => 'zapupi_credit_failed:' . $depositId, 'type' => 'wallet_inconsistent', 'severity' => 'critical',
        'reason' => 'ZapUPI confirmed deposit #' . $depositId . ' but the wallet credit transaction failed (rolled back, nothing credited).',
        'recommended_action' => 'Admin > Wallet & Deposits > deposit #' . $depositId . ' > "Verify with ZapUPI" (credits at most once).']);
}

/* ================================================================== */
/*  Settlement: verify, then credit / fail / keep pending               */
/* ================================================================== */

/** Customer-facing snapshot of a deposit row. Never contains provider secrets. */
function zapupi_public_state(PDO $pdo, int $depositId): array
{
    $s = $pdo->prepare('SELECT d.id, d.order_ref, d.user_id, d.amount, d.status, d.credit_status, d.gateway_txn_id, d.utr, d.paid_at, d.created_at, u.balance
                        FROM deposits d JOIN users u ON u.id = d.user_id WHERE d.id = :id LIMIT 1');
    $s->execute([':id' => $depositId]);
    $r = $s->fetch();
    if (!$r) return ['state' => 'FAILURE', 'message' => 'Deposit not found.'];
    if ($r['status'] === 'approved') $state = 'SUCCESS';
    elseif ($r['status'] === 'pending') $state = 'PENDING';
    elseif ($r['status'] === 'expired') $state = 'EXPIRED';
    else $state = 'FAILURE';
    return ['state' => $state, 'row' => $r, 'balance' => (string)$r['balance']];
}

/**
 * Verify one ZapUPI deposit with the provider and settle it. Shared by: webhook, status polling, result pages,
 * cron reconciliation and the admin "verify" button - so they all use the SAME verification and the SAME credit function.
 *
 * $source: webhook | poll | page | cron | admin.   Interactive/cron calls are throttled per deposit; webhooks are not.
 * Returns ['state' => SUCCESS|PENDING|FAILURE|EXPIRED, 'reason' => string, 'verdict' => string].
 */
function zapupi_settle(PDO $pdo, int $depositId, string $source): array
{
    $q = $pdo->prepare('SELECT id, order_ref, status, credit_status, method, gateway FROM deposits WHERE id = :id LIMIT 1');
    $q->execute([':id' => $depositId]);
    $d = $q->fetch();
    if (!$d || ($d['method'] ?? '') !== ZAPUPI_METHOD) return ['state' => 'FAILURE', 'reason' => 'not_zapupi', 'verdict' => 'rejected'];

    // Final states: no provider call, no write.
    if ($d['status'] !== 'pending') {
        $p = zapupi_public_state($pdo, $depositId);
        return ['state' => $p['state'], 'reason' => 'already_final', 'verdict' => 'final'];
    }

    // Throttle provider calls per deposit (atomic: only one caller wins the slot). Webhooks get a 1 s gap so a forged
    // webhook flood for a known order id cannot hammer the provider; a throttled webhook is answered 503 so it is retried.
    {
        $slot = $pdo->prepare('UPDATE deposits SET last_checked_at = NOW(), verify_attempts = verify_attempts + :inc
                               WHERE id = :id AND status = "pending" AND (last_checked_at IS NULL OR last_checked_at < (NOW() - INTERVAL ' . zapupi_status_gap_seconds($source) . ' SECOND))'
                               . ($source === 'poll' ? ' AND created_at > (NOW() - INTERVAL 30 MINUTE)' : ''));   // browser polling only for fresh orders; older ones belong to webhook + cron
        $slot->execute([':inc' => $source === 'cron' ? 1 : 0, ':id' => $depositId]);
        if ($slot->rowCount() !== 1) return ['state' => 'PENDING', 'reason' => 'throttled', 'verdict' => 'pending'];
    }

    $v = verifyZapupiPayment((string)$d['order_ref']);

    switch ($v['result']) {
        case 'success':
            $c = zapupi_credit_verified($pdo, $depositId, $v['verified'], $source);
            if ($c['credited'] || $c['already']) return ['state' => 'SUCCESS', 'reason' => $c['reason'], 'verdict' => 'success', 'balance' => $c['balance']];
            // Verified paid but the credit could not complete (e.g. amount re-check / db error): stay pending, alert already raised.
            return ['state' => 'PENDING', 'reason' => $c['reason'], 'verdict' => 'credit_incomplete'];

        case 'failed':
            $pdo->prepare('UPDATE deposits SET status = "rejected", credit_status = "failed", gateway_status = "FAILED", gateway_response = :r, reviewed_at = NOW(), updated_at = NOW()
                           WHERE id = :id AND method = :m AND status = "pending"')
                ->execute([':r' => (string)($v['deposit']['_safe'] ?? ''), ':id' => $depositId, ':m' => ZAPUPI_METHOD]);
            audit_log('deposit.failed', 'info', ['entity_type' => 'deposit', 'entity_id' => $depositId, 'order_ref' => (string)$d['order_ref'], 'reason' => 'ZapUPI reported the payment as failed (' . $source . ')']);
            return ['state' => 'FAILURE', 'reason' => 'provider_failed', 'verdict' => 'failed'];

        case 'test_ignored':
            $pdo->prepare('UPDATE deposits SET environment = "test", gateway_response = :r, updated_at = NOW() WHERE id = :id AND status = "pending"')
                ->execute([':r' => (string)($v['deposit']['_safe'] ?? ''), ':id' => $depositId]);
            audit_log('deposit.test_payment_ignored', 'denied', ['entity_type' => 'deposit', 'entity_id' => $depositId, 'order_ref' => (string)$d['order_ref'], 'reason' => 'ZapUPI TEST payment refused for wallet credit']);
            return ['state' => 'PENDING', 'reason' => 'test_payment', 'verdict' => 'test_ignored'];

        case 'verification_failed':
            $pdo->prepare('UPDATE deposits SET credit_status = "verification_failed", gateway_status = :s, gateway_response = :r, updated_at = NOW()
                           WHERE id = :id AND status = "pending"')
                ->execute([':s' => mb_substr('VERIFY_' . strtoupper($v['reason']), 0, 32), ':r' => (string)($v['deposit']['_safe'] ?? ''), ':id' => $depositId]);
            audit_log('deposit.verification_failed', 'denied', ['entity_type' => 'deposit', 'entity_id' => $depositId, 'order_ref' => (string)$d['order_ref'], 'reason' => 'ZapUPI verification failed: ' . $v['reason'] . ' (' . $source . '); nothing credited']);
            alert_raise(['dedupe_key' => 'zapupi_verify:' . $depositId, 'type' => 'zapupi_payment', 'severity' => 'warning', 'order_ref' => (string)$d['order_ref'],
                'reason' => 'ZapUPI order ' . $d['order_ref'] . ' could not be verified (' . $v['reason'] . '). Nothing was credited.',
                'recommended_action' => 'Open the deposit detail in Wallet & Deposits and compare with the ZapUPI dashboard.']);
            return ['state' => 'PENDING', 'reason' => $v['reason'], 'verdict' => 'verification_failed'];

        default:     // pending | inconclusive | rejected: nothing changes, nothing is credited
            return ['state' => 'PENDING', 'reason' => $v['reason'], 'verdict' => $v['result']];
    }
}

/* ================================================================== */
/*  Webhook                                                             */
/* ================================================================== */

function zapupi_webhook_log(PDO $pdo, string $outcome, array $p, string $detail = ''): void
{
    try {
        $pdo->prepare('INSERT INTO zapupi_webhook_events (received_at, ip, order_ref, txn_id, payload_status, environment, amount, outcome, detail)
                       VALUES (NOW(), :ip, :o, :t, :s, :e, :a, :out, :d)')
            ->execute([
                ':ip' => mb_substr(client_ip(), 0, 45),
                ':o' => mb_substr(preg_replace('/[^A-Za-z0-9_\-]/', '', zapupi_s($p, 'order_id')), 0, 64) ?: null,
                ':t' => mb_substr(preg_replace('/[^A-Za-z0-9_\-]/', '', zapupi_s($p, 'txn_id')), 0, 64) ?: null,
                ':s' => mb_substr(preg_replace('/[^A-Za-z]/', '', zapupi_s($p, 'status')), 0, 20) ?: null,
                ':e' => mb_substr(preg_replace('/[^A-Za-z]/', '', zapupi_s($p, 'environment')), 0, 20) ?: null,
                ':a' => mb_substr(preg_replace('/[^0-9.]/', '', zapupi_s($p, 'amount')), 0, 20) ?: null,
                ':out' => mb_substr($outcome, 0, 40),
                ':d' => $detail !== '' ? mb_substr(ops_sanitize($detail, 250), 0, 250) : null,
            ]);
    } catch (Throwable $e) {
        error_log('[zapupi_webhook_log] ' . $e->getMessage());
    }
}

/**
 * Handle one webhook. Returns [httpStatus, bodyArray]. The webhook only TRIGGERS verification.
 * The payload is never used as a source of truth: amount/user/status all come from our DB and ZapUPI's Order Status API.
 */
function zapupi_handle_webhook(PDO $pdo, string $rawBody): array
{
    $ok = [200, ['status' => 'ok']];

    // 1) Rate limit per IP (abuse protection for a public endpoint).
    try {
        $rl = $pdo->prepare('SELECT COUNT(*) FROM zapupi_webhook_events WHERE ip = :ip AND received_at > (NOW() - INTERVAL 60 SECOND)');
        $rl->execute([':ip' => mb_substr(client_ip(), 0, 45)]);
        if ((int)$rl->fetchColumn() >= ZAPUPI_WEBHOOK_PER_MINUTE) return [429, ['status' => 'rate_limited']];
    } catch (Throwable $e) { error_log('[zapupi_webhook:rl] ' . $e->getMessage()); }

    // 2) Strict JSON.
    if ($rawBody === '' || strlen($rawBody) > 16384) { zapupi_webhook_log($pdo, 'bad_body', [], 'empty or oversized'); return [400, ['status' => 'error']]; }
    $p = json_decode($rawBody, true);
    if (!is_array($p) || json_last_error() !== JSON_ERROR_NONE) { zapupi_webhook_log($pdo, 'bad_json', []); return [400, ['status' => 'error']]; }

    // 3) Required fields + format.
    $orderId = zapupi_s($p, 'order_id');
    $txnId = zapupi_s($p, 'txn_id');
    $status = strtolower(zapupi_s($p, 'status'));
    $env = strtolower(zapupi_s($p, 'environment'));
    foreach (['order_id', 'txn_id', 'status', 'amount'] as $req) {
        if (zapupi_s($p, $req) === '') { zapupi_webhook_log($pdo, 'missing_field', $p, $req); return [400, ['status' => 'error']]; }
    }
    if (!preg_match('/^[A-Za-z0-9_\-]{1,64}$/D', $orderId) || !preg_match('/^[A-Za-z0-9_\-]{1,64}$/D', $txnId)) {
        zapupi_webhook_log($pdo, 'bad_format', $p); return [400, ['status' => 'error']];
    }
    if (!in_array($status, ['success', 'failed'], true)) { zapupi_webhook_log($pdo, 'unknown_status', $p); return $ok; }

    // 4) Test traffic is recorded and ignored for wallets (never trust only this field; the Order Status API is checked too).
    if (zapupi_is_test_payment($env, $txnId) && !zapupi_test_credit_allowed()) {
        zapupi_webhook_log($pdo, 'test_ignored', $p, 'test environment / DUMMY txn');
        return $ok;
    }

    // 5) Local order: must exist, belong to ZapUPI.
    $st = $pdo->prepare('SELECT id, user_id, amount, status, method, gateway, gateway_txn_id FROM deposits WHERE order_ref = :o LIMIT 1');
    $st->execute([':o' => $orderId]);
    $dep = $st->fetch();
    if (!$dep) { zapupi_webhook_log($pdo, 'unknown_order', $p); return $ok; }          // 200: do not reveal which ids exist
    if (($dep['method'] ?? '') !== ZAPUPI_METHOD || ($dep['gateway'] ?? '') !== ZAPUPI_METHOD) { zapupi_webhook_log($pdo, 'wrong_gateway', $p); return $ok; }
    if ($dep['status'] === 'approved') { zapupi_webhook_log($pdo, 'duplicate_ignored', $p, 'already credited'); return $ok; }

    // 6) Cheap pre-check against OUR amount (the verified check repeats this with the provider's data).
    $pa = zapupi_paise(isset($p['amount']) ? $p['amount'] : null);
    if ($pa === null || $pa !== zapupi_paise($dep['amount'])) {
        zapupi_webhook_log($pdo, 'amount_mismatch', $p, 'payload amount differs from local order');
        audit_log('deposit.webhook_amount_mismatch', 'denied', ['entity_type' => 'deposit', 'entity_id' => (int)$dep['id'], 'reason' => 'ZapUPI webhook amount differs from local order; ignored']);
        return $ok;
    }
    $storedTxn = (string)($dep['gateway_txn_id'] ?? '');
    if ($storedTxn !== '' && $storedTxn !== $txnId) { zapupi_webhook_log($pdo, 'txn_mismatch', $p); return $ok; }

    // 7) Verify with the provider and settle (same atomic path as every other caller).
    $r = zapupi_settle($pdo, (int)$dep['id'], 'webhook');
    zapupi_webhook_log($pdo, 'processed_' . $r['verdict'], $p, $r['reason']);

    // Transient problems get a non-200 so ZapUPI may retry; the cron reconciliation is the safety net either way.
    if (in_array($r['reason'], ['provider_unreachable', 'db_error', 'error', 'throttled'], true) || strpos($r['reason'], 'provider_http_') === 0) {
        return [503, ['status' => 'retry']];
    }
    return $ok;
}

/* ================================================================== */
/*  Reconciliation (cron + admin)                                       */
/* ================================================================== */

/** Check eligible pending ZapUPI deposits. Returns counters. Bounded by attempts, age, interval and a per-run limit. */
function zapupi_reconcile_pending(PDO $pdo, int $limit = 25): array
{
    $s = ['checked' => 0, 'success' => 0, 'failed' => 0, 'pending' => 0];
    if (zapupi_api_key() === '') return $s;
    $q = $pdo->prepare('SELECT id FROM deposits
                        WHERE method = :m AND gateway = :g AND status = "pending"
                          AND created_at > (NOW() - INTERVAL ' . (int)ZAPUPI_CRON_MAX_AGE_HOURS . ' HOUR)
                          AND created_at < (NOW() - INTERVAL 60 SECOND)
                          AND verify_attempts < ' . (int)ZAPUPI_CRON_MAX_ATTEMPTS . '
                          AND (last_checked_at IS NULL OR last_checked_at < (NOW() - INTERVAL ' . (int)ZAPUPI_CRON_INTERVAL_MIN . ' MINUTE))
                        ORDER BY id ASC LIMIT ' . max(1, min(100, $limit)));
    $q->execute([':m' => ZAPUPI_METHOD, ':g' => ZAPUPI_METHOD]);
    foreach ($q->fetchAll() as $row) {
        $r = zapupi_settle($pdo, (int)$row['id'], 'cron');
        $s['checked']++;
        if ($r['state'] === 'SUCCESS') $s['success']++;
        elseif ($r['state'] === 'FAILURE') $s['failed']++;
        else $s['pending']++;
    }
    // Housekeeping: webhook diagnostics older than 30 days.
    try { $pdo->exec('DELETE FROM zapupi_webhook_events WHERE received_at < (NOW() - INTERVAL 30 DAY)'); } catch (Throwable $e) { error_log('[zapupi_reconcile:gc] ' . $e->getMessage()); }
    return $s;
}
