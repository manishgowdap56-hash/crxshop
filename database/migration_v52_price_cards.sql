-- v52 (OPTIONAL). The application applies the same changes automatically and idempotently (ProviderSyncService::ensurePendingSchema).
-- Run this only if you prefer to migrate by hand. Safe to run more than once. It only ADDS columns / an index; it never drops or rewrites data.
-- Requires provider_price_history (created by v51). Works on MySQL 5.7+/8 and MariaDB.
SET @db = DATABASE();

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='provider_price_history' AND COLUMN_NAME='change_type')=0,
  'ALTER TABLE provider_price_history ADD COLUMN change_type VARCHAR(10) DEFAULT NULL', 'SELECT 1'); PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='provider_price_history' AND COLUMN_NAME='status')=0,
  "ALTER TABLE provider_price_history ADD COLUMN status VARCHAR(8) NOT NULL DEFAULT 'success'", 'SELECT 1'); PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='provider_price_history' AND COLUMN_NAME='price_diff')=0,
  'ALTER TABLE provider_price_history ADD COLUMN price_diff DECIMAL(12,2) DEFAULT NULL', 'SELECT 1'); PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='provider_price_history' AND COLUMN_NAME='error')=0,
  'ALTER TABLE provider_price_history ADD COLUMN error VARCHAR(255) DEFAULT NULL', 'SELECT 1'); PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='provider_price_history' AND COLUMN_NAME='attempts')=0,
  'ALTER TABLE provider_price_history ADD COLUMN attempts INT UNSIGNED NOT NULL DEFAULT 0', 'SELECT 1'); PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='provider_price_history' AND COLUMN_NAME='updated_at')=0,
  'ALTER TABLE provider_price_history ADD COLUMN updated_at DATETIME DEFAULT NULL', 'SELECT 1'); PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;
SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='provider_price_history' AND INDEX_NAME='idx_pph_status')=0,
  'ALTER TABLE provider_price_history ADD INDEX idx_pph_status (status, plan_id)', 'SELECT 1'); PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;
