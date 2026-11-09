<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
$admin = require_admin();

/*
 * v59 - Admin > MONITOR (admin only; every POST needs the CSRF token).
 *   GET  ?view=orders      API/website provider-order monitor (states, HTTP code, response time, upstream id, refund status, reconciliation)

 *   GET  ?view=workers     real status of the 3 background workers (heartbeats written by the cron processes)
 *   GET  ?view=review      pending / overdue / uncertain orders + review history (Admin Order Review)
 *   GET  ?view=refunds     automatic refunds, failed attempts, duplicate-protection status
 *   GET  ?view=prices      price-increase alerts (grace period), duration lifecycle, price/duration history, settings, scheduler health
 *   POST action=set_custom        plan_id, enabled (0|1), price             Custom Price of ONE duration (never touches provider cost)
 *   POST action=confirm_increase  plan_id                                    apply the new calculated price now
 *   POST action=restore_duration  plan_id                                    restore a removed / reappeared duration
 *   POST action=save_settings     grace_minutes (10-30), decrease_policy (apply|hold), remove_after_checks, remove_after_minutes, auto_restore (0|1), usd_inr_rate (RESELLER rate, setting sync_usd_inr_rate), normal_usd_inr_rate (NORMAL USER rate, setting normal_usd_inr_rate); the two never overwrite each other. set_custom / confirm_increase take category=normal|reseller
 * Database only: no call to any provider is made here.
 */
$pdo = db();
$adminId = (int)$admin['id'];
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
try { pp_ensure_schema($pdo); ox_ensure_schema($pdo); or_ensure_schema($pdo); worker_ensure_schema($pdo); }
catch (Throwable $e) { error_log('[admin/monitor:schema] ' . $e->getMessage()); json_error('Database is not ready for the monitor (see server log).', 500); }

if (request_method() === 'GET') {
    $view = input_str('view', 'orders');
    if ($view === 'orders') json_ok(['monitor' => ox_monitor($pdo, 60), 'now' => time()]);
    if ($view === 'prices') {
        $ov = PriceSyncService::overview($pdo);
        json_ok(['monitor' => pp_monitor($pdo), 'now' => time(), 'scheduler' => [
            'enabled' => $ov['enabled'], 'alive' => $ov['scheduler_alive'], 'target_interval' => $ov['target_interval'], 'actual_interval' => $ov['actual_interval'],
            'last_run' => $ov['last_run'], 'last_success' => $ov['last_success'], 'last_status' => $ov['last_status'], 'note' => $ov['effective_note'],
        ]]);
    }
    if ($view === 'workers') {
        $ov = PriceSyncService::overview($pdo);
        $cy = $ov['cycle']['counts'] ?? [];
        json_ok(['workers' => worker_status_all($pdo), 'now' => time(), 'price_sync' => [
            'target_interval' => $ov['target_interval'], 'actual_interval' => $ov['actual_interval'], 'last_run' => $ov['last_run'], 'last_success' => $ov['last_success'], 'last_status' => $ov['last_status'],
            'variants_checked' => (int)($cy['checked'] ?? 0), 'prices_corrected' => (int)($cy['updated'] ?? 0), 'already_correct' => (int)($cy['already_correct'] ?? 0),
            'changes_detected' => (int)($cy['pending_increase'] ?? 0) + (int)($cy['updated'] ?? 0),
            'pending_price_updates' => (int)$pdo->query('SELECT COUNT(*) FROM product_plans WHERE price_increase_deadline IS NOT NULL')->fetchColumn() + (int)$pdo->query('SELECT COUNT(*) FROM product_plans WHERE reseller_increase_deadline IS NOT NULL')->fetchColumn(),
            'duration_ms' => (int)($cy['duration_ms'] ?? 0),
        ], 'cron_note' => 'Status comes from heartbeats written by the cron processes themselves. If a worker shows STOPPED / NEVER RAN, its cron job is not running.']);
    }
    if ($view === 'review') json_ok(['review' => or_admin_list($pdo), 'now' => time()]);
    if ($view === 'refunds') json_ok(['refunds' => or_refund_overview($pdo), 'now' => time()]);
    json_error('Unknown view.', 422);
}

csrf_verify();
$action = input_str('action');
$planId = input_int('plan_id', 0);

// v64: a category is REQUIRED for the two category-specific actions. Missing / unknown values are rejected - never defaulted to 'normal'.
$catIn = dual_cat_try(input_str('category', ''));
if (in_array($action, ['set_custom', 'confirm_increase'], true) && $catIn === null) json_error('A price category (normal or reseller) is required.', 422);

