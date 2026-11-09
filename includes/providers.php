<?php
declare(strict_types=1);

/**
 * Multi-provider registry.
 *
 *   primary  = the EXISTING provider (DRIPSTORE API, settings api_*)          -> includes/reseller_api.php (unchanged)
 *   keypanel = the SECOND provider (KEYPANEL reseller gateway, settings api2_*) -> keypanel_buy() below
 *
 * The provider of a duration/plan is stored in product_plans.provider_key.
 * Rows created before this feature have the default 'primary', so every existing
 * product keeps using the existing API with no data migration.
 *
 * KEYPANEL contract (ONLY what the supplied documentation states):
 *   POST <gateway url>   Content-Type: application/x-www-form-urlencoded   Header: x-master-key: <key>
 *   fields: action=buy, variant_id, quantity (optional, max 10)
 *   success: {ok:true, key, keys[], order_id, expires_at}
 *   failure: {ok:false, error, shortfall?}
 * No services / balance / status action is documented, so none is called.
 *
 *   panelstore = a further provider (PANELSTORE gateway, settings api3_*)      -> panelstore_order() / panelstore_balance()
 *
 * PANELSTORE contract (ONLY what the supplied documentation states):
 *   POST <url>   Content-Type: application/json   body: {"key": <api key>, "action": <action>, ...params}
 *   action=balance                      -> {"balance": <number>}                       (shown in INR in the docs)
 *   action=order, pid, quantity         -> success {"status":"success","license_key":"..."}
 *                                          failure {"status":<not success>,"message":"..."}
 * Not documented (therefore NOT implemented): product list, order/status lookup, provider order id, cancel/refund,
 * and the shape of license_key when quantity > 1 (so quantity is capped at 1 for this provider).
 */

const PROVIDER_PRIMARY  = 'primary';
const PROVIDER_KEYPANEL = 'keypanel';
const PROVIDER_PANELSTORE = 'panelstore';
const PROVIDER_BANTIBHAIYA = 'bantibhaiya';   // Provider 4 (settings api4_*)
const PROVIDER_LICENCEDASH = 'licencedashboard';   // Provider 5 (settings api5_*) - fits product_plans/orders.provider_key VARCHAR(20)
const PROVIDER_VIPSELLER = 'vipsellerhub';   // Provider 6 (settings api6_*) - VIP SELLER HUB reseller API, 12 chars (fits VARCHAR(20))

function provider_keys(): array
{
    return [PROVIDER_PRIMARY, PROVIDER_KEYPANEL, PROVIDER_PANELSTORE, PROVIDER_BANTIBHAIYA, PROVIDER_LICENCEDASH, PROVIDER_VIPSELLER];
}

/** Unknown/NULL/legacy values always mean the existing provider. */
function provider_normalize($key): string
{
    return in_array($key, [PROVIDER_KEYPANEL, PROVIDER_PANELSTORE, PROVIDER_BANTIBHAIYA, PROVIDER_LICENCEDASH, PROVIDER_VIPSELLER], true) ? $key : PROVIDER_PRIMARY;
}

function provider_info(string $key): array
{
    if ($key === PROVIDER_KEYPANEL) {
        return [
            'key'          => PROVIDER_KEYPANEL,
            'label'        => trim((string)get_setting('api2_service_name', 'KEYPANEL API')) ?: 'KEYPANEL API',
            'enabled'      => get_setting('api2_provider_enabled', '0') === '1',
            'configured'   => trim((string)get_setting('api2_master_token', '')) !== ''
                              && trim((string)get_setting('api2_endpoint', '')) !== '',
            'max_quantity' => 10,   // documented: "max 10"
        ];
    }
    if ($key === PROVIDER_PANELSTORE) {
        return [
            'key'          => PROVIDER_PANELSTORE,
            'label'        => trim((string)get_setting('api3_service_name', 'PANELSTORE API')) ?: 'PANELSTORE API',
            'enabled'      => get_setting('api3_provider_enabled', '0') === '1',
            'configured'   => trim((string)get_setting('api3_master_token', '')) !== ''
                              && trim((string)get_setting('api3_endpoint', '')) !== '',
            'max_quantity' => 1,    // docs only show quantity=1; the license_key shape for >1 is undocumented
        ];
    }
    if ($key === PROVIDER_BANTIBHAIYA) {
        return [
            'key'          => PROVIDER_BANTIBHAIYA,
            'label'        => trim((string)get_setting('api4_service_name', 'BANTIBHAIYA API')) ?: 'BANTIBHAIYA API',
            'enabled'      => get_setting('api4_provider_enabled', '0') === '1',
            'configured'   => trim((string)get_setting('api4_api_key', '')) !== ''
                              && trim((string)get_setting('api4_master_token', '')) !== ''
                              && trim((string)get_setting('api4_endpoint', '')) !== '',
            'max_quantity' => 100,  // documented: "Bulk quantity: 1 to 100" (the site's own per-order limit still applies)
        ];
    }
    if ($key === PROVIDER_LICENCEDASH) {
        return [
            'key'          => PROVIDER_LICENCEDASH,
            'label'        => trim((string)get_setting('api5_service_name', 'LICENCE DASHBOARD API')) ?: 'LICENCE DASHBOARD API',
            'enabled'      => get_setting('api5_provider_enabled', '0') === '1',
            'configured'   => trim((string)get_setting('api5_master_token', '')) !== ''
                              && trim((string)get_setting('api5_endpoint', '')) !== '',
            'max_quantity' => 20,   // the website's own per-order limit. The provider accepts more, but the site limit is NOT raised.
        ];
    }
    if ($key === PROVIDER_VIPSELLER) {
        return [
            'key'          => PROVIDER_VIPSELLER,
            'label'        => trim((string)get_setting('api6_service_name', 'VIP SELLER HUB API')) ?: 'VIP SELLER HUB API',
            'enabled'      => get_setting('api6_provider_enabled', '0') === '1',
            'configured'   => trim((string)get_setting('api6_master_token', '')) !== ''
                              && trim((string)get_setting('api6_endpoint', '')) !== '',
            'max_quantity' => 20,   // the website's own per-order limit. The provider accepts up to 100 per call, but the site limit is NOT raised.
        ];
    }
    return [
        'key'          => PROVIDER_PRIMARY,
        'label'        => trim((string)get_setting('api_service_name', 'DRIPSTORE API')) ?: 'DRIPSTORE API',
        'enabled'      => get_setting('api_provider_enabled', '1') === '1',
        'configured'   => trim((string)get_setting('api_master_token', '')) !== '',
        'max_quantity' => 20,   // existing site limit
    ];
}

