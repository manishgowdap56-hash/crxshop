-- =====================================================================
-- v68 ZapUPI gateway - MANUAL migration (optional).
-- The site applies exactly this automatically on the first request (includes/zapupi.php -> zapupi_ensure_schema_if_needed),
-- so running this file by hand is only needed if your DB user lacks ALTER/CREATE privileges at runtime.
-- It is additive and idempotent in spirit: NO existing row is modified or deleted. Paytm / legacy deposits keep NULLs.
--
-- BACKUP FIRST:   mysqldump --single-transaction DBNAME > backup_before_v68.sql
-- Re-running: the ALTER lines fail with "Duplicate column / key name" if already applied - that is harmless; skip them.
-- =====================================================================

ALTER TABLE deposits
  ADD COLUMN gateway         VARCHAR(20)   DEFAULT NULL,
  ADD COLUMN pay_id          VARCHAR(64)   DEFAULT NULL,
  ADD COLUMN pay_amount      DECIMAL(12,2) DEFAULT NULL,
  ADD COLUMN utr             VARCHAR(64)   DEFAULT NULL,
  ADD COLUMN environment     VARCHAR(20)   DEFAULT NULL,
  ADD COLUMN credit_status   VARCHAR(24)   DEFAULT NULL,   -- pending | success | failed | verification_failed
  ADD COLUMN paid_at         DATETIME      DEFAULT NULL,
  ADD COLUMN verify_attempts INT UNSIGNED  NOT NULL DEFAULT 0,
  ADD COLUMN last_checked_at DATETIME      DEFAULT NULL,
  ADD COLUMN updated_at      DATETIME      DEFAULT NULL;

ALTER TABLE deposits ADD KEY idx_dep_gateway_credit (gateway, credit_status, created_at);
-- (deposits.order_ref UNIQUE and deposits.gateway_order_id UNIQUE already exist and keep local order ids unique.)

CREATE TABLE IF NOT EXISTS payment_credits (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  deposit_id      BIGINT UNSIGNED NOT NULL,
  gateway         VARCHAR(20)  NOT NULL,
  user_id         INT UNSIGNED NOT NULL,
  local_order_id  VARCHAR(40)  NOT NULL,
  provider_txn_id VARCHAR(64)  NOT NULL,
  utr             VARCHAR(64)  DEFAULT NULL,
  amount          DECIMAL(12,2) NOT NULL,
  source          VARCHAR(20)  NOT NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_pc_deposit (deposit_id),                 -- one credit per local deposit, enforced by the database
  UNIQUE KEY uniq_pc_gateway_txn (gateway, provider_txn_id), -- one credit per provider transaction, enforced by the database
  KEY idx_pc_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS zapupi_webhook_events (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  received_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ip             VARCHAR(45) DEFAULT NULL,
  order_ref      VARCHAR(64) DEFAULT NULL,
  txn_id         VARCHAR(64) DEFAULT NULL,
  payload_status VARCHAR(20) DEFAULT NULL,
  environment    VARCHAR(20) DEFAULT NULL,
  amount         VARCHAR(20) DEFAULT NULL,
  outcome        VARCHAR(40) NOT NULL,
  detail         VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_zwe_order (order_ref),
  KEY idx_zwe_ip_time (ip, received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (setting_key, setting_value) VALUES
  ('zapupi_enabled', 'off'),
  ('zapupi_api_endpoint', 'https://pay.zapupi.com/api'),
  ('zapupi_api_key', ''),
  ('zapupi_display_name', 'ZapUPI'),
  ('zapupi_min_amount', '10'),
  ('zapupi_max_amount', '100000'),
  ('zapupi_instructions', ''),
  ('zapupi_mode', 'production'),
  ('zapupi_last_api', ''),
  ('zapupi_schema_v', '1')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- =====================================================================
-- ROLLBACK (only if you must; ZapUPI deposit history would be lost with the columns/tables):
--   UPDATE settings SET setting_value='off' WHERE setting_key='zapupi_enabled';   -- safest: just switch it off
--   DROP TABLE IF EXISTS zapupi_webhook_events;
--   DROP TABLE IF EXISTS payment_credits;                                       -- do NOT drop if any ZapUPI deposit was credited
--   ALTER TABLE deposits DROP KEY idx_dep_gateway_credit, DROP COLUMN gateway, DROP COLUMN pay_id, DROP COLUMN pay_amount,
--     DROP COLUMN utr, DROP COLUMN environment, DROP COLUMN credit_status, DROP COLUMN paid_at,
--     DROP COLUMN verify_attempts, DROP COLUMN last_checked_at, DROP COLUMN updated_at;
--   DELETE FROM settings WHERE setting_key LIKE 'zapupi\_%';
-- =====================================================================
