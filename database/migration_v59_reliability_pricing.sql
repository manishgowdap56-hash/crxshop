-- v59 (OPTIONAL). The application applies every additive part below automatically and idempotently (pp_ensure_schema / ox_ensure_schema / rp_add_column).
-- Run by hand only if the website's DB user has no ALTER/CREATE privilege. BACK UP FIRST. Safe to run more than once.
-- Nothing here drops, truncates or rewrites any table, order, wallet row, key, user or mapping.
-- orders.failure_code is created by migration_v58_out_of_stock.sql / the app.

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_plans' AND COLUMN_NAME = 'custom_price_enabled') = 0,
  'ALTER TABLE product_plans ADD COLUMN custom_price_enabled TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1'); PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_plans' AND COLUMN_NAME = 'custom_price') = 0,
  'ALTER TABLE product_plans ADD COLUMN custom_price DECIMAL(12,2) DEFAULT NULL', 'SELECT 1'); PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_plans' AND COLUMN_NAME = 'provider_last_valid_price') = 0,
  'ALTER TABLE product_plans ADD COLUMN provider_last_valid_price DECIMAL(12,4) DEFAULT NULL', 'SELECT 1'); PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_plans' AND COLUMN_NAME = 'provider_missing_count') = 0,
  'ALTER TABLE product_plans ADD COLUMN provider_missing_count INT UNSIGNED NOT NULL DEFAULT 0', 'SELECT 1'); PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_plans' AND COLUMN_NAME = 'provider_missing_since') = 0,
  'ALTER TABLE product_plans ADD COLUMN provider_missing_since DATETIME DEFAULT NULL', 'SELECT 1'); PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_plans' AND COLUMN_NAME = 'provider_last_seen_at') = 0,
  'ALTER TABLE product_plans ADD COLUMN provider_last_seen_at DATETIME DEFAULT NULL', 'SELECT 1'); PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_plans' AND COLUMN_NAME = 'provider_removed_at') = 0,
  'ALTER TABLE product_plans ADD COLUMN provider_removed_at DATETIME DEFAULT NULL', 'SELECT 1'); PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_plans' AND COLUMN_NAME = 'lifecycle_state') = 0,
  'ALTER TABLE product_plans ADD COLUMN lifecycle_state VARCHAR(12) NOT NULL DEFAULT 'active'', 'SELECT 1'); PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_plans' AND COLUMN_NAME = 'price_increase_detected_at') = 0,
  'ALTER TABLE product_plans ADD COLUMN price_increase_detected_at DATETIME DEFAULT NULL', 'SELECT 1'); PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_plans' AND COLUMN_NAME = 'price_increase_deadline') = 0,
  'ALTER TABLE product_plans ADD COLUMN price_increase_deadline DATETIME DEFAULT NULL', 'SELECT 1'); PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_plans' AND COLUMN_NAME = 'pending_provider_price') = 0,
  'ALTER TABLE product_plans ADD COLUMN pending_provider_price DECIMAL(12,4) DEFAULT NULL', 'SELECT 1'); PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_plans' AND COLUMN_NAME = 'pending_calculated_inr') = 0,
  'ALTER TABLE product_plans ADD COLUMN pending_calculated_inr DECIMAL(12,2) DEFAULT NULL', 'SELECT 1'); PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_plans' AND COLUMN_NAME = 'price_change_source') = 0,
  'ALTER TABLE product_plans ADD COLUMN price_change_source VARCHAR(20) DEFAULT NULL', 'SELECT 1'); PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_plans' AND COLUMN_NAME = 'sync_removed') = 0,
  'ALTER TABLE product_plans ADD COLUMN sync_removed TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1'); PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;

SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_plans' AND INDEX_NAME = 'idx_plan_lifecycle') = 0,
  'ALTER TABLE product_plans ADD INDEX idx_plan_lifecycle (lifecycle_state)', 'SELECT 1'); PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;

CREATE TABLE IF NOT EXISTS provider_order_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL,
  order_ref VARCHAR(40) NOT NULL,
  channel VARCHAR(16) NOT NULL DEFAULT 'website',
  provider_key VARCHAR(20) DEFAULT NULL,
  provider_variant_id VARCHAR(60) DEFAULT NULL,
  idempotency_key VARCHAR(64) DEFAULT NULL,
  state VARCHAR(24) NOT NULL DEFAULT 'SENDING',
  http_code SMALLINT UNSIGNED DEFAULT NULL,
  response_time_ms INT UNSIGNED DEFAULT NULL,
  upstream_order_id VARCHAR(60) DEFAULT NULL,
  error_message VARCHAR(500) DEFAULT NULL,
  response_snapshot TEXT DEFAULT NULL,
  pending_keys MEDIUMTEXT DEFAULT NULL,
  referral_pending TINYINT(1) NOT NULL DEFAULT 0,
  settle_result VARCHAR(20) DEFAULT NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 1,
  last_reconcile_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_poa_order (order_id), KEY idx_poa_state (state, created_at), KEY idx_poa_ref (order_ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS price_change_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_type VARCHAR(24) NOT NULL, plan_id INT UNSIGNED NOT NULL, product_id INT UNSIGNED DEFAULT NULL,
  product_name VARCHAR(160) DEFAULT NULL, duration VARCHAR(60) DEFAULT NULL, provider_variant_id VARCHAR(60) DEFAULT NULL,
  old_provider_price DECIMAL(12,4) DEFAULT NULL, new_provider_price DECIMAL(12,4) DEFAULT NULL, provider_currency VARCHAR(8) DEFAULT NULL, rate DECIMAL(12,4) DEFAULT NULL,
  old_inr DECIMAL(12,2) DEFAULT NULL, new_inr DECIMAL(12,2) DEFAULT NULL, old_selling_price DECIMAL(12,2) DEFAULT NULL, new_selling_price DECIMAL(12,2) DEFAULT NULL,
  custom_price DECIMAL(12,2) DEFAULT NULL, source VARCHAR(20) NOT NULL DEFAULT 'sync', status VARCHAR(16) NOT NULL DEFAULT 'info',
  detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, deadline_at DATETIME DEFAULT NULL, resolved_at DATETIME DEFAULT NULL, resolved_by INT UNSIGNED DEFAULT NULL,
  resolution VARCHAR(40) DEFAULT NULL, note VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (id), KEY idx_pce_plan (plan_id, status), KEY idx_pce_status (status, deadline_at), KEY idx_pce_type (event_type, detected_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_idempotency (
  user_id INT UNSIGNED NOT NULL, idem_key VARCHAR(64) NOT NULL, order_ref VARCHAR(40) DEFAULT NULL,
  variant_id INT UNSIGNED DEFAULT NULL, quantity INT UNSIGNED DEFAULT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, idem_key), KEY idx_idem_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- default settings (only inserted when absent)
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
 ('sync_usd_inr_rate','30'),('price_grace_minutes','25'),('price_decrease_policy','apply'),
 ('sync_remove_after_checks','30'),('sync_remove_after_minutes','5'),('sync_auto_restore','0');
