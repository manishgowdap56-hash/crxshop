<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_admin();

$method = request_method();

if ($method === 'GET') {
    // Whitelist readable settings (never expose api_master_token)
    $safe = [
        'panel_name', 'logo_path', 'join_link', 'maintenance_mode',
        'api_endpoint', 'api_service_name', 'api_provider_enabled',
    ];
    $out = [];
    foreach ($safe as $k) {
        $out[$k] = get_setting($k, '');
    }
    // Hint whether a token exists without revealing it
    // Paytm deposit gateway (MID / UPI ID are merchant identifiers, not secrets; the source shows them to admins too)
    $out['paytm_enabled']      = get_setting('paytm_enabled', 'off') === 'on';
    $out['paytm_mid']          = get_setting('paytm_mid', '');
    $out['paytm_upi_id']       = get_setting('paytm_upi_id', '');
    $out['paytm_configured']   = get_setting('paytm_mid', '') !== '' && get_setting('paytm_upi_id', '') !== '';
    $out['deposit_min_amount'] = number_format(deposit_min(), 2, '.', '');
    $out['deposit_max_amount'] = number_format(deposit_max(), 2, '.', '');
    $out['api_master_token_set'] = get_setting('api_master_token', '') !== '';
    // Provider 2 (KEYPANEL). The key itself is never returned, only whether one is stored.
    $out['api2_endpoint']          = get_setting('api2_endpoint', 'https://keypanel.shop/reseller_gateway.php');
    $out['api2_service_name']      = get_setting('api2_service_name', 'KEYPANEL API');
    $out['api2_master_token_set']  = get_setting('api2_master_token', '') !== '';
    $out['api2_provider_enabled']  = get_setting('api2_provider_enabled', '0') === '1';
    // Provider 3 (PANELSTORE). Same rule: the key is never returned.
    $out['api3_endpoint']          = get_setting('api3_endpoint', 'https://panelstore.shop/api/v1/index.php');
    $out['api3_service_name']      = get_setting('api3_service_name', 'PANELSTORE API');
    $out['api3_master_token_set']  = get_setting('api3_master_token', '') !== '';
    $out['api3_provider_enabled']  = get_setting('api3_provider_enabled', '0') === '1';
    // Provider 4 (BANTIBHAIYA). Neither secret is ever returned, only whether each is stored.
    $out['api4_endpoint']          = get_setting('api4_endpoint', 'https://bantibhaiya.to/api/reseller_v1.php');
    $out['api4_service_name']      = get_setting('api4_service_name', 'BANTIBHAIYA API');
    $out['api4_api_key_set']       = get_setting('api4_api_key', '') !== '';
    $out['api4_master_token_set']  = get_setting('api4_master_token', '') !== '';
    $out['api4_provider_enabled']  = get_setting('api4_provider_enabled', '0') === '1';
    // Provider 5 (LICENCE DASHBOARD). The token is never returned, only whether one is stored.
    $out['api5_endpoint']          = get_setting('api5_endpoint', 'https://licencedashboard.shop/api/v1');
    $out['api5_service_name']      = get_setting('api5_service_name', 'LICENCE DASHBOARD API');
    $out['api5_master_token_set']  = get_setting('api5_master_token', '') !== '';
    $out['api5_provider_enabled']  = get_setting('api5_provider_enabled', '0') === '1';
    // Provider 6 (VIP SELLER HUB). The token is never returned, only whether one is stored.
    $out['api6_endpoint']          = get_setting('api6_endpoint', 'https://whitexmodz.store/api/v1');
    $out['api6_service_name']      = get_setting('api6_service_name', 'VIP SELLER HUB API');
    $out['api6_master_token_set']  = get_setting('api6_master_token', '') !== '';
    $out['api6_provider_enabled']  = get_setting('api6_provider_enabled', '0') === '1';
    $out['providers']              = provider_list_public();
    // Dashboard display + Telegram support
    $out['telegram_username'] = telegram_support_username();
    $out['store_update_link'] = get_setting('store_update_link', '');
    $out['store_feedback_link'] = get_setting('store_feedback_link', '');
    $out['display_slides']    = [];
    $have = [];
    foreach (display_slides() as $sl) $have[$sl['n']] = $sl['url'];
    for ($n = 1; $n <= 3; $n++) $out['display_slides'][] = $have[$n] ?? '';
    $out['api_provider_enabled'] = get_setting('api_provider_enabled', '1') === '1';
    json_ok(['settings' => $out]);
}

csrf_verify();

/**
 * Validate one uploaded image exactly like the global-logo upload does (same errors, same 20 MB cap,
 * same PNG/JPG/WEBP allow-list, finfo -> mime_content_type -> getimagesize fallbacks).
 * Returns the safe file extension.
 */
