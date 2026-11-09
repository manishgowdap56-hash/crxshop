-- v37 + v38 combined migration. ADDITIVE and REPEAT-SAFE: every ALTER is guarded by an information_schema check, every CREATE uses
-- IF NOT EXISTS, every INSERT is idempotent. Nothing is dropped, renamed, reset, recalculated or credited. Safe to run any number of times.
--
-- WHEN TO RUN IT: the site applies all of this automatically on the first admin request (includes/referral.php,
-- includes/schema_compat.php). Run it by hand only if that could not happen (database user without CREATE/ALTER), or after
-- restoring a database backup. HOW: cPanel -> phpMyAdmin -> select the SAME database that config/config.php (DB_NAME) points to
-- -> Import (or SQL tab) -> run this file. Works on MySQL 5.7+/8 and MariaDB 10.2+.
--
-- (v37 problem this fixes: the old file started with plain ALTER TABLE ... ADD COLUMN lines; on a database where those columns
--  already existed phpMyAdmin stopped at the first "Duplicate column name" error and NEVER reached CREATE TABLE referral_commissions.)

-- ====================================================================
-- 1) REFERRAL COMMISSION LEDGER (first, so nothing above it can prevent it from being created)
-- ====================================================================
CREATE TABLE IF NOT EXISTS referral_commissions (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  referrer_id        INT UNSIGNED NOT NULL,
  referred_id        INT UNSIGNED NOT NULL,
  order_id           BIGINT UNSIGNED NOT NULL,
  order_ref          VARCHAR(40)  DEFAULT NULL,
  eligible_amount    DECIMAL(12,2) NOT NULL,
  percent            DECIMAL(5,2)  NOT NULL,
  commission         DECIMAL(12,2) NOT NULL,
  status             ENUM('credited','pending','failed','reversed') NOT NULL DEFAULT 'credited',
  channel            VARCHAR(12)  NOT NULL DEFAULT 'website',
  wallet_ledger_id   BIGINT UNSIGNED DEFAULT NULL,
  reversal_ledger_id BIGINT UNSIGNED DEFAULT NULL,
  reversed_amount    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  reversed_at        DATETIME DEFAULT NULL,
  note               VARCHAR(255) DEFAULT NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_commission_order (order_id),
  KEY idx_rcm_referrer (referrer_id, created_at),
  KEY idx_rcm_referred (referred_id),
  KEY idx_rcm_status (status, created_at),
  KEY idx_rcm_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Active percentage: seeded with 5% ONLY if no value exists (a saved value is never overwritten).
INSERT INTO settings (setting_key, setting_value, updated_at) VALUES ('referral_commission_percent', '5.00', NOW())
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- One-time import of commissions ALREADY paid (referral_events -> ledger). Only READS referral_events / wallet_ledger / orders.
-- It credits nothing and changes no balance. The percentage is derived from what was actually paid (commission / order total).
-- Re-running is harmless (INSERT IGNORE + UNIQUE(order_id)).
INSERT IGNORE INTO referral_commissions
  (referrer_id, referred_id, order_id, order_ref, eligible_amount, percent, commission, status, channel, wallet_ledger_id, note, created_at)
SELECT e.referrer_id, e.referred_id, e.order_id, o.order_ref, COALESCE(o.total_price, 0),
       LEAST(999.99, CASE WHEN COALESCE(o.total_price, 0) > 0 THEN ROUND(e.amount / o.total_price * 100, 2) ELSE 0 END),
       e.amount, 'credited', 'legacy',
       (SELECT l.id FROM wallet_ledger l WHERE l.user_id = e.referrer_id AND l.type = 'referral' AND l.reference_id = CONCAT('ORD#', e.order_id) ORDER BY l.id ASC LIMIT 1),
       'Imported from referral_events (pre-v37)', e.created_at
FROM referral_events e
LEFT JOIN orders o ON o.id = e.order_id
WHERE e.order_id IS NOT NULL;

INSERT INTO settings (setting_key, setting_value, updated_at) VALUES ('referral_ledger_backfilled', '1', NOW()), ('schema_v37', '1', NOW())
ON DUPLICATE KEY UPDATE setting_value = '1';

-- ====================================================================
-- 2) PENDING ORDERS / REFUND TRACKING COLUMNS ON orders (each added only if missing)
-- ====================================================================
SET @s = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `orders` ADD COLUMN `failure_reason` VARCHAR(500) DEFAULT NULL', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'failure_reason');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `orders` ADD COLUMN `refund_status` VARCHAR(16) DEFAULT NULL', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'refund_status');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `orders` ADD COLUMN `refund_method` VARCHAR(16) DEFAULT NULL', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'refund_method');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `orders` ADD COLUMN `refunded_at` DATETIME DEFAULT NULL', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'refunded_at');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `orders` ADD COLUMN `last_api_status` VARCHAR(80) DEFAULT NULL', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'last_api_status');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `orders` ADD COLUMN `last_checked_at` DATETIME DEFAULT NULL', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'last_checked_at');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `orders` ADD COLUMN `check_attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'check_attempts');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `orders` ADD COLUMN `refund_amount` DECIMAL(12,2) DEFAULT NULL', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'refund_amount');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `orders` ADD COLUMN `refund_ledger_id` BIGINT UNSIGNED DEFAULT NULL', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'refund_ledger_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `orders` ADD INDEX `idx_order_status_created` (`status`, `created_at`)', 'SELECT 1') FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'idx_order_status_created');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `orders` ADD INDEX `idx_order_refund_ledger` (`refund_ledger_id`)', 'SELECT 1') FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'idx_order_refund_ledger');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ====================================================================
-- 3) CONTROLLER DECISION LOG (history is only ever appended to)
-- ====================================================================
CREATE TABLE IF NOT EXISTS order_refund_log (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id   BIGINT UNSIGNED NOT NULL,
  order_ref  VARCHAR(40)  NOT NULL,
  user_id    INT UNSIGNED NOT NULL,
  amount     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  decision   VARCHAR(24)  NOT NULL,
  result     VARCHAR(24)  NOT NULL,
  reason     VARCHAR(500) DEFAULT NULL,
  evidence   TEXT,
  actor      VARCHAR(40)  NOT NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_orl_order (order_id, created_at),
  KEY idx_orl_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SET @s = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `order_refund_log` ADD COLUMN `prev_status` VARCHAR(40) DEFAULT NULL', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_refund_log' AND COLUMN_NAME = 'prev_status');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `order_refund_log` ADD COLUMN `new_status` VARCHAR(40) DEFAULT NULL', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_refund_log' AND COLUMN_NAME = 'new_status');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `order_refund_log` ADD COLUMN `api_status` VARCHAR(80) DEFAULT NULL', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_refund_log' AND COLUMN_NAME = 'api_status');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

INSERT INTO settings (setting_key, setting_value, updated_at) VALUES
  ('order_pending_window_seconds', '600', NOW()),
  ('refund_controller_auto', '1', NOW()),
  ('refund_controller_last_run', '0', NOW())
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- ====================================================================
-- 4) LINK PAST REFUNDS TO THEIR ORDERS (reads wallet_ledger / audit_logs; writes only the tracking columns of orders)
--    Never changes an order's status, a wallet balance or a ledger row. Orders still 'pending' are skipped on purpose.
-- ====================================================================
UPDATE orders o
JOIN wallet_ledger l ON l.user_id = o.user_id AND l.type = 'refund' AND l.reference_id = o.order_ref COLLATE utf8mb4_unicode_ci
SET o.refund_ledger_id = l.id, o.refund_amount = l.amount, o.refunded_at = COALESCE(o.refunded_at, l.created_at), o.refund_status = 'refunded'
WHERE o.refund_ledger_id IS NULL AND o.status <> 'pending';

UPDATE orders o SET o.refund_method = 'manual'
WHERE o.refund_ledger_id IS NOT NULL AND o.refund_method IS NULL
  AND EXISTS (SELECT 1 FROM audit_logs a WHERE a.event_type = 'order.admin_refund' AND a.order_ref = o.order_ref COLLATE utf8mb4_unicode_ci);

UPDATE orders SET refund_method = 'auto' WHERE refund_ledger_id IS NOT NULL AND refund_method IS NULL;

INSERT INTO settings (setting_key, setting_value, updated_at) VALUES ('refund_links_backfilled_v38', '1', NOW())
ON DUPLICATE KEY UPDATE setting_value = '1';

-- Verify (read-only):
-- SELECT COUNT(*) AS ledger_rows FROM referral_commissions;
-- SELECT COUNT(*) AS refunded_orders FROM orders WHERE refund_ledger_id IS NOT NULL;