/** Safe (no secrets) list for the admin UI. */
function provider_list_public(): array
{
    return array_map(static function (string $k): array {
        $i = provider_info($k);
        return ['key' => $i['key'], 'label' => $i['label'], 'enabled' => $i['enabled'], 'configured' => $i['configured']];
    }, provider_keys());
}

/**
 * Call KEYPANEL "buy". Returns the decoded provider JSON, normalised so that
 * $r['ok'] is a real boolean. Provider-side failures (ok:false, invalid key,
 * insufficient provider balance, invalid variant ...) are RETURNED with the
 * provider's own `error` / `shortfall`; transport problems (timeout, DNS, HTML,
 * non-JSON) throw RuntimeException. A success is never fabricated.
 */
function keypanel_buy(string $variantId, int $quantity): array
{
    if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL extension is required for the API provider.');

    $url   = rtrim(trim((string)get_setting('api2_endpoint', '')), '/');
    $token = trim((string)get_setting('api2_master_token', ''));
    if ($url === '' || $token === '') throw new RuntimeException('API provider is not configured.');
    if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https://#i', $url)) {
        throw new RuntimeException('API provider URL is invalid (HTTPS required).');
    }

    $quantity = max(1, min(10, $quantity));
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['action' => 'buy', 'variant_id' => $variantId, 'quantity' => $quantity], '', '&', PHP_QUERY_RFC3986),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-www-form-urlencoded',
            'x-master-key: ' . $token,
            'Accept: application/json',
        ],
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 15,               // same timeout as the documented example
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,            // never re-send the secret header to a redirect target
    ]);
    $raw   = curl_exec($ch);
    $errno = curl_errno($ch);
    $err   = curl_error($ch);
    $http  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        $timeout = ($errno === 28);
        throw new RuntimeException($timeout ? 'API provider timed out.' : 'API provider unavailable: ' . $err);
    }
    if ($raw === false || trim((string)$raw) === '') throw new RuntimeException('API provider returned an empty response (HTTP ' . $http . ').');
    if (ltrim((string)$raw)[0] === '<') throw new RuntimeException('API provider returned HTML instead of JSON (HTTP ' . $http . ').');
    $json = json_decode((string)$raw, true);
    if (!is_array($json)) throw new RuntimeException('API provider returned an invalid response (HTTP ' . $http . ').');

    // Documented contract: success only when ok is truthy. Anything else is a failure.
    $ok = !empty($json['ok']) && $http >= 200 && $http < 300;
    if (!$ok) {
        $json['ok'] = false;
        if (empty($json['error'])) $json['error'] = (string)($json['message'] ?? ('Provider error (HTTP ' . $http . ')'));
    } else {
        $json['ok'] = true;
    }
    return $json;
}

/**
 * PANELSTORE transport. JSON body {key, action, ...params}. Returns the decoded JSON as-is
 * (no success/failure judgement here). Transport problems throw RuntimeException; the API key
 * is only ever placed in the request body and never in a message, log line or return value.
 */
function panelstore_request(string $action, array $params = [], int $timeout = 20): array
{
    if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL extension is required for the API provider.');

    $url   = trim((string)get_setting('api3_endpoint', ''));
    $token = trim((string)get_setting('api3_master_token', ''));
    if ($url === '' || $token === '') throw new RuntimeException('API provider is not configured.');
    if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https://#i', $url)) {
        throw new RuntimeException('API provider URL is invalid (HTTPS required).');
    }

    // key/action are set last so no caller-supplied param can override them.
    $payload = array_merge($params, ['key' => $token, 'action' => $action]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,            // never re-send the key to a redirect target
    ]);
    $raw   = curl_exec($ch);
    $errno = curl_errno($ch);
    $err   = curl_error($ch);
    $http  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) throw new RuntimeException($errno === 28 ? 'API provider timed out.' : 'API provider unavailable: ' . $err);
    if ($raw === false || trim((string)$raw) === '') throw new RuntimeException('API provider returned an empty response (HTTP ' . $http . ').');
    if (ltrim((string)$raw)[0] === '<') throw new RuntimeException('API provider returned HTML instead of JSON (HTTP ' . $http . ').');
    $json = json_decode((string)$raw, true);
    if (!is_array($json)) throw new RuntimeException('API provider returned an invalid response (HTTP ' . $http . ').');
    return $json;
}

/** PANELSTORE balance. Returns ['ok'=>bool, 'balance'=>float|null, 'error'=>string]. Never throws for provider-side errors. */
function panelstore_balance(): array
{
    $r = panelstore_request('balance', [], 10);
    if (isset($r['balance']) && is_numeric($r['balance'])) {
        return ['ok' => true, 'balance' => (float)$r['balance'], 'error' => ''];
    }
    return ['ok' => false, 'balance' => null, 'error' => (string)($r['message'] ?? $r['error'] ?? 'Unexpected response from provider.')];
}

/**
 * PANELSTORE "order" by pid. Normalised to the shape the purchase flows already consume:
 *   success -> ['ok'=>true,  'key'=>..., 'keys'=>[...]]
 *   failure -> ['ok'=>false, 'error'=>provider message, ('shortfall' when the message is about provider funds)]
 * Success requires status === 'success' AND a non-empty license_key. Anything else is a failure.
 */
function panelstore_order(string $pid, int $quantity): array
{
    $quantity = 1;   // capped by provider_info()['max_quantity']; purchase flows reject >1 before any charge
    $r = panelstore_request('order', ['pid' => ctype_digit($pid) ? (int)$pid : $pid, 'quantity' => $quantity], 20);

    $status = strtolower(trim((string)($r['status'] ?? '')));
    $lk     = $r['license_key'] ?? null;
    $keys   = [];
    if (is_string($lk) && trim($lk) !== '') $keys = [trim($lk)];
    elseif (is_array($lk)) $keys = array_values(array_filter(array_map(static fn($v) => is_scalar($v) ? trim((string)$v) : '', $lk), static fn($v) => $v !== ''));

    if ($status === 'success' && $keys) {
        return ['ok' => true, 'key' => $keys[0], 'keys' => $keys];
    }

    $msg = trim((string)($r['message'] ?? $r['error'] ?? ''));
    if ($status === 'success') $msg = 'Provider reported success but returned no license key.';
    if ($msg === '') $msg = 'Provider rejected the order.';
    $out = ['ok' => false, 'error' => $msg];
    // The provider's message text is not documented; this only keeps provider-fund problems out of customer-facing text.
    if (preg_match('/balance|insufficient|fund|credit/i', $msg)) $out['shortfall'] = true;
    return $out;
}

