<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
$admin = require_admin();

/*
 * Admin: ZapUPI gateway settings. Completely separate from api/admin/settings.php (Paytm), so saving here can never
 * touch Paytm values and vice versa.   GET = read.  POST (CSRF-protected): save | toggle | test.
 *
 * The API key is write-only: it is accepted here, stored server-side, and only a mask ("••••••••ABCD") is ever returned.
 * If the ZAPUPI_API_KEY environment variable is set, it wins and the panel field is ignored (key_source = env).
 */
header('Cache-Control: no-store');
$method = request_method();

function zp_admin_state(): array
{
    $c = zapupi_config();
    $last = json_decode((string)get_setting('zapupi_last_api', ''), true);
    $pdo = db();
    $cnt = [];
    try {
        $r = $pdo->prepare('SELECT credit_status, COUNT(*) c FROM deposits WHERE method = :m GROUP BY credit_status');
        $r->execute([':m' => ZAPUPI_METHOD]);
        foreach ($r->fetchAll() as $row) $cnt[(string)($row['credit_status'] ?? 'none')] = (int)$row['c'];
    } catch (Throwable $e) { error_log('[admin/zapupi:counts] ' . $e->getMessage()); }

    $webhook = '';
    try { $webhook = base_url('api/zapupi-webhook.php'); } catch (Throwable $e) { /* leave blank */ }

    $source = zapupi_key_source();
    $status = !$c['configured'] ? 'NOT CONFIGURED' : ($c['enabled'] ? 'ACTIVE' : 'CONFIGURED • OFF');
    return [
        'enabled'        => $c['enabled'],
        'configured'     => $c['configured'],
        'status_label'   => $status,
        'name'           => $c['name'],
        'endpoint'       => $c['endpoint'],
        'min_amount'     => zapupi_fmt_paise($c['min_paise']),
        'max_amount'     => zapupi_fmt_paise($c['max_paise']),
        'instructions'   => $c['instructions'],
        'mode'           => $c['mode'],
        'app_env'        => defined('APP_ENV') ? APP_ENV : 'production',
        'test_credit'    => zapupi_test_credit_allowed(),
        'key_source'     => $source,
        'key_masked'     => $source === 'settings' ? zapupi_mask_key(zapupi_api_key()) : ($source === 'env' ? 'set via ZAPUPI_API_KEY' : ''),
        'webhook_url'    => $webhook,
        'webhook_https'  => stripos($webhook, 'https://') === 0,
        'last_api'       => is_array($last) ? $last : null,
        'counts'         => $cnt,
    ];
}

if ($method === 'GET') {
    json_ok(['zapupi' => zp_admin_state()]);
}
if ($method !== 'POST') json_error('Method not allowed', 405);
csrf_verify();

$action = input_str('action');
$adminId = (int)$admin['id'];

switch ($action) {
    case 'save':
        $name = trim(strip_tags(input_str('name', 'ZapUPI')));
        if ($name === '' || mb_strlen($name) > 40) json_error('Gateway name must be 1-40 characters.', 422);

        $endpoint = rtrim(trim(input_str('endpoint', ZAPUPI_DEFAULT_ENDPOINT)), '/');
        if (!zapupi_endpoint_valid($endpoint)) json_error('API endpoint must be an https:// address on zapupi.com.', 422);

        $minP = zapupi_paise(input_str('min_amount', ''));
        $maxP = zapupi_paise(input_str('max_amount', ''));
        if ($minP === null || $maxP === null || $minP <= 0 || $maxP <= 0) json_error('Minimum and maximum must be positive amounts (max 2 decimals).', 422);
        if ($minP > $maxP) json_error('Minimum deposit cannot be greater than maximum.', 422);
        if ($maxP > 10000000000) json_error('Maximum deposit is too large.', 422);

        $instr = trim(strip_tags(input_str('instructions', '')));
        if (mb_strlen($instr) > 500) json_error('Instructions are limited to 500 characters.', 422);

        $mode = input_str('mode', 'production') === 'test' ? 'test' : 'production';

        // API key: blank = keep the stored one. It is never echoed back and never logged.
        $newKey = input_str('api_key', '');
        if ($newKey !== '') {
            if (strlen($newKey) < 8 || strlen($newKey) > 200 || !preg_match('/^[\x21-\x7E]+$/', $newKey)) json_error('API key format looks invalid.', 422);
            if (zapupi_key_source() === 'env') json_error('The key is set by the ZAPUPI_API_KEY environment variable; remove that to store a key here.', 409);
            set_setting('zapupi_api_key', $newKey);
        }

        set_setting('zapupi_display_name', $name);
        set_setting('zapupi_api_endpoint', $endpoint);
        set_setting('zapupi_min_amount', zapupi_fmt_paise($minP));
        set_setting('zapupi_max_amount', zapupi_fmt_paise($maxP));
        set_setting('zapupi_instructions', $instr);
        set_setting('zapupi_mode', $mode);
        // NOTE: nothing here reads or writes any paytm_* setting.
        audit_log('settings.zapupi_saved', 'success', ['actor_id' => $adminId, 'reason' => 'ZapUPI settings saved' . ($newKey !== '' ? ' (API key replaced)' : '') . '; mode ' . $mode]);
        json_ok(['zapupi' => zp_admin_state()]);
        break;

    case 'toggle':
        $on = filter_var(input_param('enabled', false), FILTER_VALIDATE_BOOLEAN);
        if ($on && !zapupi_config()['configured']) json_error('Enter the ZapUPI API key before turning ZapUPI on.', 422);
        set_setting('zapupi_enabled', $on ? 'on' : 'off');      // independent of paytm_enabled
        audit_log('settings.zapupi_toggled', 'success', ['actor_id' => $adminId, 'reason' => 'ZapUPI turned ' . ($on ? 'ON' : 'OFF') . ' (Paytm unchanged)']);
        json_ok(['zapupi' => zp_admin_state()]);
        break;

    case 'test':
        /*
         * Safe connectivity test: asks ZapUPI for the status of an order id that cannot exist. It creates nothing and
         * moves no money. It proves TLS + reachability + JSON. The supplied documentation does not define the reply for
         * an invalid key vs an unknown order, so this does NOT claim the key is valid - it shows the provider's own message.
         */
        if (zapupi_api_key() === '') json_error('Enter the ZapUPI API key first.', 422);
        $probe = 'CONNTEST' . strtoupper(bin2hex(random_bytes(6)));
        try {
            $r = zapupi_http_post('order-status', ['zap_key' => zapupi_api_key(), 'order_id' => $probe]);
            $msg = zapupi_s($r['json'], 'message');
            zapupi_record_api('test-connection', true, $r['http'], 'Reachable. Provider replied: ' . ($msg !== '' ? $msg : zapupi_s($r['json'], 'status')));
            json_ok(['reachable' => true, 'http' => $r['http'], 'provider_status' => zapupi_s($r['json'], 'status'),
                     'provider_message' => ops_sanitize($msg, 200),
                     'note' => 'Reachable over verified HTTPS and returned valid JSON. Key validity is judged from the provider message above.',
                     'zapupi' => zp_admin_state()]);
        } catch (RuntimeException $e) {
            zapupi_record_api('test-connection', false, 0, $e->getMessage());
            json_error('Connection test failed: ' . ops_sanitize($e->getMessage(), 160), 502);
        }
        break;

    default:
        json_error('Unknown action.', 400);
}
