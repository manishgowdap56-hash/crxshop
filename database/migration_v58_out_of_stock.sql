-- v58: orders.failure_code (provider-confirmed failure reason, e.g. OUT_OF_STOCK). ADDITIVE and SAFE TO SKIP:
-- the application adds this column itself on first use (rp_add_column) and falls back to the stored provider reason for older rows.
-- No existing row is changed or deleted. Safe to run more than once.
SET @s = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `orders` ADD COLUMN `failure_code` VARCHAR(32) DEFAULT NULL', 'SELECT 1')
          FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'failure_code');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Strongly recommended database-level "one refund per order" guarantee: run database/migration_v39_refund_guard.sql
-- (STEP 1 must return zero rows first). The application already refunds exactly once without it.