function validate_uploaded_image(array $file, string $label): string
{
    $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE   => $label . ' is larger than the server upload limit.',
        UPLOAD_ERR_FORM_SIZE  => $label . ' is larger than the form upload limit.',
        UPLOAD_ERR_PARTIAL    => $label . ' upload was interrupted. Please try again.',
        UPLOAD_ERR_NO_FILE    => 'No file was selected for ' . $label . '.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server temporary upload directory is missing.',
        UPLOAD_ERR_CANT_WRITE => 'Server could not write the uploaded image to disk.',
        UPLOAD_ERR_EXTENSION  => 'A server extension stopped the image upload.',
    ];
    if ($uploadError !== UPLOAD_ERR_OK) json_error($uploadErrors[$uploadError] ?? ($label . ' upload failed.'), 422);
    if (!is_uploaded_file((string)$file['tmp_name'])) json_error('Invalid uploaded file for ' . $label . '.', 422);
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) json_error('The selected image for ' . $label . ' is empty.', 422);
    if ($size > 20 * 1024 * 1024) json_error($label . ' is too large (max 20 MB).', 422);

    $mime = '';
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($file['tmp_name']);
    }
    if ($mime === '' && function_exists('mime_content_type')) {
        $mime = (string)mime_content_type($file['tmp_name']);
    }
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime])) {
        $info = @getimagesize($file['tmp_name']);
        $byType = [
            IMAGETYPE_JPEG => 'image/jpeg',
            IMAGETYPE_PNG  => 'image/png',
            IMAGETYPE_WEBP => 'image/webp',
        ];
        $type = is_array($info) ? (int)($info[2] ?? 0) : 0;
        if (isset($byType[$type])) $mime = $byType[$type];
    }
    if (!isset($allowed[$mime])) json_error('Invalid image type for ' . $label . '. Use PNG, JPG or WEBP.', 422);
    // Real decodable image, not just a matching header.
    if (@getimagesize($file['tmp_name']) === false) json_error($label . ' is not a valid image.', 422);
    return $allowed[$mime];
}

/** Delete a previously stored slide file; only ever inside uploads/display with our own filename pattern. */
function delete_old_slide(string $relative): void
{
    if (!preg_match('#^uploads/display/slide[123]-[a-f0-9]{24}\.(jpg|png|webp)$#', $relative)) return;
    $abs = BASE_PATH . '/' . $relative;
    if (is_file($abs)) @unlink($abs);
}

$action = input_str('action');

