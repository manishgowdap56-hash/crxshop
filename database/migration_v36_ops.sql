-- v36: Pending Orders + Refund Controller. ADDITIVE ONLY. The site applies this automatically on first request
-- (includes/schema_compat.php, flag settings.schema_v36). Run it by hand only if your DB user cannot ALTER at runtime.
-- Nothing is dropped, renamed or reset. Skip a line if MySQL says the column/index already exists.

ALTER TABLE orders ADD COLUMN refund_status   VARCHAR(16) DEFAULT NULL;
ALTER TABLE orders ADD COLUMN refund_method   VARCHAR(16) DEFAULT NULL;
ALTER TABLE orders ADD COLUMN refunded_at     DATETIME    DEFAULT NULL;
ALTER TABLE orders ADD COLUMN last_api_status VARCHAR(80) DEFAULT NULL;
ALTER TABLE orders ADD COLUMN last_checked_at DATETIME    DEFAULT NULL;
ALTER TABLE orders ADD COLUMN check_attempts  SMALLINT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE orders ADD INDEX idx_order_status_created (`status`, `created_at`);

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

INSERT INTO settings (setting_key, setting_value, updated_at) VALUES
  ('order_pending_window_seconds', '600', NOW()),
  ('refund_controller_auto', '1', NOW()),
  ('refund_controller_last_run', '0', NOW())
ON DUPLICATE KEY UPDATE setting_key = setting_key;
