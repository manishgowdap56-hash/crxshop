<?php
declare(strict_types=1);

/*
 * v55 - PROVIDER PRICE SYNC (price verification and correction ONLY)
 *
 * Replaces the v40-v54 "PriceSyncService". Everything that discovered, queued, imported, restored, merged or removed
 * products has been deleted. This class only:
 *   1. reads the provider catalog (catalog.php, all pages),
 *   2. matches every EXISTING mapped variant (product_plans.source='api', provider_key='licencedashboard',
 *      provider_variant_id = provider service id) to its catalog entry by exact id,
 *   3. computes  website price = provider USD price x rate (Reseller: sync_usd_inr_rate, Normal: normal_usd_inr_rate; always from the ORIGINAL provider price, converted once, no markup),
 *   4. if product_plans.price differs it is corrected (an admin-typed price does NOT protect it), one transaction per variant,
 *   5. records genuine corrections and failures in provider_price_history (one card per variant problem, updated in place),
 *   6. keeps the last valid stored price whenever the provider answer is missing / zero / invalid / failed.
 * It never creates, imports, publishes, restores, renames, disables, deletes or moves a product or variant.
 *
 * Scheduling: cron/provider_sync.php --loop (CLI, server side). One named MySQL lock (GET_LOCK) prevents overlapping cycles.
 */
final class PriceSyncService
{
    public const PROVIDER = 'licencedashboard';        // = PROVIDER_LICENCEDASH
    public const LOCK     = 'royalpanel_provider_sync';
    public const RETRIES  = 3;
    public const MIN_INTERVAL = 4;                      // seconds
    public const DEFAULT_INTERVAL = 10;                 // seconds (target)
    public const RATE     = '30';                       // DEFAULT exchange rate (1 USD = 30 INR). v59: the admin can change it (setting sync_usd_inr_rate); no markup is ever added.
    public const MAX_PAGES = 100;
    public const SCHEMA_VERSION = 'v55';

    private const VARIANT_KEYS   = ['variants', 'plans', 'packages', 'durations', 'tiers', 'services'];
    private const CONTAINER_KEYS = ['products', 'items'];
    private const EVENT_COLS = ['run_id', 'price_category', 'plan_id', 'product_id', 'product_name', 'duration', 'provider_variant_id', 'api_version', 'old_provider_price', 'new_provider_price',
                                'provider_currency', 'rate', 'old_inr_cost', 'new_inr_cost', 'old_selling_price', 'new_selling_price', 'direction', 'applied', 'note',
                                'change_type', 'status', 'price_diff', 'error'];

    /** v64: the one column list shared by loadLocal() and loadPlan() (both categories' columns, so every reader sees the same row shape). */
    private const LOCAL_SELECT = "SELECT pl.id AS plan_id, pl.product_id, pl.duration, pl.price, pl.provider_key, pl.provider_variant_id, pl.provider_price, pl.provider_currency,
                                 pl.price_rate, pl.price_manual, pl.inr_cost, pl.provider_api_version, p.name,
                                 pl.custom_price_enabled, pl.custom_price, pl.lifecycle_state, pl.provider_missing_count, pl.provider_missing_since, pl.sync_removed,
                                 pl.price_increase_deadline, pl.pending_provider_price, pl.pending_calculated_inr,
                                 pl.reseller_price, pl.reseller_custom_price_enabled, pl.reseller_custom_price, pl.reseller_price_manual, pl.reseller_price_rate, pl.reseller_inr_cost,
                                 pl.reseller_provider_price, pl.reseller_pending_provider_price, pl.reseller_pending_calculated_inr, pl.reseller_increase_deadline
                          FROM product_plans pl JOIN products p ON p.id = pl.product_id";

    private static bool $schemaDone = false;
    private static bool $priceSchemaDone = false;
    private static int $fetchErrors = 0;
    private static string $lastError = '';

    /* ------------------------------ settings ------------------------------ */

    /** Fresh read (the shared settings cache is per PHP process and the cron loop lives for ~55 s). */
    private static function raw(PDO $pdo, string $key, ?string $default = null): ?string
    {
        try {
            $q = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = :k LIMIT 1');
            $q->execute([':k' => $key]);
            $v = $q->fetchColumn();
            return $v === false ? $default : (string)$v;
        } catch (Throwable $e) { return $default; }
    }

    private static function put(PDO $pdo, string $key, string $value): void
    {
        try {
            $pdo->prepare('INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (:k, :v, NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()')
                ->execute([':k' => $key, ':v' => $value]);
            settings_reload();
        } catch (Throwable $e) { error_log('[price_sync:setting] ' . $e->getMessage()); }
    }

    /** @return array{enabled:bool, interval_sec:int, rate:?string, normal_rate:?string, provider_currency:string} */
    public static function settings(?PDO $pdo = null): array
    {
        $pdo = $pdo ?? db();
        $sec = self::raw($pdo, 'sync_interval_seconds', '');
        if ($sec === null || $sec === '') {
            $oldMin = self::raw($pdo, 'sync_interval_minutes', '');
            $sec = ($oldMin === null || $oldMin === '') ? (string)self::DEFAULT_INTERVAL : (string)(max(5, (int)$oldMin) * 60);
        }
        $en = self::raw($pdo, 'sync_enabled', '');
        $cur = strtoupper((string)self::raw($pdo, 'sync_provider_currency', 'USD'));
        return [
            'enabled'           => $en === null || $en === '' ? true : $en === '1',    // price correction is the only job left: ON unless an admin switched it OFF
            'interval_sec'      => max(self::MIN_INTERVAL, min(86400, (int)$sec)),
            // v64: each rate comes ONLY from its own setting. Missing / invalid => null (NO fallback to the other category, NO silent default):
            // that category's automatic price updates are rejected and its stored prices kept; the other category keeps working.
            'rate'              => PriceSyncService::normalizeRate((string)self::raw($pdo, 'sync_usd_inr_rate', '')),     // RESELLER rate (sync_usd_inr_rate) or null
            'normal_rate'       => PriceSyncService::normalizeRate((string)self::raw($pdo, 'normal_usd_inr_rate', '')),   // NORMAL USER rate (normal_usd_inr_rate) or null
            'provider_currency' => in_array($cur, ['USD', 'INR'], true) ? $cur : 'USD',  // only used when the catalog does not name a currency
        ];
    }

    /* ------------------------------ money (pure) ------------------------------ */
    // No floats for money decisions: provider prices are integer micro-units (1e-6), the rate integer 1e-4 units.

    /** Decimal -> integer micro-units, or null when not a plain non-negative decimal <= 100000. */
    public static function toMicro($v): ?int
    {
        if ($v === null || is_bool($v) || is_array($v)) return null;
        if (is_int($v) || is_float($v)) {
            if (is_float($v) && !is_finite($v)) return null;
            $str = number_format((float)$v, 6, '.', '');
        } else {
            $str = trim((string)$v);
        }
        if (!preg_match('/^([0-9]{1,9})(?:\.([0-9]{1,12}))?$/D', $str, $m)) return null;
        $micro = (int)$m[1] * 1000000 + (int)substr(str_pad($m[2] ?? '', 6, '0'), 0, 6);
        return $micro <= 100000 * 1000000 ? $micro : null;
    }

    /** "30" / "30.5" / "0.0123" -> canonical string with at most 4 decimals, or null when invalid or outside 0.0001 .. 1000. */
    public static function normalizeRate(string $r): ?string
    {
        $r = trim($r);
        if (!preg_match('/^([0-9]{1,4})(?:\.([0-9]{1,4}))?$/D', $r, $m)) return null;
        $u = (int)$m[1] * 10000 + (int)str_pad($m[2] ?? '', 4, '0');
        if ($u < 1 || $u > 1000 * 10000) return null;
        $frac = rtrim(str_pad((string)($u % 10000), 4, '0', STR_PAD_LEFT), '0');
        return (string)intdiv($u, 10000) . ($frac !== '' ? '.' . $frac : '');
    }

    /** USD (micro) x rate -> INR string "75.00" (half-up to the paisa). Null on invalid input. */
    public static function convertToInr(?int $micro, string $rate): ?string
    {
        $r = self::normalizeRate($rate);
        if ($micro === null || $r === null) return null;
        [$i, $f] = array_pad(explode('.', $r, 2), 2, '');
        $r1e4 = (int)$i * 10000 + (int)str_pad($f, 4, '0');
        $paise = intdiv($micro * $r1e4 + 50000000, 100000000);
        return sprintf('%d.%02d', intdiv($paise, 100), $paise % 100);
    }

    /** micro -> "2.5000" (provider price, 4 decimals as stored in product_plans.provider_price). */
    public static function microToDec(?int $micro): ?string
    {
        if ($micro === null) return null;
        $u = intdiv($micro + 50, 100);
        return sprintf('%d.%04d', intdiv($u, 10000), $u % 10000);
    }

    /** A provider price that may be trusted: a plain amount strictly above zero. Anything else never replaces a stored valid price. */
    public static function priceMicroOk($price): ?int
    {
        $m = self::toMicro($price);
        return ($m !== null && $m > 0) ? $m : null;
    }

    /* ------------------------------- price rule ------------------------------- */

    /**
     * The website (INR) price for a catalog entry, ALWAYS derived from the ORIGINAL provider price:
     *   USD (or no currency named)  -> USD x the category rate, converted once, rounded half-up to the paisa, no markup;
     *   INR named explicitly        -> taken as is (never converted a second time);
     *   anything else / missing / zero / invalid -> no price (the stored price is kept).
     * @return array{inr:?string, currency:string, rate:string, micro:?int, reason:?string}
     */
    public static function websitePrice(array $item, array $cfg = []): array
    {
        // v64: when the caller passes a 'rate' key it is used STRICTLY (null / invalid => no USD price; there is no default and no other-category fallback).
        // Only a caller that passes no 'rate' key at all (legacy callers / tests) gets the historic default.
        $rate = array_key_exists('rate', $cfg) ? ($cfg['rate'] === null ? null : self::normalizeRate((string)$cfg['rate'])) : self::normalizeRate(self::RATE);
        $cur = strtoupper((string)($item['currency'] ?? ''));
        if ($cur === '') $cur = (string)($cfg['provider_currency'] ?? 'USD');
        $micro = self::priceMicroOk($item['price'] ?? null);
        if ($micro === null) return ['inr' => null, 'currency' => $cur, 'rate' => $rate, 'micro' => null, 'reason' => 'provider price missing, zero or invalid'];
        if ($cur === 'INR') return ['inr' => self::convertToInr($micro, '1'), 'currency' => 'INR', 'rate' => '1', 'micro' => $micro, 'reason' => null];   // INR is never multiplied
        if ($cur !== 'USD') return ['inr' => null, 'currency' => $cur, 'rate' => $rate, 'micro' => $micro, 'reason' => 'unsupported provider currency "' . $cur . '"'];
        if ($rate === null) return ['inr' => null, 'currency' => 'USD', 'rate' => null, 'micro' => $micro, 'reason' => 'exchange rate is missing or invalid'];
        return ['inr' => self::convertToInr($micro, $rate), 'currency' => 'USD', 'rate' => $rate, 'micro' => $micro, 'reason' => null];
    }

    /* -------------------------------- schema --------------------------------- */

