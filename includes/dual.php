<?php
declare(strict_types=1);

/*
 * v61 - NORMAL USER + RESELLER (dual pricing, independent exchange rates, Reseller Membership)
 *
 * Account type
 *   users.account_type  'normal' (DEFAULT) | 'reseller'     (separate from users.role = user|admin, which is untouched)
 *   Added with DEFAULT 'normal', so every EXISTING user becomes a Normal User and every NEW registration is a Normal User
 *   without any UPDATE statement. The migration NEVER runs an UPDATE on account_type, so it cannot reset an admin-promoted
 *   reseller. Only dual_set_account_type() (admin) and dual_membership_purchase() (wallet purchase, one transaction) change it.
 *
 * Prices (product_plans)
 *   Normal User price    = column `price`                (unchanged: every existing reader / purchase path keeps working)
 *   Reseller price       = column `reseller_price`
 *   Each category has its OWN custom price, price rate, calculated INR cost, provider baseline, pending-review columns
 *   (see dual_cat_cols()). Exchange rates: Normal = setting normal_usd_inr_rate, Reseller = setting sync_usd_inr_rate (the
 *   pre-existing Provider Price Sync rate). Neither code path ever writes the other setting.
 *
 * The category is ALWAYS derived server-side from users.account_type read from the database (never from the session,
 * a cookie, a request field or JavaScript).
 */

const DUAL_NORMAL   = 'normal';
const DUAL_RESELLER = 'reseller';
const DUAL_CATS     = [DUAL_NORMAL, DUAL_RESELLER];

/* ------------------------------------------------------------------ */
/*  Column map per price category                                      */
/* ------------------------------------------------------------------ */

/**
 * Static whitelist of column names per category. These names are interpolated into SQL by price_policy.php / price_sync.php,
 * so they must only ever come from this function.
 * @return array<string,string>
 */
function dual_cat_cols(string $cat): array
{
    $cat = dual_cat_valid($cat);   // v64: strict - an unknown category can never select the Normal columns
    if ($cat === DUAL_RESELLER) {
        return [
            'price' => 'reseller_price', 'custom' => 'reseller_custom_price', 'custom_on' => 'reseller_custom_price_enabled',
            'rate' => 'reseller_price_rate', 'inr' => 'reseller_inr_cost', 'pprice' => 'reseller_provider_price',
            'pend_pp' => 'reseller_pending_provider_price', 'pend_inr' => 'reseller_pending_calculated_inr',
            'detected' => 'reseller_increase_detected_at', 'deadline' => 'reseller_increase_deadline',
            'source' => 'reseller_price_change_source', 'version' => 'reseller_price_change_version', 'manual' => 'reseller_price_manual',
            'rate_setting' => 'sync_usd_inr_rate',
        ];
    }
    return [
        'price' => 'price', 'custom' => 'custom_price', 'custom_on' => 'custom_price_enabled',
        'rate' => 'price_rate', 'inr' => 'inr_cost', 'pprice' => 'provider_price',
        'pend_pp' => 'pending_provider_price', 'pend_inr' => 'pending_calculated_inr',
        'detected' => 'price_increase_detected_at', 'deadline' => 'price_increase_deadline',
        'source' => 'price_change_source', 'version' => 'price_change_version', 'manual' => 'price_manual',
        'rate_setting' => 'normal_usd_inr_rate',
    ];
}

/**
 * v64: STRICT category check. Only the exact strings 'normal' and 'reseller' are accepted. Anything else (empty, unknown, wrong case)
 * throws - an invalid category is NEVER silently turned into 'normal'. Use dual_cat_try() when a null result is wanted instead.
 */
function dual_cat_valid(string $cat): string
{
    if ($cat === DUAL_NORMAL || $cat === DUAL_RESELLER) return $cat;
    throw new InvalidArgumentException('Invalid price category.');
}

/** 'normal' | 'reseller' | null (missing / ambiguous / unknown). Never guesses. */
function dual_cat_try($cat): ?string
{
    return ($cat === DUAL_NORMAL || $cat === DUAL_RESELLER) ? $cat : null;
}