if ($action === 'set_custom') {
    if ($planId <= 0) json_error('Invalid duration.', 422);
    $enabled = input_int('enabled', 0) === 1;
    $r = pp_admin_set_custom($pdo, $planId, $enabled, $enabled ? input_str('price', '') : null, $adminId, $catIn);
    if (!$r['ok']) json_error((string)$r['error'], 422);
    json_ok(['price' => $r['price']]);
}
if ($action === 'confirm_increase') {
    if ($planId <= 0) json_error('Invalid duration.', 422);
    $r = pp_admin_confirm_increase($pdo, $planId, $adminId, $catIn);
    if (!$r['ok']) json_error((string)($r['error'] ?? 'Not applied.'), 409);
    json_ok([]);
}
if ($action === 'restore_duration') {
    if ($planId <= 0) json_error('Invalid duration.', 422);
    $r = pp_admin_restore($pdo, $planId, $adminId);
    if (!$r['ok']) json_error((string)$r['error'], 409);
    json_ok([]);
}
if ($action === 'save_settings') {
    $before = pp_config($pdo);
    $g = input_int('grace_minutes', $before['grace_min']);
    if ($g < PP_GRACE_MIN || $g > PP_GRACE_MAX) json_error('Grace period must be between ' . PP_GRACE_MIN . ' and ' . PP_GRACE_MAX . ' minutes.', 422);
    $pol = input_str('decrease_policy', $before['decrease_policy']);
    if (!in_array($pol, ['apply', 'hold'], true)) json_error('Invalid decrease policy.', 422);
    $chk = input_int('remove_after_checks', $before['remove_after_checks']);
    if ($chk < 1 || $chk > 1000) json_error('Removal threshold must be between 1 and 1000 checks (default 3).', 422);
    $min = input_int('remove_after_minutes', $before['remove_after_minutes']);
    if ($min < 0 || $min > 1440) json_error('Minimum missing time must be between 0 and 1440 minutes (default 0).', 422);
    // v61: TWO independent rates. Each setting is validated and written ONLY when its own field changed; neither write touches the other setting.
    $rate  = PriceSyncService::normalizeRate(input_str('usd_inr_rate', (string)($before['rate_reseller'] ?? '')));        // RESELLER (sync_usd_inr_rate)
    $rateN = PriceSyncService::normalizeRate(input_str('normal_usd_inr_rate', (string)($before['rate_normal'] ?? '')));    // NORMAL USER (normal_usd_inr_rate)
    if ($rate === null) json_error('Reseller exchange rate must be a number between 0.0001 and 1000 (at most 4 decimals).', 422);
    if ($rateN === null) json_error('Normal User exchange rate must be a number between 0.0001 and 1000 (at most 4 decimals).', 422);

    pp_put_setting($pdo, 'price_grace_minutes', (string)$g);
    pp_put_setting($pdo, 'price_decrease_policy', $pol);
    pp_put_setting($pdo, 'sync_remove_after_checks', (string)$chk);
    pp_put_setting($pdo, 'sync_remove_after_minutes', (string)$min);
    pp_put_setting($pdo, 'sync_auto_restore', input_int('auto_restore', $before['auto_restore'] ? 1 : 0) === 1 ? '1' : '0');
    foreach ([[DUAL_RESELLER, $rate, $before['rate_reseller']], [DUAL_NORMAL, $rateN, $before['rate_normal']]] as [$rc, $newRate, $oldRate]) {
        if ($newRate === $oldRate) continue;   // (an unset old rate is null, so a first valid value is always written)
        pp_put_setting($pdo, dual_rate_setting($rc), $newRate);
        pp_event($pdo, 'currency_rate', ['plan_id' => 0, 'name' => dual_cat_label($rc) . ' exchange rate USD to INR', 'duration' => '', 'pid' => ''],
                 ['rate' => $newRate, 'old_provider_price' => $oldRate ?? '', 'new_provider_price' => $newRate, 'currency' => 'USD', 'source' => 'admin', 'status' => 'applied', 'resolution' => 'rate_changed', 'category' => $rc,
                  'note' => 'Admin #' . $adminId . ' changed the ' . dual_cat_label($rc) . ' rate ' . ($oldRate ?? '(not set)') . ' -> ' . $newRate . '. Only ' . dual_cat_label($rc) . ' non-custom prices follow on the next price check; custom prices are kept.']);
    }
    audit_log('price.settings', 'success', ['entity_type' => 'settings', 'entity_id' => 'price_policy', 'actor_id' => $adminId,
        'reason' => 'grace=' . $g . 'm decrease=' . $pol . ' remove=' . $chk . ' checks/' . $min . 'm reseller_rate=' . $rate . ' normal_rate=' . $rateN]);
    json_ok(['config' => pp_config($pdo)]);
}
json_error('Unknown action.', 422);
