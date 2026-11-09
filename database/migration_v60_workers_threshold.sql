-- v60 (OPTIONAL: the app applies the same changes automatically, additively and repeatably). BACK UP FIRST. Safe to run more than once. Drops/deletes nothing.
-- Requires migration_v59_reliability_pricing.sql (or the app's own auto-migration) to have run.

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_plans' AND COLUMN_NAME = 'price_change_version') = 0,
  'ALTER TABLE product_plans ADD COLUMN price_change_version INT UNSIGNED NOT NULL DEFAULT 0', 'SELECT 1'); PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'price_change_events' AND COLUMN_NAME = 'change_version') = 0,
  'ALTER TABLE price_change_events ADD COLUMN change_version INT UNSIGNED DEFAULT NULL', 'SELECT 1'); PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'price_change_events' AND COLUMN_NAME = 'pct_diff') = 0,
  'ALTER TABLE price_change_events ADD COLUMN pct_diff DECIMAL(8,2) DEFAULT NULL', 'SELECT 1'); PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'provider_order_attempts' AND COLUMN_NAME = 'review_checks') = 0,
  'ALTER TABLE provider_order_attempts ADD COLUMN review_checks INT UNSIGNED NOT NULL DEFAULT 0', 'SELECT 1'); PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'provider_order_attempts' AND COLUMN_NAME = 'review_state') = 0,
  'ALTER TABLE provider_order_attempts ADD COLUMN review_state VARCHAR(20) DEFAULT NULL', 'SELECT 1'); PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;

CREATE TABLE IF NOT EXISTS worker_runs (
  worker VARCHAR(30) NOT NULL, last_start_at DATETIME DEFAULT NULL, last_run_at DATETIME DEFAULT NULL, last_success_at DATETIME DEFAULT NULL,
  last_error VARCHAR(500) DEFAULT NULL, last_error_at DATETIME DEFAULT NULL, runs BIGINT UNSIGNED NOT NULL DEFAULT 0, failures BIGINT UNSIGNED NOT NULL DEFAULT 0,
  fail_streak INT UNSIGNED NOT NULL DEFAULT 0, skipped_locked BIGINT UNSIGNED NOT NULL DEFAULT 0, last_duration_ms INT UNSIGNED DEFAULT NULL,
  last_stats TEXT DEFAULT NULL, last_pid INT UNSIGNED DEFAULT NULL, PRIMARY KEY (worker)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_review_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, order_id BIGINT UNSIGNED NOT NULL, order_ref VARCHAR(40) NOT NULL, review_state VARCHAR(20) NOT NULL,
  detail VARCHAR(255) DEFAULT NULL, check_no INT UNSIGNED NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), KEY idx_ore_order (order_id, id), KEY idx_ore_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- required defaults: 3 consecutive complete catalog checks, no extra time condition, auto-restore after validation.
-- Only the old v59 defaults (30 / 5 / 0) are moved; any other value you chose is left alone.
UPDATE settings SET setting_value = '3' WHERE setting_key = 'sync_remove_after_checks'  AND setting_value = '30';
UPDATE settings SET setting_value = '0' WHERE setting_key = 'sync_remove_after_minutes' AND setting_value = '5';
UPDATE settings SET setting_value = '1' WHERE setting_key = 'sync_auto_restore'         AND setting_value = '0';
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('sync_remove_after_checks','3'),('sync_remove_after_minutes','0'),('sync_auto_restore','1'),('pp_defaults_v60','1');

-- ROLLBACK (only if ever needed; data loss is limited to monitoring rows): DROP TABLE worker_runs; DROP TABLE order_review_events;
-- The added columns are harmless with defaults and can stay.
