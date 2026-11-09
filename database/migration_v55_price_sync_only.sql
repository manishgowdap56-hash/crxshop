-- v55 (OPTIONAL). The application applies the additive parts automatically and idempotently (PriceSyncService::ensureSchema / ensurePriceSchema).
-- Run by hand only if the website's DB user has no ALTER privilege. BACK UP FIRST. Safe to run more than once. Nothing here drops a table or touches products, variants, orders, users, wallets.

-- 1) v52 created provider_price_history.direction as VARCHAR(8) but the code writes the 9-character value 'corrected': widen it.
ALTER TABLE provider_price_history MODIFY direction VARCHAR(10) NOT NULL DEFAULT 'corrected';

-- 2) time the corrected price was saved (shown on the Price Sync history cards)
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'provider_price_history' AND COLUMN_NAME = 'applied_at') = 0,
  'ALTER TABLE provider_price_history ADD COLUMN applied_at DATETIME DEFAULT NULL', 'SELECT 1'); PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;

-- 3) the catalog-sync settings no longer mean anything (product discovery / import / restore / merge were removed)
DELETE FROM settings WHERE setting_key IN ('sync_remove_mode','sync_manage_manual','sync_category_rules','sync_default_category','sync_api_version',
  'sync_repair_done','sync_repair_report','sync_consolidate_done','sync_consolidate_report','sync_keep_admin_price','sync_update_prices',
  'sync_review_list','sync_skip_list','sync_last_summary','sync_last_complete','sync_mapping_audit','sync_mapping_audit_sig','sync_last_catalog_ok','sync_last_fetch_ms');

-- 4) OPTIONAL, ONLY after you are sure you never want the old data back: the tables the removed catalog-import code used. They are no longer read or written
--    by anything. They are NOT dropped automatically (no data is destroyed by this release). Uncomment to drop.
-- DROP TABLE IF EXISTS sync_job_items;
-- DROP TABLE IF EXISTS sync_jobs;
-- DROP TABLE IF EXISTS provider_pending_products;