/**
 * Provider 4 (BANTIBHAIYA reseller_v1) "buy".
 * Documented request (the only thing sent): POST <url>, form-encoded, header x-master-key,
 *   fields api_key, action=buy, product_id (exact product PID), duration (duration name, e.g. "1 Day"), quantity (1-100),
 *   android_id (ONLY for device-bound / V1 products - the Store has no android_id input, so it is never sent).
 * The documentation supplied shows the REQUEST only; the response format is not documented. Therefore success is accepted
 * conservatively and fail-closed: HTTP 2xx, no explicit failure marker (success/ok === false, status error/fail...),
 * AND at least one non-empty key actually present in the response. Anything else = failure -> existing refund path.
 * Both secrets (api_key, x-master-key) live only in the settings table and are never returned or logged.
 */
function bantibhaiya_buy(string $pid, string $durationName, int $quantity): array
{
    if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL extension is required for the API provider.');

    $url    = trim((string)get_setting('api4_endpoint', ''));
    $apiKey = trim((string)get_setting('api4_api_key', ''));
    $master = trim((string)get_setting('api4_master_token', ''));
    if ($url === '' || $apiKey === '' || $master === '') throw new RuntimeException('API provider is not configured.');
    if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https://#i', $url)) {
        throw new RuntimeException('API provider URL is invalid (HTTPS required).');
    }
    if ($pid === '' || $durationName === '') throw new RuntimeException('Provider product ID or duration name is missing.');

    $quantity = max(1, min(100, $quantity));
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'api_key'    => $apiKey,
            'action'     => 'buy',
            'product_id' => $pid,
            'duration'   => $durationName,
            'quantity'   => $quantity,
        ], '', '&', PHP_QUERY_RFC3986),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-www-form-urlencoded',
            'x-master-key: ' . $master,
            'Accept: application/json',
        ],
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 20,               // same as the supplied example
        CURLOPT_SSL_VERIFYPEER => true,             // kept ON (the example turns it off; never do that with a secret in the request)
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,            // never re-send the secrets to a redirect target
    ]);
    $raw   = curl_exec($ch);
    $errno = curl_errno($ch);
    $err   = curl_error($ch);
    $http  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) throw new RuntimeException($errno === 28 ? 'API provider timed out.' : 'API provider unavailable: ' . $err);
    if ($raw === false || trim((string)$raw) === '') throw new RuntimeException('API provider returned an empty response (HTTP ' . $http . ').');
    if (ltrim((string)$raw)[0] === '<') throw new RuntimeException('API provider returned HTML instead of JSON (HTTP ' . $http . ').');
    $json = json_decode((string)$raw, true);
    if (!is_array($json)) throw new RuntimeException('API provider returned an invalid response (HTTP ' . $http . ').');

    $data = (isset($json['data']) && is_array($json['data'])) ? $json['data'] : [];

    // Explicit failure markers always win.
    $failed = $http < 200 || $http >= 300
        || (array_key_exists('success', $json) && !filter_var($json['success'], FILTER_VALIDATE_BOOLEAN))
        || (array_key_exists('ok', $json) && !filter_var($json['ok'], FILTER_VALIDATE_BOOLEAN))
        || (isset($json['status']) && is_scalar($json['status']) && !in_array(strtolower(trim((string)$json['status'])), ['success', 'ok', 'true', '1', '200', 'completed'], true));

    // Collect keys from the common shapes (string | list of strings | list of {key|license_key|...}).
    $collect = static function ($v) use (&$collect): array {
        if (is_string($v)) { $v = trim($v); return $v === '' ? [] : [$v]; }
        if (is_int($v) || is_float($v)) return [(string)$v];
        if (!is_array($v)) return [];
        $out = [];
        foreach (['key', 'license_key', 'license', 'value'] as $f) {
            if (isset($v[$f]) && !is_array($v[$f])) { $out = array_merge($out, $collect($v[$f])); }
        }
        if ($out) return $out;
        // Only a plain list is walked; an object without a known key field is never guessed at (no metadata mistaken for keys).
        if ($v === [] || array_keys($v) !== range(0, count($v) - 1)) return [];
        foreach ($v as $item) { $out = array_merge($out, $collect($item)); }
        return $out;
    };
    $keys = [];
    foreach ([$json, $data] as $src) {
        foreach (['keys', 'key', 'license_keys', 'license_key', 'licenses', 'license'] as $f) {
            if (isset($src[$f])) $keys = array_merge($keys, $collect($src[$f]));
        }
        if ($keys) break;
    }
    $keys = array_values(array_unique($keys));

    $msg = '';
    foreach ([$json, $data] as $src) {
        foreach (['message', 'error', 'msg'] as $f) {
            if (isset($src[$f]) && is_scalar($src[$f]) && trim((string)$src[$f]) !== '') { $msg = trim((string)$src[$f]); break 2; }
        }
    }

    if (!$failed && $keys) {
        if (count($keys) < $quantity) error_log('[provider bantibhaiya] delivered ' . count($keys) . ' of ' . $quantity . ' requested keys');
        $oid = '';
        foreach ([$json, $data] as $src) {
            foreach (['order_id', 'request_id', 'id'] as $f) {
                if (isset($src[$f]) && is_scalar($src[$f]) && (string)$src[$f] !== '') { $oid = (string)$src[$f]; break 2; }
            }
        }
        return ['ok' => true, 'key' => $keys[0], 'keys' => $keys, 'order_id' => $oid];
    }

    if ($msg === '') $msg = $failed ? ('Provider rejected the order (HTTP ' . $http . ').') : 'Provider returned no key.';
    $out = ['ok' => false, 'error' => $msg];
    if (preg_match('/balance|insufficient|fund|credit/i', $msg)) $out['shortfall'] = true;   // keep provider-fund problems out of customer text
    return $out;
}

