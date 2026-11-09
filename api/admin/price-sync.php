<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
$admin = require_admin();

/*
 * Admin > PRICE SYNC (admin only). v55: price verification / correction ONLY.
 *   GET  ?view=status                      settings, intervals, last cycle counters, problems (database only, no provider call)
 *   GET  ?view=history[&status=&page=]     price-change history cards (success = Corrected, failed, pending, already = Already Correct)
 *   GET  ?view=errors                      latest provider / apply errors
 *   POST action=check_now                  run ONE price-check cycle now (under the same lock as the cron job; ignores the ON/OFF toggle and interval)
 *   POST action=save_settings              enabled (0|1), interval_seconds (min 4)
 */
$pdo = db();
$adminName = (string)($admin['username'] ?? 'admin');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try { PriceSyncService::ensureSchema($pdo); PriceSyncService::ensurePriceSchema($pdo); }
catch (Throwable $e) { error_log('[price-sync:schema] ' . $e->getMessage()); json_error('Database is not ready for Price Sync (see server log).', 500); }

if (request_method() === 'GET') {
    $view = input_str('view', 'status');
    if ($view === 'status') {
        $info = provider_info(PROVIDER_LICENCEDASH);
        json_ok(['overview' => PriceSyncService::overview($pdo), 'now' => time(),
                 'provider' => ['label' => $info['label'], 'enabled' => $info['enabled'], 'configured' => $info['configured']]]);
    }
    if ($view === 'history') {
        $page = max(1, input_int('page', 1)); $per = 30;
        $r = PriceSyncService::history($pdo, $page, $per, preg_replace('/[^a-z]/', '', strtolower(input_str('status', ''))));
        json_ok(['rows' => $r['rows'], 'total' => $r['total'], 'page' => $page, 'per_page' => $per, 'rate' => PriceSyncService::settings($pdo)['rate'], 'rates' => ['reseller' => PriceSyncService::settings($pdo)['rate'], 'normal' => PriceSyncService::settings($pdo)['normal_rate']]]);
    }
    if ($view === 'errors') { json_ok(['errors' => PriceSyncService::recentErrors($pdo, 100)]); }
    json_error('Unknown view.', 422);
}

csrf_verify();
$action = input_str('action');

if ($action === 'check_now') {
    @set_time_limit(180);
    $r = PriceSyncService::tick($pdo, 'admin:' . substr($adminName, 0, 30), true, 2);
    audit_log('price_sync.manual', $r['status'] === 'failed' ? 'failure' : 'success', ['entity_type' => 'provider', 'entity_id' => 'price_sync', 'reason' => $r['message']]);
    if ($r['status'] === 'skipped') json_error($r['message'], 409);
    json_ok(['status' => $r['status'], 'message' => $r['message'], 'counts' => $r['counts'], 'overview' => PriceSyncService::overview($pdo)]);
}

if ($action === 'save_settings') {
    $has = static fn(string $k): bool => input_param($k, null) !== null;
    $enabled = $has('enabled') ? input_int('enabled', 0) === 1 : null;
    $interval = $has('interval_seconds') ? input_int('interval_seconds', PriceSyncService::DEFAULT_INTERVAL) : null;
    if ($interval !== null && ($interval < PriceSyncService::MIN_INTERVAL || $interval > 86400)) json_error('Interval must be between ' . PriceSyncService::MIN_INTERVAL . ' and 86400 seconds.', 422);
    PriceSyncService::saveSettings($pdo, $enabled, $interval);
    audit_log('price_sync.settings', 'success', ['entity_type' => 'provider', 'entity_id' => 'price_sync', 'reason' => 'enabled=' . (PriceSyncService::settings($pdo)['enabled'] ? '1' : '0') . ' interval=' . PriceSyncService::settings($pdo)['interval_sec'] . 's']);
    json_ok(['overview' => PriceSyncService::overview($pdo)]);
}

json_error('Unknown action.', 422);