    /** Additive, repeatable. Columns the storefront / purchase code already reads + the two log tables. Never drops or rewrites data. */
    public static function ensureSchema(PDO $pdo): void
    {
        if (self::$schemaDone) return;
        if ($pdo->inTransaction()) throw new RuntimeException('ensureSchema must run outside a transaction');
        rp_add_column($pdo, 'products', 'source', "ENUM('manual','provider') NOT NULL DEFAULT 'manual'");
        rp_add_column($pdo, 'products', 'provider_service_id', 'VARCHAR(60) DEFAULT NULL');
        rp_add_column($pdo, 'products', 'sync_status', "ENUM('live','maintenance','removed') NOT NULL DEFAULT 'live'");
        rp_add_column($pdo, 'products', 'last_synced_at', 'DATETIME DEFAULT NULL');
        rp_add_column($pdo, 'products', 'provider_snapshot', 'TEXT DEFAULT NULL');
        rp_add_column($pdo, 'products', 'provider_group_key', 'VARCHAR(120) DEFAULT NULL');
        rp_add_column($pdo, 'product_plans', 'provider_snapshot', 'TEXT DEFAULT NULL');
        rp_add_column($pdo, 'product_plans', 'sync_removed', 'TINYINT(1) NOT NULL DEFAULT 0');
        rp_add_column($pdo, 'product_plans', 'provider_api_version', 'VARCHAR(4) DEFAULT NULL');
        rp_add_column($pdo, 'product_plans', 'provider_price', 'DECIMAL(12,4) DEFAULT NULL');
        rp_add_column($pdo, 'product_plans', 'provider_currency', 'VARCHAR(3) DEFAULT NULL');
        rp_add_column($pdo, 'product_plans', 'price_rate', 'DECIMAL(12,4) DEFAULT NULL');
        rp_add_column($pdo, 'product_plans', 'price_manual', 'TINYINT(1) NOT NULL DEFAULT 0');
        rp_add_column($pdo, 'product_plans', 'inr_cost', 'DECIMAL(12,2) DEFAULT NULL');
        $pdo->exec("CREATE TABLE IF NOT EXISTS sync_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            run_id VARCHAR(16) DEFAULT NULL,
            action VARCHAR(30) NOT NULL,
            product_id INT UNSIGNED DEFAULT NULL,
            provider_item_id VARCHAR(60) DEFAULT NULL,
            old_data TEXT, new_data TEXT,
            message VARCHAR(255) DEFAULT NULL,
            actor VARCHAR(40) NOT NULL DEFAULT 'cron',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id), KEY idx_sl_created (created_at), KEY idx_sl_action (action), KEY idx_sl_product (product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS sync_errors (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            run_id VARCHAR(16) DEFAULT NULL,
            stage VARCHAR(30) NOT NULL,
            attempt TINYINT UNSIGNED DEFAULT NULL,
            message VARCHAR(500) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id), KEY idx_se_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        self::$schemaDone = true;
    }

    /** Cheap per-request guard (ONE information_schema query): runs the additive ensureSchema() only when a column the storefront reads is missing. */
    public static function ensureSchemaIfNeeded(PDO $pdo): void
    {
        if (self::$schemaDone) return;
        $q = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                          AND ((TABLE_NAME = 'product_plans' AND COLUMN_NAME IN ('provider_snapshot','sync_removed','provider_api_version','provider_price','provider_currency','price_rate','price_manual','inr_cost'))
                            OR (TABLE_NAME = 'products' AND COLUMN_NAME IN ('source','provider_service_id','sync_status','last_synced_at','provider_snapshot','provider_group_key')))");
        if ((int)$q->fetchColumn() >= 14) { self::$schemaDone = true; return; }
        self::ensureSchema($pdo);
    }

    /** provider_price_history (the Price Sync history cards) + one-time removal of the obsolete catalog-sync settings. */
    public static function ensurePriceSchema(PDO $pdo): void
    {
        if (self::$priceSchemaDone) return;
        if ($pdo->inTransaction()) throw new RuntimeException('ensurePriceSchema must run outside a transaction');
        $pdo->exec("CREATE TABLE IF NOT EXISTS provider_price_history (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            run_id VARCHAR(16) DEFAULT NULL,
            plan_id INT UNSIGNED NOT NULL,
            product_id INT UNSIGNED NOT NULL,
            product_name VARCHAR(160) DEFAULT NULL,
            duration VARCHAR(60) DEFAULT NULL,
            provider_variant_id VARCHAR(60) DEFAULT NULL,
            api_version VARCHAR(4) DEFAULT NULL,
            old_provider_price DECIMAL(12,4) DEFAULT NULL,
            new_provider_price DECIMAL(12,4) DEFAULT NULL,
            provider_currency VARCHAR(8) DEFAULT NULL,
            rate DECIMAL(12,4) DEFAULT NULL,
            old_inr_cost DECIMAL(12,2) DEFAULT NULL,
            new_inr_cost DECIMAL(12,2) DEFAULT NULL,
            old_selling_price DECIMAL(12,2) DEFAULT NULL,
            new_selling_price DECIMAL(12,2) DEFAULT NULL,
            direction VARCHAR(10) NOT NULL DEFAULT 'corrected',
            applied TINYINT(1) NOT NULL DEFAULT 1,
            note VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id), KEY idx_pph_created (created_at), KEY idx_pph_plan (plan_id), KEY idx_pph_dir (direction, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        rp_add_column($pdo, 'provider_price_history', 'change_type', 'VARCHAR(10) DEFAULT NULL');
        rp_add_column($pdo, 'provider_price_history', 'status', "VARCHAR(8) NOT NULL DEFAULT 'success'");    // success = Corrected | pending | failed | already = Already Correct
        rp_add_column($pdo, 'provider_price_history', 'price_diff', 'DECIMAL(12,2) DEFAULT NULL');
        rp_add_column($pdo, 'provider_price_history', 'error', 'VARCHAR(255) DEFAULT NULL');
        rp_add_column($pdo, 'provider_price_history', 'attempts', 'INT UNSIGNED NOT NULL DEFAULT 0');
        rp_add_column($pdo, 'provider_price_history', 'updated_at', 'DATETIME DEFAULT NULL');
        rp_add_column($pdo, 'provider_price_history', 'applied_at', 'DATETIME DEFAULT NULL');                 // v55: time the corrected price was saved
        rp_add_index($pdo, 'provider_price_history', 'idx_pph_status', '(`status`, `plan_id`)');
        // one-time: the catalog-sync settings no longer mean anything (product discovery / import / restore / consolidation are gone)
        if (self::raw($pdo, 'sync_schema_version', '') !== self::SCHEMA_VERSION) {
            try {   // v52 created 'direction' as VARCHAR(8) but writes the 9-character value 'corrected': widen it (once)
                $pdo->exec("ALTER TABLE provider_price_history MODIFY direction VARCHAR(10) NOT NULL DEFAULT 'corrected'");
            } catch (Throwable $e) { error_log('[price_sync] direction column: ' . $e->getMessage()); }
            try {
                $pdo->exec("DELETE FROM settings WHERE setting_key IN ('sync_remove_mode','sync_manage_manual','sync_category_rules','sync_default_category','sync_api_version',
                    'sync_repair_done','sync_repair_report','sync_consolidate_done','sync_consolidate_report','sync_keep_admin_price','sync_update_prices',
                    'sync_review_list','sync_skip_list','sync_last_summary','sync_last_complete','sync_mapping_audit','sync_mapping_audit_sig','sync_last_catalog_ok','sync_last_fetch_ms')");
                self::put($pdo, 'sync_schema_version', self::SCHEMA_VERSION);
            } catch (Throwable $e) { error_log('[price_sync] settings cleanup: ' . $e->getMessage()); }
        }
        self::$priceSchemaDone = true;
    }

    /* ------------------------------ logging ------------------------------- */

    private static function log(PDO $pdo, string $run, string $action, ?int $productId, ?string $itemId, $old, $new, string $msg, string $actor): void
    {
        try {
            $enc = static fn($v): ?string => $v === null ? null : substr((string)json_encode($v, JSON_UNESCAPED_UNICODE), 0, 4000);
            $pdo->prepare('INSERT INTO sync_logs (run_id, action, product_id, provider_item_id, old_data, new_data, message, actor, created_at)
                           VALUES (:r, :a, :p, :i, :o, :n, :m, :u, NOW())')
                ->execute([':r' => $run, ':a' => substr($action, 0, 30), ':p' => $productId, ':i' => $itemId, ':o' => $enc($old), ':n' => $enc($new),
                           ':m' => substr(ops_sanitize($msg, 250), 0, 255), ':u' => substr($actor, 0, 40)]);
        } catch (Throwable $e) { error_log('[price_sync:log] ' . $e->getMessage()); }
    }

    private static function error(PDO $pdo, string $run, string $stage, string $message, ?int $attempt = null): void
    {
        if ($stage === 'fetch') self::$fetchErrors++;
        error_log('[price_sync:' . $stage . '] ' . $message);
        self::$lastError = ops_sanitize($message, 300);
        try {
            $pdo->prepare('INSERT INTO sync_errors (run_id, stage, attempt, message, created_at) VALUES (:r, :s, :a, :m, NOW())')
                ->execute([':r' => $run, ':s' => substr($stage, 0, 30), ':a' => $attempt, ':m' => ops_sanitize($message, 480)]);   // ops_sanitize strips tokens/headers
        } catch (Throwable $e) { error_log('[price_sync:error-log] ' . $e->getMessage()); }
    }

    /** Keeps the log tables small when the job runs every few seconds. At most once per 6 hours. */
    private static function prune(PDO $pdo): void
    {
        try {
            if (time() - (int)self::raw($pdo, 'sync_last_prune', '0') < 21600) return;
            self::put($pdo, 'sync_last_prune', (string)time());
            $pdo->exec('DELETE FROM sync_errors WHERE created_at < (NOW() - INTERVAL 30 DAY)');
            $pdo->exec('DELETE FROM sync_logs WHERE created_at < (NOW() - INTERVAL 365 DAY)');
        } catch (Throwable $e) { error_log('[price_sync:prune] ' . $e->getMessage()); }
    }

    /* ------------------------------- fetching ------------------------------ */

    /**
     * catalog.php with up to $retries attempts (back-off 2s, 4s). Transport errors, 429 and 5xx are retried; an explicit 4xx / ok:false
     * (bad token etc.) is not (it would fail identically). Returns the decoded JSON. Throws after the last attempt.
     */
    public static function fetchCatalog(PDO $pdo, string $run, int $retries = self::RETRIES, array $params = []): array
    {
        if (defined('RP_TEST_MODE') && PHP_SAPI === 'cli' && isset($GLOBALS['RP_TEST_CATALOG']) && is_callable($GLOBALS['RP_TEST_CATALOG'])) {
            return ($GLOBALS['RP_TEST_CATALOG'])($params);                            // CLI self-test hook only
        }
        $info = provider_info(PROVIDER_LICENCEDASH);
        if (!$info['enabled'] || !$info['configured']) throw new RuntimeException('Provider 5 is switched OFF or not configured (Admin -> API Settings).');

        $retries = max(1, min(5, $retries));
        $last = 'unknown error';
        for ($try = 1; $try <= $retries; $try++) {
            try {
                $r = licencedash_request('catalog.php', $params, 'GET', 20);
                $ok = (($r['json']['ok'] ?? null) === true) && $r['http'] >= 200 && $r['http'] < 300;
                if ($ok) return $r['json'];
                $last = licencedash_error_message($r['json'], $r['http']) . ' (HTTP ' . $r['http'] . ')';
                self::error($pdo, $run, 'fetch', $last, $try);
                if ($r['http'] >= 400 && $r['http'] < 500 && $r['http'] !== 429 && $r['http'] !== 408) break;   // permanent: do not retry
            } catch (Throwable $e) {
                $last = $e->getMessage();
                self::error($pdo, $run, 'fetch', $last, $try);
            }
            if ($try < $retries) sleep(2 * $try);
        }
        throw new RuntimeException('Provider catalog could not be fetched: ' . ops_sanitize($last, 200));
    }

    private static function pick(array $a, array $fields)
    {
        foreach ($fields as $f) if (isset($a[$f]) && is_scalar($a[$f]) && (string)$a[$f] !== '') return $a[$f];
        return null;
    }

    private static function validId($v): ?string
    {
        if ($v === null || !is_scalar($v)) return null;
        $v = trim((string)$v);
        if (!preg_match('/^[0-9]{1,10}$/D', $v) || (int)$v <= 0) return null;
        return (string)(int)$v;
    }

    private static function isList($v): bool { return is_array($v) && $v !== [] && array_keys($v) === range(0, count($v) - 1); }

    /** Provider price as a plain decimal string in the provider's own currency (never converted here). Null when absent/invalid. */
    private static function priceOf(array $n): ?string
    {
        $p = self::pick($n, ['price', 'user_price', 'cost', 'rate', 'amount']);
        if ($p === null || !is_numeric($p)) return null;
        return self::microToDec(self::toMicro($p));
    }

    private static function currencyOf(array $n): string
    {
        $c = self::pick($n, ['currency', 'price_currency', 'currency_code']);
        $c = $c === null ? '' : strtoupper(trim((string)$c));
        return preg_match('/^[A-Z]{3}$/D', $c) ? $c : '';
    }

    /**
     * The product LIST inside a catalog response. v46: besides the known containers it also accepts an object keyed by category name
     * ({"data": {"Root": [...], "iOS": [...]}}), which becomes one container per key.
     */
    public static function extractList(array $json): array
    {
        $cands = [];
        $d = $json['data'] ?? null;
        if (is_array($d)) {
            foreach (['products', 'catalog', 'items', 'list', 'categories'] as $f) if (isset($d[$f]) && is_array($d[$f])) $cands[] = $d[$f];
            $cands[] = $d;
        }
        foreach (['products', 'catalog', 'items', 'categories'] as $f) if (isset($json[$f]) && is_array($json[$f])) $cands[] = $json[$f];
        foreach ($cands as $c) if (self::isList($c)) return $c;
        foreach ($cands as $c) {           // object keyed by category / group name -> containers
            if (!is_array($c) || $c === [] || self::isList($c)) continue;
            $groups = [];
            foreach ($c as $k => $v) if (is_string($k) && self::isList($v) && is_array($v[0])) $groups[] = ['name' => $k, 'products' => $v];
            if ($groups) return $groups;
        }
        return [];
    }

    /**
     * Pure. Does this catalog response say there are MORE pages? Looks (tolerantly) at pagination|meta|links objects, at data.pagination|data.meta and at the top level for
     * has_more|has_next, next_page, next_page_url, current_page|page + last_page|total_pages|pages|page_count. No pagination field at all => single page.
     * @return array{more:bool, next:?int}
     */
    public static function pageInfo(array $json): array
    {
        $srcs = [$json['pagination'] ?? null, $json['meta'] ?? null, $json['links'] ?? null];
        $d = $json['data'] ?? null;
        if (is_array($d)) { $srcs[] = $d['pagination'] ?? null; $srcs[] = $d['meta'] ?? null; $srcs[] = $d['links'] ?? null; $srcs[] = $d; }
        $srcs[] = $json;
        $more = false; $next = null;
        foreach ($srcs as $a) {
            if (!is_array($a)) continue;
            foreach (['has_more', 'has_next', 'hasMore', 'hasNext', 'has_next_page'] as $f) if (isset($a[$f]) && ($a[$f] === true || $a[$f] === 1 || $a[$f] === '1' || $a[$f] === 'true')) $more = true;
            foreach (['next_page', 'next_page_number', 'nextPage'] as $f) if (isset($a[$f]) && is_numeric($a[$f]) && (int)$a[$f] > 0) { $more = true; $next = (int)$a[$f]; }
            foreach (['next_page_url', 'next'] as $f) if (isset($a[$f]) && is_string($a[$f]) && trim($a[$f]) !== '') $more = true;
            $cur = null; $last = null;
            foreach (['current_page', 'page', 'currentPage'] as $f) if (isset($a[$f]) && is_numeric($a[$f])) { $cur = (int)$a[$f]; break; }
            foreach (['last_page', 'total_pages', 'pages', 'page_count', 'totalPages', 'lastPage'] as $f) if (isset($a[$f]) && is_numeric($a[$f])) { $last = (int)$a[$f]; break; }
            if ($cur !== null && $last !== null && $cur < $last) { $more = true; $next = $next ?? $cur + 1; }
        }
        return ['more' => $more, 'next' => $next];
    }

    /**
     * Every supported page of the catalog as ONE product list. A failed / unsuccessful / repeated / empty-but-"more" page aborts the whole fetch with an exception:
     * a partial catalog must never be mistaken for the complete one (the caller then changes nothing, in particular removes nothing).
     * @return array{list: array, pages: int}
     */
    public static function fetchAllPages(PDO $pdo, string $run, int $retries = self::RETRIES, ?callable $onPage = null): array
    {
        $json = self::fetchCatalog($pdo, $run, $retries);
        $list = self::extractList($json); $pages = 1;
        $sigs = [md5((string)json_encode($list))];
        if ($onPage) $onPage(1, count($list));
        $pi = self::pageInfo($json);
        while ($pi['more']) {
            if ($pages >= self::MAX_PAGES) throw new RuntimeException('Catalog reports more than ' . self::MAX_PAGES . ' pages: it was NOT treated as complete. Nothing was changed.');
            $want = $pi['next'] ?? ($pages + 1);
            $j = self::fetchCatalog($pdo, $run, $retries, ['page' => $want]);
            $l = self::extractList($j);
            if ($l === []) throw new RuntimeException('Catalog page ' . $want . ' was empty although the provider reports more pages: catalog incomplete. Nothing was changed.');
            $sig = md5((string)json_encode($l));
            if (in_array($sig, $sigs, true)) throw new RuntimeException('Provider returned the same products for page ' . $want . ': the page parameter is not supported as assumed. Catalog incomplete. Nothing was changed.');
            $sigs[] = $sig;
            $list = array_merge($list, $l); $pages++;
            if ($onPage) $onPage($pages, count($list));
            $pi = self::pageInfo($j);
        }
        return ['list' => $list, 'pages' => $pages];
    }

    /* ------------------------------ catalog -> prices ------------------------------ */

    /**
     * Pure. Provider list (as returned by fetchAllPages) -> map  service id => ['id','name','price','currency','conflict'].
     * Id rules are exactly those the importer used when the variants were mapped: a nested variant uses variant_id|plan_id|service_id|product_id|id|pid,
     * a flat entry product_id|id|pid|service_id. A service id that appears twice with DIFFERENT price/currency is flagged 'conflict' (it is never used for a correction).
     */
    public static function collectPrices(array $list): array
    {
        $map = [];
        self::collect($list, 0, '', $map);
        return $map;
    }

    private static function record(array &$map, string $id, array $n, string $cur): void
    {
        $price = self::priceOf($n);
        $name = self::pick($n, ['name', 'title', 'product_name', 'product', 'duration', 'plan', 'plan_name', 'label']);
        $sig = (string)$price . '|' . $cur;
        if (isset($map[$id])) { if ($map[$id]['sig'] !== $sig) $map[$id]['conflict'] = true; return; }
        $map[$id] = ['id' => $id, 'name' => $name === null ? '' : mb_substr(trim((string)$name), 0, 120), 'price' => $price, 'currency' => $cur, 'sig' => $sig, 'conflict' => false];
    }

    private static function collect(array $nodes, int $depth, string $inheritCur, array &$map): void
    {
        if ($depth > 5) return;
        foreach ($nodes as $n) {
            if (!is_array($n)) continue;
            $cur = self::currencyOf($n) ?: $inheritCur;
            foreach (self::CONTAINER_KEYS as $k) {
                if (self::isList($n[$k] ?? null) && (self::validId(self::pick($n, ['product_id', 'id', 'pid'])) === null || self::priceOf($n) === null)) {
                    self::collect($n[$k], $depth + 1, $cur, $map);
                    continue 2;
                }
            }
            foreach (self::VARIANT_KEYS as $k) {
                if (!self::isList($n[$k] ?? null)) continue;
                foreach ($n[$k] as $v) {
                    if (!is_array($v)) continue;
                    $vid = self::validId(self::pick($v, ['variant_id', 'plan_id', 'service_id', 'product_id', 'id', 'pid']));
                    if ($vid === null) continue;
                    self::record($map, $vid, $v, self::currencyOf($v) ?: $cur);
                }
                continue 2;
            }
            $id = self::validId(self::pick($n, ['product_id', 'id', 'pid', 'service_id']));
            if ($id === null) continue;
            self::record($map, $id, $n, $cur);
        }
    }

    /* ------------------------- single-variant lookup (v56) ------------------------- */

    /**
     * v56: ONE provider variant, looked up by its exact provider id, priced with the SAME rule the scheduled sync uses (websitePrice(): USD x 30 once,
     * explicit INR as is, anything else refused). Read-only: nothing is written to product_plans. Used by Admin -> Create Product.
     * It reads the same catalog the cron job reads (fetchAllPages + collectPrices), so the id matching is identical to the sync's.
     * Never falls back to another id / name. Throws RuntimeException(message, code) with code 422 (admin input / data problem) or 502 (provider problem).
     * @return array{id:string, name:string, provider_price:string, currency:string, rate:string, inr:string, fetched_at:int}
     */
    public static function lookupVariant(PDO $pdo, string $rawId, int $retries = 2): array
    {
        $v = licencedash_parse_variant($rawId);
        if ($v === null) throw new RuntimeException('Provider 5 Product ID must be a number (e.g. 76).', 422);
        $id = (string)$v['product_id'];
        try {
            $f = self::catalogSnapshot($pdo, true, $retries);      // fresh read: a verified price is never taken from the cache
        } catch (Throwable $e) {
            throw new RuntimeException('The provider price could not be verified: ' . ops_sanitize($e->getMessage(), 200), 502);
        }
        $map = self::collectPrices($f['list']);
        if (!$map) throw new RuntimeException('The provider catalog had no usable entries, so the price could not be verified.', 502);
        if (!isset($map[$id])) throw new RuntimeException('Variant ID ' . $id . ' was not found in the provider catalog. Check the ID.', 422);
        $it = $map[$id];
        if ($it['conflict']) throw new RuntimeException('The provider catalog lists ID ' . $id . ' more than once with different prices, so it cannot be verified.', 422);
        $s = self::settings($pdo);
        $wp  = self::websitePrice($it, $s);                                   // RESELLER rate (sync_usd_inr_rate ONLY)
        $wpN = self::websitePrice($it, ['rate' => $s['normal_rate']] + $s);   // NORMAL USER rate (normal_usd_inr_rate ONLY)
        // v64: the provider price itself must be usable; a missing RATE of one category only blanks that category (null inr), never the other one.
        if ($wp['micro'] === null || $wpN['micro'] === null) throw new RuntimeException('Provider price for ID ' . $id . ' cannot be used: ' . ($wp['reason'] ?? 'unusable price') . '.', 422);
        if ($wp['currency'] !== 'USD' && $wp['currency'] !== 'INR') throw new RuntimeException('Provider price for ID ' . $id . ' cannot be used: ' . ($wp['reason'] ?? 'unsupported currency') . '.', 422);
        return ['id' => $id, 'name' => (string)$it['name'], 'provider_price' => (string)self::microToDec($wp['micro']), 'currency' => $wp['currency'],
                'rate' => $wp['rate'], 'inr' => $wp['inr'], 'reseller_reason' => $wp['reason'],
                'normal_rate' => $wpN['rate'], 'normal_inr' => $wpN['inr'], 'normal_reason' => $wpN['reason'], 'fetched_at' => $f['fetched_at']];
    }

    /* ------------------- v64: independent per-category verification + correction (Admin -> Product Editor -> CHECK AGAIN) ------------------- */

    /** "325.00" / "-2.5" / 175 -> integer paise (half-up), or null when not a plain decimal. No floats are compared. */
    public static function paise($v): ?int
    {
        if ($v === null || $v === '' || is_bool($v) || is_array($v)) return null;
        $str = is_float($v) ? number_format($v, 4, '.', '') : trim((string)$v);
        if (!preg_match('/^(-?)([0-9]{1,10})(?:\.([0-9]{1,6}))?$/D', $str, $m)) return null;
        $frac = str_pad($m[3] ?? '', 4, '0');
        $p = (int)$m[2] * 100 + (int)substr($frac, 0, 2);
        if ((int)$frac[2] >= 5) $p++;
        return $m[1] === '-' ? -$p : $p;
    }

    private static function fmtPaise(int $p): string
    {
        return ($p < 0 ? '-' : '') . sprintf('%d.%02d', intdiv(abs($p), 100), abs($p) % 100);
    }

    /** One saved plan row (loadLocal() shape) by its stable id, or null. $forUpdate must be used inside a transaction. */
    public static function loadPlan(PDO $pdo, int $planId): ?array
    {
        if ($planId <= 0) return null;
        $q = $pdo->prepare(self::LOCAL_SELECT . ' WHERE pl.id = :id LIMIT 1');
        $q->execute([':id' => $planId]);
        $r = $q->fetch();
        if (!$r) return null;
        $raw = trim((string)($r['provider_variant_id'] ?? ''));
        $v = $raw === '' ? null : licencedash_parse_variant($raw);
        $r['pid'] = $v === null ? '' : (string)$v['product_id'];
        return $r;
    }

    /**
     * Verification of ONE category. Pure: no writes. $lv = lookupVariant() result, $plan = loadPlan() row or null (null = unsaved / not matching),
     * $entered = the price currently typed in the editor for this category (used only when there is no saved plan to compare with).
     * @return array<string,mixed>
     */
    public static function verifyCategory(array $lv, string $cat, ?array $plan, ?string $entered): array
    {
        $cat = dual_cat_valid($cat);
        $isN = $cat === DUAL_NORMAL;
        $calc = $isN ? ($lv['normal_inr'] ?? null) : ($lv['inr'] ?? null);
        $rate = $isN ? ($lv['normal_rate'] ?? null) : ($lv['rate'] ?? null);
        $why  = $isN ? ($lv['normal_reason'] ?? null) : ($lv['reseller_reason'] ?? null);
        $out = ['category' => $cat, 'label' => dual_cat_label($cat), 'provider_price' => $lv['provider_price'], 'provider_currency' => $lv['currency'],
                'exchange_rate' => $rate, 'rate_setting' => dual_rate_setting($cat), 'calculated' => $calc, 'current' => null, 'current_source' => null, 'difference' => null,
                'status' => 'UNVERIFIED', 'message' => '', 'can_apply' => false, 'apply_mode' => null, 'needs_confirm' => false, 'plan_id' => $plan ? (int)$plan['plan_id'] : null,
                'custom_on' => false, 'manual' => false, 'pending_review' => false, 'checked' => false];
        if ($calc === null) {
            $out['status'] = ($lv['currency'] === 'USD' && $rate === null) ? 'INVALID RATE' : 'INVALID PROVIDER PRICE';
            $out['message'] = $out['status'] === 'INVALID RATE'
                ? 'The ' . dual_cat_label($cat) . ' exchange rate (' . dual_rate_setting($cat) . ') is missing or invalid. Set it in Monitor. The ' . dual_cat_label($cat) . ' price was NOT verified and nothing is changed.'
                : 'The provider price cannot be converted (' . ($why ?? 'unusable') . '). Not verified.';
            return $out;
        }
        $cur = null;
        if ($plan !== null) {
            $v = pp_row_view($plan, $cat);
            $cur = self::paise($v['price']);
            $out['current_source'] = 'database';
            $out['custom_on'] = (int)($v['custom_price_enabled'] ?? 0) === 1 && pp_money_ok($v['custom_price'] ?? null) !== null;
            $out['manual'] = (int)($v['price_manual'] ?? 0) === 1;
            $out['pending_review'] = (string)($v['price_increase_deadline'] ?? '') !== '';
        } elseif ($entered !== null && trim($entered) !== '') {
            $cur = self::paise($entered);
            $out['current_source'] = 'form';
        }
        if ($cur === null) { $out['message'] = 'No current ' . dual_cat_label($cat) . ' selling price to compare with yet. Expected price: ₹' . $calc . '.'; $out['checked'] = true; return $out; }
        $want = self::paise($calc);
        $out['checked'] = true;
        $out['current'] = self::fmtPaise($cur);
        $out['difference'] = self::fmtPaise($want - $cur);
        $same = $want === $cur;
        $mode = $plan !== null ? 'database' : 'form';
        if ($out['pending_review']) { $out['status'] = 'PENDING REVIEW'; $out['message'] = 'A price review is running for ' . dual_cat_label($cat) . '. The automatic review decides the price; it cannot be changed from here.'; return $out; }
        if ($out['custom_on']) { $out['status'] = 'CUSTOM PRICE'; $out['message'] = 'A ' . dual_cat_label($cat) . ' custom price is active and protected. It is never overwritten by Check Again or Price Sync.'; return $out; }
        if ($same) { $out['status'] = 'CORRECT'; $out['message'] = dual_cat_label($cat) . ' selling price matches provider price × ' . dual_cat_label($cat) . ' rate.'; return $out; }
        if ($out['manual'] && $plan !== null) { $out['status'] = 'MANUAL PRICE'; $out['needs_confirm'] = true; }
        else $out['status'] = 'PRICE MISMATCH';
        $out['can_apply'] = true; $out['apply_mode'] = $mode;
        $out['message'] = dual_cat_label($cat) . ' selling price ₹' . $out['current'] . ' differs from the calculated ₹' . $calc . ($out['manual'] ? ' (the current price was typed by an admin).' : '.');
        return $out;
    }

    /**
     * CHECK AGAIN: ONE fresh provider read, then Normal and Reseller are calculated and compared INDEPENDENTLY (each with its own rate and its own columns).
     * Read-only. A saved plan is only used for the comparison when its stable id, product id and provider id all match.
     * @return array{provider:array, categories:array{normal:array, reseller:array}}
     */
    public static function verifyBoth(PDO $pdo, string $variantId, int $planId, int $productId, ?string $enteredNormal, ?string $enteredReseller): array
    {
        $lv = self::lookupVariant($pdo, $variantId, 2);                         // fresh catalog read; throws 422 / 502
        $plan = null;
        if ($planId > 0) {
            $p = self::loadPlan($pdo, $planId);
            if ($p !== null && (string)$p['provider_key'] === self::PROVIDER && ($productId <= 0 || (int)$p['product_id'] === $productId) && (string)$p['pid'] === (string)$lv['id']) $plan = $p;
        }
        return ['provider' => ['id' => $lv['id'], 'name' => $lv['name'], 'provider_price' => $lv['provider_price'], 'currency' => $lv['currency'], 'fetched_at' => $lv['fetched_at'], 'plan_matched' => $plan !== null],
                'categories' => [DUAL_NORMAL => self::verifyCategory($lv, DUAL_NORMAL, $plan, $enteredNormal), DUAL_RESELLER => self::verifyCategory($lv, DUAL_RESELLER, $plan, $enteredReseller)]];
    }

    /**
     * Apply the verified price of ONE explicit category to a saved plan. Never trusts an amount from the browser: the provider is read again and the
     * price recalculated with THAT category's own rate. Refused for a missing / unknown category, unmatched plan / provider id, invalid rate or price,
     * a custom price, a running review, an admin-typed (manual) price without explicit confirmation. Writes only that category's columns (correct()).
     * Runs under the same named lock as the scheduled cycle, so the two can never write the same plan at the same time.
     * @return array{ok:bool, code?:int, error?:string, changed?:bool, category?:string, price?:string, status?:string}
     */
    public static function applyCategory(PDO $pdo, int $planId, string $catIn, string $variantId, int $adminId, bool $confirmManual): array
    {
        $cat = dual_cat_try($catIn);
        if ($cat === null) return ['ok' => false, 'code' => 422, 'error' => 'A price category (normal or reseller) is required.'];
        if ($planId <= 0) return ['ok' => false, 'code' => 422, 'error' => 'Save the product first; there is no saved duration to correct.'];
        try { $lv = self::lookupVariant($pdo, $variantId, 2); }
        catch (RuntimeException $e) { return ['ok' => false, 'code' => ((int)$e->getCode() === 422 ? 422 : 502), 'error' => $e->getMessage()]; }
        $want = $cat === DUAL_NORMAL ? ($lv['normal_inr'] ?? null) : ($lv['inr'] ?? null);
        $rate = $cat === DUAL_NORMAL ? ($lv['normal_rate'] ?? null) : ($lv['rate'] ?? null);
        $micro = self::toMicro($lv['provider_price']);
        if ($want === null || $rate === null || $micro === null || $micro <= 0) return ['ok' => false, 'code' => 422, 'error' => dual_cat_label($cat) . ' price cannot be calculated (exchange rate or provider price missing / invalid). Nothing was changed.'];

        $locked = false;
        try {
            $locked = (int)$pdo->query("SELECT GET_LOCK('" . self::LOCK . "', 5)")->fetchColumn() === 1;
            if (!$locked) return ['ok' => false, 'code' => 409, 'error' => 'A price check is running right now. Try again in a few seconds.'];
            $plan = self::loadPlan($pdo, $planId);                                   // re-read UNDER the lock: protections are judged on current data
            if ($plan === null) return ['ok' => false, 'code' => 404, 'error' => 'Duration not found.'];
            if ((string)$plan['provider_key'] !== self::PROVIDER || (string)$plan['pid'] !== (string)$lv['id']) return ['ok' => false, 'code' => 409, 'error' => 'The saved provider ID of this duration is different from the ID that was checked. Save the product, then check again.'];
            $v = pp_row_view($plan, $cat);
            if ((string)($v['price_increase_deadline'] ?? '') !== '') return ['ok' => false, 'code' => 409, 'status' => 'PENDING REVIEW', 'error' => dual_cat_label($cat) . ' price review is running; it cannot be changed now.'];
            if ((int)($v['custom_price_enabled'] ?? 0) === 1 && pp_money_ok($v['custom_price'] ?? null) !== null) return ['ok' => false, 'code' => 409, 'status' => 'CUSTOM PRICE', 'error' => dual_cat_label($cat) . ' custom price is active and protected. Switch it off first if you want the calculated price.'];
            if ((int)($v['price_manual'] ?? 0) === 1 && !$confirmManual) return ['ok' => false, 'code' => 409, 'status' => 'MANUAL PRICE', 'error' => dual_cat_label($cat) . ' price was typed by an admin. Confirm to replace it with the calculated price.'];
            if (self::paise($v['price']) === self::paise($want)) return ['ok' => true, 'changed' => false, 'category' => $cat, 'price' => (string)$want, 'status' => 'CORRECT'];
            $wp = ['inr' => (string)$want, 'currency' => (string)$lv['currency'], 'rate' => (string)$rate, 'micro' => $micro, 'reason' => null];
            $row = $v + ['pid' => (string)$plan['pid']];
            $ok = self::correct($pdo, bin2hex(random_bytes(6)), 'admin#' . $adminId . ':check_again', $row, $wp, [], $cat);
            if (!$ok) return ['ok' => false, 'code' => 500, 'error' => 'The ' . dual_cat_label($cat) . ' price could not be updated. Nothing was changed.'];
            pp_event($pdo, 'price_corrected', $row, ['old_provider_price' => $row['provider_price'], 'new_provider_price' => self::microToDec($micro), 'currency' => $wp['currency'], 'rate' => $wp['rate'], 'old_inr' => $row['inr_cost'], 'new_inr' => $want,
                     'old_selling' => number_format((float)$v['price'], 2, '.', ''), 'new_selling' => $want, 'source' => 'admin_check', 'status' => 'applied', 'resolution' => 'applied', 'category' => $cat,
                     'note' => 'Admin #' . $adminId . ' applied the verified ' . dual_cat_label($cat) . ' price with Check Again.']);
            return ['ok' => true, 'changed' => true, 'category' => $cat, 'price' => (string)$want, 'status' => 'CORRECT'];
        } catch (Throwable $e) {
            error_log('[price_sync:applyCategory] ' . $e->getMessage());
            return ['ok' => false, 'code' => 500, 'error' => 'The price could not be updated (server error). Nothing was changed.'];
        } finally {
            if ($locked) self::lockRelease($pdo);
        }
    }

    /* ------------------- catalog snapshot + name/duration search (v57) ------------------- */

    private const PARENT_FIELDS   = ['product_name', 'product_title', 'parent_name', 'parent_product', 'parent', 'service_name', 'group_name', 'product', 'service'];
    private const DURATION_FIELDS = ['duration', 'duration_label', 'validity', 'period', 'plan', 'plan_name'];
    private const UNIT_FIELDS     = ['duration_unit', 'unit', 'period_unit'];
    private const DUR_RE = '/(?<![A-Za-z0-9.])(\d{1,4}(?:\.\d+)?)\s*(hours?|hrs?|h|days?|d|weeks?|w|months?|mos?)(?![A-Za-z0-9])/iu';

    /**
     * The provider catalog (all pages) for the admin lookups. A short-lived file cache (default 45 s) keeps several Duration Plans / keystrokes from each
     * reading the whole catalog; $fresh = true bypasses it and refreshes it. Used ONLY by the Create Product lookups - the scheduled sync always reads the
     * provider itself. Concurrent requests are serialised with flock so one read serves all of them.
     * @return array{list:array, fetched_at:int, cached:bool}
     */
    public static function catalogSnapshot(PDO $pdo, bool $fresh = false, int $retries = 2, int $ttl = 45): array
    {
        $testHook = defined('RP_TEST_MODE') && PHP_SAPI === 'cli' && isset($GLOBALS['RP_TEST_CATALOG']);
        $run = 'lookup-' . bin2hex(random_bytes(4));
        if ($testHook) { $f = self::fetchAllPages($pdo, $run, $retries); return ['list' => $f['list'], 'fetched_at' => time(), 'cached' => false]; }
        $file = rtrim(sys_get_temp_dir(), '/\\') . '/rp_catalog_' . md5(__DIR__) . '.json';
        $lock = @fopen($file . '.lock', 'c');
        if ($lock) @flock($lock, LOCK_EX);
        try {
            if (!$fresh && is_file($file) && (time() - (int)@filemtime($file)) < $ttl) {
                $j = json_decode((string)@file_get_contents($file), true);
                if (is_array($j) && isset($j['list'], $j['fetched_at']) && is_array($j['list'])) return ['list' => $j['list'], 'fetched_at' => (int)$j['fetched_at'], 'cached' => true];
            }
            self::$fetchErrors = 0; self::$lastError = '';
            $f = self::fetchAllPages($pdo, $run, $retries);
            $now = time();
            $tmp = $file . '.' . bin2hex(random_bytes(3)) . '.tmp';
            if (@file_put_contents($tmp, json_encode(['fetched_at' => $now, 'list' => $f['list']], JSON_UNESCAPED_UNICODE)) !== false) { @chmod($tmp, 0600); if (!@rename($tmp, $file)) @unlink($tmp); }
            return ['list' => $f['list'], 'fetched_at' => $now, 'cached' => false];
        } finally {
            if ($lock) { @flock($lock, LOCK_UN); @fclose($lock); }
        }
    }

    /** "Root+Pc" / "ROOT  PC" -> comparable key. Case, spacing and punctuation are ignored; "+" and "&" are kept as words so different products stay different. */
    public static function normKey(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $s = str_replace(['+', '&'], [' plus ', ' and '], $s);
        $s = (string)preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
        return trim((string)preg_replace('/\s+/', ' ', $s));
    }

    /**
     * "7 Days" / "24 hours" / "3D" -> ['key' => canonical, 'label' => "7 days"]; null when there is no duration or more than one (never guessed).
     * Hours/days/weeks compare by minutes (24 hours = 1 day); months stay months (1 month is NOT treated as 30 days).
     * @return array{key:string,label:string,match:string}|null
     */
    public static function parseDuration(string $s): ?array
    {
        if (!preg_match_all(self::DUR_RE, $s, $m, PREG_SET_ORDER) || count($m) !== 1) return null;
        $n = (float)$m[0][1]; $u = strtolower($m[0][2][0]);
        if ($n <= 0) return null;
        $mo = (strtolower(substr($m[0][2], 0, 2)) === 'mo');
        if ($mo) { $key = 'M' . rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.'); $unit = 'month'; }
        else { $mult = $u === 'h' ? 60 : ($u === 'd' ? 1440 : 10080); $key = 'm' . (int)round($n * $mult); $unit = $u === 'h' ? 'hour' : ($u === 'd' ? 'day' : 'week'); }
        $nn = rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
        return ['key' => $key, 'label' => $nn . ' ' . $unit . ($n == 1.0 ? '' : 's'), 'match' => $m[0][0]];
    }

    /**
     * Pure. Catalog list -> one record per provider id with the PRODUCT name and DURATION the catalog states:
     *   nested   product {name, variants:[{id, name|duration…}]}      -> parent = the product's name
     *   flat     {id, name:"BRMOD 7 Days"}                           -> parent = name without the duration, duration from the name
     *   flat     {id, name:"BRMOD", duration:"7 Days"}               -> parent = name, duration from the duration field
     *   flat     {id, name:"7 Days", product_name:"BRMOD"}           -> parent = the explicit product-name field
     * A duration-only entry with no product-name field is flagged 'weak' (its parent would be a guess, e.g. the category): it is listed, never auto-matched.
     * Ids follow collectPrices() exactly. @return array<int,array>
     */
    public static function indexCatalog(array $list): array
    {
        $out = []; $seen = [];
        self::indexNodes($list, 0, '', $out, $seen);
        return $out;
    }

    private static function indexNodes(array $nodes, int $depth, string $category, array &$out, array &$seen): void
    {
        if ($depth > 5) return;
        foreach ($nodes as $n) {
            if (!is_array($n)) continue;
            $nm = self::pick($n, ['name', 'title', 'product_name', 'product', 'plan_name', 'label']);
            $nameS = $nm === null ? '' : trim((string)$nm);
            foreach (self::CONTAINER_KEYS as $k) {
                if (self::isList($n[$k] ?? null) && (self::validId(self::pick($n, ['product_id', 'id', 'pid'])) === null || self::priceOf($n) === null)) {
                    self::indexNodes($n[$k], $depth + 1, $nameS !== '' ? $nameS : $category, $out, $seen);
                    continue 2;
                }
            }
            foreach (self::VARIANT_KEYS as $k) {
                if (!self::isList($n[$k] ?? null)) continue;
                foreach ($n[$k] as $v) {
                    if (!is_array($v)) continue;
                    $vid = self::validId(self::pick($v, ['variant_id', 'plan_id', 'service_id', 'product_id', 'id', 'pid']));
                    if ($vid === null || isset($seen[$vid])) continue;
                    $seen[$vid] = true;
                    $out[] = self::makeRecord($vid, $nameS, $v, true);
                }
                continue 2;
            }
            $id = self::validId(self::pick($n, ['product_id', 'id', 'pid', 'service_id']));
            if ($id === null || isset($seen[$id])) continue;
            $seen[$id] = true;
            $out[] = self::makeRecord($id, '', $n, false);
        }
    }

    private static function makeRecord(string $id, string $parentName, array $e, bool $nested): array
    {
        $lab = self::pick($e, ['name', 'title', 'product_name', 'plan_name', 'plan', 'duration', 'label']);
        $label = $lab === null ? '' : trim((string)$lab);
        $dur = null;
        foreach (self::DURATION_FIELDS as $f) {
            $raw = self::pick($e, [$f]);
            if ($raw === null) continue;
            $raw = trim((string)$raw);
            $d = self::parseDuration($raw);
            if ($d === null && is_numeric($raw)) { $u = self::pick($e, self::UNIT_FIELDS); if ($u !== null) $d = self::parseDuration($raw . ' ' . trim((string)$u)); }
            if ($d !== null) { $dur = $d; break; }
        }
        $fromLabel = self::parseDuration($label);
        if ($dur === null) $dur = $fromLabel;
        $ep = self::pick($e, self::PARENT_FIELDS);
        $ep = $ep === null ? '' : trim((string)$ep);
        $weak = false; $full = self::normKey($label);
        if ($nested && $parentName !== '') { $parent = $parentName; $full = self::normKey($parentName . ' ' . $label); }
        elseif ($ep !== '' && self::parseDuration($ep) === null && !is_numeric($ep) && self::normKey($ep) !== self::normKey($label)) { $parent = $ep; }
        else {
            $base = $label;
            if ($fromLabel !== null) $base = trim((string)preg_replace('/^[\s\-_|\/:()\[\],]+|[\s\-_|\/:()\[\],]+$/u', '', (string)preg_replace(self::DUR_RE, ' ', $label, 1)));
            if ($base !== '' && self::normKey($base) !== '') $parent = $base;
            else { $parent = ''; $weak = true; }          // duration-only name and no product-name field: the parent would be a guess
        }
        return ['id' => $id, 'parent' => $parent, 'parentKey' => self::normKey($parent), 'label' => $label, 'fullKey' => $full,
                'dur' => $dur['key'] ?? null, 'durLabel' => $dur['label'] ?? '', 'weak' => $weak];
    }

    /** Distinct provider product names (for the Create Product suggestion list). */
    public static function providerProductNames(PDO $pdo, int $limit = 1000): array
    {
        $snap = self::catalogSnapshot($pdo, false);
        $names = [];
        foreach (self::indexCatalog($snap['list']) as $r) if (!$r['weak'] && $r['parent'] !== '') $names[$r['parentKey']] = $r['parent'];
        $v = array_values($names); sort($v, SORT_NATURAL | SORT_FLAG_CASE);
        return ['names' => array_slice($v, 0, $limit), 'fetched_at' => $snap['fetched_at']];
    }

    /**
     * Product name + duration -> provider variant(s), from the real catalog only. Exact (case / spacing / punctuation-insensitive) product name AND the
     * same duration; nothing fuzzy is ever auto-selected. Prices go through websitePrice() like everything else.
     * status: match (exactly one priced variant) | ambiguous (several - the admin must pick) | none | unpriced | duration_missing | duration_unreadable
     * @return array<string,mixed>
     */
    public static function findVariants(PDO $pdo, string $productName, string $duration, bool $fresh = false): array
    {
        $q = self::normKey($productName);
        if ($q === '') throw new RuntimeException('Enter the provider product name.', 422);
        try { $snap = self::catalogSnapshot($pdo, $fresh); }
        catch (Throwable $e) { throw new RuntimeException('The provider catalog could not be read: ' . ops_sanitize($e->getMessage(), 200), 502); }
        $map = self::collectPrices($snap['list']);
        if (!$map) throw new RuntimeException('The provider catalog had no usable entries.', 502);
        $idx = self::indexCatalog($snap['list']);
        $base = ['fetched_at' => $snap['fetched_at'], 'cached' => $snap['cached'], 'candidates' => [], 'available_durations' => [], 'suggestions' => [], 'message' => ''];

        $prod = array_values(array_filter($idx, static fn(array $r): bool => !$r['weak'] && ($r['parentKey'] === $q || $r['fullKey'] === $q)));
        if (!$prod) {
            $sug = [];
            foreach ($idx as $r) if (!$r['weak'] && $r['parentKey'] !== '' && (strpos($r['parentKey'], $q) !== false || strpos($q, $r['parentKey']) !== false)) $sug[$r['parentKey']] = $r['parent'];
            return ['status' => 'none', 'suggestions' => array_slice(array_values($sug), 0, 8), 'message' => 'No provider product is named "' . mb_substr(trim($productName), 0, 60) . '".'] + $base;
        }
        $avail = []; foreach ($prod as $r) if ($r['durLabel'] !== '') $avail[$r['dur']] = $r['durLabel'];
        $base['available_durations'] = array_values($avail);
        $duration = trim($duration);
        if ($duration === '') return ['status' => 'duration_missing', 'message' => 'Select or enter the duration.'] + $base;
        $qd = self::parseDuration($duration);
        if ($qd === null) return ['status' => 'duration_unreadable', 'message' => 'The duration "' . mb_substr($duration, 0, 40) . '" is not readable (use e.g. 1 Day, 3 Hours, 7 Days).'] + $base;

        $cands = [];
        foreach ($prod as $r) {
            if ($r['dur'] !== $qd['key']) continue;
            $it = $map[$r['id']] ?? null;
            $c = ['id' => $r['id'], 'product' => $r['parent'], 'label' => $r['label'], 'duration' => $r['durLabel'], 'provider_price' => null, 'currency' => null, 'rate' => null, 'inr' => null, 'problem' => null];
            if ($it === null) $c['problem'] = 'id not in the price list';
            elseif ($it['conflict']) $c['problem'] = 'the catalog lists this id twice with different prices';
            else {
                $sx = self::settings($pdo);
                $wp = self::websitePrice($it, $sx);
                $wpN = self::websitePrice($it, ['rate' => $sx['normal_rate']] + $sx);
                // v65: identity + ORIGINAL provider price decide whether the candidate is usable; a missing RATE of one category only blanks that category.
                if ($wp['micro'] === null || $wpN['micro'] === null) $c['problem'] = $wp['reason'] ?? 'unusable provider price';
                elseif ($wp['currency'] !== 'USD' && $wp['currency'] !== 'INR') $c['problem'] = $wp['reason'] ?? 'unsupported provider currency';
                else {
                    $c['provider_price'] = self::microToDec($wp['micro']); $c['currency'] = $wp['currency'];
                    $c['rate'] = $wp['rate']; $c['inr'] = $wp['inr']; $c['reseller_reason'] = $wp['reason'];            // RESELLER (sync_usd_inr_rate only)
                    $c['normal_rate'] = $wpN['rate']; $c['normal_inr'] = $wpN['inr']; $c['normal_reason'] = $wpN['reason']; // NORMAL USER (normal_usd_inr_rate only)
                }
            }
            $cands[] = $c;
        }
        if (!$cands) return ['status' => 'none', 'message' => 'The provider has "' . $prod[0]['parent'] . '" but no ' . $qd['label'] . ' variant.'] + $base;
        $base['candidates'] = $cands;
        if (count($cands) > 1) return ['status' => 'ambiguous', 'message' => count($cands) . ' provider variants match ' . $qd['label'] . '. Choose the correct one.'] + $base;
        if ($cands[0]['problem'] !== null) return ['status' => 'unpriced', 'message' => 'Found ID ' . $cands[0]['id'] . ' but its provider price cannot be used (' . $cands[0]['problem'] . '). Nothing was filled.'] + $base;
        return ['status' => 'match', 'message' => ''] + $base;
    }

    /* -------------------------------- local -------------------------------- */

    /**
     * Every EXISTING mapped variant of this provider (product_plans.source='api', provider_key=PROVIDER, not archived) with the provider id parsed from
     * provider_variant_id. Plans that cannot be matched are counted per reason (never silently dropped).
     * @return array{rows:array<int,array>, bad:array<int,array>, other_provider:int, empty_variant:int, unparsable:int}
     */
    public static function loadLocal(PDO $pdo): array
    {
        $q = $pdo->query(self::LOCAL_SELECT . " WHERE pl.source = 'api' AND p.archived_at IS NULL");
        $out = ['rows' => [], 'bad' => [], 'other_provider' => 0, 'empty_variant' => 0, 'unparsable' => 0];
        foreach ($q->fetchAll() as $r) {
            if ((string)$r['provider_key'] !== self::PROVIDER) { $out['other_provider']++; continue; }     // another provider's variant: not handled by this API
            $raw = trim((string)($r['provider_variant_id'] ?? ''));
            $v = $raw === '' ? null : licencedash_parse_variant($raw);
            if ($v === null) {
                $kind = $raw === '' ? 'empty_variant' : 'unparsable'; $out[$kind]++;
                if (count($out['bad']) < 50) $out['bad'][] = ['plan_id' => (int)$r['plan_id'], 'product' => (string)$r['name'], 'duration' => (string)$r['duration'], 'provider_id' => $raw,
                                                               'reason' => $raw === '' ? 'no provider service id on this variant' : 'provider service id is not readable'];
                continue;
            }
            $r['pid'] = (string)$v['product_id'];
            $out['rows'][] = $r;
        }
        return $out;
    }

    /* --------------------------------- run ---------------------------------- */

    private static function lockGet(PDO $pdo): bool { return (int)$pdo->query("SELECT GET_LOCK('" . self::LOCK . "', 0)")->fetchColumn() === 1; }
    private static function lockRelease(PDO $pdo): void { try { $pdo->query("SELECT RELEASE_LOCK('" . self::LOCK . "')")->fetchColumn(); } catch (Throwable $e) { /* frees on disconnect */ } }

    public static function emptyCounts(): array
    {
        return ['pages' => 0, 'fetched' => 0, 'catalog_ids' => 0, 'mapped' => 0, 'checked' => 0, 'updated' => 0, 'already_correct' => 0, 'invalid_price' => 0,
                'failed_requests' => 0, 'mapping_errors' => 0, 'not_in_catalog' => 0, 'failed_updates' => 0, 'other_provider' => 0, 'duration_ms' => 0,
                'pending_increase' => 0, 'missing' => 0, 'removed' => 0, 'grace_applied' => 0];
    }

    /**
     * ONE price-check cycle (under the named lock): fetch -> match -> compare -> correct. $force ignores the ON/OFF toggle and the interval (admin CHECK NOW).
     * @return array{status:string, message:string, counts:array, run_id:string}   status: ok | partial | failed | skipped
     */
    public static function tick(PDO $pdo, string $trigger = 'cron', bool $force = false, int $retries = self::RETRIES): array
    {
        $none = static fn(string $st, string $msg): array => ['status' => $st, 'message' => $msg, 'counts' => self::emptyCounts(), 'run_id' => ''];
        $locked = false;
        try {
            self::ensureSchema($pdo); self::ensurePriceSchema($pdo); pp_ensure_schema($pdo);
            $s = self::settings($pdo);
            if (!$force) {
                if (!$s['enabled']) return $none('skipped', 'Auto price sync is switched OFF.');
                $last = (int)self::raw($pdo, 'sync_last_run', '0');
                if ($last > 0 && (time() - $last) < max(0, $s['interval_sec'] - 1)) return $none('skipped', 'Interval not reached yet.');
            }
            if (!self::lockGet($pdo)) return $none('skipped', 'Another price check is already running.');
            $locked = true;
            if (!$force) {                                   // another process may have just finished a cycle while we waited for the lock
                $last = (int)self::raw($pdo, 'sync_last_run', '0');
                if ($last > 0 && (time() - $last) < max(0, $s['interval_sec'] - 1)) return $none('skipped', 'Interval not reached yet.');
            }
            self::prune($pdo);
            return self::cycle($pdo, substr($trigger, 0, 40), $retries, $s);
        } catch (Throwable $e) {
            error_log('[price_sync:tick] ' . $e->getMessage());
            return $none('failed', 'Price check failed: ' . ops_sanitize($e->getMessage(), 200));
        } finally {
            if ($locked) self::lockRelease($pdo);
        }
    }

    private static function cycle(PDO $pdo, string $actor, int $retries, array $s): array
    {
        $run = bin2hex(random_bytes(6)); $t0 = microtime(true);
        $c = self::emptyCounts(); self::$fetchErrors = 0; self::$lastError = '';
        try {
            $f = self::fetchAllPages($pdo, $run, $retries);
        } catch (Throwable $e) {
            self::error($pdo, $run, 'fetch_final', $e->getMessage());
            $c['failed_requests'] = max(1, self::$fetchErrors);
            $c['grace_applied'] = pp_apply_expired($pdo, $run);   // v59: a deadline is honoured even when the catalog could not be read (uses the last verified pending price)
            return self::finish($pdo, $run, $c, 'failed', 'The provider catalog could not be read, so NO price was checked or changed (stored prices kept): ' . ops_sanitize($e->getMessage(), 180), $t0, false, []);
        }
        $c['pages'] = $f['pages']; $c['fetched'] = count($f['list']); $c['failed_requests'] = self::$fetchErrors;
        $map = self::collectPrices($f['list']);
        $c['catalog_ids'] = count($map);
        if (!$map) {
            self::error($pdo, $run, 'normalize', 'The catalog contained no usable entries (' . $c['fetched'] . ' raw entries). Nothing was changed.');
            $c['grace_applied'] = pp_apply_expired($pdo, $run);
            return self::finish($pdo, $run, $c, 'failed', 'The catalog had no usable entries; no price was checked or changed.', $t0, false, []);
        }

        $loc = self::loadLocal($pdo);
        $c['mapped'] = count($loc['rows']);
        $c['other_provider'] = $loc['other_provider'];
        $c['mapping_errors'] = $loc['empty_variant'] + $loc['unparsable'];
        $problems = $loc['bad'];
        $okPlans = [];
        $cfgP = pp_config($pdo);
        // v59: the whole catalog was read (all pages, valid JSON, no errors) - the ONLY situation in which a missing duration may be counted.
        $missingNow = 0;
        foreach ($loc['rows'] as $row) { if (!isset($map[$row['pid']])) $missingNow++; }
        $plausible = pp_catalog_plausible(count($loc['rows']), $missingNow);
        foreach ($loc['rows'] as $row) {
            $label = ['plan_id' => (int)$row['plan_id'], 'product' => (string)$row['name'], 'duration' => (string)$row['duration'], 'provider_id' => (string)$row['pid']];
            if (!isset($map[$row['pid']])) {
                if (in_array((string)($row['lifecycle_state'] ?? 'active'), ['removed', 'reappeared'], true)) continue;   // already archived: not a problem any more
                $c['not_in_catalog']++;
                if (!$plausible) {
                    $c['mapping_errors']++;
                    if (count($problems) < 50) $problems[] = $label + ['reason' => 'catalog looks incomplete (' . $missingNow . ' of ' . count($loc['rows']) . ' mapped durations missing in one read): NOT counted as removal'];
                    continue;
                }
                $c['missing']++;
                $res = pp_lifecycle_missing($pdo, $row, $cfgP);
                if ($res === 'removed') $c['removed']++;
                if (count($problems) < 50) $problems[] = $label + ['reason' => 'service id is not in the provider catalog (' . ($res === 'removed' ? 'archived now' : 'check ' . ((int)$row['provider_missing_count'] + 1) . ' of ' . $cfgP['remove_after_checks']) . '; stored price kept)'];
                continue;
            }
            $it = $map[$row['pid']];
            if ($it['conflict']) {
                pp_lifecycle_seen($pdo, $row, $cfgP, false);
                $c['invalid_price']++;
                if (count($problems) < 50) $problems[] = $label + ['reason' => 'the catalog lists this service id twice with different prices (stored price kept)'];
                continue;
            }
            // v61/v64: TWO independent price categories. Each uses its OWN exchange rate and its OWN columns; one can never overwrite the other,
            // and an invalid rate / price in one category never blocks, corrupts or borrows from the other.
            $wps = [
                DUAL_NORMAL   => self::websitePrice($it, ['rate' => $s['normal_rate']] + $s),   // Normal User  (normal_usd_inr_rate ONLY)
                DUAL_RESELLER => self::websitePrice($it, ['rate' => $s['rate']] + $s),          // Reseller     (sync_usd_inr_rate ONLY)
            ];
            $anyValid = false;
            foreach (DUAL_CATS as $cat) if ($wps[$cat]['inr'] !== null && $wps[$cat]['micro'] !== null) $anyValid = true;
            pp_lifecycle_seen($pdo, $row, $cfgP, !($it['conflict'] ?? false) && $anyValid);   // v60: a removed duration is restored only with a valid price
            if (!$anyValid) {
                $c['invalid_price']++;
                foreach (DUAL_CATS as $cat) if (count($problems) < 50) $problems[] = $label + ['category' => $cat, 'reason' => dual_cat_label($cat) . ': ' . ($wps[$cat]['reason'] ?? 'unusable provider price') . ' (stored price kept)'];
                continue;
            }
            $c['checked']++;
            $allOk = true;
            foreach (DUAL_CATS as $cat) {
                $wp = $wps[$cat];
                if ($wp['inr'] === null || $wp['micro'] === null) {      // this category cannot be calculated (e.g. its rate is not configured): reject ITS update only
                    $c['invalid_price']++; $allOk = false;
                    if (count($problems) < 50) $problems[] = $label + ['category' => $cat, 'reason' => dual_cat_label($cat) . ': ' . ($wp['reason'] ?? 'unusable provider price') . ' (stored ' . dual_cat_label($cat) . ' price kept)'];
                    continue;
                }
                $rowC = pp_row_view($row, $cat);
                $d = pp_handle_price($pdo, $run, $actor, $rowC, $wp, $it, $cfgP, $cat);
                if ($d['result'] === 'already') { $c['already_correct']++; }
                elseif ($d['result'] === 'handled') { $c['pending_increase']++; $allOk = false; }
                elseif ($d['result'] === 'apply') {
                    $allOk = false;
                    if (self::correct($pdo, $run, $actor, $rowC, $d['wp'], $it, $cat)) {
                        $c['updated']++;
                        pp_event($pdo, $d['event'], $rowC + ['pid' => $row['pid']], $d['ev'] + ['source' => $d['source'], 'status' => 'applied', 'new_selling' => $d['wp']['inr'], 'resolution' => 'applied', 'category' => $cat]);
                    } else { $c['failed_updates']++; }
                } else { $c['failed_updates']++; $allOk = false; }
            }
            if ($allOk) $okPlans[] = (int)$row['plan_id'];
        }
        $c['grace_applied'] = pp_apply_expired($pdo, $run);   // v59: deadlines that passed (after this read, so a price that reverted was already cancelled above)
        if ($plausible) self::put($pdo, 'sync_last_complete_catalog', (string)time());
        self::closeOpenCards($pdo, $okPlans);
        self::reportProblems($pdo, $run, $problems);
        self::put($pdo, 'sync_problems', (string)json_encode(array_slice($problems, 0, 50), JSON_UNESCAPED_UNICODE));

        $partial = $c['failed_updates'] > 0 || $c['invalid_price'] > 0 || $c['mapping_errors'] > 0;
        $msg = 'Checked ' . $c['checked'] . ' of ' . $c['mapped'] . ' mapped variants: ' . $c['updated'] . ' corrected, ' . $c['already_correct'] . ' already correct'
             . ($c['failed_updates'] ? ', ' . $c['failed_updates'] . ' FAILED to update' : '')
             . ($c['invalid_price'] ? ', ' . $c['invalid_price'] . ' with an unusable provider price (kept)' : '')
             . ($c['mapping_errors'] ? ', ' . $c['mapping_errors'] . ' mapping error(s)' : '') . '.';
        return self::finish($pdo, $run, $c, $partial ? 'partial' : 'ok', $msg, $t0, $c['failed_updates'] === 0 && $c['checked'] > 0, $problems);
    }

    private static function finish(PDO $pdo, string $run, array $c, string $status, string $msg, float $t0, bool $success, array $problems): array
    {
        $c['duration_ms'] = (int)round((microtime(true) - $t0) * 1000);
        $now = time();
        self::put($pdo, 'sync_last_run', (string)$now);
        self::put($pdo, 'sync_last_status', $status . ': ' . $msg);
        if ($success) self::put($pdo, 'sync_last_success', (string)$now);
        self::put($pdo, 'sync_last_cycle', (string)json_encode(['at' => $now, 'run_id' => $run, 'status' => $status, 'message' => $msg, 'counts' => $c]));
        if ($status === 'ok' || $status === 'partial') {              // only cycles that really read the catalog count for the measured interval
            $ts = json_decode((string)self::raw($pdo, 'sync_cycle_times', '[]'), true); $ts = is_array($ts) ? array_map('intval', $ts) : [];
            $ts[] = $now; $ts = array_slice($ts, -8);
            self::put($pdo, 'sync_cycle_times', (string)json_encode(array_values($ts)));
        }
        return ['status' => $status, 'message' => $msg, 'counts' => $c, 'run_id' => $run];
    }

    /** Problems (unusable provider price / mapping) go to the error log only when the set CHANGES, so a 10 s cycle does not write every time. */
    private static function reportProblems(PDO $pdo, string $run, array $problems): void
    {
        try {
            $sig = md5((string)json_encode(array_map(static fn(array $p): array => [$p['plan_id'], $p['reason']], $problems)));
            if (self::raw($pdo, 'sync_problem_sig', '') === $sig) return;
            self::put($pdo, 'sync_problem_sig', $sig);
            foreach (array_slice($problems, 0, 25) as $p) self::error($pdo, $run, 'price_check', $p['product'] . ' / ' . $p['duration'] . ' (provider id ' . $p['provider_id'] . '): ' . $p['reason']);
        } catch (Throwable $e) { error_log('[price_sync:problems] ' . $e->getMessage()); }
    }

    /** The price is already right: keep the provider price / rate / cost columns in step, but only when they differ (no write on a normal cycle). No history card. */
    private static function refreshMeta(PDO $pdo, array $row, array $wp): void
    {
        try {
            $pp = self::microToDec($wp['micro']);
            $same = $row['provider_price'] !== null && number_format((float)$row['provider_price'], 4, '.', '') === $pp
                && (string)$row['provider_currency'] === $wp['currency']
                && $row['price_rate'] !== null && number_format((float)$row['price_rate'], 4, '.', '') === number_format((float)$wp['rate'], 4, '.', '')
                && $row['inr_cost'] !== null && number_format((float)$row['inr_cost'], 2, '.', '') === $wp['inr']
                && (int)$row['price_manual'] === 0;
            if ($same) return;
            $pdo->prepare('UPDATE product_plans SET provider_price = :pp, provider_currency = :pc, price_rate = :pr, inr_cost = :ic, price_manual = 0 WHERE id = :id')
                ->execute([':pp' => $pp, ':pc' => $wp['currency'], ':pr' => $wp['rate'], ':ic' => $wp['inr'], ':id' => (int)$row['plan_id']]);
        } catch (Throwable $e) { error_log('[price_sync:meta] ' . $e->getMessage()); }
    }

    /** A pending / failed card whose variant now verifiably has the right price becomes "Already Correct" (one statement per cycle, only when such cards exist). */
    private static function closeOpenCards(PDO $pdo, array $planIds): void
    {
        if (!$planIds) return;
        try {
            if ((int)$pdo->query("SELECT COUNT(*) FROM provider_price_history WHERE status IN ('pending','failed')")->fetchColumn() === 0) return;
            foreach (array_chunk($planIds, 300) as $chunk) {
                $pdo->exec("UPDATE provider_price_history SET status = 'already', applied = 1, error = NULL, note = 'Verified correct on a later check.', updated_at = NOW(), applied_at = COALESCE(applied_at, NOW())
                            WHERE status IN ('pending','failed') AND plan_id IN (" . implode(',', array_map('intval', $chunk)) . ')');
            }
        } catch (Throwable $e) { error_log('[price_sync:close_cards] ' . $e->getMessage()); }
    }

    /* ------------------------------ correction ------------------------------ */

    private static function saveCard(PDO $pdo, ?int $id, array $f): int
    {
        $cols = array_values(array_intersect(self::EVENT_COLS, array_keys($f)));
        $bind = []; foreach ($cols as $k) $bind[':' . $k] = $f[$k];
        if ($id !== null && $id > 0) {
            $set = array_map(static fn(string $k): string => $k . ' = :' . $k, $cols);
            $pdo->prepare('UPDATE provider_price_history SET ' . implode(', ', $set) . ', updated_at = NOW() WHERE id = :__id')->execute($bind + [':__id' => $id]);
            return $id;
        }
        $pdo->prepare('INSERT INTO provider_price_history (' . implode(', ', $cols) . ', created_at, updated_at) VALUES (:' . implode(', :', $cols) . ', NOW(), NOW())')->execute($bind);
        return (int)$pdo->lastInsertId();
    }

    /**
     * Corrects ONE variant: price = provider price x category rate (from the original provider price). An admin-typed price / price_manual flag does not prevent it.
     * The history card is written BEFORE the transaction (so a failure stays visible as FAILED) and finished after it; one open card per variant (updated in place, never duplicated).
     */
    private static function correct(PDO $pdo, string $run, string $actor, array $row, array $wp, array $it, string $cat = DUAL_NORMAL): bool
    {
        $cat = dual_cat_valid($cat); $cc = dual_cat_cols($cat);
        $plan = (int)$row['plan_id']; $pid = (int)$row['product_id']; $want = (string)$wp['inr'];
        $old = number_format((float)$row['price'], 2, '.', '');
        $om = $row['provider_price'] === null ? null : self::toMicro($row['provider_price']);
        $dir = ($om === null || $om === $wp['micro']) ? 'corrected' : ($wp['micro'] > $om ? 'up' : 'down');
        $card = null;
        try {
            $f = ['run_id' => $run, 'plan_id' => $plan, 'product_id' => $pid, 'product_name' => mb_substr((string)$row['name'], 0, 160), 'duration' => mb_substr((string)($row['duration'] ?? ''), 0, 60),
                  'provider_variant_id' => (string)$row['pid'], 'api_version' => ($row['provider_api_version'] ?? '') !== '' ? (string)$row['provider_api_version'] : null,
                  'old_provider_price' => $om === null ? null : self::microToDec($om), 'new_provider_price' => self::microToDec($wp['micro']), 'provider_currency' => $wp['currency'], 'rate' => $wp['rate'],
                  'old_inr_cost' => $row['inr_cost'], 'new_inr_cost' => $want, 'old_selling_price' => $old, 'new_selling_price' => null, 'direction' => $dir, 'price_category' => $cat,
                  'change_type' => $dir === 'up' ? 'Increased' : ($dir === 'down' ? 'Decreased' : 'Corrected'), 'applied' => 0, 'status' => 'pending', 'error' => null,
                  'price_diff' => number_format((float)$want - (float)$old, 2, '.', ''), 'note' => 'Detected; the correction is being applied.'];
            $q = $pdo->prepare("SELECT id FROM provider_price_history WHERE plan_id = :p AND price_category = :cat AND status IN ('pending','failed') ORDER BY id DESC LIMIT 1");
            $q->execute([':p' => $plan, ':cat' => $cat]);
            $open = $q->fetchColumn();
            $card = self::saveCard($pdo, $open ? (int)$open : null, $f);
            $pdo->prepare('UPDATE provider_price_history SET attempts = attempts + 1 WHERE id = :i')->execute([':i' => $card]);
        } catch (Throwable $e) { error_log('[price_sync:card] ' . $e->getMessage()); $card = null; }     // the correction itself still runs

        try {
            $pdo->beginTransaction();
            $q = $pdo->prepare('SELECT `' . $cc['price'] . '` FROM product_plans WHERE id = :id FOR UPDATE');
            $q->execute([':id' => $plan]);
            $cur = $q->fetchColumn();
            if ($cur === false) throw new RuntimeException('variant no longer exists');
            $already = number_format((float)$cur, 2, '.', '') === $want;
            $snap = (string)json_encode(['id' => (string)$row['pid'], 'price' => self::microToDec($wp['micro']), 'currency' => $wp['currency'], 'rate' => $wp['rate'], 'inr' => $want, 'checked_at' => date('c')]);
            $pdo->prepare('UPDATE product_plans SET `' . $cc['price'] . '` = :p, `' . $cc['pprice'] . '` = :pp, provider_currency = :pc, `' . $cc['rate'] . '` = :pr, `' . $cc['manual'] . '` = 0, `' . $cc['inr'] . '` = :ic, provider_snapshot = :sn, provider_last_valid_price = :pp2,
                            `' . $cc['source'] . '` = \'sync\', `' . $cc['detected'] . '` = NULL, `' . $cc['deadline'] . '` = NULL, `' . $cc['pend_pp'] . '` = NULL, `' . $cc['pend_inr'] . '` = NULL WHERE id = :id')
                ->execute([':p' => $want, ':pp' => self::microToDec($wp['micro']), ':pc' => $wp['currency'], ':pr' => $wp['rate'], ':ic' => $want, ':sn' => $snap, ':pp2' => self::microToDec($wp['micro']), ':id' => $plan]);
            $q->execute([':id' => $plan]);
            $final = $q->fetchColumn();
            if ($final === false || number_format((float)$final, 2, '.', '') !== $want) throw new RuntimeException('read-back check failed: stored price is ' . ($final === false ? 'missing' : number_format((float)$final, 2, '.', '')) . ', expected ' . $want);
            $pdo->prepare('UPDATE products SET last_synced_at = NOW() WHERE id = :p')->execute([':p' => $pid]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            self::error($pdo, $run, 'apply_price', 'plan ' . $plan . ': ' . $e->getMessage());
            if ($card !== null) {
                try {
                    $pdo->prepare("UPDATE provider_price_history SET status = 'failed', applied = 0, error = :e, note = 'Correction failed; it is retried on the next cycle (this card is updated, not duplicated).', updated_at = NOW() WHERE id = :i")
                        ->execute([':e' => mb_substr(ops_sanitize($e->getMessage(), 240), 0, 250), ':i' => $card]);
                } catch (Throwable $e2) { error_log('[price_sync:card_fail] ' . $e2->getMessage()); }
            }
            return false;
        }
        try {
            if ($card !== null) {
                $pdo->prepare("UPDATE provider_price_history SET status = :st, applied = 1, error = NULL, new_selling_price = :fp, note = :n, applied_at = NOW(), updated_at = NOW() WHERE id = :i")
                    ->execute([':st' => $already ? 'already' : 'success', ':fp' => $want, ':i' => $card,
                               ':n' => $already ? 'Another process had already set the correct price.' : ($dir === 'corrected' ? 'Website price did not match provider price x ' . $wp['rate'] . '; corrected.' : 'Provider price changed; website price set to provider price x ' . $wp['rate'] . '.')]);
            }
            if (!$already) self::log($pdo, $run, 'price_changed', $pid, (string)$row['pid'], ['plan_id' => $plan, 'category' => $cat, 'price_inr' => $old, 'provider_price' => $om === null ? null : self::microToDec($om)],
                                     ['plan_id' => $plan, 'category' => $cat, 'price_inr' => $want, 'provider_price' => self::microToDec($wp['micro']), 'currency' => $wp['currency'], 'rate' => $wp['rate']], 'Website ' . dual_cat_label($cat) . ' price corrected to provider price x rate.', $actor);
        } catch (Throwable $e) { error_log('[price_sync:card_done] ' . $e->getMessage()); }     // the price itself is already saved
        return true;
    }

    /* ------------------------------ admin read side ------------------------------ */

    /** Everything the Price Sync page shows. Database only (no provider call). */
    public static function overview(PDO $pdo): array
    {
        self::ensureSchema($pdo); self::ensurePriceSchema($pdo);
        $cfg = self::settings($pdo);
        $cy = json_decode((string)self::raw($pdo, 'sync_last_cycle', ''), true); $cy = is_array($cy) ? $cy : null;
        $lastRun = (int)self::raw($pdo, 'sync_last_run', '0');
        $lastOk = (int)self::raw($pdo, 'sync_last_success', '0');
        $ts = json_decode((string)self::raw($pdo, 'sync_cycle_times', '[]'), true); $ts = is_array($ts) ? array_values(array_map('intval', $ts)) : [];
        $actual = count($ts) >= 2 ? (int)round(($ts[count($ts) - 1] - $ts[0]) / (count($ts) - 1)) : null;
        $durSec = $cy ? (int)ceil(((int)($cy['counts']['duration_ms'] ?? 0)) / 1000) : 0;
        $alive = $lastRun > 0 && (time() - $lastRun) <= max(90, 3 * $cfg['interval_sec'] + 2 * $durSec);
        $o = [
            'enabled' => $cfg['enabled'], 'target_interval' => $cfg['interval_sec'], 'actual_interval' => $actual, 'measured_samples' => count($ts),
            'rate' => $cfg['rate'], 'rates' => ['reseller' => $cfg['rate'], 'normal' => $cfg['normal_rate']],   // v63: both live settings (Monitor is the single place that edits them)
            'last_run' => $lastRun, 'last_success' => $lastOk, 'last_status' => (string)self::raw($pdo, 'sync_last_status', ''),
            'scheduler_alive' => $alive, 'next_check_at' => ($cfg['enabled'] && $alive) ? max(time(), $lastRun + $cfg['interval_sec']) : null,
            'cycle' => $cy, 'problems' => json_decode((string)self::raw($pdo, 'sync_problems', '[]'), true) ?: [],
            'cycle_seconds' => $cy ? round(((int)($cy['counts']['duration_ms'] ?? 0)) / 1000, 1) : null,
        ];
        $o['effective_note'] = !$cfg['enabled'] ? 'Auto price sync is switched OFF.'
            : (!$alive ? 'No server-side cycle ran recently: the cron job is not running (see README_V55.md).'
            : ($actual !== null && $actual > $cfg['interval_sec'] * 1.5 ? 'Running slower than the target: one cycle reads the whole provider catalog (' . ($o['cycle_seconds'] ?? '?') . ' s per cycle) and the provider / hosting limits the cadence.' : 'Running at the target interval.'));
        $o['mapped_total'] = (int)$pdo->query("SELECT COUNT(*) FROM product_plans pl JOIN products p ON p.id = pl.product_id WHERE pl.source = 'api' AND pl.provider_key = '" . self::PROVIDER . "' AND p.archived_at IS NULL")->fetchColumn();
        $o['corrected_24h'] = (int)$pdo->query("SELECT COUNT(*) FROM provider_price_history WHERE status = 'success' AND created_at > (NOW() - INTERVAL 24 HOUR)")->fetchColumn();
        $o['failed_open'] = (int)$pdo->query("SELECT COUNT(*) FROM provider_price_history WHERE status = 'failed'")->fetchColumn();
        $o['errors_24h'] = (int)$pdo->query('SELECT COUNT(*) FROM sync_errors WHERE created_at > (NOW() - INTERVAL 24 HOUR)')->fetchColumn();
        return $o;
    }

    /** Price-change history cards (DB only). status: '' | success | failed | pending | already */
    public static function history(PDO $pdo, int $page, int $per, string $status = ''): array
    {
        self::ensurePriceSchema($pdo);
        $where = in_array($status, ['success', 'failed', 'pending', 'already'], true) ? 'WHERE status = ' . $pdo->quote($status) : '';
        $total = (int)$pdo->query('SELECT COUNT(*) FROM provider_price_history ' . $where)->fetchColumn();
        $q = $pdo->query('SELECT * FROM provider_price_history ' . $where . ' ORDER BY id DESC LIMIT ' . max(1, $per) . ' OFFSET ' . (max(1, $page) - 1) * max(1, $per));
        return ['rows' => $q->fetchAll(), 'total' => $total];
    }

    public static function recentErrors(PDO $pdo, int $limit = 60): array
    {
        self::ensureSchema($pdo);
        return $pdo->query('SELECT id, run_id, stage, attempt, message, created_at FROM sync_errors ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)))->fetchAll();
    }

    /** Admin switch / interval (settings only). */
    public static function saveSettings(PDO $pdo, ?bool $enabled, ?int $interval): void
    {
        if ($enabled !== null) self::put($pdo, 'sync_enabled', $enabled ? '1' : '0');
        if ($interval !== null) self::put($pdo, 'sync_interval_seconds', (string)max(self::MIN_INTERVAL, min(86400, $interval)));
    }
}

/**
 * Shared "may this synced product leave Maintenance?" check, used by the EXISTING Products -> Maintenance toggle
 * (api/admin/products.php) (the Provider Sync GO LIVE page was removed in v55). Manual products are never affected.
 * Returns null when OK, otherwise the message to show the admin.
 */
function rp_provider_go_live_check(PDO $pdo, int $pid): ?string
{
    try {
        $q = $pdo->prepare('SELECT p.source, p.sync_status, p.name, c.enabled AS cat_enabled FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.id = :id');
        $q->execute([':id' => $pid]);
        $p = $q->fetch();
        if (!$p) return 'Product not found.';
        if (($p['source'] ?? 'manual') !== 'provider') return null;
        if ($p['sync_status'] === 'removed') return 'This product was removed on the provider and cannot go live.';
        if ((int)$p['cat_enabled'] !== 1) return 'Choose an active category before going live.';
        if (trim((string)$p['name']) === '') return 'Name is required before going live.';
        $pl = $pdo->prepare('SELECT price, provider_variant_id, provider_api_version, duration FROM product_plans WHERE product_id = :id AND enabled = 1');
        $pl->execute([':id' => $pid]);
        $plans = $pl->fetchAll();
        if (!$plans) return 'This product has no enabled variant.';
        $seenIds = [];
        foreach ($plans as $pr) {
            if ((float)$pr['price'] <= 0) return 'Set a price above 0 on every enabled variant before going live.';
            // v47: never expose an unmapped / mis-mapped variant: valid numeric provider id, valid version, no id shared by two variants of this card
            $v = licencedash_parse_variant((string)$pr['provider_variant_id']);
            if ($v === null) return 'Variant "' . (string)$pr['duration'] . '" has no valid provider service ID. Fix the mapping or disable the variant before going live.';
            $ver = strtolower(trim((string)$pr['provider_api_version']));
            if ($ver !== '' && !in_array($ver, ['v1', 'v2', 'both'], true)) return 'Variant "' . (string)$pr['duration'] . '" has an invalid API version.';
            $k = (string)$v['product_id'];
            if (isset($seenIds[$k])) return 'Two enabled variants of this product use provider service #' . $k . '. Each duration needs its own service ID.';
            $seenIds[$k] = true;
        }
        return null;
    } catch (Throwable $e) {
        error_log('[provider_sync:golive_check] ' . $e->getMessage());
        return null;   // columns missing (sync never ran) => plain manual behaviour
    }
}