/**
 * Provider 5 (LICENCE DASHBOARD, https://licencedashboard.shop/api/v1) - ONLY what the supplied source states:
 *   GET  balance.php | catalog.php | history.php?limit=N        POST generate.php (JSON body)
 *   Header: Authorization: Bearer <token>        Accept: application/json
 *   generate body: {product_id:int, quantity:int, idempotency_key:string}
 *                  device-bound v1 adds {api_version:"v1", android_id:"..."}   (v2 sends neither)
 *   success: {ok:true,  data:{keys:[...]}}       failure: {ok:false, error:{message:"..."}}
 *   A 429 may be retried (the supplied client sleeps 2s * attempt, max 4 attempts).
 * The catalog/balance/history response shapes are not specified, so none of them is interpreted for money decisions.
 *
 * The plan's provider_variant_id is "<product_id>" or "<product_id>|<mode>" with mode = v2 (default) | v1 | both:
 *   v2   -> direct key, android_id never sent.
 *   v1   -> device-bound: api_version=v1 + android_id are ALWAYS sent; no android_id = rejected before any charge.
 *   both -> defaults to v2; v1 only when the request explicitly asks for it (api_version=v1) and carries an android_id.
 */
function licencedash_parse_variant(string $variant): ?array
{
    if (!preg_match('/^([0-9]{1,10})(?:\|(v1|v2|both))?$/iD', trim($variant), $m)) return null;
    $pid = (int)$m[1];
    if ($pid <= 0) return null;
    return ['product_id' => $pid, 'mode' => isset($m[2]) && $m[2] !== '' ? strtolower($m[2]) : 'v2'];
}

/**
 * v42: the variant string licencedash_resolve()/licencedash_generate() understand, built from the plan's SEPARATE columns:
 *   product_plans.provider_variant_id  = the bare provider product/service id (digits only, e.g. "283")
 *   product_plans.provider_api_version = 'v2' | 'v1' | 'both'   (NULL/'' on legacy rows -> read from a legacy "283|both" suffix, else v2)
 * The composed string exists only in memory for the request; it is never stored. v2 (the default) composes to the bare id, i.e. the
 * outgoing generate.php body is exactly {product_id, quantity, idempotency_key} - nothing is appended to the id.
 */
function licencedash_plan_variant(array $plan): string
{
    $raw = trim((string)($plan['provider_variant_id'] ?? ''));
    $v = licencedash_parse_variant($raw);
    if ($v === null) return $raw;                                   // invalid stays invalid -> licencedash_resolve() rejects it before any charge
    $col = strtolower(trim((string)($plan['provider_api_version'] ?? '')));
    $mode = in_array($col, ['v1', 'v2', 'both'], true) ? $col : $v['mode'];
    return $mode === 'v2' ? (string)$v['product_id'] : ($v['product_id'] . '|' . $mode);
}

/**
 * Decide - BEFORE any wallet charge - exactly what will be sent. Returns ['error'=>?string, 'use_v1'=>bool, 'android_id'=>string].
 * $requestedVersion / $androidId come from the purchase request (never from the plan).
 */
function licencedash_resolve(string $variant, string $requestedVersion, string $androidId): array
{
    $v = licencedash_parse_variant($variant);
    if ($v === null) return ['error' => 'Provider product is not configured correctly (invalid product ID).', 'use_v1' => false, 'android_id' => ''];
    $androidId = trim($androidId);
    $requestedVersion = strtolower(trim($requestedVersion));
    if ($requestedVersion !== '' && !in_array($requestedVersion, ['v1', 'v2'], true)) {
        return ['error' => 'api_version must be v1 or v2.', 'use_v1' => false, 'android_id' => ''];
    }
    if ($v['mode'] === 'v1')        $useV1 = true;
    elseif ($v['mode'] === 'both')  $useV1 = ($requestedVersion === 'v1');   // default v2 unless v1 is explicitly selected
    else                            $useV1 = false;                          // v2 product: v1 can never be forced
    if ($useV1) {
        if ($androidId === '') return ['error' => 'Android ID is required for this device-bound (v1) product.', 'use_v1' => true, 'android_id' => ''];
        if (!preg_match('/^[\x21-\x7E]{1,64}$/D', $androidId)) return ['error' => 'Android ID is invalid.', 'use_v1' => true, 'android_id' => ''];
        return ['error' => null, 'use_v1' => true, 'android_id' => $androidId];
    }
    return ['error' => null, 'use_v1' => false, 'android_id' => ''];
}

/**
 * v59: metadata of the LAST provider HTTP exchange in this PHP process (http status + response head). Read by the shared order executor
 * (includes/order_executor.php) to record HTTP code / response snapshot per order. Never holds a token; keys are masked by ox_snapshot().
 */
function provider_call_meta_set(?array $m): void { $GLOBALS['RP_LAST_PROVIDER_CALL'] = $m; }
function provider_call_meta_get(): array { $m = $GLOBALS['RP_LAST_PROVIDER_CALL'] ?? null; return is_array($m) ? $m : []; }

/**
 * Transport for provider 5. Returns ['json'=>array, 'http'=>int]. Transport problems throw RuntimeException.
 * The token only ever goes into the Authorization header: never into a URL, message, log line or return value.
 */
function licencedash_request(string $endpoint, array $params = [], string $method = 'GET', int $timeout = 20): array
{
    if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL extension is required for the API provider.');
    if (!in_array($endpoint, ['balance.php', 'catalog.php', 'generate.php', 'history.php'], true)) throw new RuntimeException('Unknown provider endpoint.');

    $base  = rtrim(trim((string)get_setting('api5_endpoint', '')), '/');
    $token = trim((string)get_setting('api5_master_token', ''));
    if ($base === '' || $token === '') throw new RuntimeException('API provider is not configured.');
    if (!filter_var($base, FILTER_VALIDATE_URL) || !preg_match('#^https://#i', $base)) {
        throw new RuntimeException('API provider URL is invalid (HTTPS required).');
    }
    $isPost = strtoupper($method) === 'POST';
    $url = $base . '/' . $endpoint;
    if (!$isPost && $params) $url .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

    $raw = false; $errno = 0; $err = ''; $http = 0;
    provider_call_meta_set(['endpoint' => $endpoint, 'http' => 0, 'head' => '', 'json' => null]);
    for ($try = 1; $try <= 4; $try++) {
        $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,      // never re-send the bearer token to a redirect target
        ];
        if ($isPost) {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($params);
            $headers[] = 'Content-Type: application/json';
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $raw   = curl_exec($ch);
        $errno = curl_errno($ch);
        $err   = curl_error($ch);
        $http  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errno === 0 && $http === 429 && $try < 4) { sleep(2 * $try); continue; }   // same back-off as the supplied client
        break;
    }

    provider_call_meta_set(['endpoint' => $endpoint, 'http' => $http, 'head' => ($raw === false ? '' : mb_substr((string)$raw, 0, 300)), 'json' => null, 'curl_errno' => $errno]);
    if ($errno !== 0) throw new RuntimeException($errno === 28 ? 'API provider timed out.' : 'API provider unavailable: ' . $err);
    // v39: a 4xx reply (except 408 Request Timeout) means the provider REFUSED the request: nothing was generated, whatever the body
    // looks like (empty / HTML / not JSON). Returned as a structured result instead of a throw so the purchase flow can treat it as a
    // confirmed rejection (FAILED + refund) rather than an "uncertain" order that stays PENDING.
    $rawStr = ($raw === false) ? '' : (string)$raw;
    $json = null;
    if (trim($rawStr) !== '' && ltrim($rawStr)[0] !== '<') {
        $decoded = json_decode($rawStr, true);
        if (is_array($decoded)) $json = $decoded;
    }
    if ($json === null) {
        if ($http >= 400 && $http < 500 && $http !== 408) {
            return ['json' => ['ok' => false, 'error' => ['message' => 'Provider rejected the request (HTTP ' . $http . ').']], 'http' => $http, 'unparsed' => true];
        }
        if (trim($rawStr) === '') throw new RuntimeException('API provider returned an empty response (HTTP ' . $http . ').');
        if (ltrim($rawStr)[0] === '<') throw new RuntimeException('API provider returned HTML instead of JSON (HTTP ' . $http . ').');
        throw new RuntimeException('API provider returned an invalid response (HTTP ' . $http . ').');
    }
    provider_call_meta_set(['endpoint' => $endpoint, 'http' => $http, 'head' => '', 'json' => $json]);
    return ['json' => $json, 'http' => $http];
}