function dual_cat_label(string $cat): string
{
    return $cat === DUAL_RESELLER ? 'Reseller' : 'Normal User';
}

/* ------------------------------------------------------------------ */
/*  Schema (additive, idempotent, never touches existing values)       */
/* ------------------------------------------------------------------ */

/** Mirrors database/migration_v61_dual_pricing_membership.sql. MUST run outside a transaction. */
function dual_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    if ($pdo->inTransaction()) throw new RuntimeException('dual_ensure_schema must run outside a transaction');

    // ---- users: account type (DEFAULT 'normal' = all existing users become Normal Users, nothing is UPDATEd) ----
    rp_add_column($pdo, 'users', 'account_type', "VARCHAR(10) NOT NULL DEFAULT 'normal'");
    rp_add_column($pdo, 'users', 'reseller_since', 'DATETIME DEFAULT NULL');
    rp_add_column($pdo, 'users', 'reseller_source', 'VARCHAR(12) DEFAULT NULL');           // 'admin' | 'paid'
    rp_add_column($pdo, 'users', 'account_type_changed_by', 'INT UNSIGNED DEFAULT NULL');
    rp_add_column($pdo, 'users', 'account_type_changed_at', 'DATETIME DEFAULT NULL');
    rp_add_index($pdo, 'users', 'idx_users_account_type', '(`account_type`)');

    // ---- product_plans: Reseller price + the independent per-category price-sync columns ----
    $cols = [
        'reseller_price'                    => 'DECIMAL(12,2) DEFAULT NULL',
        'reseller_custom_price_enabled'     => 'TINYINT(1) NOT NULL DEFAULT 0',
        'reseller_custom_price'             => 'DECIMAL(12,2) DEFAULT NULL',
        'reseller_price_manual'             => 'TINYINT(1) NOT NULL DEFAULT 0',
        'reseller_price_rate'               => 'DECIMAL(12,4) DEFAULT NULL',
        'reseller_inr_cost'                 => 'DECIMAL(12,2) DEFAULT NULL',
        'reseller_provider_price'           => 'DECIMAL(12,4) DEFAULT NULL',
        'reseller_pending_provider_price'   => 'DECIMAL(12,4) DEFAULT NULL',
        'reseller_pending_calculated_inr'   => 'DECIMAL(12,2) DEFAULT NULL',
        'reseller_increase_detected_at'     => 'DATETIME DEFAULT NULL',
        'reseller_increase_deadline'        => 'DATETIME DEFAULT NULL',
        'reseller_price_change_source'      => 'VARCHAR(20) DEFAULT NULL',
        'reseller_price_change_version'     => 'INT UNSIGNED NOT NULL DEFAULT 0',
    ];
    foreach ($cols as $c => $def) rp_add_column($pdo, 'product_plans', $c, $def);

    // ---- history / event tables get a category (existing rows = 'normal', the original single price) ----
    rp_add_column($pdo, 'price_change_events', 'price_category', "VARCHAR(10) NOT NULL DEFAULT 'normal'");
    rp_add_column($pdo, 'provider_price_history', 'price_category', "VARCHAR(10) NOT NULL DEFAULT 'normal'");

    // ---- Reseller Membership ledger (paid purchases AND admin grants/revokes) ----
    $pdo->exec("CREATE TABLE IF NOT EXISTS reseller_memberships (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT UNSIGNED NOT NULL,
        source VARCHAR(12) NOT NULL DEFAULT 'paid',
        payment_ref VARCHAR(64) DEFAULT NULL,
        price_due DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        price_paid DECIMAL(12,2) DEFAULT NULL,
        gateway_txn_id VARCHAR(64) DEFAULT NULL,
        gateway_status VARCHAR(32) DEFAULT NULL,
        gateway_response TEXT,
        payment_status VARCHAR(16) NOT NULL DEFAULT 'pending',
        expires_at DATETIME DEFAULT NULL,
        activated_at DATETIME DEFAULT NULL,
        revoked_at DATETIME DEFAULT NULL,
        admin_id INT UNSIGNED DEFAULT NULL,
        note VARCHAR(255) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_rm_ref (payment_ref),
        UNIQUE KEY uniq_rm_txn (gateway_txn_id),
        KEY idx_rm_user (user_id, payment_status),
        KEY idx_rm_status (payment_status, created_at),
        CONSTRAINT fk_rm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // v62: Membership is paid from the website WALLET. Extra columns (existing rows keep NULL = legacy / admin rows).
    rp_add_column($pdo, 'reseller_memberships', 'payment_method', 'VARCHAR(20) DEFAULT NULL');       // 'wallet' | NULL (legacy Paytm / admin)
    rp_add_column($pdo, 'reseller_memberships', 'amount_deducted', 'DECIMAL(12,2) DEFAULT NULL');
    rp_add_column($pdo, 'reseller_memberships', 'wallet_ledger_id', 'BIGINT UNSIGNED DEFAULT NULL');
    rp_add_column($pdo, 'reseller_memberships', 'wallet_ref', 'VARCHAR(60) DEFAULT NULL');
    rp_add_column($pdo, 'reseller_memberships', 'balance_before', 'DECIMAL(12,2) DEFAULT NULL');
    rp_add_column($pdo, 'reseller_memberships', 'balance_after', 'DECIMAL(12,2) DEFAULT NULL');

    // ---- settings: INSERT IGNORE only (an existing value is never overwritten) ----
    $pdo->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
        ('reseller_membership_enabled', '0'),
        ('reseller_membership_price', '0'),
        ('reseller_membership_description', ''),
        ('reseller_membership_button', '1')");

    // ---- one-time seeding (guarded by a flag, never repeated) ----
    $flag = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'dual_seed_v61' LIMIT 1");
    $flag->execute();
    if ((string)$flag->fetchColumn() !== '1') {
        // Reseller columns start as a copy of the CURRENT values so no price changes silently at migration time. Only rows still NULL are seeded.
        $pdo->exec("UPDATE product_plans SET
                reseller_price = price,
                reseller_custom_price_enabled = custom_price_enabled,
                reseller_custom_price = custom_price,
                reseller_price_manual = price_manual,
                reseller_price_rate = price_rate,
                reseller_inr_cost = inr_cost,
                reseller_provider_price = provider_price
            WHERE reseller_price IS NULL");
        // Normal rate starts equal to the existing sync rate (no silent repricing). Admin then edits either rate independently.
        $r = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'sync_usd_inr_rate' LIMIT 1")->fetchColumn();
        $seed = ($r === false || trim((string)$r) === '') ? '30' : trim((string)$r);
        $pdo->prepare("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('normal_usd_inr_rate', :v)")->execute([':v' => $seed]);
        // Review window default: 15 minutes unless the admin already chose a value other than the old 25-minute default.
        $g = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'price_grace_minutes' LIMIT 1")->fetchColumn();
        if ($g === false || trim((string)$g) === '' || trim((string)$g) === '25') {
            $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('price_grace_minutes', '15') ON DUPLICATE KEY UPDATE setting_value = '15'")->execute();
        }
        $pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('dual_seed_v61', '1') ON DUPLICATE KEY UPDATE setting_value = '1'");
    }
    $done = true;
    if (function_exists('settings_reload')) settings_reload();
}

/** Cheap per-request guard (two information_schema reads) so no reader ever meets a missing column. */
function dual_ensure_schema_if_needed(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $a = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_plans'
                               AND COLUMN_NAME IN ('reseller_price','reseller_custom_price','reseller_custom_price_enabled','reseller_price_manual','reseller_price_rate','reseller_inr_cost','reseller_provider_price','reseller_pending_provider_price','reseller_pending_calculated_inr','reseller_increase_detected_at','reseller_increase_deadline','reseller_price_change_source','reseller_price_change_version')")->fetchColumn();
        $b = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME IN ('account_type','reseller_since','reseller_source')")->fetchColumn();
        $t = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reseller_memberships' AND COLUMN_NAME IN ('payment_method','amount_deducted','wallet_ledger_id','wallet_ref','balance_before','balance_after')")->fetchColumn();
        $e = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('price_change_events','provider_price_history') AND COLUMN_NAME = 'price_category'")->fetchColumn();
        $s = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'dual_seed_v61' LIMIT 1")->fetchColumn();
        if ($a >= 13 && $b >= 3 && $t === 6 && $e >= 2 && (string)$s === '1') return;
        // price_policy / price_sync tables must exist before the category columns are added to them
        if (function_exists('pp_ensure_schema')) pp_ensure_schema($pdo);
        if (class_exists('PriceSyncService')) { PriceSyncService::ensureSchema($pdo); PriceSyncService::ensurePriceSchema($pdo); }
        dual_ensure_schema($pdo);
    } catch (Throwable $ex) { error_log('[dual_ensure_schema_if_needed] ' . $ex->getMessage()); }
}

