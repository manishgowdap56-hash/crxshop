<?php
declare(strict_types=1);

/*
 * v59 - PRICE POLICY + DURATION LIFECYCLE (extends the v55 PriceSyncService; the Price Sync cron/lock/page architecture is unchanged)
 *
 * Six values are kept apart for every duration (product_plans):
 *   1 provider original price      provider_price            (never touched by a custom price)
 *   2 provider currency            provider_currency
 *   3 exchange rate used           price_rate                (setting sync_usd_inr_rate, default 30)
 *   4 calculated provider INR      inr_cost                  = provider_price x rate, converted ONCE, no markup
 *   5 admin custom selling price   custom_price (+ custom_price_enabled)
 *   6 customer-facing price        price                     (the ONLY column every purchase path reads - no reader had to change)
 *
 * Provider price INCREASE: price (6) is NOT changed. An event row (price_change_events) + admin alert are created with a deadline
 * (grace, default 25 min, 20-30). Admin may confirm / set a custom price inside the window. At the deadline the new calculated price is
 * applied automatically (see pp_apply_expired for the custom-price rule). Money maths: integer micro-units via PriceSyncService, never floats.
 *
 * Duration REMOVAL: a mapped service id absent from N consecutive COMPLETE catalog reads (and for a minimum time) is archived:
 * enabled = 0, sync_removed = 1, lifecycle_state = 'removed'. Nothing is deleted; orders/ledger/history/mapping/custom price stay.
 * REAPPEARANCE: alert + 'reappeared' state; restored only by an admin action (or automatically when the admin enabled sync_auto_restore).
 */

const PP_GRACE_MIN = 10;      // v61: review window, admin-configurable (setting price_grace_minutes), default 15 minutes
const PP_GRACE_MAX = 30;
const PP_GRACE_DEFAULT = 15;
const PP_REMOVE_CHECKS_DEFAULT = 3;

/* ------------------------------------------------------------------ */
/*  Settings (fresh reads, validated)                                  */
/* ------------------------------------------------------------------ */

function pp_setting(PDO $pdo, string $key, string $default): string
{
    try {
        $q = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = :k LIMIT 1');
        $q->execute([':k' => $key]);
        $v = $q->fetchColumn();
        return ($v === false || $v === null || $v === '') ? $default : (string)$v;
    } catch (Throwable $e) { return $default; }
}

function pp_put_setting(PDO $pdo, string $key, string $value): void
{
    $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')->execute([':k' => $key, ':v' => $value]);
    if (function_exists('settings_reload')) settings_reload();
}

/** @return array{grace_min:int, decrease_policy:string, remove_after_checks:int, remove_after_minutes:int, auto_restore:bool, rate:?string, rate_reseller:?string, rate_normal:?string} */
function pp_config(PDO $pdo): array
{
    $grace = (int)pp_setting($pdo, 'price_grace_minutes', (string)PP_GRACE_DEFAULT);
    $grace = max(PP_GRACE_MIN, min(PP_GRACE_MAX, $grace ?: PP_GRACE_DEFAULT));
    $dec = pp_setting($pdo, 'price_decrease_policy', 'apply');
    // v64: each rate comes ONLY from its own setting; missing / invalid => null. No fallback to the other category and no silent default.
    $rate  = dual_rate($pdo, DUAL_RESELLER);   // RESELLER rate (sync_usd_inr_rate) or null
    $rateN = dual_rate($pdo, DUAL_NORMAL);     // NORMAL USER rate (normal_usd_inr_rate) or null
    return [
        'grace_min'            => $grace,
        'decrease_policy'      => in_array($dec, ['apply', 'hold'], true) ? $dec : 'apply',          // apply = non-custom prices follow a decrease; hold = alert only
        'remove_after_checks'  => max(1, min(1000, (int)pp_setting($pdo, 'sync_remove_after_checks', (string)PP_REMOVE_CHECKS_DEFAULT))),   // v60: default 3 consecutive COMPLETE catalog checks
        'remove_after_minutes' => max(0, min(1440, (int)pp_setting($pdo, 'sync_remove_after_minutes', '0'))),                                  // v60: no extra time condition by default
        'auto_restore'         => pp_setting($pdo, 'sync_auto_restore', '1') === '1',                                                          // v60: restore after validation (price/mapping valid)
        'rate'                 => $rate,        // = reseller rate (kept under the old key for existing readers)
        'rate_reseller'        => $rate,
        'rate_normal'          => $rateN,
    ];
}

/* ------------------------------------------------------------------ */
/*  Schema                                                             */
/* ------------------------------------------------------------------ */

/** Additive + repeatable. Mirrors database/migration_v59_reliability_pricing.sql. MUST run outside a transaction. */
function pp_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    if ($pdo->inTransaction()) throw new RuntimeException('pp_ensure_schema must run outside a transaction');
    $cols = [
        'custom_price_enabled'       => 'TINYINT(1) NOT NULL DEFAULT 0',
        'custom_price'               => 'DECIMAL(12,2) DEFAULT NULL',
        'provider_last_valid_price'  => 'DECIMAL(12,4) DEFAULT NULL',
        'provider_missing_count'     => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'provider_missing_since'     => 'DATETIME DEFAULT NULL',
        'provider_last_seen_at'      => 'DATETIME DEFAULT NULL',
        'provider_removed_at'        => 'DATETIME DEFAULT NULL',
        'lifecycle_state'            => "VARCHAR(12) NOT NULL DEFAULT 'active'",
        'price_increase_detected_at' => 'DATETIME DEFAULT NULL',
        'price_increase_deadline'    => 'DATETIME DEFAULT NULL',
        'pending_provider_price'     => 'DECIMAL(12,4) DEFAULT NULL',
        'pending_calculated_inr'     => 'DECIMAL(12,2) DEFAULT NULL',
        'price_change_source'        => 'VARCHAR(20) DEFAULT NULL',
        'sync_removed'               => 'TINYINT(1) NOT NULL DEFAULT 0',
        'price_change_version'       => 'INT UNSIGNED NOT NULL DEFAULT 0',
    ];
    foreach ($cols as $c => $def) rp_add_column($pdo, 'product_plans', $c, $def);
    $pdo->exec("CREATE TABLE IF NOT EXISTS price_change_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        event_type VARCHAR(24) NOT NULL,
        plan_id INT UNSIGNED NOT NULL,
        product_id INT UNSIGNED DEFAULT NULL,
        product_name VARCHAR(160) DEFAULT NULL,
        duration VARCHAR(60) DEFAULT NULL,
        provider_variant_id VARCHAR(60) DEFAULT NULL,
        old_provider_price DECIMAL(12,4) DEFAULT NULL,
        new_provider_price DECIMAL(12,4) DEFAULT NULL,
        provider_currency VARCHAR(8) DEFAULT NULL,
        rate DECIMAL(12,4) DEFAULT NULL,
        old_inr DECIMAL(12,2) DEFAULT NULL,
        new_inr DECIMAL(12,2) DEFAULT NULL,
        old_selling_price DECIMAL(12,2) DEFAULT NULL,
        new_selling_price DECIMAL(12,2) DEFAULT NULL,
        custom_price DECIMAL(12,2) DEFAULT NULL,
        source VARCHAR(20) NOT NULL DEFAULT 'sync',
        status VARCHAR(16) NOT NULL DEFAULT 'info',
        detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        deadline_at DATETIME DEFAULT NULL,
        resolved_at DATETIME DEFAULT NULL,
        resolved_by INT UNSIGNED DEFAULT NULL,
        resolution VARCHAR(40) DEFAULT NULL,
        note VARCHAR(255) DEFAULT NULL,
        PRIMARY KEY (id),
        KEY idx_pce_plan (plan_id, status),
        KEY idx_pce_status (status, deadline_at),
        KEY idx_pce_type (event_type, detected_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    rp_add_column($pdo, 'price_change_events', 'change_version', 'INT UNSIGNED DEFAULT NULL');
    rp_add_column($pdo, 'price_change_events', 'pct_diff', 'DECIMAL(8,2) DEFAULT NULL');
    rp_add_index($pdo, 'product_plans', 'idx_plan_lifecycle', '(`lifecycle_state`)');
    // one-time (v60): move the v59 defaults (30 checks + 5 minutes, auto-restore off) to the required defaults (3 checks, no time condition, auto-restore with validation).
    if (pp_setting($pdo, 'pp_defaults_v60', '') !== '1') {
        if (pp_setting($pdo, 'sync_remove_after_checks', '') === '30') pp_put_setting($pdo, 'sync_remove_after_checks', '3');
        if (pp_setting($pdo, 'sync_remove_after_minutes', '') === '5') pp_put_setting($pdo, 'sync_remove_after_minutes', '0');
        if (pp_setting($pdo, 'sync_auto_restore', '') === '0') pp_put_setting($pdo, 'sync_auto_restore', '1');
        pp_put_setting($pdo, 'pp_defaults_v60', '1');
    }
    $done = true;
}

/** Cheap per-request guard (one information_schema query) used by bootstrap, so listings/readers never hit a missing column. */
function pp_ensure_schema_if_needed(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $q = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_plans'
                          AND COLUMN_NAME IN ('custom_price_enabled','custom_price','provider_last_valid_price','provider_missing_count','provider_missing_since','provider_last_seen_at','provider_removed_at','lifecycle_state','price_increase_detected_at','price_increase_deadline','pending_provider_price','pending_calculated_inr','price_change_source','sync_removed','price_change_version')");
        $t = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'price_change_events'");
        if ((int)$q->fetchColumn() >= 15 && (int)$t->fetchColumn() === 1) return;
        pp_ensure_schema($pdo);
    } catch (Throwable $e) { error_log('[pp_ensure_schema_if_needed] ' . $e->getMessage()); }
}

