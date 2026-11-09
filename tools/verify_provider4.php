<?php
declare(strict_types=1);
/**
 * Provider 4 staging verification helper (CLI only, never reachable over HTTP).
 *
 *   php tools/verify_provider4.php                       -> checks saved Provider 4 settings only (nothing is sent)
 *   php tools/verify_provider4.php --buy PID "1 Day" 1   -> places ONE REAL order at the provider (costs provider balance!)
 *                                                           and prints the SANITIZED response structure + what the parser decides
 *
 * It does not touch the wallet, orders or products. Secrets are never printed: values are shown only as type/length,
 * except short status/message-type fields, and any value equal to a stored secret is masked.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/bootstrap.php';

$secrets = array_filter([trim((string)get_setting('api4_api_key', '')), trim((string)get_setting('api4_master_token', ''))]);
$i = provider_info(PROVIDER_BANTIBHAIYA);
echo "== Provider 4 settings ==\n";
echo 'label:      ' . $i['label'] . "\n";
echo 'enabled:    ' . ($i['enabled'] ? 'yes' : 'NO') . "\n";
echo 'configured: ' . ($i['configured'] ? 'yes (url + api key + master key saved)' : 'NO') . "\n";
echo 'url:        ' . get_setting('api4_endpoint', '') . "\n";
$n = (int)db()->query("SELECT COUNT(*) FROM product_plans WHERE source='api' AND provider_key='" . PROVIDER_BANTIBHAIYA . "'")->fetchColumn();
echo "plans mapped to Provider 4: $n\n";
foreach (db()->query("SELECT p.name, pl.duration, pl.provider_variant_id, pl.allow_quantity FROM product_plans pl JOIN products p ON p.id=pl.product_id WHERE pl.source='api' AND pl.provider_key='" . PROVIDER_BANTIBHAIYA . "' ORDER BY p.id, pl.id LIMIT 20") as $r) {
    echo '  - ' . $r['name'] . ' / ' . $r['duration'] . '  PID=' . $r['provider_variant_id'] . '  qty-allowed=' . ((int)$r['allow_quantity'] ? 'yes' : 'no') . "\n";
}

$idx = array_search('--buy', $argv, true);
if ($idx === false) { echo "\n(no --buy given: nothing was sent to the provider)\n"; exit(0); }
$pid = (string)($argv[$idx + 1] ?? ''); $dur = (string)($argv[$idx + 2] ?? ''); $qty = (int)($argv[$idx + 3] ?? 1);
if ($pid === '' || $dur === '' || $qty < 1) { fwrite(STDERR, "usage: --buy PID \"Duration Name\" QTY\n"); exit(2); }
if (!$i['configured']) { fwrite(STDERR, "Provider 4 is not configured.\n"); exit(2); }

// Same request as bantibhaiya_buy(), but the raw reply is kept so its structure can be shown.
$ch = curl_init(trim((string)get_setting('api4_endpoint', '')));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query(['api_key' => trim((string)get_setting('api4_api_key', '')), 'action' => 'buy', 'product_id' => $pid, 'duration' => $dur, 'quantity' => $qty], '', '&', PHP_QUERY_RFC3986),
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'x-master-key: ' . trim((string)get_setting('api4_master_token', '')), 'Accept: application/json'],
    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 20, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_FOLLOWLOCATION => false,
]);
$raw = curl_exec($ch); $errno = curl_errno($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE); $cerr = curl_error($ch);
curl_close($ch);

echo "\n== Real provider response (sanitized) ==\n";
if ($errno !== 0) { echo "transport error ($errno): $cerr\n"; exit(1); }
echo "HTTP status:  $http\ncontent-type: $ctype\nbody bytes:   " . strlen((string)$raw) . "\n";
$json = json_decode((string)$raw, true);
if (!is_array($json)) { echo 'body is NOT JSON. First 80 chars: ' . preg_replace('/[^\x20-\x7E]/', '?', substr((string)$raw, 0, 80)) . "\n"; exit(1); }

$show = static function ($v, string $path = '', int $depth = 0) use (&$show, $secrets): void {
    $pad = str_repeat('  ', $depth);
    foreach ($v as $k => $val) {
        $p = $path === '' ? (string)$k : $path . '.' . $k;
        if (is_array($val)) { echo $pad . $k . ': ' . (array_keys($val) === range(0, max(0, count($val) - 1)) && $val ? 'list[' . count($val) . ']' : 'object') . "\n"; if ($depth < 4) $show($val, $p, $depth + 1); continue; }
        $type = gettype($val);
        if (is_string($val)) {
            $masked = in_array($val, $secrets, true);
            $isMsg = preg_match('/^(status|success|ok|message|msg|error|code|result)$/i', (string)$k) && !$masked;
            echo $pad . $k . ': string(len ' . strlen($val) . ')' . ($isMsg ? ' = "' . substr($val, 0, 120) . '"' : ($masked ? ' [MASKED]' : ' [value hidden]')) . "\n";
        } else {
            echo $pad . $k . ': ' . $type . ' = ' . var_export($val, true) . "\n";
        }
    }
};
$show($json);

echo "\n== Current parser decision ==\n";
try {
    // Re-run the real adapter on the same input (a second order!) is NOT done; the decision is derived from the captured body instead.
    $failed = $http < 200 || $http >= 300
        || (array_key_exists('success', $json) && !filter_var($json['success'], FILTER_VALIDATE_BOOLEAN))
        || (array_key_exists('ok', $json) && !filter_var($json['ok'], FILTER_VALIDATE_BOOLEAN))
        || (isset($json['status']) && is_scalar($json['status']) && !in_array(strtolower(trim((string)$json['status'])), ['success', 'ok', 'true', '1', '200', 'completed'], true));
    $found = [];
    foreach ([$json, (isset($json['data']) && is_array($json['data'])) ? $json['data'] : []] as $s) {
        foreach (['keys', 'key', 'license_keys', 'license_key', 'licenses', 'license'] as $f) if (isset($s[$f])) $found[] = $f;
    }
    echo 'explicit failure marker: ' . ($failed ? 'YES -> parser = FAILURE (refund)' : 'no') . "\n";
    echo 'key field(s) the parser knows: ' . ($found ? implode(', ', $found) : 'NONE FOUND -> parser = FAILURE (refund), even if the order really succeeded at the provider!') . "\n";
} catch (Throwable $e) { echo 'could not evaluate: ' . $e->getMessage() . "\n"; }
echo "\nIf the real key lives under a field not listed above, that field name is what must be added in bantibhaiya_buy().\n";
