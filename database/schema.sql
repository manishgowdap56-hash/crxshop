-- =====================================================================
-- ROYAL PANEL — MySQL schema
-- Charset: utf8mb4 / InnoDB
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';

-- ------------------------- users -------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username        VARCHAR(40)  NOT NULL,
  email           VARCHAR(120) DEFAULT NULL,
  password_hash   VARCHAR(255) NOT NULL,
  role            ENUM('user','admin') NOT NULL DEFAULT 'user',
  banned          TINYINT(1)   NOT NULL DEFAULT 0,
  balance         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  api_token       VARCHAR(80)  DEFAULT NULL,
  referral_code   VARCHAR(32)  DEFAULT NULL,
  referred_by     INT UNSIGNED DEFAULT NULL,
  last_login_at   DATETIME     DEFAULT NULL,
  last_active_at  DATETIME     DEFAULT NULL,
  password_reset_at DATETIME   DEFAULT NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_username (username),
  UNIQUE KEY uniq_email (email),
  UNIQUE KEY uniq_api_token (api_token),
  UNIQUE KEY uniq_referral_code (referral_code),
  KEY idx_referred_by (referred_by),
  CONSTRAINT fk_users_referrer FOREIGN KEY (referred_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------- user preferences -----------------------------
CREATE TABLE IF NOT EXISTS user_preferences (
  user_id        INT UNSIGNED NOT NULL,
  tap_sound      TINYINT(1)   NOT NULL DEFAULT 1,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_pref_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------- categories --------------------------------
CREATE TABLE IF NOT EXISTS categories (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name         VARCHAR(80)  NOT NULL,
  slug         VARCHAR(80)  NOT NULL,
  sort_order   INT          NOT NULL DEFAULT 0,
  enabled      TINYINT(1)   NOT NULL DEFAULT 1,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_cat_name (name),
  KEY idx_cat_sort (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------- products ----------------------------------
CREATE TABLE IF NOT EXISTS products (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  category_id    INT UNSIGNED NOT NULL,
  name           VARCHAR(120) NOT NULL,
  description    TEXT,
  image_path     VARCHAR(255) DEFAULT NULL,
  video_path     VARCHAR(255) DEFAULT NULL,
  features       MEDIUMTEXT,
  enabled        TINYINT(1)   NOT NULL DEFAULT 1,
  maintenance    TINYINT(1)   NOT NULL DEFAULT 0,
  sort_order     INT          NOT NULL DEFAULT 0,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_prod_cat (category_id),
  KEY idx_prod_enabled (enabled),
  CONSTRAINT fk_prod_cat FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------- product_plans -----------------------------
-- Duration / variant per product. `source` mirrors the original JS:
--   'local' -> our own key inventory
--   'api'   -> forwarded to upstream provider using provider_variant_id
CREATE TABLE IF NOT EXISTS product_plans (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id            INT UNSIGNED NOT NULL,
  duration              VARCHAR(60)  NOT NULL,
  price                 DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  source                ENUM('local','api') NOT NULL DEFAULT 'local',
  provider_variant_id   VARCHAR(60)  DEFAULT NULL,
  provider_key          VARCHAR(20)  NOT NULL DEFAULT 'primary',
  allow_quantity        TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order            INT          NOT NULL DEFAULT 0,
  enabled               TINYINT(1)   NOT NULL DEFAULT 1,
  created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_plan_prod (product_id),
  UNIQUE KEY uniq_plan (product_id, duration),
  CONSTRAINT fk_plan_prod FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------- product keys ------------------------------
CREATE TABLE IF NOT EXISTS product_keys (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id     INT UNSIGNED NOT NULL,
  plan_id        INT UNSIGNED NOT NULL,
  key_value      VARCHAR(255) NOT NULL,
  status         ENUM('available','delivered','void') NOT NULL DEFAULT 'available',
  order_id       BIGINT UNSIGNED DEFAULT NULL,
  delivered_at   DATETIME DEFAULT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_key_per_plan (plan_id, key_value),
  KEY idx_key_lookup (plan_id, status),
  CONSTRAINT fk_key_prod FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_key_plan FOREIGN KEY (plan_id)    REFERENCES product_plans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------- orders ------------------------------------
CREATE TABLE IF NOT EXISTS orders (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_ref         VARCHAR(40) NOT NULL,
  user_id           INT UNSIGNED NOT NULL,
  product_id        INT UNSIGNED NOT NULL,
  plan_id           INT UNSIGNED NOT NULL,
  quantity          INT UNSIGNED NOT NULL DEFAULT 1,
  unit_price        DECIMAL(12,2) NOT NULL,
  total_price       DECIMAL(12,2) NOT NULL,
  source            ENUM('website','api') NOT NULL DEFAULT 'website',
  plan_source       ENUM('local','api')  NOT NULL DEFAULT 'local',
  upstream_order_id VARCHAR(60) DEFAULT NULL,
  provider_key      VARCHAR(20) DEFAULT NULL,
  status            ENUM('completed','pending','failed','refunded') NOT NULL DEFAULT 'completed',
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_order_ref (order_ref),
  KEY idx_order_user (user_id),
  KEY idx_order_created (created_at),
  KEY idx_order_source (source),
  CONSTRAINT fk_order_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_order_prod FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
  CONSTRAINT fk_order_plan FOREIGN KEY (plan_id) REFERENCES product_plans(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Delivered keys tied to a specific order line
CREATE TABLE IF NOT EXISTS order_keys (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id      BIGINT UNSIGNED NOT NULL,
  product_key_id BIGINT UNSIGNED DEFAULT NULL,   -- set when delivered from our own inventory
  external_key  VARCHAR(255) DEFAULT NULL,       -- set when delivered from upstream
  delivered_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ok_order (order_id),
  CONSTRAINT fk_ok_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_ok_key   FOREIGN KEY (product_key_id) REFERENCES product_keys(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------- deposits ----------------------------------
CREATE TABLE IF NOT EXISTS deposits (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_ref      VARCHAR(40) NOT NULL,
  user_id        INT UNSIGNED NOT NULL,
  amount         DECIMAL(12,2) NOT NULL,
  status         ENUM('pending','approved','rejected','expired') NOT NULL DEFAULT 'pending',
  proof_path     VARCHAR(255) DEFAULT NULL,
  admin_note     TEXT,
  reviewed_by    INT UNSIGNED DEFAULT NULL,
  reviewed_at    DATETIME DEFAULT NULL,
  expires_at     DATETIME DEFAULT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  method           VARCHAR(50) DEFAULT NULL,
  gateway_order_id VARCHAR(64) DEFAULT NULL,
  gateway_txn_id   VARCHAR(64) DEFAULT NULL,
  gateway_status   VARCHAR(32) DEFAULT NULL,
  gateway_response TEXT,
  gateway          VARCHAR(20) DEFAULT NULL,            -- v68 ZapUPI
  pay_id           VARCHAR(64) DEFAULT NULL,
  pay_amount       DECIMAL(12,2) DEFAULT NULL,
  utr              VARCHAR(64) DEFAULT NULL,
  environment      VARCHAR(20) DEFAULT NULL,
  credit_status    VARCHAR(24) DEFAULT NULL,
  paid_at          DATETIME DEFAULT NULL,
  verify_attempts  INT UNSIGNED NOT NULL DEFAULT 0,
  last_checked_at  DATETIME DEFAULT NULL,
  updated_at       DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_dep_ref (order_ref),
  UNIQUE KEY uniq_dep_gateway_order (gateway_order_id),
  KEY idx_dep_gateway_txn (gateway_txn_id),
  KEY idx_dep_user (user_id),
  KEY idx_dep_status (status),
  CONSTRAINT fk_dep_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_dep_admin FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------- wallet ledger -----------------------------
CREATE TABLE IF NOT EXISTS wallet_ledger (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id         INT UNSIGNED NOT NULL,
  amount          DECIMAL(12,2) NOT NULL,        -- signed
  balance_after   DECIMAL(12,2) NOT NULL,
  type            ENUM('deposit','purchase','refund','referral','admin_adjust') NOT NULL,
  reference_id    VARCHAR(60) DEFAULT NULL,
  description     VARCHAR(255) DEFAULT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_led_user (user_id, created_at),
  KEY idx_led_type (type),
  CONSTRAINT fk_led_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------- referrals ---------------------------------
-- One row per user who was referred by another user.
CREATE TABLE IF NOT EXISTS referrals (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  referrer_id      INT UNSIGNED NOT NULL,
  referred_id      INT UNSIGNED NOT NULL,
  active           TINYINT(1) NOT NULL DEFAULT 0,   -- flips to 1 on first paid purchase
  total_earned     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_ref_pair (referrer_id, referred_id),
  KEY idx_ref_referrer (referrer_id),
  CONSTRAINT fk_ref_referrer FOREIGN KEY (referrer_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ref_referred FOREIGN KEY (referred_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS referral_events (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  referrer_id   INT UNSIGNED NOT NULL,
  referred_id   INT UNSIGNED NOT NULL,
  order_id      BIGINT UNSIGNED DEFAULT NULL,
  amount        DECIMAL(12,2) NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ref_event_user (referrer_id),
  CONSTRAINT fk_rev_referrer FOREIGN KEY (referrer_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_rev_referred FOREIGN KEY (referred_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------- api request log ---------------------------
CREATE TABLE IF NOT EXISTS api_logs (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       INT UNSIGNED NOT NULL,
  action        VARCHAR(30) NOT NULL,
  variant_id    VARCHAR(60) DEFAULT NULL,
  quantity      INT DEFAULT NULL,
  status        ENUM('ok','error') NOT NULL DEFAULT 'ok',
  message       VARCHAR(255) DEFAULT NULL,
  ip            VARCHAR(45) DEFAULT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_api_user (user_id),
  KEY idx_api_created (created_at),
  CONSTRAINT fk_api_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------- access keys -------------------------------
-- One-time signup keys created by the admin. Each key is bound to one username and can be used once.
CREATE TABLE IF NOT EXISTS access_keys (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------- operations: audit / alerts / price changes -----
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS price_change_acks (
  user_id   INT UNSIGNED NOT NULL,
  change_id BIGINT UNSIGNED NOT NULL,
  acked_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, change_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------- settings ----------------------------------
CREATE TABLE IF NOT EXISTS settings (
  setting_key   VARCHAR(60) NOT NULL,
  setting_value TEXT,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- DEFAULT SETTINGS
-- =====================================================================
INSERT INTO settings (setting_key, setting_value) VALUES
  ('panel_name',        'Keys Panel'),
  ('logo_path',         ''),
  ('join_link',         ''),
  ('maintenance_mode',  '0'),
  ('api_endpoint',      'https://keypanel.shop/reseller_gateway.php'),
  ('api_master_token',  ''),
  ('api_service_name',  'KEYSPANEL API'),
  ('api2_endpoint',     'https://keypanel.shop/reseller_gateway.php'),
  ('api2_master_token', ''),
  ('api2_service_name', 'KEYPANEL API'),
  ('api2_provider_enabled', '0'),
  ('api3_endpoint',     'https://panelstore.shop/api/v1/index.php'),
  ('api3_master_token', ''),
  ('api3_service_name', 'PANELSTORE API'),
  ('api3_provider_enabled', '0'),
  ('api4_endpoint',     'https://bantibhaiya.to/api/reseller_v1.php'),
  ('api4_api_key',      ''),
  ('api4_master_token', ''),
  ('api4_service_name', 'BANTIBHAIYA API'),
  ('api4_provider_enabled', '0'),
  ('api5_endpoint',      'https://licencedashboard.shop/api/v1'),
  ('api5_master_token',  ''),
  ('api5_service_name',  'LICENCE DASHBOARD API'),
  ('api5_provider_enabled', '0'),
  ('api6_endpoint',      'https://whitexmodz.store/api/v1'),
  ('api6_master_token',  ''),
  ('api6_service_name',  'VIP SELLER HUB API'),
  ('api6_provider_enabled', '0'),
  ('access_key_required', '1'),
  ('deposit_min_amount', '10'),
  ('deposit_max_amount', '100000'),
  ('paytm_enabled',      'off'),
  ('paytm_mid',          ''),
  ('paytm_upi_id',       ''),
  ('paytm_display_name', '')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);