/* ------------------------------------------------------------------ */
/*  Account type (server-side, fresh database read)                    */
/* ------------------------------------------------------------------ */

/** 'normal' | 'reseller' for a user id, read from the database NOW (revocation is therefore immediate). Unknown / error => 'normal'. */
function dual_user_type(PDO $pdo, int $userId): string
{
    if ($userId <= 0) return DUAL_NORMAL;
    try {
        $q = $pdo->prepare('SELECT account_type FROM users WHERE id = :id LIMIT 1');
        $q->execute([':id' => $userId]);
        return (string)$q->fetchColumn() === DUAL_RESELLER ? DUAL_RESELLER : DUAL_NORMAL;
    } catch (Throwable $e) {
        error_log('[dual_user_type] ' . $e->getMessage());
        return DUAL_NORMAL;
    }
}

/** Price category of the CURRENTLY AUTHENTICATED user. Guests / errors => normal. */
function dual_current_cat(?PDO $pdo = null): string
{
    $u = function_exists('current_user') ? current_user() : null;
    if (!$u) return DUAL_NORMAL;
    return dual_user_type($pdo ?? db(), (int)$u['id']);
}

function dual_is_reseller(?PDO $pdo = null): bool
{
    return dual_current_cat($pdo) === DUAL_RESELLER;
}

