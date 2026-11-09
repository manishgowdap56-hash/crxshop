<?php
declare(strict_types=1);

/*
 * Schema bootstrap/compatibility layer.
 *
 * The previous version only tried to ADD columns to legacy tables. On a new
 * or empty cPanel database that meant users/orders did not exist at all, so
 * the first SELECT/INSERT in an API endpoint could throw a raw PDO exception
 * and the browser reported: "Server returned a non-JSON response."
 *
 * This version first ensures the canonical schema exists (CREATE IF NOT
 * EXISTS only; it never drops or renames application data), then applies
 * additive compatibility columns.
 */
function rp_table_exists(PDO $pdo, string $table): bool
{
    $q = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
    );
    $q->execute([':t' => $table]);
    return (int)$q->fetchColumn() > 0;
}

function rp_column_exists(PDO $pdo, string $table, string $column): bool
{
    $q = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
    );
    $q->execute([':t' => $table, ':c' => $column]);
    return (int)$q->fetchColumn() > 0;
}

function rp_index_exists(PDO $pdo, string $table, string $index): bool
{
    $q = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i'
    );
    $q->execute([':t' => $table, ':i' => $index]);
    return (int)$q->fetchColumn() > 0;
}

function rp_add_column(PDO $pdo, string $table, string $column, string $definition): void
{
    if (!rp_table_exists($pdo, $table) || rp_column_exists($pdo, $table, $column)) return;
    try {
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        error_log("[schema_compat] added {$table}.{$column}");
    } catch (Throwable $e) {
        error_log("[schema_compat] failed adding {$table}.{$column}: " . $e->getMessage());
    }
}

function rp_add_index(PDO $pdo, string $table, string $index, string $columnsSql): void
{
    if (!rp_table_exists($pdo, $table) || rp_index_exists($pdo, $table, $index)) return;
    try {
        $pdo->exec("ALTER TABLE `{$table}` ADD INDEX `{$index}` {$columnsSql}");
        error_log("[schema_compat] added index {$table}.{$index}");
    } catch (Throwable $e) {
        error_log("[schema_compat] failed adding index {$table}.{$index}: " . $e->getMessage());
    }
}

function rp_execute_schema_file(PDO $pdo): void
{
    $schemaFile = BASE_PATH . '/database/schema.sql';
    if (!is_file($schemaFile) || !is_readable($schemaFile)) {
        throw new RuntimeException('Canonical database/schema.sql is missing or unreadable.');
    }

    $sql = file_get_contents($schemaFile);
    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException('Canonical database/schema.sql is empty.');
    }

    // The project schema contains ordinary CREATE/INSERT statements only;
    // split on statement-ending semicolons while avoiding semicolons inside
    // quoted strings.
    $statements = [];
    $buffer = '';
    $quote = null;
    $len = strlen($sql);

    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        $next = $i + 1 < $len ? $sql[$i + 1] : '';

        if ($quote !== null) {
            $buffer .= $ch;
            if ($ch === '\\' && $next !== '') {
                $buffer .= $next;
                $i++;
                continue;
            }
            if ($ch === $quote) {
                // SQL escapes a quote by doubling it.
                if ($next === $quote) {
                    $buffer .= $next;
                    $i++;
                } else {
                    $quote = null;
                }
            }
            continue;
        }

        if ($ch === "'" || $ch === '"') {
            $quote = $ch;
            $buffer .= $ch;
            continue;
        }

        if ($ch === ';') {
            $statement = trim($buffer);
            if ($statement !== '') $statements[] = $statement;
            $buffer = '';
            continue;
        }

        $buffer .= $ch;
    }

    $tail = trim($buffer);
    if ($tail !== '') $statements[] = $tail;

    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }
}

function ensure_canonical_schema(PDO $pdo): void
{
    // If the database is empty or partially provisioned, CREATE IF NOT EXISTS
    // from the canonical schema restores the complete table set safely.
    $required = ['users', 'categories', 'products', 'product_plans', 'orders', 'settings'];
    $missing = false;
    foreach ($required as $table) {
        if (!rp_table_exists($pdo, $table)) {
            $missing = true;
            break;
        }
    }

    if (!$missing) return;

    rp_execute_schema_file($pdo);
    error_log('[schema_compat] canonical schema bootstrap completed');
}