switch ($action) {
    case 'save_join_link':
        $link = trim((string)input_param('link', ''));
        if ($link !== '' && !preg_match('#^https?://#i', $link)) {
            json_error('Link must start with http:// or https://', 422);
        }
        set_setting('join_link', $link);
        json_ok();
        break;

    case 'save_panel_name':
        $name = trim((string)input_param('name', ''));
        if (strlen($name) < 2) json_error('Panel name must be at least 2 characters.', 422);
        set_setting('panel_name', $name);
        json_ok();
        break;

    case 'toggle_maintenance':
        // v36: the browser may send state=on|off (idempotent, a double click cannot flip it back). Without it: flip, as before.
        $want = strtolower(trim((string)input_param('state', '')));
        $was  = maintenance_is_on();
        if ($want === 'on' || $want === 'off') $on = ($want === 'on');
        else $on = !$was;
        set_setting('maintenance_mode', $on ? '1' : '0');
        audit_log('settings.maintenance', 'success', ['entity_type' => 'setting', 'entity_id' => 'maintenance_mode', 'reason' => 'Maintenance ' . ($on ? 'ON' : 'OFF') . ' (was ' . ($was ? 'ON' : 'OFF') . ')']);
        json_ok(['maintenance_mode' => $on]);
        break;

    case 'save_api_settings':
        $endpoint = trim((string)input_param('endpoint', 'https://dripclientstore.shop/api/v1'));
        $service  = trim((string)input_param('service_name', 'DRIPSTORE API'));
        $token    = trim((string)input_param('master_token', ''));
        $enabled  = input_param('enabled', null);

        if (!preg_match('#^https?://#i', $endpoint)) json_error('API base URL must start with http:// or https://', 422);
        if ($service === '') $service = 'DRIPSTORE API';
        set_setting('api_endpoint', rtrim($endpoint, '/'));
        set_setting('api_service_name', $service);
        if ($token !== '') set_setting('api_master_token', $token);
        if ($enabled !== null) set_setting('api_provider_enabled', filter_var($enabled, FILTER_VALIDATE_BOOLEAN) ? '1' : '0');
        json_ok(['token_set' => get_setting('api_master_token', '') !== '', 'enabled' => get_setting('api_provider_enabled', '1') === '1']);
        break;

    /* ---------------- Provider 2: KEYPANEL (supplied documentation: x-master-key + action=buy) ---------------- */
    case 'save_api2_settings':
        $endpoint = trim((string)input_param('endpoint', 'https://keypanel.shop/reseller_gateway.php'));
        $service  = trim((string)input_param('service_name', 'KEYPANEL API'));
        $token    = trim((string)input_param('master_token', ''));
        $enabled  = input_param('enabled', null);
        if (!preg_match('#^https://#i', $endpoint) || !filter_var($endpoint, FILTER_VALIDATE_URL)) json_error('Gateway URL must be a valid https:// URL (the key is sent to it).', 422);
        if ($service === '') $service = 'KEYPANEL API';
        if (mb_strlen($service) > 60) json_error('Service name is too long.', 422);
        if ($token !== '' && !preg_match('/^[\x21-\x7E]{8,200}$/', $token)) json_error('API key format looks invalid.', 422);
        set_setting('api2_endpoint', rtrim($endpoint, '/'));
        set_setting('api2_service_name', $service);
        if ($token !== '') set_setting('api2_master_token', $token);
        if ($enabled !== null) {
            $on = filter_var($enabled, FILTER_VALIDATE_BOOLEAN);
            if ($on && get_setting('api2_master_token', '') === '') json_error('Save an API key before enabling this provider.', 422);
            set_setting('api2_provider_enabled', $on ? '1' : '0');
        }
        json_ok(['token_set' => get_setting('api2_master_token', '') !== '', 'enabled' => get_setting('api2_provider_enabled', '0') === '1']);
        break;

    case 'toggle_api2_provider':
        $on = filter_var(input_param('enabled', false), FILTER_VALIDATE_BOOLEAN);
        if ($on && get_setting('api2_master_token', '') === '') json_error('Save an API key before enabling this provider.', 422);
        // Only flips the switch. Key, URL and existing product assignments are kept.
        set_setting('api2_provider_enabled', $on ? '1' : '0');
        json_ok(['enabled' => $on]);
        break;

    case 'remove_api2_token':
        set_setting('api2_master_token', '');
        set_setting('api2_provider_enabled', '0');     // cannot stay ON without a key
        json_ok();
        break;

    case 'test_api2':
        // The supplied documentation defines ONLY the "buy" action (no balance/status/ping action),
        // and a test must never place a real order. So this validates the saved configuration only
        // and sends NOTHING to the provider.
        $i = provider_info(PROVIDER_KEYPANEL);
        $url = get_setting('api2_endpoint', '');
        $problems = [];
        if (!preg_match('#^https://#i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) $problems[] = 'Gateway URL is missing or not https.';
        if (get_setting('api2_master_token', '') === '') $problems[] = 'API key is not saved.';
        if (!function_exists('curl_init')) $problems[] = 'PHP cURL extension is missing on this server.';
        if ($problems) json_error(implode(' ', $problems), 422);
        json_ok(['message' => 'Configuration is complete. No request was sent to the provider (the documentation has no test/balance action). The first real purchase confirms the key.', 'enabled' => $i['enabled']]);
        break;

    /* ---------------- PANELSTORE provider (supplied documentation: JSON {key, action} -> balance / order by pid) ---------------- */
    case 'save_api3_settings':
        $endpoint = trim((string)input_param('endpoint', 'https://panelstore.shop/api/v1/index.php'));
        $service  = trim((string)input_param('service_name', 'PANELSTORE API'));
        $token    = trim((string)input_param('master_token', ''));
        $enabled  = input_param('enabled', null);
        if (!preg_match('#^https://#i', $endpoint) || !filter_var($endpoint, FILTER_VALIDATE_URL)) json_error('API URL must be a valid https:// URL (the key is sent to it).', 422);
        if ($service === '') $service = 'PANELSTORE API';
        if (mb_strlen($service) > 60) json_error('Service name is too long.', 422);
        if ($token !== '' && !preg_match('/^[\x21-\x7E]{8,200}$/', $token)) json_error('API key format looks invalid.', 422);
        set_setting('api3_endpoint', $endpoint);
        set_setting('api3_service_name', $service);
        if ($token !== '') set_setting('api3_master_token', $token);
        if ($enabled !== null) {
            $on = filter_var($enabled, FILTER_VALIDATE_BOOLEAN);
            if ($on && get_setting('api3_master_token', '') === '') json_error('Save an API key before enabling this provider.', 422);
            set_setting('api3_provider_enabled', $on ? '1' : '0');
        }
        json_ok(['token_set' => get_setting('api3_master_token', '') !== '', 'enabled' => get_setting('api3_provider_enabled', '0') === '1']);
        break;

    case 'toggle_api3_provider':
        $on = filter_var(input_param('enabled', false), FILTER_VALIDATE_BOOLEAN);
        if ($on && get_setting('api3_master_token', '') === '') json_error('Save an API key before enabling this provider.', 422);
        set_setting('api3_provider_enabled', $on ? '1' : '0');   // key, URL and product assignments are kept
        json_ok(['enabled' => $on]);
        break;

    case 'remove_api3_token':
        set_setting('api3_master_token', '');
        set_setting('api3_provider_enabled', '0');                // cannot stay ON without a key
        json_ok();
        break;

    case 'test_api3':
    case 'check_api3_balance':
        // Uses the documented "balance" action: read-only, never places an order. Works while the provider is OFF
        // so the key can be verified before it is switched ON. The key is never part of the response.
        try {
            $b = panelstore_balance();
        } catch (Throwable $e) {
            json_error('FAILED: ' . $e->getMessage(), 502);
        }
        if (!$b['ok']) json_error('FAILED: ' . $b['error'], 502);
        json_ok(['message' => 'SUCCESS', 'balance' => $b['balance']]);
        break;

    /* ---------------- Provider 4 (BANTIBHAIYA: form POST, api_key + x-master-key, action=buy) ---------------- */
    case 'save_api4_settings':
        $endpoint = trim((string)input_param('endpoint', 'https://bantibhaiya.to/api/reseller_v1.php'));
        $service  = trim((string)input_param('service_name', 'BANTIBHAIYA API'));
        $apiKey   = trim((string)input_param('api_key', ''));
        $token    = trim((string)input_param('master_token', ''));
        $enabled  = input_param('enabled', null);
        if (!preg_match('#^https://#i', $endpoint) || !filter_var($endpoint, FILTER_VALIDATE_URL)) json_error('API URL must be a valid https:// URL (the keys are sent to it).', 422);
        if ($service === '') $service = 'BANTIBHAIYA API';
        if (mb_strlen($service) > 60) json_error('Service name is too long.', 422);
        if ($apiKey !== '' && !preg_match('/^[\x21-\x7E]{8,200}$/', $apiKey)) json_error('API key format looks invalid.', 422);
        if ($token !== '' && !preg_match('/^[\x21-\x7E]{8,200}$/', $token)) json_error('Master key format looks invalid.', 422);
        set_setting('api4_endpoint', $endpoint);
        set_setting('api4_service_name', $service);
        if ($apiKey !== '') set_setting('api4_api_key', $apiKey);
        if ($token !== '')  set_setting('api4_master_token', $token);
        $both = get_setting('api4_api_key', '') !== '' && get_setting('api4_master_token', '') !== '';
        if ($enabled !== null) {
            $on = filter_var($enabled, FILTER_VALIDATE_BOOLEAN);
            if ($on && !$both) json_error('Save both the API key and the master key before enabling this provider.', 422);
            set_setting('api4_provider_enabled', $on ? '1' : '0');
        }
        json_ok(['configured' => $both, 'enabled' => get_setting('api4_provider_enabled', '0') === '1']);
        break;

    case 'toggle_api4_provider':
        $on = filter_var(input_param('enabled', false), FILTER_VALIDATE_BOOLEAN);
        if ($on && (get_setting('api4_api_key', '') === '' || get_setting('api4_master_token', '') === '')) json_error('Save both the API key and the master key before enabling this provider.', 422);
        set_setting('api4_provider_enabled', $on ? '1' : '0');   // keys, URL and product assignments are kept
        json_ok(['enabled' => $on]);
        break;

    case 'remove_api4_token':
        set_setting('api4_api_key', '');
        set_setting('api4_master_token', '');
        set_setting('api4_provider_enabled', '0');               // cannot stay ON without its keys
        json_ok();
        break;

    case 'test_api4':
        // The supplied documentation defines ONLY the "buy" action (no balance/status/ping), and a test must never place a
        // real order. So this validates the saved configuration only and sends NOTHING to the provider.
        $url = get_setting('api4_endpoint', '');
        $problems = [];
        if (!preg_match('#^https://#i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) $problems[] = 'API URL is missing or not https.';
        if (get_setting('api4_api_key', '') === '') $problems[] = 'API key is not saved.';
        if (get_setting('api4_master_token', '') === '') $problems[] = 'Master key (x-master-key) is not saved.';
        if (!function_exists('curl_init')) $problems[] = 'PHP cURL extension is missing on this server.';
        if ($problems) json_error(implode(' ', $problems), 422);
        json_ok(['message' => 'Configuration is complete. No request was sent to the provider (the documentation has no test/balance action). The first real purchase confirms the keys.', 'enabled' => provider_info(PROVIDER_BANTIBHAIYA)['enabled']]);
        break;

    /* ---------------- Provider 5 (LICENCE DASHBOARD: Bearer token, balance / catalog / generate / history) ---------------- */
    case 'save_api5_settings':
        $endpoint = trim((string)input_param('endpoint', 'https://licencedashboard.shop/api/v1'));
        $service  = trim((string)input_param('service_name', 'LICENCE DASHBOARD API'));
        $token    = trim((string)input_param('master_token', ''));
        $enabled  = input_param('enabled', null);
        if (!preg_match('#^https://#i', $endpoint) || !filter_var($endpoint, FILTER_VALIDATE_URL)) json_error('API Base URL must be a valid https:// URL (the token is sent to it).', 422);
        if ($service === '') $service = 'LICENCE DASHBOARD API';
        if (mb_strlen($service) > 60) json_error('Service name is too long.', 422);
        if ($token !== '' && !preg_match('/^[\x21-\x7E]{8,200}$/', $token)) json_error('API token format looks invalid.', 422);
        set_setting('api5_endpoint', rtrim($endpoint, '/'));
        set_setting('api5_service_name', $service);
        if ($token !== '') set_setting('api5_master_token', $token);
        if ($enabled !== null) {
            $on = filter_var($enabled, FILTER_VALIDATE_BOOLEAN);
            if ($on && get_setting('api5_master_token', '') === '') json_error('Save an API token before enabling this provider.', 422);
            set_setting('api5_provider_enabled', $on ? '1' : '0');
        }
        json_ok(['token_set' => get_setting('api5_master_token', '') !== '', 'enabled' => get_setting('api5_provider_enabled', '0') === '1']);
        break;

    case 'toggle_api5_provider':
        $on = filter_var(input_param('enabled', false), FILTER_VALIDATE_BOOLEAN);
        if ($on && get_setting('api5_master_token', '') === '') json_error('Save an API token before enabling this provider.', 422);
        set_setting('api5_provider_enabled', $on ? '1' : '0');   // token, URL and product assignments are kept
        json_ok(['enabled' => $on]);
        break;

    case 'remove_api5_token':
        set_setting('api5_master_token', '');
        set_setting('api5_provider_enabled', '0');               // cannot stay ON without a token
        json_ok();
        break;

    case 'test_api5':
    case 'check_api5_balance':
        // balance.php is read-only: it never generates a key. Works while the provider is OFF so the token can be verified first.
        try {
            $b = licencedash_balance();
        } catch (Throwable $e) {
            json_error('FAILED: ' . $e->getMessage(), 502);
        }
        if (!$b['ok']) json_error('FAILED: ' . $b['error'], 502);
        $detail = '';
        if ($b['balance'] === null && $b['data']) $detail = mb_substr((string)json_encode($b['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 400);
        json_ok(['message' => 'SUCCESS', 'balance' => $b['balance'], 'detail' => $detail]);
        break;

    case 'api5_catalog':
    case 'api5_history':
        // Read-only admin helpers. Catalog = catalog.php (used to look up the provider product_id to type into a duration).
        // History = history.php?limit=50. Raw JSON is shown so the real response shape is visible; the token is never part of it.
        try {
            $r5 = $action === 'api5_catalog'
                ? licencedash_request('catalog.php', [], 'GET', 20)
                : licencedash_request('history.php', ['limit' => 50], 'GET', 20);
        } catch (Throwable $e) {
            json_error('FAILED: ' . $e->getMessage(), 502);
        }
        if (($r5['json']['ok'] ?? null) !== true || $r5['http'] < 200 || $r5['http'] >= 300) {
            json_error('FAILED: ' . licencedash_error_message($r5['json'], $r5['http']), 502);
        }
        $items5 = licencedash_list_items($r5['json']);
        $raw5 = (string)json_encode($r5['json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        json_ok(['items' => $items5, 'count' => count($items5), 'raw' => mb_substr($raw5, 0, 30000), 'truncated' => mb_strlen($raw5) > 30000]);
        break;

    /* ---------------- Provider 6 (VIP SELLER HUB: X-API-Token, balance / products / generate_key / key_history) ---------------- */
    case 'save_api6_settings':
        $endpoint = trim((string)input_param('endpoint', 'https://whitexmodz.store/api/v1'));
        $service  = trim((string)input_param('service_name', 'VIP SELLER HUB API'));
        $token    = trim((string)input_param('master_token', ''));
        $enabled  = input_param('enabled', null);
        if (!preg_match('#^https://#i', $endpoint) || !filter_var($endpoint, FILTER_VALIDATE_URL)) json_error('API Base URL must be a valid https:// URL (the token is sent to it).', 422);
        if ($service === '') $service = 'VIP SELLER HUB API';
        if (mb_strlen($service) > 60) json_error('Service name is too long.', 422);
        if ($token !== '' && !preg_match('/^[a-f0-9]{32,128}$/i', $token)) json_error('API token format looks invalid (expected 32-128 hex characters).', 422);
        set_setting('api6_endpoint', rtrim($endpoint, '/'));
        set_setting('api6_service_name', $service);
        if ($token !== '') set_setting('api6_master_token', $token);
        if ($enabled !== null) {
            $on = filter_var($enabled, FILTER_VALIDATE_BOOLEAN);
            if ($on && get_setting('api6_master_token', '') === '') json_error('Save an API token before enabling this provider.', 422);
            set_setting('api6_provider_enabled', $on ? '1' : '0');
        }
        json_ok(['token_set' => get_setting('api6_master_token', '') !== '', 'enabled' => get_setting('api6_provider_enabled', '0') === '1']);
        break;

    case 'toggle_api6_provider':
        $on = filter_var(input_param('enabled', false), FILTER_VALIDATE_BOOLEAN);
        if ($on && get_setting('api6_master_token', '') === '') json_error('Save an API token before enabling this provider.', 422);
        set_setting('api6_provider_enabled', $on ? '1' : '0');   // token, URL and product assignments are kept
        json_ok(['enabled' => $on]);
        break;

    case 'remove_api6_token':
        set_setting('api6_master_token', '');
        set_setting('api6_provider_enabled', '0');               // cannot stay ON without a token
        json_ok();
        break;

    case 'test_api6':
    case 'check_api6_balance':
        // balance.php is read-only: it never generates a key. Works while the provider is OFF so the token can be verified first.
        try {
            $b = vipseller_balance();
        } catch (Throwable $e) {
            json_error('FAILED: ' . $e->getMessage(), 502);
        }
        if (!$b['ok']) json_error('FAILED: ' . $b['error'], 502);
        $detail = $b['data'] ? mb_substr((string)json_encode($b['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 400) : '';
        json_ok(['message' => 'SUCCESS', 'balance' => $b['balance'], 'detail' => $detail]);
        break;

    case 'api6_catalog':
    case 'api6_history':
        // Read-only admin helpers. Catalog = products.php (to look up the variant_id to type into a duration). History = key_history.php?limit=50.
        try {
            $r6 = $action === 'api6_catalog'
                ? vipseller_request('products.php', [], 'GET', 20)
                : vipseller_request('key_history.php', ['limit' => 50], 'GET', 20);
        } catch (Throwable $e) {
            json_error('FAILED: ' . $e->getMessage(), 502);
        }
        if (($r6['json']['success'] ?? null) !== true || $r6['http'] < 200 || $r6['http'] >= 300) {
            json_error('FAILED: ' . vipseller_error_message($r6['json'], $r6['http']), 502);
        }
        $items6 = vipseller_list_items($r6['json'], $action === 'api6_catalog' ? 'catalog' : 'history');
        $raw6 = (string)json_encode($r6['json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        json_ok(['items' => $items6, 'count' => count($items6), 'raw' => mb_substr($raw6, 0, 30000), 'truncated' => mb_strlen($raw6) > 30000]);
        break;

    case 'save_paytm':
        // Source admin form: only Paytm MID + Paytm UPI ID, both required.
        $mid = trim((string)input_param('mid', ''));
        $upi = trim((string)input_param('upi_id', ''));
        if ($mid === '' || $upi === '') json_error('Enter both Paytm MID and UPI ID.', 422);
        if (!preg_match('/^[A-Za-z0-9_\-]{3,64}$/', $mid)) json_error('Paytm MID looks invalid.', 422);
        if (!preg_match('/^[A-Za-z0-9._\-]{2,100}@[A-Za-z0-9]{2,64}$/', $upi)) json_error('UPI ID must look like yourid@paytm.', 422);
        set_setting('paytm_mid', $mid);
        set_setting('paytm_upi_id', $upi);
        json_ok(['configured' => true]);
        break;

    case 'toggle_paytm':
        $enabled = filter_var(input_param('enabled', false), FILTER_VALIDATE_BOOLEAN);
        set_setting('paytm_enabled', $enabled ? 'on' : 'off');
        json_ok(['enabled' => $enabled]);
        break;

    case 'save_deposit_limits':
        // Same validation rules as the source admin_settings_update.
        $min = round((float)input_param('min', 0), 2);
        $max = round((float)input_param('max', 0), 2);
        if (!is_finite($min) || !is_finite($max) || $min <= 0 || $max <= 0) json_error('Deposit minimum and maximum must be positive numbers.', 422);
        if ($min > $max) json_error('Deposit minimum cannot be greater than maximum.', 422);
        if ($max > 100000000) json_error('Deposit maximum is too large.', 422);
        set_setting('deposit_min_amount', number_format($min, 2, '.', ''));
        set_setting('deposit_max_amount', number_format($max, 2, '.', ''));
        json_ok(['min' => $min, 'max' => $max]);
        break;

    case 'toggle_api_provider':
        $enabled = filter_var(input_param('enabled', false), FILTER_VALIDATE_BOOLEAN);
        set_setting('api_provider_enabled', $enabled ? '1' : '0');
        json_ok(['enabled' => $enabled]);
        break;

    case 'remove_api_token':
        set_setting('api_master_token', '');
        json_ok();
        break;

    case 'test_api':
    case 'check_api_balance':
        require_once __DIR__ . '/../../includes/reseller_api.php';
        try {
            if (get_setting('api_provider_enabled', '1') !== '1') json_error('API provider is OFF.', 400);
            $resp = reseller_api_call('balance', [], 10);
            json_ok(['response' => $resp]);
        } catch (Throwable $e) {
            json_error($e->getMessage(), 502);
        }
        break;

    /* ---------------- Dashboard display: 3 slides, one save ---------------- */
    case 'save_display':
        $dir = UPLOAD_PATH . '/display';
        $plan = [];
        for ($n = 1; $n <= 3; $n++) {
            $key = 'slide' . $n;
            $label = 'Slide 0' . $n;
            if (isset($_FILES[$key]) && (int)($_FILES[$key]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $plan[$n] = ['ext' => validate_uploaded_image($_FILES[$key], $label), 'tmp' => $_FILES[$key]['tmp_name']];
            } elseif (filter_var(input_param('clear_slide_' . $n, false), FILTER_VALIDATE_BOOLEAN)) {
                $plan[$n] = ['clear' => true];
            }
        }
        if (!$plan) json_error('Choose at least one slide image to save.', 422);

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            json_error('Display upload directory could not be created. Make uploads/display writable.', 500);
        }
        if (!is_writable($dir)) @chmod($dir, 0755);
        if (!is_writable($dir)) json_error('Display upload directory is not writable. Make uploads/display writable on the server.', 500);

        // Everything validated -> store all new files first; if any move fails nothing is changed.
        $moved = [];
        foreach ($plan as $n => $p) {
            if (!empty($p['clear'])) continue;
            $name = 'slide' . $n . '-' . bin2hex(random_bytes(12)) . '.' . $p['ext'];
            if (!move_uploaded_file($p['tmp'], $dir . '/' . $name)) {
                foreach ($moved as $m) @unlink($dir . '/' . basename($m));
                json_error('Could not save Slide 0' . $n . '. Make uploads/display writable and try again.', 500);
            }
            @chmod($dir . '/' . $name, 0644);
            $moved[$n] = 'uploads/display/' . $name;
        }
        foreach ($plan as $n => $p) {
            $old = (string)get_setting('display_slide_' . $n, '');
            set_setting('display_slide_' . $n, !empty($p['clear']) ? '' : $moved[$n]);
            if ($old !== '') delete_old_slide($old);
        }
        $have = [];
        foreach (display_slides() as $sl) $have[$sl['n']] = $sl['url'];
        json_ok(['slides' => [$have[1] ?? '', $have[2] ?? '', $have[3] ?? '']]);
        break;

    /* ---------------- Telegram support username ---------------- */
    case 'save_store_links':
        // Global Store buttons: CHECK UPDATE FILE / CHECK FEEDBACK. Empty = button hidden on the Store.
        $links = [];
        foreach (['store_update_link' => 'update_link', 'store_feedback_link' => 'feedback_link'] as $setting => $field) {
            $v = trim((string)input_param($field, ''));
            if ($v !== '' && !preg_match('#^https?://#i', $v)) json_error('Links must start with http:// or https://', 422);
            if (strlen($v) > 500) json_error('Link is too long (max 500 characters).', 422);
            $links[$setting] = $v;
        }
        foreach ($links as $k => $v) set_setting($k, $v);
        json_ok($links);
        break;

    case 'save_telegram':
        $u = normalize_telegram_username(input_str('username'));
        if ($u === null) json_error('Invalid Telegram username. Use 5-32 letters, numbers or underscores, starting with a letter (with or without @).', 422);
        set_setting('telegram_username', $u);
        json_ok(['username' => $u]);
        break;

    case 'save_logo':
        // Multipart upload diagnostics are deliberately explicit so hosting
        // limits/permissions never turn into a generic "Upload failed".
        if (!isset($_FILES['logo'])) {
            json_error('No logo file received by the server. Check PHP upload settings.', 422);
        }
        $file = $_FILES['logo'];
        $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE   => 'Logo is larger than the server upload limit.',
            UPLOAD_ERR_FORM_SIZE  => 'Logo is larger than the form upload limit.',
            UPLOAD_ERR_PARTIAL    => 'Logo upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_FILE    => 'No logo file was selected.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server temporary upload directory is missing.',
            UPLOAD_ERR_CANT_WRITE => 'Server could not write the uploaded logo to disk.',
            UPLOAD_ERR_EXTENSION  => 'A server extension stopped the logo upload.',
        ];
        if ($uploadError !== UPLOAD_ERR_OK) {
            $message = $uploadErrors[$uploadError] ?? 'Logo upload failed.';
            if ($uploadError === UPLOAD_ERR_INI_SIZE) {
                $runtimeLimit = (string)ini_get('upload_max_filesize');
                $postLimit = (string)ini_get('post_max_size');
                $message = 'PHP rejected this upload before the application could receive it. Runtime upload_max_filesize='
                    . ($runtimeLimit !== '' ? $runtimeLimit : 'unknown')
                    . ', post_max_size=' . ($postLimit !== '' ? $postLimit : 'unknown')
                    . '. The project includes .user.ini with a 256M upload limit; if your host ignores it, raise the PHP upload_max_filesize/post_max_size values in hosting PHP settings.';
            }
            json_error($message, 422);
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            json_error('Invalid uploaded logo file.', 422);
        }
        $size = (int)($file['size'] ?? 0);
        if ($size <= 0) json_error('The selected logo file is empty.', 422);
        if ($size > 20 * 1024 * 1024) json_error('File too large (max 20 MB).', 422);

        // finfo is preferred, but do not make the upload depend on the
        // Fileinfo PHP extension being installed on the hosting server.
        $mime = '';
        if (class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = (string)$finfo->file($file['tmp_name']);
        }
        if ($mime === '' && function_exists('mime_content_type')) {
            $mime = (string)mime_content_type($file['tmp_name']);
        }
        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
        ];
        if (!isset($allowed[$mime])) {
            $info = @getimagesize($file['tmp_name']);
            $byType = [
                IMAGETYPE_JPEG => ['image/jpeg', 'jpg'],
                IMAGETYPE_PNG  => ['image/png', 'png'],
                IMAGETYPE_WEBP => ['image/webp', 'webp'],
            ];
            $type = is_array($info) ? (int)($info[2] ?? 0) : 0;
            if (isset($byType[$type])) {
                $mime = $byType[$type][0];
            }
        }
        if (!isset($allowed[$mime])) json_error('Invalid image type. Use PNG, JPG or WEBP.', 422);

        if (!is_dir(LOGO_PATH)) {
            if (!@mkdir(LOGO_PATH, 0755, true) && !is_dir(LOGO_PATH)) {
                json_error('Logo upload directory could not be created. Make uploads/logo writable.', 500);
            }
        }
        if (!is_writable(LOGO_PATH)) {
            // Some hosts mark the directory non-writable until the first
            // request. Try to apply normal directory permissions once.
            @chmod(LOGO_PATH, 0755);
        }
        if (!is_writable(LOGO_PATH)) {
            json_error('Logo upload directory is not writable. Make uploads/logo writable on the server.', 500);
        }

        $name = 'logo-' . bin2hex(random_bytes(12)) . '.' . $allowed[$mime];
        $dest = LOGO_PATH . '/' . $name;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            json_error('Could not save the logo file. Make uploads/logo writable and try again.', 500);
        }
        @chmod($dest, 0644);

        // One canonical DB setting is the only branding source. Every PHP
        // page calls panel_logo_url(), so no page gets its own logo copy.
        $relative = 'uploads/logo/' . $name;
        set_setting('logo_path', $relative);
        json_ok([
            'logo_url'  => base_url($relative),
            'logo_path' => $relative,
            'version'   => (int)(@filemtime($dest) ?: time()),
        ]);
        break;

    case 'clear_logo':
        set_setting('logo_path', '');
        json_ok();
        break;
}

json_error('Unknown action.', 400);