/** Error text from {"ok":false,"error":{"message":"..."}} (also tolerates a plain string error). */
function licencedash_error_message(array $json, int $http): string
{
    $e = $json['error'] ?? null;
    if (is_array($e) && isset($e['message']) && is_scalar($e['message']) && trim((string)$e['message']) !== '') {
        $code = (isset($e['code']) && is_scalar($e['code'])) ? trim((string)$e['code']) : '';
        // v39: keep the provider's own error code (e.g. OUT_OF_STOCK) in the admin-side text; it is never shown to customers.
        return trim((string)$e['message']) . ($code !== '' && stripos((string)$e['message'], $code) === false ? ' [' . $code . ']' : '');
    }
    if (is_string($e) && trim($e) !== '') return trim($e);
    if (isset($json['message']) && is_scalar($json['message']) && trim((string)$json['message']) !== '') return trim((string)$json['message']);
    return 'Provider rejected the request (HTTP ' . $http . ').';
}

/** Keys from the documented data.keys list. Only non-empty scalar strings (or {key|license_key|value}) count. */
function licencedash_extract_keys(array $json): array
{
    $list = (isset($json['data']) && is_array($json['data'])) ? ($json['data']['keys'] ?? null) : null;
    if (!is_array($list)) return [];
    $keys = [];
    foreach ($list as $item) {
        if (is_array($item)) {
            foreach (['key', 'license_key', 'value'] as $f) {
                if (isset($item[$f]) && is_scalar($item[$f])) { $item = $item[$f]; break; }
            }
        }
        if (is_string($item) || is_int($item)) {
            $k = trim((string)$item);
            if ($k !== '') $keys[] = $k;
        }
    }
    return $keys;
}

/**
 * generate.php, normalised to the shape every purchase flow already consumes:
 *   success -> ['ok'=>true, 'key'=>..., 'keys'=>[...], 'order_id'=>string]
 *   failure -> ['ok'=>false, 'error'=>message, ('shortfall' for provider-fund problems)]
 * Success requires ok === true AND at least one real key. Anything else is a failure: no key is ever fabricated.
 * $opts: api_version, android_id (request values), idempotency_key (unique per website order).
 */
function licencedash_generate(string $variant, int $quantity, array $opts = []): array
{
    $res = licencedash_resolve($variant, (string)($opts['api_version'] ?? ''), (string)($opts['android_id'] ?? ''));
    if ($res['error'] !== null) return ['ok' => false, 'error' => $res['error']];   // nothing is sent to the provider
    $v = licencedash_parse_variant($variant);

    $quantity = max(1, min(provider_info(PROVIDER_LICENCEDASH)['max_quantity'], $quantity));
    $idem = trim((string)($opts['idempotency_key'] ?? ''));
    if ($idem === '' || !preg_match('/^[A-Za-z0-9._\-]{1,64}$/D', $idem)) $idem = 'ld-' . bin2hex(random_bytes(12));

    $payload = ['product_id' => $v['product_id'], 'quantity' => $quantity, 'idempotency_key' => $idem];
    if ($res['use_v1']) { $payload['api_version'] = 'v1'; $payload['android_id'] = $res['android_id']; }

    // Test hook (CLI self-test only, never active on the website): lets tools/test_instant_refund.php simulate provider replies.
    if (defined('RP_TEST_MODE') && PHP_SAPI === 'cli' && isset($GLOBALS['RP_TEST_PROVIDER']) && is_callable($GLOBALS['RP_TEST_PROVIDER'])) {
        $r = ($GLOBALS['RP_TEST_PROVIDER'])($payload);
    } else {
        $r = licencedash_request('generate.php', $payload, 'POST', 25);
    }
    $json = $r['json']; $http = $r['http'];

    // v39: a 5xx reply that does not carry the documented explicit {"ok":false,...} refusal is NOT proof that nothing was generated
    // (gateway error after the provider processed the order). It stays "uncertain" and is reconciled, never refunded blindly.
    if ($http >= 500 && ($json['ok'] ?? null) !== false) {
        throw new RuntimeException('API provider returned a server error (HTTP ' . $http . ').');
    }

    $ok = (($json['ok'] ?? null) === true) && $http >= 200 && $http < 300;
    $keys = $ok ? licencedash_extract_keys($json) : [];
    if ($ok && $keys) {
        if (count($keys) < $quantity) error_log('[provider licencedashboard] delivered ' . count($keys) . ' of ' . $quantity . ' requested keys');
        $oid = '';
        foreach (['order_id', 'request_id', 'id'] as $f) {
            $d = (isset($json['data']) && is_array($json['data'])) ? $json['data'] : [];
            if (isset($d[$f]) && is_scalar($d[$f]) && (string)$d[$f] !== '') { $oid = substr((string)$d[$f], 0, 60); break; }
        }
        return ['ok' => true, 'key' => $keys[0], 'keys' => $keys, 'order_id' => $oid];
    }

    // v58: only an EXPLICIT refusal is a confirmed failure: the documented {"ok":false,...}, or an HTTP 4xx (not 408) that does not claim ok:true.
    // Everything else is NOT proof that nothing was generated, so it stays "uncertain" (thrown -> held for reconciliation, never refunded, never re-sent):
    //   - ok:true but no key (the provider ACCEPTED the order; the key may be retrievable)
    //   - HTTP 2xx/3xx whose body is not the documented shape (e.g. {"foo":1}, ok missing / not boolean)
    $okField = $json['ok'] ?? null;
    $is4xx = $http >= 400 && $http < 500 && $http !== 408;
    if ($ok) throw new RuntimeException('API provider accepted the order but returned no key (HTTP ' . $http . ').');
    if (!($okField === false || ($is4xx && $okField !== true))) {
        throw new RuntimeException('API provider returned an unrecognised response (HTTP ' . $http . ').');
    }

    $msg = licencedash_error_message($json, $http);
    $out = ['ok' => false, 'error' => $msg, 'confirmed' => true, 'http' => $http];   // v39: an explicit provider answer (ok:false / 4xx / ok:true without a key) is a CONFIRMED failure
    if (preg_match('/balance|insufficient|fund|credit/i', $msg)) $out['shortfall'] = true;   // keep provider-fund problems out of customer text
    // v58: carry the provider's own documented error.code (never invented) so out-of-stock is decided from what the provider said.
    $ec = (!$ok && isset($json['error']) && is_array($json['error']) && isset($json['error']['code']) && is_scalar($json['error']['code'])) ? trim((string)$json['error']['code']) : '';
    if ($ec !== '') $out['error_code'] = substr($ec, 0, 64);
    return $out;
}