/** Authorisation gate for reseller-only endpoints / pages (server side). */
function dual_require_reseller(): array
{
    $u = require_user();
    if (dual_user_type(db(), (int)$u['id']) !== DUAL_RESELLER) {
        if (function_exists('is_api_request') && is_api_request()) json_error('Reseller access required.', 403, ['code' => 'RESELLER_ONLY']);
        http_response_code(403);
        exit('Reseller access required.');
    }
    return $u;
}

/**
 * The unit price of a plan row for a category. The row must contain `price` and `reseller_price`.
 * A reseller plan price that was never set (NULL) falls back to the Normal price so the item stays purchasable (documented).
 */
function dual_pick_price(array $plan, string $cat): float
{
    if ($cat === DUAL_RESELLER && array_key_exists('reseller_price', $plan) && $plan['reseller_price'] !== null && $plan['reseller_price'] !== '') {
        return (float)$plan['reseller_price'];
    }
    return (float)($plan['price'] ?? 0);
}

/** Authoritative unit price for (plan, user) from the database. Returns [price, category]. */
function dual_unit_price(PDO $pdo, int $planId, int $userId): array
{
    $cat = dual_user_type($pdo, $userId);
    $q = $pdo->prepare('SELECT price, reseller_price FROM product_plans WHERE id = :id LIMIT 1');
    $q->execute([':id' => $planId]);
    $r = $q->fetch();
    return [$r ? dual_pick_price($r, $cat) : 0.0, $cat];
}

/* ------------------------------------------------------------------ */
/*  Exchange rates (two independent settings)                          */
/* ------------------------------------------------------------------ */

