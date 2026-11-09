-- v41 Provider Sync. OPTIONAL: the application applies the same additive changes automatically (ProviderSyncService::ensureSchema)
-- the first time Admin -> Provider Sync is opened, "SYNC NOW" is pressed or the cron job runs. Run this by hand only if the DB user
-- of the website has no ALTER privilege. MySQL 8 / MariaDB 10.3+ (IF NOT EXISTS). BACK UP FIRST. Nothing is dropped or rewritten.
ALTER TABLE products      ADD COLUMN IF NOT EXISTS source ENUM('manual','provider') NOT NULL DEFAULT 'manual';
ALTER TABLE products      ADD COLUMN IF NOT EXISTS provider_service_id VARCHAR(60) DEFAULT NULL;
ALTER TABLE products      ADD COLUMN IF NOT EXISTS sync_status ENUM('live','maintenance','removed') NOT NULL DEFAULT 'live';
ALTER TABLE products      ADD COLUMN IF NOT EXISTS last_synced_at DATETIME DEFAULT NULL;
ALTER TABLE products      ADD COLUMN IF NOT EXISTS provider_snapshot TEXT DEFAULT NULL;
ALTER TABLE product_plans ADD COLUMN IF NOT EXISTS provider_snapshot TEXT DEFAULT NULL;
ALTER TABLE product_plans ADD COLUMN IF NOT EXISTS sync_removed TINYINT(1) NOT NULL DEFAULT 0;
-- (fails harmlessly if it already exists)
ALTER TABLE products ADD UNIQUE KEY uniq_provider_service (provider_service_id);

CREATE TABLE IF NOT EXISTS sync_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, run_id VARCHAR(16) DEFAULT NULL, action VARCHAR(30) NOT NULL,
  product_id INT UNSIGNED DEFAULT NULL, provider_item_id VARCHAR(60) DEFAULT NULL, old_data TEXT, new_data TEXT,
  message VARCHAR(255) DEFAULT NULL, actor VARCHAR(40) NOT NULL DEFAULT 'cron', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), KEY idx_sl_created (created_at), KEY idx_sl_action (action), KEY idx_sl_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS sync_errors (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, run_id VARCHAR(16) DEFAULT NULL, stage VARCHAR(30) NOT NULL, attempt TINYINT UNSIGNED DEFAULT NULL,
  message VARCHAR(500) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY idx_se_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- v40 left products.enabled = 0 + a hidden category "Provider Sync (staging)" for products it created. To show those now (still in maintenance):
-- UPDATE products SET enabled = 1, maintenance = 1 WHERE source = 'provider' AND sync_status = 'maintenance';
-- then move them to a real category from Admin -> Products or Admin -> Provider Sync.
