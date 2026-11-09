<?php
declare(strict_types=1);

/**
 * Paytm Business (UPI QR) deposit gateway.
 *
 * Ported 1:1 from the supplied index.php payment implementation:
 *   - paytm_config()              <- index.php paytm_config()
 *   - paytm_post_json()           <- index.php paytm_post_json()
 *   - paytm_create_dynamic_qr()   <- index.php paytm_create_dynamic_qr()  (UPI URI, same fields)
 *   - paytm_get_qr_status()       <- index.php paytm_get_qr_status()      (getTxnStatus, MID + ORDERID)
 *   - deposit_min()/deposit_max() <- limits logic in index.php deposit_create_paytm
 *
 * The source has NO webhook/callback. A payment is confirmed only when THIS
 * SERVER asks Paytm (securegw.paytm.in getTxnStatus) and Paytm answers
 * STATUS = TXN_SUCCESS. Nothing the browser sends is ever trusted as proof
 * of payment.
 */

/** Paytm merchant-status endpoint used by the source. */
const PAYTM_STATUS_URL = 'https://securegw.paytm.in/merchant-status/getTxnStatus';

/** Source: 180-second pending-payment lifetime (server side + QR countdown). */
const PAYTM_PAYMENT_WINDOW = 180;

/* ------------------------------------------------------------------ */
/*  Amount limits (source defaults: min 10, max 100000, admin-editable) */
/* ------------------------------------------------------------------ */

function deposit_min(): float
{
    $min = round((float)get_setting('deposit_min_amount', '10'), 2);
    if ($min <= 0) $min = 10.0;
    return $min;
}

function deposit_max(): float
{
    $min = deposit_min();
    $max = round((float)get_setting('deposit_max_amount', '100000'), 2);
    if ($max < $min) $max = max($min, 100000.0);
    return $max;
}

/* ------------------------------------------------------------------ */
/*  Gateway configuration                                              */
/* ------------------------------------------------------------------ */

function paytm_config(): array
{
    // Same two merchant values as the source: Paytm MID + Paytm UPI ID.
    // The UPI display name comes from the panel name.
    $name = trim((string)get_setting('paytm_display_name', ''));
    if ($name === '') $name = strtoupper(panel_name());

    return [
        'enabled'  => get_setting('paytm_enabled', 'off') === 'on',
        'mid'      => trim((string)get_setting('paytm_mid', '')),
        'upi_id'   => trim((string)get_setting('paytm_upi_id', '')),
        'upi_name' => $name,
    ];
}

/* ------------------------------------------------------------------ */
/*  HTTP                                                               */
/* ------------------------------------------------------------------ */

function paytm_post_json(string $url, array $payload): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL extension is required for Paytm payments.');
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $err !== '') {
        throw new RuntimeException('Paytm connection failed: ' . ($err ?: 'network error'));
    }
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) {
        $preview = trim((string)preg_replace('/\s+/', ' ', substr((string)$raw, 0, 180)));
        throw new RuntimeException('Paytm returned an invalid response (HTTP ' . $code . ').' . ($preview !== '' ? ' Response: ' . $preview : ''));
    }
    if ($code < 200 || $code >= 300) {
        $msg = '';
        foreach (['RESPMSG', 'respMsg', 'message', 'MESSAGE', 'errorMessage'] as $k) {
            if (isset($data[$k]) && is_scalar($data[$k]) && trim((string)$data[$k]) !== '') {
                $msg = trim((string)$data[$k]);
                break;
            }
        }
        throw new RuntimeException('Paytm returned HTTP ' . $code . ($msg !== '' ? ': ' . $msg : ''));
    }
    return $data;
}

/* ------------------------------------------------------------------ */
/*  Create payment (UPI QR) / check payment                            */
/* ------------------------------------------------------------------ */

function paytm_create_dynamic_qr(string $orderId, float $amount): array
{
    $c = paytm_config();
    if (!$c['enabled']) throw new RuntimeException('Paytm payment is not enabled by admin.');
    if ($c['mid'] === '' || $c['upi_id'] === '') {
        throw new RuntimeException('Configure Paytm MID and Paytm UPI ID first.');
    }

    // Same UPI payload fields as the source: pa, pn, am, cu, tn, tr (tr = order id).
    $upi = 'upi://pay?pa=' . rawurlencode($c['upi_id'])
         . '&pn=' . rawurlencode($c['upi_name'])
         . '&am=' . rawurlencode(number_format($amount, 2, '.', ''))
         . '&cu=INR&tn=' . rawurlencode($c['upi_name'] . ' ' . $orderId)
         . '&tr=' . rawurlencode($orderId);

    // The QR image is only a visual rendering of the UPI URI (as in the source);
    // no merchant secret is ever part of it.
    $qrImage = 'https://api.qrserver.com/v1/create-qr-code/?size=420x420&data=' . rawurlencode($upi);

    return ['qr_data' => $upi, 'image' => $qrImage, 'mode' => 'upi_auto'];
}

function paytm_get_qr_status(string $orderId): array
{
    $c = paytm_config();
    if (!$c['enabled'] || $c['mid'] === '' || $c['upi_id'] === '') {
        throw new RuntimeException('Paytm MID and UPI ID are not configured.');
    }
    // Same endpoint + payload as the source.
    $res = paytm_post_json(PAYTM_STATUS_URL, ['MID' => $c['mid'], 'ORDERID' => $orderId]);
    return ['body' => $res];
}

/**
 * Normalise Paytm's status response exactly as the source does: fields may sit
 * at the top level or inside body/data/result/response wrappers.
 * Returns [status, txnAmount, txnId, message].
 */
function paytm_parse_status(array $res): array
{
    $body = $res['body'] ?? [];
    if (!is_array($body)) $body = [];

    $candidates = [$body];
    foreach (['body', 'data', 'result', 'response'] as $k) {
        if (isset($body[$k]) && is_array($body[$k])) $candidates[] = $body[$k];
    }
    $pick = function (array $names, $default = null) use ($candidates) {
        foreach ($candidates as $cb) {
            foreach ($names as $name) {
                if (array_key_exists($name, $cb) && $cb[$name] !== null && $cb[$name] !== '') return $cb[$name];
            }
        }
        return $default;
    };

    return [
        strtoupper(trim((string)$pick(['STATUS', 'status', 'resultStatus'], 'PENDING'))),
        (float)$pick(['TXNAMOUNT', 'txnAmount', 'TXN_AMOUNT'], 0),
        trim((string)$pick(['TXNID', 'txnId', 'TXN_ID'], '')),
        (string)$pick(['RESPMSG', 'respMsg', 'resultMsg', 'message'], 'Payment status pending.'),
    ];
}