/* ------------------------------------------------------------------ */
/*  Pure helpers (unit-testable without a database)                    */
/* ------------------------------------------------------------------ */

/** "12", "12.5", "12.50" -> "12.50"; anything else (negative, 0, text, >1,000,000, >2 decimals) -> null. */
function pp_money_ok($v): ?string
{
    if ($v === null || is_bool($v) || is_array($v)) return null;
    $s = trim((string)$v);
    if (!preg_match('/^([0-9]{1,7})(?:\.([0-9]{1,2}))?$/D', $s, $m)) return null;
    $paise = (int)$m[1] * 100 + (int)str_pad($m[2] ?? '', 2, '0');
    if ($paise <= 0) return null;
    return sprintf('%d.%02d', intdiv($paise, 100), $paise % 100);
}

/**
 * Decide what a freshly read, VALID provider price means for one duration. Pure.
 * @param ?int   $storedMicro  provider price the current selling price is based on (null = unknown / first check)
 * @param int    $newMicro     provider price now (already validated > 0)
 * @param string $oldInr       stored calculated INR ("9.00") or ''
 * @param string $newInr       freshly calculated INR
 * @return string one of: first | same | rate_change | increase | decrease
 */
function pp_classify_change(?int $storedMicro, int $newMicro, string $oldInr, string $newInr): string
{
    if ($storedMicro === null) return 'first';
    if ($newMicro > $storedMicro) return 'increase';
    if ($newMicro < $storedMicro) return 'decrease';
    return ($oldInr !== '' && $oldInr !== $newInr) ? 'rate_change' : 'same';
}

/** Missing-duration decision. Pure. true = archive now. */
function pp_should_remove(int $missingCount, ?int $missingSinceTs, int $now, int $needChecks, int $needMinutes): bool
{
    if ($missingSinceTs === null) return false;
    return $missingCount >= $needChecks && ($now - $missingSinceTs) >= $needMinutes * 60;
}

/** Percentage change old -> new (provider micro-units), 2 decimals, integer maths. Null when old is missing/zero. Pure. */
function pp_pct_diff(?int $oldMicro, int $newMicro): ?string
{
    if ($oldMicro === null || $oldMicro <= 0) return null;
    $bp = intdiv(($newMicro - $oldMicro) * 20000 + ($newMicro >= $oldMicro ? $oldMicro : -$oldMicro), 2 * $oldMicro);   // basis points, rounded half away from zero
    return sprintf('%s%d.%02d', $bp < 0 ? '-' : '', intdiv(abs($bp), 100), abs($bp) % 100);
}

/** Catalog sanity: refuse removal decisions when an implausibly large share of mapped durations vanished in one read (partial/garbled catalog). */
function pp_catalog_plausible(int $mapped, int $missingNow): bool
{
    if ($mapped < 4) return true;                      // tiny sets: cannot tell, the N-consecutive-checks rule protects
    return $missingNow <= max(3, (int)floor($mapped * 0.5));
}

/** The price a duration is sold at: custom when enabled and valid, else the provider-based calculated price. Pure. */
function pp_effective_price(bool $customEnabled, ?string $custom, ?string $calculated): ?string
{
    $c = pp_money_ok($custom);
    if ($customEnabled && $c !== null) return $c;
    return pp_money_ok($calculated);
}

function pp_plan_is_removed_row(array $plan): bool
{
    return (int)($plan['sync_removed'] ?? 0) === 1 || (string)($plan['lifecycle_state'] ?? '') === 'removed';
}

/** Server-side guard used by EVERY order endpoint (website checkout, reseller API buy). */
function plan_is_removed(PDO $pdo, int $planId): bool
{
    try {
        $q = $pdo->prepare('SELECT sync_removed, lifecycle_state FROM product_plans WHERE id = :id LIMIT 1');
        $q->execute([':id' => $planId]);
        $r = $q->fetch();
        return $r ? pp_plan_is_removed_row($r) : false;
    } catch (Throwable $e) { return false; }   // column missing = pre-v59 data = nothing was ever removed
}

/* ------------------------------------------------------------------ */
/*  Events + history                                                   */
/* ------------------------------------------------------------------ */

function pp_event(PDO $pdo, string $type, array $row, array $f = []): int
{
    try {
        $pdo->prepare(
            'INSERT INTO price_change_events (event_type, plan_id, product_id, product_name, duration, provider_variant_id, old_provider_price, new_provider_price, provider_currency, rate,
                                              old_inr, new_inr, old_selling_price, new_selling_price, custom_price, source, status, detected_at, deadline_at, resolved_at, resolution, note, price_category)
             VALUES (:t, :pl, :p, :pn, :d, :v, :opp, :npp, :cur, :rate, :oi, :ni, :osp, :nsp, :cp, :src, :st, NOW(), :dl, :ra, :res, :note, :cat)'
        )->execute([
            ':t' => $type, ':pl' => (int)$row['plan_id'], ':p' => isset($row['product_id']) ? (int)$row['product_id'] : null,
            ':pn' => mb_substr((string)($row['name'] ?? ''), 0, 160), ':d' => mb_substr((string)($row['duration'] ?? ''), 0, 60), ':v' => (string)($row['pid'] ?? ''),
            ':opp' => $f['old_provider_price'] ?? null, ':npp' => $f['new_provider_price'] ?? null, ':cur' => $f['currency'] ?? null, ':rate' => $f['rate'] ?? null,
            ':oi' => $f['old_inr'] ?? null, ':ni' => $f['new_inr'] ?? null, ':osp' => $f['old_selling'] ?? null, ':nsp' => $f['new_selling'] ?? null, ':cp' => $f['custom_price'] ?? null,
            ':src' => substr((string)($f['source'] ?? 'sync'), 0, 20), ':st' => substr((string)($f['status'] ?? 'info'), 0, 16),
            ':dl' => $f['deadline'] ?? null, ':ra' => ($f['status'] ?? 'info') === 'pending' ? null : date('Y-m-d H:i:s'),
            ':res' => $f['resolution'] ?? null, ':note' => isset($f['note']) ? mb_substr((string)$f['note'], 0, 255) : null,
            ':cat' => dual_cat_valid((string)($f['category'] ?? DUAL_NORMAL)),
        ]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) { error_log('[pp_event] ' . $e->getMessage()); return 0; }
}

