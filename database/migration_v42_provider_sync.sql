-- v42 Provider Sync. OPTIONAL: the application applies the same additive changes automatically on EVERY request
-- (ProviderSyncService::ensureSchemaIfNeeded, called from ensure_schema_compatibility). Run this by hand only if the website's DB user has no
-- ALTER privilege. MySQL 8 / MariaDB 10.3+ (IF NOT EXISTS). BACK UP FIRST. Nothing is dropped or rewritten.
-- (The v41 columns/tables - products.source, provider_service_id, sync_status, sync_logs, sync_errors ... - must exist: see migration_v41_provider_sync.sql.)
ALTER TABLE product_plans ADD COLUMN IF NOT EXISTS provider_api_version VARCHAR(4)   DEFAULT NULL;   -- v2 | v1 | both  (never part of the id)
ALTER TABLE product_plans ADD COLUMN IF NOT EXISTS provider_price      DECIMAL(12,4) DEFAULT NULL;   -- the provider's own price, original currency
ALTER TABLE product_plans ADD COLUMN IF NOT EXISTS provider_currency   VARCHAR(3)   DEFAULT NULL;   -- USD | INR
ALTER TABLE product_plans ADD COLUMN IF NOT EXISTS price_rate          DECIMAL(12,4) DEFAULT NULL;   -- USD->INR rate used for the last conversion
ALTER TABLE product_plans ADD COLUMN IF NOT EXISTS price_manual        TINYINT(1) NOT NULL DEFAULT 0; -- 1 = an admin typed the selling price

-- DATA REPAIR is done by the application (Admin -> Provider Sync opens it automatically the first time; button "REPAIR EXISTING PRODUCTS"; or
-- `php tools/repair_provider_products.php`). It is idempotent, logs every change to sync_logs and never deletes anything. The statements below are
-- the manual equivalent of its two most important steps, for people who insist on SQL. Run them in this order, once.
-- 1) split "283|both" into id + version (synced products: both -> v2, v1 stays v1; hand-made products keep their exact mode)
-- UPDATE product_plans pl JOIN products p ON p.id = pl.product_id
--    SET pl.provider_api_version = CASE WHEN pl.provider_variant_id LIKE '%|v1' THEN 'v1'
--                                       WHEN pl.provider_variant_id LIKE '%|both' AND p.source <> 'provider' THEN 'both' ELSE 'v2' END
--  WHERE pl.source = 'api' AND pl.provider_key = 'licencedashboard' AND pl.provider_api_version IS NULL;
-- UPDATE product_plans SET provider_variant_id = SUBSTRING_INDEX(provider_variant_id, '|', 1)
--  WHERE source = 'api' AND provider_key = 'licencedashboard' AND provider_variant_id LIKE '%|%';
-- 2) make hidden synced products visible (they stay in Maintenance). Also needs an ENABLED category - the app moves them to one; by hand:
--    UPDATE products SET category_id = (SELECT id FROM categories WHERE name = 'Root / Non Root' AND enabled = 1 LIMIT 1)
--     WHERE source = 'provider' AND archived_at IS NULL AND category_id IN (SELECT id FROM categories WHERE enabled = 0);
--    UPDATE products SET enabled = 1 WHERE source = 'provider' AND maintenance = 1 AND sync_status = 'maintenance' AND archived_at IS NULL;
-- PRICES are NOT converted by SQL: the sync converts them (USD x rate) from the provider's real price on its first run.