/** balance.php (read-only). Response shape is not specified: a numeric balance is reported only when one is clearly present. */
function licencedash_balance(): array
{
    $r = licencedash_request('balance.php', [], 'GET', 12);
    $json = $r['json'];
    if (($json['ok'] ?? null) !== true || $r['http'] < 200 || $r['http'] >= 300) {
        return ['ok' => false, 'balance' => null, 'error' => licencedash_error_message($json, $r['http']), 'data' => null];
    }
    $data = (isset($json['data']) && is_array($json['data'])) ? $json['data'] : [];
    $bal = null;
    foreach ([$data['balance'] ?? null, $json['balance'] ?? null] as $c) {
        if (is_int($c) || is_float($c) || (is_string($c) && is_numeric($c))) { $bal = (float)$c; break; }
    }
    return ['ok' => true, 'balance' => $bal, 'error' => '', 'data' => $data];
}

/**
 * Admin display helper for catalog.php / history.php. The response shape is not specified by the supplied source, so this
 * only lists what is clearly a plain list of objects and never drives a purchase decision (mapping is always typed by the admin).
 */
function licencedash_list_items(array $json, int $max = 300): array
{
    $cands = [];
    $d = $json['data'] ?? null;
    if (is_array($d)) {
        foreach (['products', 'catalog', 'items', 'history', 'keys', 'list'] as $f) {
            if (isset($d[$f]) && is_array($d[$f])) $cands[] = $d[$f];
        }
        $cands[] = $d;
    }
    foreach (['products', 'catalog', 'items', 'history'] as $f) {
        if (isset($json[$f]) && is_array($json[$f])) $cands[] = $json[$f];
    }
    foreach ($cands as $c) {
        if ($c === [] || array_keys($c) !== range(0, count($c) - 1)) continue;
        $out = [];
        foreach (array_slice($c, 0, $max) as $it) {
            if (!is_array($it)) { if (is_scalar($it)) $out[] = ['id' => '', 'name' => (string)$it, 'mode' => '', 'extra' => '']; continue; }
            $pick = static function (array $a, array $fields): string {
                foreach ($fields as $f) { if (isset($a[$f]) && is_scalar($a[$f]) && (string)$a[$f] !== '') return (string)$a[$f]; }
                return '';
            };
            $used = ['product_id', 'id', 'pid', 'name', 'title', 'product_name', 'product', 'api_version_mode'];
            $extra = [];
            foreach ($it as $k => $v) {
                if (in_array($k, $used, true) || !is_scalar($v) || count($extra) >= 5) continue;
                $extra[] = $k . ': ' . mb_substr((string)$v, 0, 40);
            }
            $out[] = [
                'id'    => mb_substr($pick($it, ['product_id', 'id', 'pid']), 0, 40),
                'name'  => mb_substr($pick($it, ['name', 'title', 'product_name', 'product']), 0, 120),
                'mode'  => mb_substr($pick($it, ['api_version_mode']), 0, 10),
                'extra' => implode(' • ', $extra),
            ];
        }
        return $out;
    }
    return [];
}

/**
 * Provider 6 (VIP SELLER HUB, default base https://whitexmodz.store/api/v1) - ONLY what the supplied PHP client states:
 *   Header: X-API-Token: <token>        Accept: application/json
 *   GET  balance.php                    -> {success:true, balance, currency, email}
 *   GET  products.php                   -> {success:true, products:[{variant_id, product_name, variant_name, price, in_stock}]}
 *   GET  key_history.php?limit=N        -> {success:true, orders:[{order_id, product_name, variant_name, quantity, total_amount, completed_at}]}
 *   POST generate_key.php  (form-encoded: variant_id, quantity 1..100)
 *        success -> {success:true, keys:[...], order_id, product_name, variant_name, total_cost, currency, new_balance}
 *        failure -> {success:false, code, error}
 * CLOUDFLARE: the provider's edge can wrap an origin 5xx as HTTP 200 + {success:false, code, error}. So the JSON `success` field is the
 * only truth - never the HTTP status alone. A wrapped server error is NOT proof that nothing was generated, so (like a real 5xx) it stays
 * "uncertain" (thrown -> held for reconciliation, never refunded blindly). Only an explicit refusal is a confirmed failure.
 * 429 (per-IP / per-token / Cloudflare) is a refusal BEFORE processing, so it is safe to retry; Retry-After is honoured (capped).
 * No idempotency key is documented for this API, so the request is never re-sent after anything but a 429.
 * The plan's provider_variant_id is the provider's numeric variant_id (digits only).
 */
