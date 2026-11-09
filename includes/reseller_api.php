<?php
declare(strict_types=1);

/**
 * DRIPSTORE reseller API client.
 * Contract taken from the supplied PHP client: X-API-Token header,
 * https://dripclientstore.shop/api/v1 endpoints.
 */
function reseller_api_call(string $action, array $params = [], int $timeout = 15): array
{
    $base = rtrim((string)get_setting('api_endpoint', 'https://dripclientstore.shop/api/v1'), '/');
    $token = trim((string)get_setting('api_master_token', ''));
    if ($base === '' || $token === '') throw new RuntimeException('API provider is not configured.');

    $paths = [
        'balance' => '/balance.php',
        'products' => '/products.php',
        'generate_key' => '/generate_key.php',
        'reset_apis' => '/reset_apis.php',
        'reset_key' => '/reset_key.php',
        'key_history' => '/key_history.php',
    ];
    if (!isset($paths[$action])) throw new RuntimeException('Unsupported provider API action.');

    $method = in_array($action, ['generate_key','reset_key'], true) ? 'POST' : 'GET';
    return reseller_api_request($base . $paths[$action], $token, $method, $params, $timeout);
}

function reseller_api_request(string $url, string $token, string $method = 'GET', array $params = [], int $timeout = 15): array
{
    if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL extension is required for the API provider.');
    if (!filter_var($url, FILTER_VALIDATE_URL)) throw new RuntimeException('API provider URL is invalid.');

    if ($method === 'GET' && $params) {
        $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'X-API-Token: ' . $token,
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_USERAGENT => 'KeysPanel-DRIPSTORE-Client/1.0',
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) throw new RuntimeException('API connection failed: ' . $err);
    if ($raw === false || $raw === null || trim((string)$raw) === '') throw new RuntimeException('API returned an empty response (HTTP ' . $http . ').');
    $trim = ltrim((string)$raw);
    if ($trim !== '' && $trim[0] === '<') throw new RuntimeException('API returned HTML instead of JSON (HTTP ' . $http . '). Check the provider allowlist/Cloudflare settings.');
    $json = json_decode((string)$raw, true);
    if (!is_array($json)) throw new RuntimeException('API returned invalid JSON (HTTP ' . $http . ').');
    if ($http < 200 || $http >= 300) {
        $msg = (string)($json['error'] ?? $json['message'] ?? ('HTTP ' . $http));
        throw new RuntimeException('API error: ' . $msg);
    }
    return $json;
}