/* ------------------------------------------------------------------ */
/*  Price decision for one duration (called by PriceSyncService::cycle) */
/* ------------------------------------------------------------------ */

/**
 * v61: the per-category view of a loadLocal() row. The Normal User category lives in the original columns, the Reseller category in the
 * reseller_* columns (dual_cat_cols). The view exposes the ORIGINAL key names so the price logic below is written once and runs per category.
 * @return array the row with price / custom_price / custom_price_enabled / price_rate / inr_cost / provider_price / pending_* / price_increase_deadline / price_manual
 *               replaced by the values of $cat, plus 'price_category'.
 */
function pp_row_view(array $row, string $cat): array
{
    $cat = dual_cat_valid($cat);
    $c = dual_cat_cols($cat);
    $map = ['price' => 'price', 'custom_price' => 'custom', 'custom_price_enabled' => 'custom_on', 'price_rate' => 'rate', 'inr_cost' => 'inr', 'provider_price' => 'pprice',
            'pending_provider_price' => 'pend_pp', 'pending_calculated_inr' => 'pend_inr', 'price_increase_deadline' => 'deadline', 'price_manual' => 'manual'];
    $v = $row;
    foreach ($map as $canon => $key) {
        $v[$canon] = $cat === DUAL_NORMAL ? ($row[$canon] ?? null) : ($row[$c[$key]] ?? null);
    }
    if ($cat === DUAL_RESELLER && ($v['price'] === null || $v['price'] === '')) $v['price'] = $row['price'] ?? null;   // unseeded legacy row: compare against the normal price, never 0
    $v['price_category'] = $cat;
    return $v;
}

/** The exchange rate (validated string) a category uses. Normal = normal_usd_inr_rate, Reseller = sync_usd_inr_rate. Never crosses over. */
function pp_cat_rate(PDO $pdo, string $cat): ?string
{
    return dual_rate($pdo, $cat);
}

/** Delete-safe alert key per category. */
function pp_alert_key(string $cat, int $plan, int $ver, int $evId): string
{
    return 'price_increase:' . dual_cat_valid($cat) . ':' . $plan . ':' . $ver . ':' . $evId;
}

/**
 * @param array  $row  pp_row_view() of a PriceSyncService::loadLocal row (the category being processed)
 * @param array  $wp   PriceSyncService::websitePrice() result for THIS category's rate, 'inr' + 'micro' both non-null
 * @param string $cat  'normal' | 'reseller'
 * @return array{result:string, source?:string, event?:string, ev?:array, wp?:array}
 *   result: handled (review period opened) | already (nothing to change) | failed | apply (cycle() applies wp via PriceSyncService::correct(), then logs the event)
 */
function pp_handle_price(PDO $pdo, string $run, string $actor, array $row, array $wp, array $it, array $cfg, string $cat = DUAL_NORMAL): array
{
    $cat = dual_cat_valid($cat);
    $plan = (int)$row['plan_id'];
    $storedMicro = ($row['provider_price'] === null || $row['provider_price'] === '') ? null : PriceSyncService::toMicro($row['provider_price']);
    $oldInr = ($row['inr_cost'] === null || $row['inr_cost'] === '') ? '' : number_format((float)$row['inr_cost'], 2, '.', '');
    // Compare at the precision the database stores (4 decimals) so a 6-decimal provider price can never look like a change every cycle.
    $newMicro4 = PriceSyncService::toMicro((string)PriceSyncService::microToDec((int)$wp['micro'])) ?? (int)$wp['micro'];
    $wp['micro'] = $newMicro4;
    $kind = pp_classify_change($storedMicro, $newMicro4, $oldInr, (string)$wp['inr']);
    $customOn = (int)($row['custom_price_enabled'] ?? 0) === 1 && pp_money_ok($row['custom_price'] ?? null) !== null;
    $selling = number_format((float)$row['price'], 2, '.', '');
    $ev = [
        'old_provider_price' => $storedMicro === null ? null : PriceSyncService::microToDec($storedMicro), 'new_provider_price' => PriceSyncService::microToDec((int)$wp['micro']),
        'currency' => $wp['currency'], 'rate' => $wp['rate'], 'old_inr' => $oldInr !== '' ? $oldInr : null, 'new_inr' => $wp['inr'], 'old_selling' => $selling,
        'custom_price' => $customOn ? pp_money_ok($row['custom_price']) : null, 'category' => $cat,
    ];

    // Always remember the last VALID provider price we saw (shared, informational; both categories see the same provider price).
    try {
        $pdo->prepare('UPDATE product_plans SET provider_last_valid_price = :v, provider_last_seen_at = NOW() WHERE id = :id')->execute([':v' => PriceSyncService::microToDec((int)$wp['micro']), ':id' => $plan]);
    } catch (Throwable $e) { error_log('[pp_handle_price:seen] ' . $e->getMessage()); }

    if ($kind === 'increase' && $selling !== '' && (float)$selling > 0) {
        // v61: an increase ALWAYS opens a review window - for a custom price too. The admin's price is never overwritten immediately.
        return ['result' => pp_open_increase($pdo, $run, $row, $wp, $ev, $cfg, $customOn, $cat)];
    }
    // A provider price that is no longer higher than the stored one cancels a running review for this duration + category.
    if ((string)($row['price_increase_deadline'] ?? '') !== '' && $kind !== 'increase') {
        pp_resolve_pending($pdo, $plan, 'reverted', 'Provider price returned to the stored value; the increase was cancelled.', null, $cat);
    }

    if ($kind === 'decrease') {
        // Explicit policy (price_decrease_policy). A custom price is NEVER lowered by a provider decrease.
        if ($customOn || $cfg['decrease_policy'] === 'hold') {
            pp_event($pdo, 'provider_decrease', $row, $ev + ['source' => 'sync', 'status' => 'info', 'resolution' => $customOn ? 'custom_price_kept' : 'held_by_policy',
                     'new_selling' => $selling, 'note' => $customOn ? 'Custom selling price kept.' : 'Decrease policy is HOLD: selling price kept.']);
            return ['result' => pp_store_meta($pdo, $row, $wp, $cat) ? 'already' : 'failed'];
        }
        return ['result' => 'apply', 'source' => 'sync_decrease', 'event' => 'provider_decrease', 'ev' => $ev, 'wp' => $wp];
    }

    if ($customOn) {
        // Custom price active: only the provider/calculated columns are refreshed; the selling price is the admin's.
        if ($kind === 'rate_change') pp_event($pdo, 'rate_change', $row, $ev + ['status' => 'info', 'new_selling' => $selling, 'resolution' => 'custom_price_kept', 'note' => 'Exchange rate changed; custom price kept.']);
        return ['result' => pp_store_meta($pdo, $row, $wp, $cat) ? 'already' : 'failed'];
    }

    // No custom price: the website price follows provider price x this category's rate (v55 rule). Equal = nothing to do.
    if ($selling === $wp['inr']) {
        return ['result' => pp_store_meta($pdo, $row, $wp, $cat) ? 'already' : 'failed'];
    }
    return ['result' => 'apply', 'source' => $kind === 'rate_change' ? 'rate_change' : 'sync_correct', 'event' => $kind === 'rate_change' ? 'rate_change' : 'price_corrected', 'ev' => $ev, 'wp' => $wp];
}