function vipseller_request(string $endpoint, array $params = [], string $method = 'GET', int $timeout = 20): array
{
    if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL extension is required for the API provider.');
    if (!in_array($endpoint, ['balance.php', 'products.php', 'key_history.php', 'generate_key.php'], true)) throw new RuntimeException('Unknown provider endpoint.');

    $base  = rtrim(trim((string)get_setting('api6_endpoint', '')), '/');
    $token = trim((string)get_setting('api6_master_token', ''));
    if ($base === '' || $token === '') throw new RuntimeException('API provider is not configured.');
    if (!filter_var($base, FILTER_VALIDATE_URL) || !preg_match('#^https://#i', $base)) {
        throw new RuntimeException('API provider URL is invalid (HTTPS required).');
    }
    $isPost = strtoupper($method) === 'POST';
    $url = $base . '/' . $endpoint;
    if (!$isPost && $params) $url .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

    $raw = false; $errno = 0; $err = ''; $http = 0;
    provider_call_meta_set(['endpoint' => $endpoint, 'http' => 0, 'head' => '', 'json' => null]);
    for ($try = 1; $try <= 4; $try++) {
        $retryAfter = 0;
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,      // never re-send the token to a redirect target
            CURLOPT_HTTPHEADER     => ['X-API-Token: ' . $token, 'Accept: application/json'],
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$retryAfter): int {
                if (stripos($line, 'Retry-After:') === 0) $retryAfter = (int)trim(substr($line, 12));
                return strlen($line);
            },
        ];
        if ($isPost) {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        }
        curl_setopt_array($ch, $opts);
        $raw   = curl_exec($ch);
        $errno = curl_errno($ch);
        $err   = curl_error($ch);
        $http  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errno === 0 && $http === 429 && $try < 4) {
            sleep($retryAfter > 0 ? min(5, $retryAfter) : (1 << ($try - 1)));   // 1s, 2s, 4s as the provider documentation recommends
            continue;
        }
        break;
    }

    provider_call_meta_set(['endpoint' => $endpoint, 'http' => $http, 'head' => ($raw === false ? '' : mb_substr((string)$raw, 0, 300)), 'json' => null, 'curl_errno' => $errno]);
    if ($errno !== 0) throw new RuntimeException($errno === 28 ? 'API provider timed out.' : 'API provider unavailable: ' . $err);
    $rawStr = ($raw === false) ? '' : (string)$raw;
    $json = null;
    if (trim($rawStr) !== '' && ltrim($rawStr)[0] !== '<') {
        $decoded = json_decode($rawStr, true);
        if (is_array($decoded)) $json = $decoded;
    }
    if ($json === null) {
        // A 4xx (not 408) means the provider REFUSED the request before processing: a confirmed rejection, not an uncertain order.
        if ($http >= 400 && $http < 500 && $http !== 408) {
            return ['json' => ['success' => false, 'error' => 'Provider rejected the request (HTTP ' . $http . ').'], 'http' => $http, 'unparsed' => true];
        }
        if (trim($rawStr) === '') throw new RuntimeException('API provider returned an empty response (HTTP ' . $http . ').');
        if (ltrim($rawStr)[0] === '<') throw new RuntimeException('API provider returned HTML instead of JSON (HTTP ' . $http . ') - possibly a Cloudflare challenge page.');
        throw new RuntimeException('API provider returned an invalid response (HTTP ' . $http . ').');
    }
    provider_call_meta_set(['endpoint' => $endpoint, 'http' => $http, 'head' => '', 'json' => $json]);
    return ['json' => $json, 'http' => $http];
}

/** Error text from {"success":false,"code":"...","error":"..."} (tolerates {error:{message}} too). The provider's own code is kept for admin-side text only. */
function vipseller_error_message(array $json, int $http): string
{
    $e = $json['error'] ?? null;
    $msg = '';
    if (is_string($e) && trim($e) !== '') $msg = trim($e);
    elseif (is_array($e) && isset($e['message']) && is_scalar($e['message'])) $msg = trim((string)$e['message']);
    elseif (isset($json['message']) && is_scalar($json['message'])) $msg = trim((string)$json['message']);
    if ($msg === '') $msg = 'Provider rejected the request (HTTP ' . $http . ').';
    $code = (isset($json['code']) && is_scalar($json['code'])) ? trim((string)$json['code']) : '';
    return mb_substr($msg, 0, 300) . ($code !== '' && stripos($msg, $code) === false ? ' [' . mb_substr($code, 0, 40) . ']' : '');
}

/** Keys from the documented top-level `keys` list. Only non-empty scalar strings (or {key|license_key|value}) count. */
function vipseller_extract_keys(array $json): array
{
    $list = $json['keys'] ?? null;
    if (!is_array($list) && isset($json['data']) && is_array($json['data'])) $list = $json['data']['keys'] ?? null;   // tolerated envelope
    if (!is_array($list)) return [];
    $keys = [];
    foreach ($list as $item) {
        if (is_array($item)) {
            foreach (['key', 'license_key', 'value'] as $f) {
                if (isset($item[$f]) && is_scalar($item[$f])) { $item = $item[$f]; break; }
            }
        }
        if (is_string($item) || is_int($item)) {
            $k = trim((string)$item);
            if ($k !== '') $keys[] = $k;
        }
    }
    return $keys;
}

/**
 * generate_key.php, normalised to the shape every purchase flow already consumes:
 *   success -> ['ok'=>true, 'key'=>..., 'keys'=>[...], 'order_id'=>string]
 *   failure -> ['ok'=>false, 'error'=>message, 'confirmed'=>true, ('shortfall' for provider-fund problems)]
 * Success requires success === true AND at least one real key. No key is ever fabricated.
 */