function ensure_schema_compatibility(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        $pdo = db();

        // IMPORTANT: create the actual tables before attempting any SELECT,
        // INSERT or ALTER against them. This fixes fresh/empty cPanel DBs.
        ensure_canonical_schema($pdo);

        // The settings table is required by the public site and admin settings API.
        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
            setting_key VARCHAR(60) NOT NULL,
            setting_value TEXT,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES
            ('panel_name', 'Keys Panel'),
            ('logo_path', ''),
            ('join_link', ''),
            ('maintenance_mode', '0'),
            ('api_endpoint', 'https://dripclientstore.shop/api/v1'),
            ('api_master_token', ''),
            ('api_service_name', 'DRIPSTORE API'),
            ('api_provider_enabled', '1'),
            ('api2_endpoint', 'https://keypanel.shop/reseller_gateway.php'),
            ('api2_master_token', ''),
            ('api2_service_name', 'KEYPANEL API'),
            ('api2_provider_enabled', '0'),
            ('api3_endpoint', 'https://panelstore.shop/api/v1/index.php'),
            ('api3_master_token', ''),
            ('api3_service_name', 'PANELSTORE API'),
            ('api3_provider_enabled', '0'),
            ('api4_endpoint', 'https://bantibhaiya.to/api/reseller_v1.php'),
            ('api4_api_key', ''),
            ('api4_master_token', ''),
            ('api4_service_name', 'BANTIBHAIYA API'),
            ('api4_provider_enabled', '0'),
            ('api5_endpoint', 'https://licencedashboard.shop/api/v1'),
            ('api5_master_token', ''),
            ('api5_service_name', 'LICENCE DASHBOARD API'),
            ('api5_provider_enabled', '0'),
            ('api6_endpoint', 'https://whitexmodz.store/api/v1'),
            ('api6_master_token', ''),
            ('api6_service_name', 'VIP SELLER HUB API'),
            ('api6_provider_enabled', '0'),
            ('deposit_min_amount', '10'),
            ('deposit_max_amount', '100000'),
            ('paytm_enabled', 'off'),
            ('paytm_mid', ''),
            ('paytm_upi_id', ''),
            ('paytm_display_name', '')
        ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key)");

        // Move the legacy single-provider gateway setting to the supplied DRIPSTORE API contract
        // when the old default is still present. Never overwrite a custom endpoint.
        $legacyEndpoint = (string)$pdo->query("SELECT setting_value FROM settings WHERE setting_key='api_endpoint' LIMIT 1")->fetchColumn();
        if ($legacyEndpoint === '' || strpos($legacyEndpoint, 'keypanel.shop/reseller_gateway.php') !== false) {
            $pdo->prepare("UPDATE settings SET setting_value='https://dripclientstore.shop/api/v1' WHERE setting_key='api_endpoint'")->execute();
        }

        // Fields required by the current authentication/session layer.
        rp_add_column($pdo, 'users', 'email', 'VARCHAR(120) DEFAULT NULL');
        rp_add_column($pdo, 'users', 'banned', 'TINYINT(1) NOT NULL DEFAULT 0');
        rp_add_column($pdo, 'users', 'balance', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00');
        rp_add_column($pdo, 'users', 'api_token', 'VARCHAR(80) DEFAULT NULL');
        rp_add_column($pdo, 'users', 'referral_code', 'VARCHAR(32) DEFAULT NULL');
        rp_add_column($pdo, 'users', 'referred_by', 'INT UNSIGNED DEFAULT NULL');
        rp_add_column($pdo, 'users', 'last_login_at', 'DATETIME DEFAULT NULL');
        rp_add_column($pdo, 'users', 'last_active_at', 'DATETIME DEFAULT NULL');
        rp_add_column($pdo, 'users', 'password_reset_at', 'DATETIME DEFAULT NULL');
        rp_add_column($pdo, 'users', 'created_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');

        // Critical for Dashboard, My Keys and both purchase paths.
        rp_add_column($pdo, 'orders', 'user_id', 'INT UNSIGNED DEFAULT NULL');
        rp_add_column($pdo, 'orders', 'source', "ENUM('website','api') NOT NULL DEFAULT 'website'");
        rp_add_column($pdo, 'orders', 'plan_source', "ENUM('local','api') NOT NULL DEFAULT 'local'");
        rp_add_column($pdo, 'orders', 'upstream_order_id', 'VARCHAR(60) DEFAULT NULL');
        rp_add_column($pdo, 'orders', 'status', "ENUM('completed','pending','failed','refunded') NOT NULL DEFAULT 'completed'");
        rp_add_column($pdo, 'orders', 'created_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');

        // Multi-provider support (additive). Existing rows get 'primary' = the existing provider,
        // so no product or order is migrated or changed. orders.provider_key stays NULL for old orders.
        rp_add_column($pdo, 'product_plans', 'provider_key', "VARCHAR(20) NOT NULL DEFAULT 'primary'");
        // Per-duration ON/OFF. The canonical schema already has it; this only repairs a legacy table. Existing rows default to ON (1).
        rp_add_column($pdo, 'product_plans', 'enabled', 'TINYINT(1) NOT NULL DEFAULT 1');
        rp_add_column($pdo, 'orders', 'provider_key', 'VARCHAR(20) DEFAULT NULL');

        // Paytm UPI-QR deposit gateway columns (additive; legacy manual rows keep NULLs).
        rp_add_column($pdo, 'deposits', 'method', 'VARCHAR(50) DEFAULT NULL');
        rp_add_column($pdo, 'deposits', 'gateway_order_id', 'VARCHAR(64) DEFAULT NULL');
        rp_add_column($pdo, 'deposits', 'gateway_txn_id', 'VARCHAR(64) DEFAULT NULL');
        rp_add_column($pdo, 'deposits', 'gateway_status', 'VARCHAR(32) DEFAULT NULL');
        rp_add_column($pdo, 'deposits', 'gateway_response', 'TEXT DEFAULT NULL');
        if (rp_table_exists($pdo, 'deposits')) {
            try {
                // One deposit row per gateway order id (NULLs allowed for legacy manual deposits).
                if (!rp_index_exists($pdo, 'deposits', 'uniq_dep_gateway_order') && rp_column_exists($pdo, 'deposits', 'gateway_order_id')) {
                    $pdo->exec('ALTER TABLE `deposits` ADD UNIQUE KEY `uniq_dep_gateway_order` (`gateway_order_id`)');
                }
                if (!rp_index_exists($pdo, 'deposits', 'idx_dep_gateway_txn') && rp_column_exists($pdo, 'deposits', 'gateway_txn_id')) {
                    $pdo->exec('ALTER TABLE `deposits` ADD KEY `idx_dep_gateway_txn` (`gateway_txn_id`)');
                }
            } catch (Throwable $e) {
                error_log('[schema_compat] deposits gateway indexes: ' . $e->getMessage());
            }
        }

        // Store UI redesign (additive): product image + feature sections shown on the Store cards.
        rp_add_column($pdo, 'products', 'description', 'TEXT');
        rp_add_column($pdo, 'products', 'image_path', 'VARCHAR(255) DEFAULT NULL');
        rp_add_column($pdo, 'products', 'features', 'MEDIUMTEXT');
        rp_add_column($pdo, 'products', 'video_path', 'VARCHAR(255) DEFAULT NULL');
        rp_add_index($pdo, 'order_keys', 'idx_ok_external', '(`external_key`(100))');
        rp_add_index($pdo, 'product_keys', 'idx_pk_keyvalue', '(`key_value`(100))');

        // Product plan controls used by the admin builder and checkout.
        rp_add_column($pdo, 'product_plans', 'allow_quantity', 'TINYINT(1) NOT NULL DEFAULT 1');

        // Product/order relationships used by Store and Admin.
        rp_add_column($pdo, 'orders', 'product_id', 'INT UNSIGNED DEFAULT NULL');
        rp_add_column($pdo, 'orders', 'plan_id', 'INT UNSIGNED DEFAULT NULL');
        rp_add_column($pdo, 'orders', 'quantity', 'INT UNSIGNED NOT NULL DEFAULT 1');
        rp_add_column($pdo, 'orders', 'unit_price', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00');
        rp_add_column($pdo, 'orders', 'total_price', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00');
    } catch (Throwable $e) {
        error_log('[schema_compat] ' . $e->getMessage());
        // Do not hide a schema/bootstrap failure behind an HTML PHP fatal.
        // API requests are converted to JSON by the global shutdown handler.
    }

    // One-time signup Access Keys (additive table, own try/catch: can never affect the checks above).
    try {
        $pdo = db();
        $pdo->exec("CREATE TABLE IF NOT EXISTS access_keys (
            id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
            access_key       VARCHAR(32)  NOT NULL,
            username         VARCHAR(40)  NOT NULL,
            status           ENUM('active','used','revoked') NOT NULL DEFAULT 'active',
            used_by_user_id  INT UNSIGNED DEFAULT NULL,
            used_at          DATETIME     DEFAULT NULL,
            revoked_at       DATETIME     DEFAULT NULL,
            created_by       INT UNSIGNED DEFAULT NULL,
            created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_access_key (access_key),
            KEY idx_ak_username (username),
            KEY idx_ak_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        error_log('[schema_compat] access_keys: ' . $e->getMessage());
    }

    // v36 operations: pending-order refund controller columns + decision log. Additive only; runs once (flag schema_v36).
    // Placed BEFORE the v35 block because that block returns early once v35 is done.
    try {
        if (get_setting('schema_v36', '') !== '1') {
            $pdo = db();
            rp_add_column($pdo, 'orders', 'refund_status', 'VARCHAR(16) DEFAULT NULL');
            rp_add_column($pdo, 'orders', 'refund_method', 'VARCHAR(16) DEFAULT NULL');
            rp_add_column($pdo, 'orders', 'refunded_at', 'DATETIME DEFAULT NULL');
            rp_add_column($pdo, 'orders', 'last_api_status', 'VARCHAR(80) DEFAULT NULL');
            rp_add_column($pdo, 'orders', 'last_checked_at', 'DATETIME DEFAULT NULL');
            rp_add_column($pdo, 'orders', 'check_attempts', 'SMALLINT UNSIGNED NOT NULL DEFAULT 0');
            rp_add_index($pdo, 'orders', 'idx_order_status_created', '(`status`, `created_at`)');
            $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS order_refund_log (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id   BIGINT UNSIGNED NOT NULL,
  order_ref  VARCHAR(40)  NOT NULL,
  user_id    INT UNSIGNED NOT NULL,
  amount     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  decision   VARCHAR(24)  NOT NULL,
  result     VARCHAR(24)  NOT NULL,
  reason     VARCHAR(500) DEFAULT NULL,
  evidence   TEXT,
  actor      VARCHAR(40)  NOT NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_orl_order (order_id, created_at),
  KEY idx_orl_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
            );
            // Defaults are inserted only when missing: an existing value is never overwritten.
            $pdo->exec("INSERT INTO settings (setting_key, setting_value, updated_at) VALUES
                ('order_pending_window_seconds', '600', NOW()),
                ('refund_controller_auto', '1', NOW()),
                ('refund_controller_last_run', '0', NOW())
                ON DUPLICATE KEY UPDATE setting_key = setting_key");
            // Only flag as done when every column really exists (rp_add_column swallows errors and logs them).
            if (rp_column_exists($pdo, 'orders', 'refund_status') && rp_column_exists($pdo, 'orders', 'refund_method') && rp_column_exists($pdo, 'orders', 'refunded_at')
                && rp_column_exists($pdo, 'orders', 'last_api_status') && rp_column_exists($pdo, 'orders', 'last_checked_at')
                && rp_column_exists($pdo, 'orders', 'check_attempts') && rp_table_exists($pdo, 'order_refund_log')) {
                set_setting('schema_v36', '1');
            }
        }
    } catch (Throwable $e) {
        error_log('[schema_compat] v36 refund controller: ' . $e->getMessage());
    }

    // v35 operations: audit log, admin alerts, price changes, API-user flag, product archive, order failure reason.
    // Additive only (CREATE IF NOT EXISTS / ADD COLUMN IF MISSING). Own try/catch: can never affect the checks above.
    try {
        if (get_setting('schema_v35', '') === '1') return;   // already migrated: skip all checks on later requests
        $pdo = db();
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS audit_logs (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actor_user_id INT UNSIGNED DEFAULT NULL,
  actor_label   VARCHAR(60)  DEFAULT NULL,
  event_type    VARCHAR(60)  NOT NULL,
  result        ENUM('success','failure','info','denied') NOT NULL DEFAULT 'info',
  entity_type   VARCHAR(30)  DEFAULT NULL,
  entity_id     VARCHAR(40)  DEFAULT NULL,
  order_ref     VARCHAR(40)  DEFAULT NULL,
  user_id       INT UNSIGNED DEFAULT NULL,
  reason        VARCHAR(500) DEFAULT NULL,
  ip            VARCHAR(45)  DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_audit_created (created_at),
  KEY idx_audit_event (event_type, created_at),
  KEY idx_audit_order (order_ref),
  KEY idx_audit_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
);
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS admin_alerts (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  open_key           VARCHAR(120) DEFAULT NULL,
  dedupe_key         VARCHAR(120) NOT NULL,
  alert_type         VARCHAR(40)  NOT NULL,
  severity           ENUM('info','warning','critical') NOT NULL DEFAULT 'warning',
  status             ENUM('open','resolved') NOT NULL DEFAULT 'open',
  product_id         INT UNSIGNED DEFAULT NULL,
  product_name       VARCHAR(120) DEFAULT NULL,
  plan_id            INT UNSIGNED DEFAULT NULL,
  duration           VARCHAR(60)  DEFAULT NULL,
  order_id           BIGINT UNSIGNED DEFAULT NULL,
  order_ref          VARCHAR(40)  DEFAULT NULL,
  provider_key       VARCHAR(20)  DEFAULT NULL,
  provider_label     VARCHAR(80)  DEFAULT NULL,
  stock_source       ENUM('local','api') DEFAULT NULL,
  reason             VARCHAR(500) DEFAULT NULL,
  recommended_action VARCHAR(500) DEFAULT NULL,
  occurrences        INT UNSIGNED NOT NULL DEFAULT 1,
  first_seen         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at        DATETIME     DEFAULT NULL,
  resolved_by        INT UNSIGNED DEFAULT NULL,
  resolved_note      VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_alert_open (open_key),
  KEY idx_alert_status (status, last_seen),
  KEY idx_alert_type (alert_type),
  KEY idx_alert_order (order_ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
);
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS price_changes (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id      INT UNSIGNED NOT NULL,
  plan_id         INT UNSIGNED NOT NULL,
  product_name    VARCHAR(120) NOT NULL,
  duration        VARCHAR(60)  NOT NULL,
  old_price       DECIMAL(12,2) NOT NULL,
  new_price       DECIMAL(12,2) NOT NULL,
  changed_by      INT UNSIGNED DEFAULT NULL,
  changed_by_name VARCHAR(60)  DEFAULT NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pc_plan (plan_id, created_at),
  KEY idx_pc_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
);
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS price_change_acks (
  user_id   INT UNSIGNED NOT NULL,
  change_id BIGINT UNSIGNED NOT NULL,
  acked_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, change_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
);
        rp_add_column($pdo, 'users', 'api_enabled', 'TINYINT(1) NOT NULL DEFAULT 0');
        rp_add_column($pdo, 'users', 'lowbal_ack_at', 'DATETIME DEFAULT NULL');
        // v42: provider-sync columns (provider_api_version, provider_price, ...) are read by the storefront and the purchase endpoints,
        // so they must exist on EVERY request. One cheap information_schema query; the full additive ALTERs run only when something is missing.
        try { PriceSyncService::ensureSchemaIfNeeded($pdo); } catch (Throwable $e) { error_log('[schema_compat] price sync schema: ' . $e->getMessage()); }
        rp_add_column($pdo, 'products', 'archived_at', 'DATETIME DEFAULT NULL');
        rp_add_column($pdo, 'products', 'archived_by', 'INT UNSIGNED DEFAULT NULL');
        rp_add_column($pdo, 'orders', 'failure_reason', 'VARCHAR(500) DEFAULT NULL');
        // Never overwrites an existing value: a missing row means "required" (existing behaviour).
        if (rp_table_exists($pdo, 'settings')) {
            $pdo->exec("INSERT INTO settings (setting_key, setting_value, updated_at) VALUES ('access_key_required', '1', NOW())
                        ON DUPLICATE KEY UPDATE setting_key = setting_key");
        }
        // v37: rp_add_column() swallows errors, so the flag may only be set after the columns are verified to exist.
        // (Before v37 the flag was set unconditionally; one failed ALTER then left orders.failure_reason missing for good
        //  and the Pending Orders query failed with "Server error. Check the database/schema configuration.")
        if (rp_column_exists($pdo, 'orders', 'failure_reason') && rp_column_exists($pdo, 'users', 'api_enabled')
            && rp_column_exists($pdo, 'users', 'lowbal_ack_at') && rp_table_exists($pdo, 'audit_logs') && rp_table_exists($pdo, 'admin_alerts')) {
            set_setting('schema_v35', '1');
        } else {
            error_log('[schema_compat] v35 incomplete: flag NOT set, will retry on the next request');
        }
    } catch (Throwable $e) {
        error_log('[schema_compat] v35 ops: ' . $e->getMessage());
    }
}


/* ====================================================================
 *  v37: schema verification / safe repair used by the Pending Orders API,
 *  and database error classification (no secrets ever leave the server).
 * ==================================================================== */

/** Columns the Pending Orders + Refund Controller code reads/writes on `orders` (name => definition used to add it). */
function ops_required_order_columns(): array
{
    return [
        'failure_reason'  => 'VARCHAR(500) DEFAULT NULL',
        'refund_status'   => 'VARCHAR(16) DEFAULT NULL',
        'refund_method'   => 'VARCHAR(16) DEFAULT NULL',
        'refunded_at'     => 'DATETIME DEFAULT NULL',
        'last_api_status' => 'VARCHAR(80) DEFAULT NULL',
        'last_checked_at' => 'DATETIME DEFAULT NULL',
        'check_attempts'  => 'SMALLINT UNSIGNED NOT NULL DEFAULT 0',
        // v38: refund tracking written inside the refund transaction
        'refund_amount'    => 'DECIMAL(12,2) DEFAULT NULL',
        'refund_ledger_id' => 'BIGINT UNSIGNED DEFAULT NULL',
        // v58: provider-confirmed failure reason (OUT_OF_STOCK); NULL for every other order
        'failure_code'     => 'VARCHAR(32) DEFAULT NULL',
    ];
}

/** v38 columns on order_refund_log (before/after status and the provider/API result summary of each decision). */
function ops_required_log_columns(): array
{
    return [
        'prev_status' => 'VARCHAR(40) DEFAULT NULL',
        'new_status'  => 'VARCHAR(40) DEFAULT NULL',
        'api_status'  => 'VARCHAR(80) DEFAULT NULL',
    ];
}

/**
 * Verify (and, when $repair is true, additively repair) everything the Pending Orders page needs.
 * Never drops/renames anything. Two information_schema reads when nothing is missing.
 * @return array{ok:bool, missing:string[], repaired:string[]}
 */
function ops_schema_check(PDO $pdo, bool $repair = true): array
{
    $missing = []; $repaired = [];

    $tables = [];
    foreach ($pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $tables[strtolower((string)$t)] = true;
    }

    foreach (['settings', 'users', 'products', 'product_plans', 'orders', 'order_keys', 'product_keys', 'wallet_ledger', 'audit_logs', 'admin_alerts'] as $t) {
        if (empty($tables[$t])) $missing[] = 'table ' . $t;
    }

    if (empty($tables['order_refund_log']) && $repair) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS order_refund_log (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, order_id BIGINT UNSIGNED NOT NULL, order_ref VARCHAR(40) NOT NULL,
              user_id INT UNSIGNED NOT NULL, amount DECIMAL(12,2) NOT NULL DEFAULT 0.00, decision VARCHAR(24) NOT NULL,
              result VARCHAR(24) NOT NULL, reason VARCHAR(500) DEFAULT NULL, evidence TEXT, actor VARCHAR(40) NOT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id),
              KEY idx_orl_order (order_id, created_at), KEY idx_orl_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $repaired[] = 'table order_refund_log';
            $tables['order_refund_log'] = true;
        } catch (Throwable $e) {
            error_log('[ops_schema_check] cannot create order_refund_log: ' . $e->getMessage());
        }
    }
    if (empty($tables['order_refund_log'])) $missing[] = 'table order_refund_log';

    if (!empty($tables['orders'])) {
        $have = [];
        foreach ($pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders'")->fetchAll(PDO::FETCH_COLUMN) as $c) {
            $have[strtolower((string)$c)] = true;
        }
        foreach (ops_required_order_columns() as $col => $def) {
            if (!empty($have[$col])) continue;
            if ($repair) {
                rp_add_column($pdo, 'orders', $col, $def);             // logs its own failure
                if (rp_column_exists($pdo, 'orders', $col)) { $repaired[] = 'orders.' . $col; continue; }
            }
            $missing[] = 'orders.' . $col;
        }
        if ($repair && rp_column_exists($pdo, 'orders', 'refund_ledger_id') && !rp_index_exists($pdo, 'orders', 'idx_order_refund_ledger')) {
            rp_add_index($pdo, 'orders', 'idx_order_refund_ledger', '(`refund_ledger_id`)');
        }
        if ($repair && !rp_index_exists($pdo, 'orders', 'idx_order_status_created')) {
            rp_add_index($pdo, 'orders', 'idx_order_status_created', '(`status`, `created_at`)');   // optional performance index
        }
    }

    if (!empty($tables['order_refund_log'])) {
        $haveLog = [];
        foreach ($pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_refund_log'")->fetchAll(PDO::FETCH_COLUMN) as $c) {
            $haveLog[strtolower((string)$c)] = true;
        }
        foreach (ops_required_log_columns() as $col => $def) {
            if (!empty($haveLog[$col])) continue;
            if ($repair) {
                rp_add_column($pdo, 'order_refund_log', $col, $def);
                if (rp_column_exists($pdo, 'order_refund_log', $col)) { $repaired[] = 'order_refund_log.' . $col; continue; }
            }
            $missing[] = 'order_refund_log.' . $col;
        }
    }

    if ($repaired) {
        error_log('[ops_schema_check] repaired: ' . implode(', ', $repaired));
        if (!$missing) { try { set_setting('schema_v36', '1'); } catch (Throwable $e) { /* best effort */ } }
    }
    if ($missing) error_log('[ops_schema_check] STILL MISSING: ' . implode(', ', $missing));
    return ['ok' => !$missing, 'missing' => $missing, 'repaired' => $repaired];
}

/**
 * Turn any Throwable into a safe, admin-readable error. Never returns credentials, DSNs, SQL text or stack traces.
 * @return array{status:int, code:string, message:string, detail:string}
 */
function ops_classify_db_error(Throwable $e): array
{
    $msg = $e->getMessage();
    $no  = ($e instanceof PDOException && isset($e->errorInfo[1])) ? (int)$e->errorInfo[1] : 0;
    if ($no === 1054 || stripos($msg, 'Unknown column') !== false) {
        $d = preg_match("/Unknown column '([^']+)'/i", $msg, $m) ? $m[1] : 'unknown column';
        return ['status' => 500, 'code' => 'SCHEMA_MISMATCH', 'detail' => $d,
                'message' => 'The database is missing a column this page needs (' . $d . '). Run database/migration_v37_ops.sql (safe, additive) or reload once so the automatic repair can run.'];
    }
    if ($no === 1146 || stripos($msg, "doesn't exist") !== false) {
        $d = preg_match("/Table '(?:[^']*\\.)?([^']+)' doesn't exist/i", $msg, $m) ? $m[1] : 'unknown table';
        return ['status' => 500, 'code' => 'SCHEMA_MISMATCH', 'detail' => $d,
                'message' => 'The database is missing a table this page needs (' . $d . '). Run database/migration_v37_ops.sql (safe, additive).'];
    }
    if (in_array($no, [1044, 1045, 2002, 2006, 2013], true)) {
        return ['status' => 503, 'code' => 'DB_UNAVAILABLE', 'detail' => 'mysql ' . $no, 'message' => 'The database is not reachable or the configured credentials were rejected. Check config/config.php or the ROYALPANEL_DB_* variables, then the server error log.'];
    }
    if ($no === 1142 || $no === 1143 || $no === 1227) {
        return ['status' => 500, 'code' => 'DB_PERMISSION', 'detail' => 'mysql ' . $no, 'message' => 'The database user lacks a required privilege (SELECT/INSERT/UPDATE/ALTER). Grant it in cPanel, or apply the migration manually.'];
    }
    if ($e instanceof PDOException) {
        return ['status' => 500, 'code' => 'DB_ERROR', 'detail' => 'mysql ' . ($no ?: 'n/a'), 'message' => 'A database query failed. The technical detail was written to the server error log.'];
    }
    return ['status' => 500, 'code' => 'SERVER_ERROR', 'detail' => get_class($e), 'message' => 'The request failed. The technical detail was written to the server error log.'];
}

/**
 * One-time link of PAST refunds to their orders (v38). Reads wallet_ledger / audit_logs; writes ONLY the tracking columns of
 * orders (refund_ledger_id, refund_amount, refunded_at, refund_status, refund_method). It never changes an order's status,
 * a wallet balance or a ledger row, and never credits anything. Idempotent (only touches rows still without a ledger link).
 * Orders that are still 'pending' are deliberately skipped: a refund row on a pending order is an inconsistency for an admin to review.
 */
function ops_backfill_refund_links(PDO $pdo): void
{
    if (get_setting('refund_links_backfilled_v38', '') === '1') return;
    try {
        $pdo->exec(
            "UPDATE orders o
             JOIN wallet_ledger l ON l.user_id = o.user_id AND l.type = 'refund' AND l.reference_id = o.order_ref COLLATE utf8mb4_unicode_ci
             SET o.refund_ledger_id = l.id, o.refund_amount = l.amount,
                 o.refunded_at = COALESCE(o.refunded_at, l.created_at), o.refund_status = 'refunded'
             WHERE o.refund_ledger_id IS NULL AND o.status <> 'pending'"
        );
        $pdo->exec(
            "UPDATE orders o
             SET o.refund_method = 'manual'
             WHERE o.refund_ledger_id IS NOT NULL AND o.refund_method IS NULL
               AND EXISTS (SELECT 1 FROM audit_logs a WHERE a.event_type = 'order.admin_refund' AND a.order_ref = o.order_ref COLLATE utf8mb4_unicode_ci)"
        );
        $pdo->exec("UPDATE orders SET refund_method = 'auto' WHERE refund_ledger_id IS NOT NULL AND refund_method IS NULL");
        set_setting('refund_links_backfilled_v38', '1');
    } catch (Throwable $e) {
        error_log('[ops_backfill_refund_links] (not fatal, retried on a later admin request) ' . $e->getMessage());
    }
}