function dual_rate_setting(string $cat): string
{
    return $cat === DUAL_RESELLER ? 'sync_usd_inr_rate' : 'normal_usd_inr_rate';
}

/**
 * v64: the validated rate of ONE category from ITS OWN setting only (Normal = normal_usd_inr_rate, Reseller = sync_usd_inr_rate).
 * Returns null when that setting is missing or invalid. There is NO fallback to the other category's rate and NO silent default:
 * the caller must reject the automatic price update for this category and keep the stored price.
 */
function dual_rate(PDO $pdo, string $cat): ?string
{
    $key = dual_rate_setting(dual_cat_valid($cat));
    $raw = function_exists('pp_setting') ? pp_setting($pdo, $key, '') : '';
    return $raw === '' ? null : PriceSyncService::normalizeRate($raw);
}

/* ------------------------------------------------------------------ */
/*  Admin: grant / revoke Reseller access                              */
/* ------------------------------------------------------------------ */

/**
 * Only an admin action or a verified payment may call this. $type = normal|reseller.
 * @return array{ok:bool, error?:string, account_type?:string, changed?:bool}
 */
function dual_set_account_type(PDO $pdo, int $userId, string $type, int $adminId, string $note = ''): array
{
    if (!in_array($type, DUAL_CATS, true)) return ['ok' => false, 'error' => 'Invalid account type.'];
    try {
        $pdo->beginTransaction();
        $q = $pdo->prepare('SELECT id, role, account_type FROM users WHERE id = :id FOR UPDATE');
        $q->execute([':id' => $userId]);
        $u = $q->fetch();
        if (!$u) { $pdo->rollBack(); return ['ok' => false, 'error' => 'User not found.']; }
        if ((string)$u['account_type'] === $type) { $pdo->commit(); return ['ok' => true, 'account_type' => $type, 'changed' => false]; }
        if ($type === DUAL_RESELLER) {
            $pdo->prepare("UPDATE users SET account_type = 'reseller', reseller_since = NOW(), reseller_source = 'admin', account_type_changed_by = :a, account_type_changed_at = NOW() WHERE id = :id")
                ->execute([':a' => $adminId, ':id' => $userId]);
            $pdo->prepare("INSERT INTO reseller_memberships (user_id, source, payment_status, price_due, activated_at, admin_id, note) VALUES (:u, 'admin', 'granted', 0, NOW(), :a, :n)")
                ->execute([':u' => $userId, ':a' => $adminId, ':n' => mb_substr($note !== '' ? $note : 'Granted by admin', 0, 255)]);
        } else {
            $pdo->prepare("UPDATE users SET account_type = 'normal', reseller_source = NULL, account_type_changed_by = :a, account_type_changed_at = NOW() WHERE id = :id")
                ->execute([':a' => $adminId, ':id' => $userId]);
            $pdo->prepare("UPDATE reseller_memberships SET revoked_at = NOW() WHERE user_id = :u AND revoked_at IS NULL AND payment_status IN ('paid','granted')")
                ->execute([':u' => $userId]);
            $pdo->prepare("INSERT INTO reseller_memberships (user_id, source, payment_status, price_due, revoked_at, admin_id, note) VALUES (:u, 'admin', 'revoked', 0, NOW(), :a, :n)")
                ->execute([':u' => $userId, ':a' => $adminId, ':n' => mb_substr($note !== '' ? $note : 'Reseller access revoked by admin', 0, 255)]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[dual_set_account_type] ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not change the account type.'];
    }
    if (function_exists('audit_log')) {
        audit_log($type === DUAL_RESELLER ? 'user.reseller_granted' : 'user.reseller_revoked', 'success', ['entity_type' => 'user', 'entity_id' => $userId, 'user_id' => $userId, 'actor_id' => $adminId,
            'reason' => $type === DUAL_RESELLER ? 'Admin granted Reseller access' : 'Admin revoked Reseller access']);
    }
    return ['ok' => true, 'account_type' => $type, 'changed' => true];
}

/* ------------------------------------------------------------------ */
/*  Reseller Membership: settings                                      */
/* ------------------------------------------------------------------ */

/** @return array{enabled:bool, price:string, description:string, button:bool, purchasable:bool} */
function dual_membership_config(PDO $pdo): array
{
    $price = function_exists('pp_money_ok') ? pp_money_ok(pp_setting($pdo, 'reseller_membership_price', '0')) : null;
    $enabled = pp_setting($pdo, 'reseller_membership_enabled', '0') === '1';
    $button  = pp_setting($pdo, 'reseller_membership_button', '1') !== '0';
    return [
        'enabled'     => $enabled,
        'price'       => $price ?? '0.00',
        'description' => mb_substr(pp_setting($pdo, 'reseller_membership_description', ''), 0, 1000),
        'button'      => $button,
        'purchasable' => $enabled && $button && $price !== null,
    ];
}

/** Admin save. Price is any custom INR amount > 0 with at most 2 decimals; nothing is hard-coded. */
function dual_membership_save(PDO $pdo, bool $enabled, ?string $price, ?string $description, ?bool $button, int $adminId): array
{
    $p = $price === null ? null : pp_money_ok($price);
    if ($price !== null && $p === null) return ['ok' => false, 'error' => 'Membership price must be a number greater than 0 (at most 2 decimals).'];
    if ($enabled && $p === null && pp_money_ok(pp_setting($pdo, 'reseller_membership_price', '0')) === null) {
        return ['ok' => false, 'error' => 'Set a membership price before switching Reseller Membership ON.'];
    }
    pp_put_setting($pdo, 'reseller_membership_enabled', $enabled ? '1' : '0');
    if ($p !== null) pp_put_setting($pdo, 'reseller_membership_price', $p);
    if ($description !== null) pp_put_setting($pdo, 'reseller_membership_description', mb_substr(trim($description), 0, 1000));
    if ($button !== null) pp_put_setting($pdo, 'reseller_membership_button', $button ? '1' : '0');
    if (function_exists('audit_log')) audit_log('membership.settings', 'success', ['actor_id' => $adminId, 'reason' => 'enabled=' . ($enabled ? 'ON' : 'OFF') . ($p !== null ? ' price=' . $p : '')]);
    return ['ok' => true];
}

/* ------------------------------------------------------------------ */
/*  API access (Reseller + Admin only) - enforced on the server        */
/* ------------------------------------------------------------------ */

/** True when the user may use the API page / API key / API docs / reseller gateway: an ADMIN, or a RESELLER. Read from the database NOW. */
function dual_can_use_api(PDO $pdo, int $userId): bool
{
    if ($userId <= 0) return false;
    try {
        $q = $pdo->prepare('SELECT role, account_type FROM users WHERE id = :id LIMIT 1');
        $q->execute([':id' => $userId]);
        $r = $q->fetch();
        return $r && ((string)$r['role'] === 'admin' || (string)$r['account_type'] === DUAL_RESELLER);
    } catch (Throwable $e) { error_log('[dual_can_use_api] ' . $e->getMessage()); return false; }
}

/** Guard for API pages ($page = true: redirect to the dashboard) and API endpoints ($page = false: JSON 403). Call right after require_user(). */
function dual_require_api_access(array $user, bool $page): void
{
    if (dual_can_use_api(db(), (int)$user['id'])) return;
    if ($page) { redirect('dashboard.php'); }
    json_error('API access is available to Resellers only.', 403, ['code' => 'RESELLER_ONLY']);
}

/** The role label shown to the user: always the DB value, never a client value. */
function dual_role_label(PDO $pdo, int $userId): string
{
    return dual_user_type($pdo, $userId) === DUAL_RESELLER ? 'Reseller' : 'Normal User';
}

/** Membership state for the user dashboard. Wallet balance and role are read from the DATABASE. */
function dual_membership_status_for_user(PDO $pdo, int $userId): array
{
    $cfg = dual_membership_config($pdo);
    $type = dual_user_type($pdo, $userId);
    $out = ['config' => $cfg, 'account_type' => $type, 'role_label' => $type === DUAL_RESELLER ? 'Reseller' : 'Normal User', 'is_reseller' => $type === DUAL_RESELLER,
            'since' => null, 'source' => null, 'balance' => '0.00', 'needed' => '0.00', 'sufficient' => false, 'last_purchase' => null];
    try {
        $u = $pdo->prepare('SELECT balance, reseller_since, reseller_source FROM users WHERE id = :id');
        $u->execute([':id' => $userId]);
        if ($r = $u->fetch()) {
            $out['since'] = $r['reseller_since']; $out['source'] = $r['reseller_source'];
            $out['balance'] = number_format((float)$r['balance'], 2, '.', '');
            $price = (float)$cfg['price'];
            $out['sufficient'] = $price > 0 && (float)$r['balance'] + 0.0001 >= $price;
            $out['needed'] = number_format(max(0.0, $price - (float)$r['balance']), 2, '.', '');
        }
        $m = $pdo->prepare("SELECT id, price_due, amount_deducted, payment_method, payment_status, activated_at FROM reseller_memberships WHERE user_id = :u AND source <> 'admin' ORDER BY id DESC LIMIT 1");
        $m->execute([':u' => $userId]);
        if ($r = $m->fetch()) $out['last_purchase'] = $r;
    } catch (Throwable $e) { error_log('[dual_membership_status_for_user] ' . $e->getMessage()); }
    return $out;
}

/* ------------------------------------------------------------------ */
/*  Reseller Membership: purchase with the WEBSITE WALLET              */
/* ------------------------------------------------------------------ */

/**
 * Buy Reseller Membership with the existing wallet (users.balance + wallet_ledger). No QR, no Paytm, no gateway.
 * ONE transaction: lock the user row -> re-check role / ON-OFF / price / balance (all from the database) -> insert the membership record ->
 * atomic deduction (UPDATE ... WHERE balance >= price) -> wallet_ledger row -> promote to Reseller. Any failure rolls EVERYTHING back.
 * The user row lock serialises double-clicks and concurrent requests: the second one finds the user already a Reseller and is not charged.
 * Only the membership price is deducted; the rest of the balance stays in the same wallet.
 * @return array{ok:bool, code?:int, error?:string, insufficient?:bool, price?:string, balance?:string, needed?:string, membership_id?:int, balance_after?:string, deducted?:string}
 */
function dual_membership_purchase(PDO $pdo, int $userId): array
{
    if ($userId <= 0) return ['ok' => false, 'code' => 401, 'error' => 'Login required.'];
    try {
        $pdo->beginTransaction();
        $lk = $pdo->prepare('SELECT id, role, account_type, balance, banned FROM users WHERE id = :id FOR UPDATE');
        $lk->execute([':id' => $userId]);
        $u = $lk->fetch();
        if (!$u) { $pdo->rollBack(); return ['ok' => false, 'code' => 404, 'error' => 'User not found.']; }
        if ((int)$u['banned'] === 1) { $pdo->rollBack(); return ['ok' => false, 'code' => 403, 'error' => 'Account is banned.']; }
        if ((string)$u['account_type'] === DUAL_RESELLER) { $pdo->rollBack(); return ['ok' => false, 'code' => 409, 'error' => 'You are already a Reseller. You have not been charged.']; }

        $cfg = dual_membership_config($pdo);                                    // price + ON/OFF straight from the settings table, inside the lock
        if (!$cfg['purchasable'] || (float)$cfg['price'] <= 0) { $pdo->rollBack(); return ['ok' => false, 'code' => 403, 'error' => 'Reseller Membership is not available right now.']; }
        $price = round((float)$cfg['price'], 2);
        $priceStr = number_format($price, 2, '.', '');
        $before = round((float)$u['balance'], 2);
        if ($before + 0.0001 < $price) {
            $pdo->rollBack();
            return ['ok' => false, 'code' => 402, 'insufficient' => true, 'error' => 'Insufficient wallet balance.', 'price' => $priceStr,
                    'balance' => number_format($before, 2, '.', ''), 'needed' => number_format($price - $before, 2, '.', '')];
        }

        $tmpRef = 'W' . bin2hex(random_bytes(12));                                 // unique placeholder, replaced by MEM#<id> below
        $pdo->prepare("INSERT INTO reseller_memberships (user_id, source, payment_ref, price_due, price_paid, amount_deducted, payment_method, payment_status, gateway_status, balance_before, activated_at, created_at)
                       VALUES (:u, 'wallet', :r, :p, :p2, :p3, 'wallet', 'paid', 'WALLET', :bb, NOW(), NOW())")
            ->execute([':u' => $userId, ':r' => $tmpRef, ':p' => $priceStr, ':p2' => $priceStr, ':p3' => $priceStr, ':bb' => number_format($before, 2, '.', '')]);
        $mid = (int)$pdo->lastInsertId();
        $ref = 'MEM#' . $mid;

        $dec = $pdo->prepare('UPDATE users SET balance = balance - :p WHERE id = :id AND balance >= :p2');   // atomic: cannot go negative
        $dec->execute([':p' => $priceStr, ':p2' => $priceStr, ':id' => $userId]);
        if ($dec->rowCount() !== 1) throw new RuntimeException('Wallet deduction did not apply.');
        $nb = $pdo->prepare('SELECT balance FROM users WHERE id = :id');
        $nb->execute([':id' => $userId]);
        $after = round((float)$nb->fetchColumn(), 2);
        if (abs(($before - $price) - $after) > 0.009) throw new RuntimeException('Wallet balance check failed after deduction.');

        $pdo->prepare('INSERT INTO wallet_ledger (user_id, amount, balance_after, type, reference_id, description, created_at) VALUES (:u, :a, :b, "purchase", :r, :d, NOW())')
            ->execute([':u' => $userId, ':a' => '-' . $priceStr, ':b' => number_format($after, 2, '.', ''), ':r' => $ref, ':d' => 'Reseller Membership (Website Wallet)']);
        $lid = (int)$pdo->lastInsertId();

        $pdo->prepare('UPDATE reseller_memberships SET payment_ref = :r, wallet_ref = :r2, wallet_ledger_id = :l, balance_after = :ba WHERE id = :id')
            ->execute([':r' => $ref, ':r2' => $ref, ':l' => $lid, ':ba' => number_format($after, 2, '.', ''), ':id' => $mid]);
        $up = $pdo->prepare("UPDATE users SET account_type = 'reseller', reseller_since = NOW(), reseller_source = 'paid', account_type_changed_at = NOW() WHERE id = :id AND account_type = 'normal'");
        $up->execute([':id' => $userId]);
        if ($up->rowCount() !== 1) throw new RuntimeException('Reseller activation did not apply.');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();                               // nothing deducted, nothing activated, no membership row
        error_log('[dual_membership_purchase] ' . $e->getMessage());
        if (function_exists('audit_log')) audit_log('membership.purchase', 'failure', ['entity_type' => 'user', 'entity_id' => $userId, 'user_id' => $userId, 'actor_id' => $userId, 'reason' => 'Rolled back: ' . $e->getMessage()]);
        return ['ok' => false, 'code' => 500, 'error' => 'Could not complete the purchase. Nothing was charged.'];
    }
    if (function_exists('audit_log')) audit_log('membership.purchase', 'success', ['entity_type' => 'membership', 'entity_id' => $mid, 'user_id' => $userId, 'actor_id' => $userId,
        'reason' => 'Reseller Membership bought with the website wallet: -' . $priceStr . ' (' . $ref . ')']);
    return ['ok' => true, 'membership_id' => $mid, 'deducted' => $priceStr, 'balance_after' => number_format($after, 2, '.', '')];
}