function vipseller_generate(string $variantId, int $quantity, array $opts = []): array
{
    $variantId = trim($variantId);
    if (!preg_match('/^[0-9]{1,10}$/D', $variantId) || (int)$variantId <= 0) {
        return ['ok' => false, 'error' => 'Provider variant is not configured correctly (invalid variant ID).'];   // nothing is sent to the provider
    }
    $quantity = max(1, min(provider_info(PROVIDER_VIPSELLER)['max_quantity'], $quantity));
    $payload = ['variant_id' => (int)$variantId, 'quantity' => $quantity];

    // Test hook (CLI self-test only, never active on the website): lets the tools/ self-tests simulate provider replies.
    if (defined('RP_TEST_MODE') && PHP_SAPI === 'cli' && isset($GLOBALS['RP_TEST_PROVIDER']) && is_callable($GLOBALS['RP_TEST_PROVIDER'])) {
        $r = ($GLOBALS['RP_TEST_PROVIDER'])($payload);
    } else {
        $r = vipseller_request('generate_key.php', $payload, 'POST', 30);
    }
    $json = $r['json']; $http = $r['http'];

    // A 5xx is NOT proof that nothing was generated (gateway error after the provider processed the order): uncertain, reconciled later.
    if ($http >= 500) throw new RuntimeException('API provider returned a server error (HTTP ' . $http . ').');

    $success = array_key_exists('success', $json) ? filter_var($json['success'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
    $ok   = ($success === true) && $http >= 200 && $http < 300;
    $keys = $ok ? vipseller_extract_keys($json) : [];
    if ($ok && $keys) {
        if (count($keys) < $quantity) error_log('[provider vipsellerhub] delivered ' . count($keys) . ' of ' . $quantity . ' requested keys');
        $oid = (isset($json['order_id']) && is_scalar($json['order_id'])) ? substr((string)$json['order_id'], 0, 60) : '';
        return ['ok' => true, 'key' => $keys[0], 'keys' => $keys, 'order_id' => $oid];
    }
    // success:true without any key = the provider ACCEPTED (and probably charged) the order. Never refunded blindly.
    if ($ok) throw new RuntimeException('API provider accepted the order but returned no key (HTTP ' . $http . ').');

    $is4xx = $http >= 400 && $http < 500 && $http !== 408;
    if (!($success === false || ($is4xx && $success !== true))) {
        throw new RuntimeException('API provider returned an unrecognised response (HTTP ' . $http . ').');
    }
    $code = (isset($json['code']) && is_scalar($json['code'])) ? strtoupper(trim((string)$json['code'])) : '';
    // Cloudflare wraps origin 5xx as HTTP 200 + success:false. A code that names a server/gateway fault is therefore NOT a refusal.
    if (!$is4xx && $code !== '' && preg_match('/^(INTERNAL|SERVER|UPSTREAM|BAD_GATEWAY|GATEWAY|TIMEOUT|UNAVAILABLE|SERVICE_UNAVAILABLE|DB_|DATABASE|CF_|CLOUDFLARE|ORIGIN)/', $code)) {
        throw new RuntimeException('API provider reported a server-side error (' . substr($code, 0, 40) . ').');
    }
    $msg = vipseller_error_message($json, $http);
    $out = ['ok' => false, 'error' => $msg, 'confirmed' => true, 'http' => $http];
    if (preg_match('/balance|insufficient|fund|credit/i', $msg)) $out['shortfall'] = true;   // keep provider-fund problems out of customer text
    if ($code !== '') $out['error_code'] = substr($code, 0, 64);
    return $out;
}

/** balance.php (read-only). Never generates a key. */
function vipseller_balance(): array
{
    $r = vipseller_request('balance.php', [], 'GET', 12);
    $json = $r['json'];
    if (($json['success'] ?? null) !== true || $r['http'] < 200 || $r['http'] >= 300) {
        return ['ok' => false, 'balance' => null, 'error' => vipseller_error_message($json, $r['http']), 'data' => null];
    }
    $bal = null;
    $c = $json['balance'] ?? null;
    if (is_int($c) || is_float($c) || (is_string($c) && is_numeric($c))) $bal = (float)$c;
    $data = [];
    foreach (['currency', 'email'] as $f) { if (isset($json[$f]) && is_scalar($json[$f])) $data[$f] = mb_substr((string)$json[$f], 0, 120); }
    return ['ok' => true, 'balance' => $bal, 'error' => '', 'data' => $data];
}

/**
 * Admin display helper for products.php / key_history.php: a plain list for the admin to look up the variant_id to type in.
 * Display only - it never drives a purchase decision (mapping is always typed by the admin).
 */
function vipseller_list_items(array $json, string $kind, int $max = 300): array
{
    $list = $json[$kind === 'history' ? 'orders' : 'products'] ?? null;
    if (!is_array($list) || ($list !== [] && array_keys($list) !== range(0, count($list) - 1))) return [];
    $out = [];
    foreach (array_slice($list, 0, $max) as $it) {
        if (!is_array($it)) continue;
        $name = trim(((string)($it['product_name'] ?? '')) . ' — ' . ((string)($it['variant_name'] ?? '')), ' —');
        $extra = [];
        if ($kind === 'history') {
            foreach (['order_id' => 'order', 'quantity' => 'qty', 'total_amount' => 'amount', 'completed_at' => 'at'] as $f => $lbl) {
                if (isset($it[$f]) && is_scalar($it[$f]) && (string)$it[$f] !== '') $extra[] = $lbl . ': ' . mb_substr((string)$it[$f], 0, 40);
            }
        } else {
            foreach (['price' => 'price', 'in_stock' => 'stock'] as $f => $lbl) {
                if (isset($it[$f]) && is_scalar($it[$f]) && (string)$it[$f] !== '') $extra[] = $lbl . ': ' . mb_substr((string)$it[$f], 0, 40);
            }
        }
        $out[] = [
            'id'    => mb_substr((string)($kind === 'history' ? ($it['order_id'] ?? '') : ($it['variant_id'] ?? '')), 0, 40),
            'name'  => mb_substr($name, 0, 120),
            'mode'  => '',
            'extra' => implode(' • ', $extra),
        ];
    }
    return $out;
}

/** Route a purchase to the provider stored on the plan. $opts is only used by providers that need extra request fields. */
function provider_generate(string $provider, string $variantId, int $quantity, string $durationLabel = '', array $opts = []): array
{
    if ($provider === PROVIDER_LICENCEDASH) return licencedash_generate($variantId, $quantity, $opts);
    if ($provider === PROVIDER_VIPSELLER) return vipseller_generate($variantId, $quantity, $opts);
    if ($provider === PROVIDER_BANTIBHAIYA) {
        // Stored as "PID" (duration name = the plan's own duration label) or "PID|Duration Name" (explicit override).
        $parts = explode('|', $variantId, 2);
        $pid   = trim($parts[0]);
        $dur   = isset($parts[1]) && trim($parts[1]) !== '' ? trim($parts[1]) : trim($durationLabel);
        return bantibhaiya_buy($pid, $dur, $quantity);
    }
    if ($provider === PROVIDER_PANELSTORE) return panelstore_order($variantId, $quantity);
    if ($provider === PROVIDER_KEYPANEL) return keypanel_buy($variantId, $quantity);
    require_once __DIR__ . '/reseller_api.php';
    return reseller_api_call('generate_key', ['variant_id' => $variantId, 'quantity' => $quantity]);
}