/** Refresh provider price / currency / rate / calculated INR of ONE category WITHOUT touching its selling price. Returns false on a database error. */
function pp_store_meta(PDO $pdo, array $row, array $wp, string $cat = DUAL_NORMAL): bool
{
    $c = dual_cat_cols(dual_cat_valid($cat));
    try {
        $pdo->prepare('UPDATE product_plans SET `' . $c['pprice'] . '` = :pp, provider_currency = :pc, `' . $c['rate'] . '` = :pr, `' . $c['inr'] . '` = :ic WHERE id = :id')
            ->execute([':pp' => PriceSyncService::microToDec((int)$wp['micro']), ':pc' => $wp['currency'], ':pr' => $wp['rate'], ':ic' => $wp['inr'], ':id' => (int)$row['plan_id']]);
        return true;
    } catch (Throwable $e) { error_log('[pp_store_meta] ' . $e->getMessage()); return false; }
}

function pp_event_set_pct(PDO $pdo, int $eventId, ?string $pct, ?int $version): void
{
    try { $pdo->prepare('UPDATE price_change_events SET pct_diff = :p, change_version = :v WHERE id = :id')->execute([':p' => $pct, ':v' => $version, ':id' => $eventId]); }
    catch (Throwable $e) { error_log('[pp_event_set_pct] ' . $e->getMessage()); }
}

/* ------------------------------------------------------------------ */
/*  Increase alert + review period                                     */
/* ------------------------------------------------------------------ */

/**
 * Provider cost went UP for ONE category: open (or update) the review period. A custom price is treated like any other price: it is NOT
 * overwritten now, the admin has the whole review window to change it, and only when the window ends WITHOUT an admin change is the new
 * provider-derived price applied (pp_apply_expired).
 * VERSIONING: every newer pending price bumps the category's price_change_version and the pending event carries the same number;
 * the automatic update applies ONLY the pending price whose version still matches (an outdated first price can never be applied).
 * The original deadline is kept when a newer price arrives (a moving provider price cannot postpone the update forever).
 */
function pp_open_increase(PDO $pdo, string $run, array $row, array $wp, array $ev, array $cfg, bool $customOn, string $cat = DUAL_NORMAL): string
{
    $cat = dual_cat_valid($cat);
    $c = dual_cat_cols($cat);
    $plan = (int)$row['plan_id'];
    $storedMicro = ($row['provider_price'] === null || $row['provider_price'] === '') ? null : PriceSyncService::toMicro($row['provider_price']);
    $pct = pp_pct_diff($storedMicro, (int)$wp['micro']);
    try {
        $existing = (string)($row['price_increase_deadline'] ?? '') !== '';
        if ($existing) {
            $samePending = PriceSyncService::microToDec((int)$wp['micro']) === number_format((float)($row['pending_provider_price'] ?? 0), 4, '.', '');
            if ($samePending) return 'already';                      // nothing new: no write, no version bump
            $pdo->beginTransaction();
            // only while the review is still open for this plan + category (an admin who just acted cleared the deadline)
            $u = $pdo->prepare('UPDATE product_plans SET `' . $c['pend_pp'] . '` = :pp, `' . $c['pend_inr'] . '` = :pi, `' . $c['version'] . '` = `' . $c['version'] . '` + 1 WHERE id = :id AND `' . $c['deadline'] . '` IS NOT NULL');
            $u->execute([':pp' => PriceSyncService::microToDec((int)$wp['micro']), ':pi' => $wp['inr'], ':id' => $plan]);
            if ($u->rowCount() < 1) { $pdo->rollBack(); return 'already'; }
            $v = $pdo->prepare('SELECT `' . $c['version'] . '` FROM product_plans WHERE id = :id'); $v->execute([':id' => $plan]); $ver = (int)$v->fetchColumn();
            $pdo->prepare("UPDATE price_change_events SET new_provider_price = :pp, new_inr = :pi, rate = :r, pct_diff = :pct, change_version = :ver, note = 'A newer provider price replaced the pending one; only the latest is applied.'
                           WHERE plan_id = :id AND price_category = :cat AND status = 'pending' AND event_type = 'provider_increase'")
                ->execute([':pp' => PriceSyncService::microToDec((int)$wp['micro']), ':pi' => $wp['inr'], ':r' => $wp['rate'], ':pct' => $pct, ':ver' => $ver, ':id' => $plan, ':cat' => $cat]);
            $pdo->commit();
            return 'already';
        }
        $deadline = date('Y-m-d H:i:s', time() + $cfg['grace_min'] * 60);   // server clock; cron + DB only, no browser timer
        $pdo->beginTransaction();
        // guard: only open when no review is open for this plan + category (a second cron process / double cycle cannot create a duplicate)
        $u = $pdo->prepare('UPDATE product_plans SET `' . $c['pend_pp'] . '` = :pp, `' . $c['pend_inr'] . '` = :pi, `' . $c['detected'] . '` = NOW(), `' . $c['deadline'] . '` = :dl, `' . $c['source'] . '` = :s, `' . $c['version'] . '` = `' . $c['version'] . '` + 1
                            WHERE id = :id AND `' . $c['deadline'] . '` IS NULL');
        $u->execute([':pp' => PriceSyncService::microToDec((int)$wp['micro']), ':pi' => $wp['inr'], ':dl' => $deadline, ':s' => 'pending_increase', ':id' => $plan]);
        if ($u->rowCount() < 1) { $pdo->rollBack(); return 'already'; }
        $v = $pdo->prepare('SELECT `' . $c['version'] . '` FROM product_plans WHERE id = :id'); $v->execute([':id' => $plan]); $ver = (int)$v->fetchColumn();
        $evId = pp_event($pdo, 'provider_increase', $row, $ev + ['source' => 'sync', 'status' => 'pending', 'deadline' => $deadline, 'new_selling' => null,
                         'note' => ($customOn ? 'Custom price in place; it is kept during the review. ' : '') . 'Selling price unchanged until the review ends or the admin acts.']);
        if ($evId > 0) pp_event_set_pct($pdo, $evId, $pct, $ver);
        $pdo->commit();
        alert_raise([
            'dedupe_key' => pp_alert_key($cat, $plan, $ver, $evId), 'type' => 'price_increase', 'severity' => 'critical',
            'product_id' => (int)$row['product_id'], 'product_name' => (string)$row['name'], 'plan_id' => $plan, 'duration' => (string)$row['duration'],
            'provider_key' => PriceSyncService::PROVIDER, 'provider_label' => provider_info(PROVIDER_LICENCEDASH)['label'],
            'reason' => strtoupper(dual_cat_label($cat)) . ' PRICE INCREASE DETECTED. Old provider price ' . ($ev['old_provider_price'] ?? '?') . ' ' . $wp['currency'] . ' -> new ' . $ev['new_provider_price'] . ' ' . $wp['currency']
                      . ($pct !== null ? ' (+' . ltrim($pct, '+') . '%)' : '') . '. Rate ' . $wp['rate'] . '. Old calculated INR ' . ($ev['old_inr'] ?? '?') . ' -> new ' . $wp['inr']
                      . '. Current ' . dual_cat_label($cat) . ' price INR ' . $ev['old_selling'] . ($customOn ? ' (custom)' : '') . '. Detected ' . date('Y-m-d H:i:s') . '. Automatic update at ' . $deadline . '.',
            'recommended_action' => 'Admin action before ' . $deadline . ': Admin -> Monitor -> Price Review -> set the ' . dual_cat_label($cat) . ' price (Custom Price) or apply the new price. Otherwise the new provider-derived price is applied automatically.',
        ]);
        return 'handled';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[pp_open_increase] ' . $e->getMessage());
        return 'failed';
    }
}

/** Close the running review of one duration + category (event row + alert). Does not change the selling price. */
function pp_resolve_pending(PDO $pdo, int $planId, string $resolution, string $note, ?int $adminId, string $cat = DUAL_NORMAL): void
{
    $cat = dual_cat_valid($cat);
    $c = dual_cat_cols($cat);
    try {
        $pdo->prepare("UPDATE price_change_events SET status = 'resolved', resolution = :r, note = :n, resolved_at = NOW(), resolved_by = :a WHERE plan_id = :id AND price_category = :cat AND status = 'pending' AND event_type = 'provider_increase'")
            ->execute([':r' => substr($resolution, 0, 40), ':n' => mb_substr($note, 0, 255), ':a' => $adminId, ':id' => $planId, ':cat' => $cat]);
        $pdo->prepare('UPDATE product_plans SET `' . $c['detected'] . '` = NULL, `' . $c['deadline'] . '` = NULL, `' . $c['pend_pp'] . '` = NULL, `' . $c['pend_inr'] . '` = NULL WHERE id = :id')->execute([':id' => $planId]);
        $like = $cat === DUAL_RESELLER ? "open_key LIKE 'price_increase:reseller:%'" : "open_key NOT LIKE 'price_increase:reseller:%'";   // pre-v61 alerts (no category in the key) belong to Normal
        $q = $pdo->prepare("SELECT open_key FROM admin_alerts WHERE plan_id = :id AND alert_type = 'price_increase' AND status = 'open' AND " . $like);
        $q->execute([':id' => $planId]);
        foreach ($q->fetchAll() as $a) alert_resolve_key((string)$a['open_key'], 'Price increase (' . dual_cat_label($cat) . '): ' . $resolution, $adminId);
    } catch (Throwable $e) { error_log('[pp_resolve_pending] ' . $e->getMessage()); }
}

/** Resolve every category's review of a duration (duration removed by the provider). */
function pp_resolve_pending_all(PDO $pdo, int $planId, string $resolution, string $note, ?int $adminId): void
{
    foreach (DUAL_CATS as $cat) pp_resolve_pending($pdo, $planId, $resolution, $note, $adminId, $cat);
}

/**
 * Review over: apply the latest pending provider-derived price of each category whose review deadline has passed (that category's own rate,
 * exact decimals). v61: a Custom Price is NOT exempt - if the admin did not change anything inside the window the new calculated price is applied
 * and the custom flag is switched off (the custom VALUE is kept so the admin can re-enable it). An admin change inside the window already cleared
 * the deadline in the same row-locked transaction (pp_admin_set_custom), so a newer admin price can never be overwritten here.
 * Version guard: applied only when the pending event carries the same price_change_version as the plan (the newest pending price).
 * Safe to run every cycle and with a failed catalog read: it uses only values stored by an earlier successful read.
 * @return int number of durations settled (both categories)
 */
function pp_apply_expired(PDO $pdo, string $run, ?string $onlyCat = null): int
{
    $n = 0;
    foreach (DUAL_CATS as $cat) {
        if ($onlyCat !== null && $onlyCat !== $cat) continue;
        $c = dual_cat_cols($cat);
        try {
            $rows = $pdo->query("SELECT pl.id AS plan_id, pl.product_id, pl.duration, pl.provider_currency, pl.provider_variant_id, p.name
                                 FROM product_plans pl JOIN products p ON p.id = pl.product_id
                                 WHERE pl.`" . $c['deadline'] . "` IS NOT NULL AND pl.`" . $c['deadline'] . "` <= NOW() ORDER BY pl.id LIMIT 100")->fetchAll();
            $rate = pp_cat_rate($pdo, $cat);
            foreach ($rows as $r) {
                $plan = (int)$r['plan_id'];
                $resolution = ''; $final = ''; $old = ''; $newInr = ''; $micro = null; $cur = strtoupper((string)($r['provider_currency'] ?: 'USD')); $customOn = false; $customVal = null;
                try {
                    $pdo->beginTransaction();
                    $q = $pdo->prepare('SELECT `' . $c['price'] . '` AS price, `' . $c['custom'] . '` AS custom_price, `' . $c['custom_on'] . '` AS custom_on, `' . $c['deadline'] . '` AS dl, `' . $c['pend_pp'] . '` AS pend_pp, `' . $c['version'] . '` AS ver
                                        FROM product_plans WHERE id = :id FOR UPDATE');
                    $q->execute([':id' => $plan]); $cur0 = $q->fetch();
                    if (!$cur0 || (string)$cur0['dl'] === '') { $pdo->rollBack(); continue; }      // already handled by an admin / another process
                    $q2 = $pdo->prepare('SELECT 1 FROM product_plans WHERE id = :id AND `' . $c['deadline'] . '` <= NOW()');   // re-check under the lock
                    $q2->execute([':id' => $plan]);
                    if (!$q2->fetchColumn()) { $pdo->rollBack(); continue; }
                    $micro = PriceSyncService::priceMicroOk($cur0['pend_pp']);
                    if ($micro !== null && $cur !== 'INR' && $rate === null) {   // v64: this category's own rate is not configured: do NOT apply and do NOT cancel the review; keep the stored price
                        $pdo->rollBack();
                        error_log('[pp_apply_expired] plan ' . $plan . ' ' . $cat . ': ' . dual_cat_label($cat) . ' exchange rate is missing or invalid; pending price not applied');
                        continue;
                    }
                    $newInr = $micro === null ? null : ($cur === 'INR' ? PriceSyncService::convertToInr($micro, '1') : PriceSyncService::convertToInr($micro, (string)$rate));
                    if ($micro === null || $newInr === null) { $pdo->rollBack(); pp_resolve_pending($pdo, $plan, 'invalid_pending', 'No valid pending price; selling price kept.', null, $cat); continue; }
                    $e = $pdo->prepare("SELECT id, change_version FROM price_change_events WHERE plan_id = :id AND price_category = :cat AND status = 'pending' AND event_type = 'provider_increase' ORDER BY id DESC LIMIT 1 FOR UPDATE");
                    $e->execute([':id' => $plan, ':cat' => $cat]); $ev = $e->fetch();
                    if ($ev && $ev['change_version'] !== null && (int)$ev['change_version'] !== (int)$cur0['ver']) {
                        $pdo->rollBack();
                        error_log('[pp_apply_expired] plan ' . $plan . ' ' . $cat . ': pending event version ' . $ev['change_version'] . ' != plan version ' . $cur0['ver'] . '; not applied');
                        continue;                                                                                    // never apply an outdated price
                    }
                    $customOn = (int)$cur0['custom_on'] === 1 && pp_money_ok($cur0['custom_price']) !== null;
                    $customVal = $customOn ? pp_money_ok($cur0['custom_price']) : null;
                    $old = number_format((float)$cur0['price'], 2, '.', '');
                    $pdo->prepare('UPDATE product_plans SET `' . $c['price'] . '` = :p, `' . $c['pprice'] . '` = :pp, `' . $c['rate'] . '` = :pr, `' . $c['inr'] . '` = :ic, `' . $c['manual'] . '` = 0, `' . $c['custom_on'] . '` = 0, `' . $c['source'] . '` = \'auto_fallback\',
                                    `' . $c['detected'] . '` = NULL, `' . $c['deadline'] . '` = NULL, `' . $c['pend_pp'] . '` = NULL, `' . $c['pend_inr'] . '` = NULL WHERE id = :id')
                        ->execute([':p' => $newInr, ':pp' => PriceSyncService::microToDec($micro), ':pr' => $cur === 'INR' ? '1' : $rate, ':ic' => $newInr, ':id' => $plan]);
                    $final = $newInr; $resolution = $customOn ? 'auto_applied_custom_off' : 'auto_applied';
                    pp_event($pdo, 'auto_price_update', ['plan_id' => $plan, 'product_id' => $r['product_id'], 'name' => $r['name'], 'duration' => $r['duration'], 'pid' => $r['provider_variant_id']], [
                        'new_provider_price' => PriceSyncService::microToDec($micro), 'currency' => $cur, 'rate' => $cur === 'INR' ? '1' : $rate, 'new_inr' => $newInr, 'old_selling' => $old, 'new_selling' => $final,
                        'custom_price' => $customVal, 'source' => 'auto_fallback', 'status' => 'applied', 'resolution' => $resolution, 'category' => $cat,
                        'note' => $customOn ? 'Review ended without an admin change; the custom price was switched off (value kept) and the new provider-derived price applied.' : 'Review ended without admin action; the latest pending price was applied.',
                    ]);
                    $pdo->commit();
                } catch (Throwable $ex) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log('[pp_apply_expired] plan ' . $plan . ' ' . $cat . ': ' . $ex->getMessage());
                    continue;
                }
                pp_resolve_pending($pdo, $plan, $resolution, 'Review period ended.', null, $cat);
                alert_raise([
                    'dedupe_key' => 'price_auto_applied:' . $cat . ':' . $plan . ':' . time(), 'type' => 'price_auto_applied', 'severity' => 'info',
                    'product_id' => (int)$r['product_id'], 'product_name' => (string)$r['name'], 'plan_id' => $plan, 'duration' => (string)$r['duration'],
                    'reason' => 'Automatic ' . dual_cat_label($cat) . ' price update completed: ' . $old . ' -> ' . $final . ' (provider ' . PriceSyncService::microToDec($micro) . ' ' . $cur . ' x rate ' . $rate . ')'
                              . ($customOn ? '. Your custom price ' . $customVal . ' was switched off (value kept).' : '.'),
                    'recommended_action' => 'You can still set a Custom Price for this duration at any time.',
                ]);
                $n++;
            }
        } catch (Throwable $e) { error_log('[pp_apply_expired:' . $cat . '] ' . $e->getMessage()); }
    }
    return $n;
}

/* ------------------------------------------------------------------ */
/*  Admin actions (all server-side validated; callers enforce admin + CSRF) */
/* ------------------------------------------------------------------ */

/**
 * Save the custom price of ONE duration for ONE category. $price = null while $enabled = false just switches it off (the stored value is kept).
 * Never touches the OTHER category and never touches the provider data except to ACKNOWLEDGE a pending increase the admin has just handled
 * (so the same increase is not detected again after the admin chose a price). Resolves that category's running review for the duration.
 * @return array{ok:bool, error?:string, price?:string}
 */
function pp_admin_set_custom(PDO $pdo, int $planId, bool $enabled, ?string $price, int $adminId, string $cat = DUAL_NORMAL): array
{
    pp_ensure_schema($pdo);
    $cat = dual_cat_valid($cat);
    $c = dual_cat_cols($cat);
    $p = $price === null ? null : pp_money_ok($price);
    if ($enabled && $p === null) return ['ok' => false, 'error' => 'Enter a custom price greater than 0 (at most 2 decimals).'];
    $hadPending = false;
    try {
        $pdo->beginTransaction();
        $q = $pdo->prepare('SELECT pl.id, pl.product_id, pl.duration, pl.provider_currency, pl.provider_variant_id, p.name,
                                   pl.`' . $c['price'] . '` AS price, pl.`' . $c['pprice'] . '` AS provider_price, pl.`' . $c['rate'] . '` AS price_rate, pl.`' . $c['inr'] . '` AS inr_cost,
                                   pl.`' . $c['custom'] . '` AS custom_price, pl.`' . $c['custom_on'] . '` AS custom_on,
                                   pl.`' . $c['pend_pp'] . '` AS pend_pp, pl.`' . $c['pend_inr'] . '` AS pend_inr, pl.`' . $c['deadline'] . '` AS dl
                            FROM product_plans pl JOIN products p ON p.id = pl.product_id WHERE pl.id = :id FOR UPDATE');
        $q->execute([':id' => $planId]);
        $r = $q->fetch();
        if (!$r) { $pdo->rollBack(); return ['ok' => false, 'error' => 'Duration not found.']; }
        $old = number_format((float)$r['price'], 2, '.', '');
        $storedCustom = $p ?? ($r['custom_price'] !== null ? number_format((float)$r['custom_price'], 2, '.', '') : null);
        if ($enabled) {
            $pdo->prepare('UPDATE product_plans SET `' . $c['custom_on'] . '` = 1, `' . $c['custom'] . '` = :c, `' . $c['price'] . '` = :c2, `' . $c['manual'] . '` = 1, `' . $c['source'] . '` = \'custom\' WHERE id = :id')
                ->execute([':c' => $p, ':c2' => $p, ':id' => $planId]);
            $newSelling = $p;
        } else {
            // Switch OFF: price returns to the calculated provider-based price when one exists (never a hidden markup); otherwise it is left as is.
            $calc = pp_money_ok($r['inr_cost']);
            $pdo->prepare('UPDATE product_plans SET `' . $c['custom_on'] . '` = 0, `' . $c['custom'] . '` = :c, `' . $c['price'] . '` = :np, `' . $c['manual'] . '` = 0, `' . $c['source'] . '` = \'custom_off\' WHERE id = :id')
                ->execute([':c' => $storedCustom, ':np' => $calc ?? $old, ':id' => $planId]);
            $newSelling = $calc ?? $old;
        }
        // The admin has acted on a pending increase: acknowledge the new provider cost as this category's baseline (inside the same lock).
        if ((string)$r['dl'] !== '' && PriceSyncService::priceMicroOk($r['pend_pp']) !== null) {
            $hadPending = true;
            $ackRate = pp_cat_rate($pdo, $cat);   // v64: null (not configured) keeps the stored rate; never another category's rate
            $pdo->prepare('UPDATE product_plans SET `' . $c['pprice'] . '` = `' . $c['pend_pp'] . '`, `' . $c['inr'] . '` = COALESCE(`' . $c['pend_inr'] . '`, `' . $c['inr'] . '`), `' . $c['rate'] . '` = COALESCE(:rt, `' . $c['rate'] . '`) WHERE id = :id')
                ->execute([':rt' => $ackRate, ':id' => $planId]);
        }
        pp_event($pdo, 'custom_price', ['plan_id' => $planId, 'product_id' => $r['product_id'], 'name' => $r['name'], 'duration' => $r['duration'], 'pid' => $r['provider_variant_id']], [
            'old_provider_price' => $r['provider_price'], 'new_provider_price' => $hadPending ? $r['pend_pp'] : $r['provider_price'], 'currency' => $r['provider_currency'], 'rate' => $r['price_rate'],
            'old_inr' => $r['inr_cost'], 'new_inr' => $hadPending ? $r['pend_inr'] : $r['inr_cost'], 'old_selling' => $old, 'new_selling' => $newSelling, 'custom_price' => $p, 'source' => 'admin', 'status' => 'applied', 'category' => $cat,
            'resolution' => $enabled ? 'custom_enabled' : 'custom_disabled', 'note' => 'Admin #' . $adminId . ' (' . dual_cat_label($cat) . ')' . ($enabled ? ' set custom price ' . $p : ' disabled the custom price'),
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[pp_admin_set_custom] ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not save the custom price.'];
    }
    pp_resolve_pending($pdo, $planId, 'admin_custom_price', 'Admin #' . $adminId . ' handled the price increase.', $adminId, $cat);
    audit_log('price.custom', 'success', ['entity_type' => 'plan', 'entity_id' => $planId, 'actor_id' => $adminId, 'reason' => dual_cat_label($cat) . ': ' . ($enabled ? 'custom price ' . $p : 'custom price disabled')]);
    return ['ok' => true, 'price' => $newSelling];
}

/** Admin confirms the new calculated price NOW (instead of waiting for the review period). */
function pp_admin_confirm_increase(PDO $pdo, int $planId, int $adminId, string $cat = DUAL_NORMAL): array
{
    pp_ensure_schema($pdo);
    $cat = dual_cat_valid($cat);
    $c = dual_cat_cols($cat);
    try {
        $q = $pdo->prepare('SELECT `' . $c['pend_pp'] . '` AS pend_pp FROM product_plans WHERE id = :id');
        $q->execute([':id' => $planId]);
        $r = $q->fetch();
        if (!$r || PriceSyncService::priceMicroOk($r['pend_pp']) === null) return ['ok' => false, 'error' => 'No pending price increase for this duration.'];
        $pdo->prepare('UPDATE product_plans SET `' . $c['deadline'] . '` = NOW() - INTERVAL 1 SECOND WHERE id = :id AND `' . $c['deadline'] . '` IS NOT NULL')->execute([':id' => $planId]);
        $n = pp_apply_expired($pdo, 'admin', $cat);
        audit_log('price.increase_confirmed', 'success', ['entity_type' => 'plan', 'entity_id' => $planId, 'actor_id' => $adminId, 'reason' => dual_cat_label($cat) . ': admin confirmed the new calculated price']);
        return ['ok' => $n > 0, 'error' => $n > 0 ? null : 'Nothing was applied (already handled?).'];
    } catch (Throwable $e) { error_log('[pp_admin_confirm_increase] ' . $e->getMessage()); return ['ok' => false, 'error' => 'Could not apply the price.']; }
}

/**
 * Admin typed a plain price (Amount box / Create Product) for a category while a review is open for it: the admin's price is PRESERVED by
 * turning it into that category's Custom Price (otherwise the next sync cycle would simply recalculate over it). No review open = returns null
 * and the caller saves the price as before.
 */
function pp_admin_price_during_review(PDO $pdo, int $planId, string $cat, string $newPrice, int $adminId): ?array
{
    $cat = dual_cat_valid($cat);
    $c = dual_cat_cols($cat);
    try {
        $q = $pdo->prepare('SELECT `' . $c['deadline'] . '` AS dl FROM product_plans WHERE id = :id');
        $q->execute([':id' => $planId]);
        $dl = $q->fetchColumn();
        if ($dl === false || (string)$dl === '') return null;
    } catch (Throwable $e) { return null; }
    return pp_admin_set_custom($pdo, $planId, true, $newPrice, $adminId, $cat);
}

/** Admin restores a removed / reappeared duration. Mapping, orders, custom price and history are untouched. */
function pp_admin_restore(PDO $pdo, int $planId, int $adminId): array
{
    pp_ensure_schema($pdo);
    try {
        $pdo->beginTransaction();
        $q = $pdo->prepare('SELECT pl.id, pl.product_id, pl.duration, pl.provider_variant_id, pl.lifecycle_state, pl.sync_removed, p.name FROM product_plans pl JOIN products p ON p.id = pl.product_id WHERE pl.id = :id FOR UPDATE');
        $q->execute([':id' => $planId]);
        $r = $q->fetch();
        if (!$r) { $pdo->rollBack(); return ['ok' => false, 'error' => 'Duration not found.']; }
        if ((string)$r['lifecycle_state'] === 'missing' || (string)$r['lifecycle_state'] === 'active') { $pdo->rollBack(); return ['ok' => false, 'error' => 'This duration is not removed.']; }
        if (licencedash_parse_variant((string)$r['provider_variant_id']) === null) { $pdo->rollBack(); return ['ok' => false, 'error' => 'The provider mapping of this duration is not valid.']; }
        $pdo->prepare("UPDATE product_plans SET enabled = 1, sync_removed = 0, lifecycle_state = 'active', provider_missing_count = 0, provider_missing_since = NULL, provider_removed_at = NULL WHERE id = :id")->execute([':id' => $planId]);
        pp_event($pdo, 'duration_restored', ['plan_id' => $planId, 'product_id' => $r['product_id'], 'name' => $r['name'], 'duration' => $r['duration'], 'pid' => $r['provider_variant_id']],
                 ['source' => 'admin', 'status' => 'applied', 'resolution' => 'restored', 'note' => 'Admin #' . $adminId . ' restored the duration.']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[pp_admin_restore] ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not restore the duration.'];
    }
    alert_resolve_key('duration_reappeared:' . $planId, 'Restored by admin', $adminId);
    audit_log('duration.restored', 'success', ['entity_type' => 'plan', 'entity_id' => $planId, 'actor_id' => $adminId, 'reason' => 'Duration restored']);
    return ['ok' => true];
}

/* ------------------------------------------------------------------ */
/*  Duration lifecycle (called by PriceSyncService::cycle after a COMPLETE catalog read) */
/* ------------------------------------------------------------------ */

/** The duration IS in the catalog this cycle. Resets missing counters; reports a reappearance. */
function pp_lifecycle_seen(PDO $pdo, array $row, array $cfg, bool $priceValid = true): void
{
    $state = (string)($row['lifecycle_state'] ?? 'active');
    $plan = (int)$row['plan_id'];
    try {
        if ($state === 'active' && (int)($row['provider_missing_count'] ?? 0) === 0) return;   // normal case: no write
        if ($state === 'missing' || ($state === 'active' && (int)$row['provider_missing_count'] > 0)) {
            $pdo->prepare("UPDATE product_plans SET lifecycle_state = 'active', provider_missing_count = 0, provider_missing_since = NULL, provider_last_seen_at = NOW() WHERE id = :id")->execute([':id' => $plan]);
            alert_resolve_key('duration_missing:' . $plan, 'Duration is back in the provider catalog');
            return;
        }
        if ($state === 'removed') {
            // v60: restore only AFTER validation: valid mapping + a valid provider price in this complete catalog read + product not archived
            if ($cfg['auto_restore'] && $priceValid && licencedash_parse_variant((string)$row['provider_variant_id']) !== null) {
                $pdo->prepare("UPDATE product_plans SET enabled = 1, sync_removed = 0, lifecycle_state = 'active', provider_missing_count = 0, provider_missing_since = NULL, provider_removed_at = NULL, provider_last_seen_at = NOW() WHERE id = :id")->execute([':id' => $plan]);
                pp_event($pdo, 'duration_restored', $row, ['source' => 'sync', 'status' => 'applied', 'resolution' => 'auto_restored', 'note' => 'Same provider service id reappeared; auto-restore is ON.']);
                alert_raise(['dedupe_key' => 'duration_restored:' . $plan . ':' . time(), 'type' => 'duration_restored', 'severity' => 'info', 'product_id' => (int)$row['product_id'], 'product_name' => (string)$row['name'],
                    'plan_id' => $plan, 'duration' => (string)$row['duration'], 'reason' => 'The provider re-added this duration (same service id) and it was restored automatically (auto-restore is ON).',
                    'recommended_action' => 'Check the price: it is recalculated by the next price check; any custom price was kept.']);
                return;
            }
            $pdo->prepare("UPDATE product_plans SET lifecycle_state = 'reappeared', provider_last_seen_at = NOW() WHERE id = :id AND lifecycle_state = 'removed'")->execute([':id' => $plan]);
            pp_event($pdo, 'duration_reappeared', $row, ['source' => 'sync', 'status' => 'pending', 'note' => 'Provider lists this service id again. NOT restored automatically.']);
            alert_raise(['dedupe_key' => 'duration_reappeared:' . $plan, 'type' => 'duration_reappeared', 'severity' => 'warning', 'product_id' => (int)$row['product_id'], 'product_name' => (string)$row['name'],
                'plan_id' => $plan, 'duration' => (string)$row['duration'], 'reason' => 'The provider lists the removed duration again (service id ' . ops_sanitize((string)$row['pid'], 20) . '). It stays hidden from customers until an admin restores it.',
                'recommended_action' => 'Admin -> Duration Monitor -> Restore (the mapping, custom price and history were kept).']);
        }
        // state 'reappeared': already reported, waiting for the admin
    } catch (Throwable $e) { error_log('[pp_lifecycle_seen] ' . $e->getMessage()); }
}

/**
 * The duration is NOT in a COMPLETE, successfully read catalog. Counts consecutive misses; archives after the configured thresholds.
 * Only called when the whole catalog was fetched (all pages, valid JSON, plausible size). Returns 'missing' | 'removed' | 'noop'.
 */
function pp_lifecycle_missing(PDO $pdo, array $row, array $cfg): string
{
    $plan = (int)$row['plan_id'];
    $state = (string)($row['lifecycle_state'] ?? 'active');
    if ($state === 'removed' || $state === 'reappeared') return 'noop';   // already archived; a reappeared one that vanished again just stays archived
    try {
        $since = ((string)($row['provider_missing_since'] ?? '')) !== '' ? strtotime((string)$row['provider_missing_since']) : null;
        $count = (int)($row['provider_missing_count'] ?? 0) + 1;
        if ($since === null || $since === false) $since = time();
        $first = $count === 1;
        $pdo->prepare("UPDATE product_plans SET provider_missing_count = :c, provider_missing_since = COALESCE(provider_missing_since, NOW()), lifecycle_state = 'missing' WHERE id = :id AND lifecycle_state IN ('active','missing')")
            ->execute([':c' => $count, ':id' => $plan]);
        if ($first) {
            pp_event($pdo, 'duration_missing', $row, ['source' => 'sync', 'status' => 'pending', 'note' => 'Not in the complete provider catalog (check 1).']);
            alert_raise(['dedupe_key' => 'duration_missing:' . $plan, 'type' => 'duration_missing', 'severity' => 'warning', 'product_id' => (int)$row['product_id'], 'product_name' => (string)$row['name'],
                'plan_id' => $plan, 'duration' => (string)$row['duration'], 'provider_key' => PriceSyncService::PROVIDER,
                'reason' => 'The provider catalog no longer lists service id ' . ops_sanitize((string)$row['pid'], 20) . ' (' . $row['name'] . ' / ' . $row['duration'] . '). It is removed from the store after ' . $cfg['remove_after_checks'] . ' consecutive complete checks and at least ' . $cfg['remove_after_minutes'] . ' minutes.',
                'recommended_action' => 'No action needed. If this is a provider glitch it clears by itself once the duration is listed again.']);
        }
        if (!pp_should_remove($count, (int)$since, time(), $cfg['remove_after_checks'], $cfg['remove_after_minutes'])) return 'missing';

        $pdo->beginTransaction();
        $pdo->prepare("UPDATE product_plans SET enabled = 0, sync_removed = 1, lifecycle_state = 'removed', provider_removed_at = NOW(), price_increase_detected_at = NULL, price_increase_deadline = NULL, pending_provider_price = NULL, pending_calculated_inr = NULL,
                       reseller_increase_detected_at = NULL, reseller_increase_deadline = NULL, reseller_pending_provider_price = NULL, reseller_pending_calculated_inr = NULL
                       WHERE id = :id AND lifecycle_state = 'missing'")->execute([':id' => $plan]);
        pp_event($pdo, 'duration_removed', $row, ['source' => 'sync', 'status' => 'applied', 'resolution' => 'archived', 'old_selling' => number_format((float)$row['price'], 2, '.', ''),
                 'note' => 'Missing in ' . $count . ' consecutive complete catalog checks; archived (nothing deleted).']);
        $pdo->commit();
        pp_resolve_pending_all($pdo, $plan, 'duration_removed', 'Duration removed by the provider.', null);
        alert_resolve_key('duration_missing:' . $plan, 'Duration archived');
        alert_raise(['dedupe_key' => 'duration_removed:' . $plan . ':' . time(), 'type' => 'duration_removed', 'severity' => 'critical', 'product_id' => (int)$row['product_id'], 'product_name' => (string)$row['name'],
            'plan_id' => $plan, 'duration' => (string)$row['duration'], 'reason' => 'REMOVED BY PROVIDER: ' . $row['name'] . ' / ' . $row['duration'] . ' was absent from ' . $count . ' consecutive complete catalog checks and is no longer purchasable (website and API). Orders, ledger and history are untouched.',
            'recommended_action' => 'Nothing to do. If the provider re-adds it, you get a "reappeared" alert and can restore it with one click.']);
        audit_log('duration.removed', 'success', ['entity_type' => 'plan', 'entity_id' => $plan, 'actor_id' => 0, 'reason' => 'Archived: missing in ' . $count . ' consecutive complete catalog checks']);
        return 'removed';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[pp_lifecycle_missing] ' . $e->getMessage());
        return 'noop';
    }
}

/* ------------------------------------------------------------------ */
/*  Admin read side                                                    */
/* ------------------------------------------------------------------ */

/** Everything the "Price & Duration Monitor" page shows. Database only (no provider call). */
function pp_monitor(PDO $pdo): array
{
    pp_ensure_schema($pdo);
    $cfg = pp_config($pdo);
    $o = ['config' => $cfg, 'alerts' => [], 'durations' => [], 'history' => [], 'counts' => []];
    $o['alerts'] = [];
    foreach (DUAL_CATS as $cat) {
        $c = dual_cat_cols($cat);
        $rows = $pdo->query("SELECT pl.id AS plan_id, pl.product_id, pl.provider_variant_id AS provider_id, p.name AS product, pl.duration, pl.`" . $c['pprice'] . "` AS old_provider_price, pl.`" . $c['pend_pp'] . "` AS new_provider_price, pl.provider_currency,
                                    pl.`" . $c['rate'] . "` AS rate, pl.`" . $c['inr'] . "` AS old_inr, pl.`" . $c['pend_inr'] . "` AS new_inr, pl.`" . $c['price'] . "` AS selling_price, pl.`" . $c['custom'] . "` AS custom_price, pl.`" . $c['custom_on'] . "` AS custom_price_enabled,
                                    pl.`" . $c['detected'] . "` AS detected_at, pl.`" . $c['deadline'] . "` AS deadline,
                                    GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), pl.`" . $c['deadline'] . "`)) AS seconds_left
                             FROM product_plans pl JOIN products p ON p.id = pl.product_id
                             WHERE pl.`" . $c['deadline'] . "` IS NOT NULL ORDER BY pl.`" . $c['deadline'] . "` ASC LIMIT 200")->fetchAll();
        foreach ($rows as $r) { $r['price_category'] = $cat; $r['category_label'] = dual_cat_label($cat); $r['status'] = 'Pending Review'; $o['alerts'][] = $r; }
    }
    usort($o['alerts'], static fn(array $a, array $b): int => strcmp((string)$a['deadline'], (string)$b['deadline']));
    $o['durations'] = $pdo->query("SELECT pl.id AS plan_id, p.name AS product, pl.duration, pl.lifecycle_state, pl.provider_missing_count, pl.provider_missing_since, pl.provider_last_seen_at, pl.provider_removed_at,
                                          pl.enabled, pl.price, pl.provider_variant_id
                                   FROM product_plans pl JOIN products p ON p.id = pl.product_id
                                   WHERE pl.source = 'api' AND pl.provider_key = '" . PriceSyncService::PROVIDER . "' AND (pl.lifecycle_state <> 'active' OR pl.provider_missing_count > 0)
                                   ORDER BY pl.provider_removed_at DESC, pl.id DESC LIMIT 200")->fetchAll();
    $o['history'] = $pdo->query('SELECT id, event_type, plan_id, product_name, duration, old_provider_price, new_provider_price, provider_currency, rate, old_inr, new_inr, old_selling_price, new_selling_price,
                                        custom_price, source, status, detected_at, deadline_at, resolved_at, resolution, note, price_category
                                 FROM price_change_events ORDER BY id DESC LIMIT 150')->fetchAll();
    $c = ['active' => 0, 'missing' => 0, 'removed' => 0, 'reappeared' => 0];
    foreach ($pdo->query("SELECT lifecycle_state s, COUNT(*) n FROM product_plans WHERE source = 'api' AND provider_key = '" . PriceSyncService::PROVIDER . "' GROUP BY lifecycle_state")->fetchAll() as $r) {
        if (isset($c[$r['s']])) $c[$r['s']] = (int)$r['n'];
    }
    $o['counts'] = $c + ['pending_increases' => count($o['alerts']), 'last_complete_catalog' => (int)pp_setting($pdo, 'sync_last_complete_catalog', '0')];
    return $o;
}
