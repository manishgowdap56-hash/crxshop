-- v35 operations migration: audit log, admin alerts, price changes, API-user flag, product archive, failure reason

CREATE TABLE IF NOT EXISTS audit_logs (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actor_user_id INT UNSIGNED DEFAULT NULL,
  actor_label   VARCHAR(60)  DEFAULT NULL,
  event_type    VARCHAR(60)  NOT NULL,
  result        ENUM('success','failure','info','denied') NOT NULL DEFAULT 'info',
  entity_type   VARCHAR(30)  DEFAULT NULL,
  entity_id     VARCHAR(40)  DEFAULT NULL,
  order_ref     VARCHAR(40)  DEFAULT NULL,
  user_id       INT UNSIGNED DEFAULT NULL,
  reason        VARCHAR(500) DEFAULT NULL,
  ip            VARCHAR(45)  DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_audit_created (created_at),
  KEY idx_audit_event (event_type, created_at),
  KEY idx_audit_order (order_ref),
  KEY idx_audit_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_alerts (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  open_key           VARCHAR(120) DEFAULT NULL,
  dedupe_key         VARCHAR(120) NOT NULL,
  alert_type         VARCHAR(40)  NOT NULL,
  severity           ENUM('info','warning','critical') NOT NULL DEFAULT 'warning',
  status             ENUM('open','resolved') NOT NULL DEFAULT 'open',
  product_id         INT UNSIGNED DEFAULT NULL,
  product_name       VARCHAR(120) DEFAULT NULL,
  plan_id            INT UNSIGNED DEFAULT NULL,
  duration           VARCHAR(60)  DEFAULT NULL,
  order_id           BIGINT UNSIGNED DEFAULT NULL,
  order_ref          VARCHAR(40)  DEFAULT NULL,
  provider_key       VARCHAR(20)  DEFAULT NULL,
  provider_label     VARCHAR(80)  DEFAULT NULL,
  stock_source       ENUM('local','api') DEFAULT NULL,
  reason             VARCHAR(500) DEFAULT NULL,
  recommended_action VARCHAR(500) DEFAULT NULL,
  occurrences        INT UNSIGNED NOT NULL DEFAULT 1,
  first_seen         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at        DATETIME     DEFAULT NULL,
  resolved_by        INT UNSIGNED DEFAULT NULL,
  resolved_note      VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_alert_open (open_key),
  KEY idx_alert_status (status, last_seen),
  KEY idx_alert_type (alert_type),
  KEY idx_alert_order (order_ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS price_changes (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id      INT UNSIGNED NOT NULL,
  plan_id         INT UNSIGNED NOT NULL,
  product_name    VARCHAR(120) NOT NULL,
  duration        VARCHAR(60)  NOT NULL,
  old_price       DECIMAL(12,2) NOT NULL,
  new_price       DECIMAL(12,2) NOT NULL,
  changed_by      INT UNSIGNED DEFAULT NULL,
  changed_by_name VARCHAR(60)  DEFAULT NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pc_plan (plan_id, created_at),
  KEY idx_pc_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS price_change_acks (
  user_id   INT UNSIGNED NOT NULL,
  change_id BIGINT UNSIGNED NOT NULL,
  acked_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, change_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Run ONCE only if you do not let the application migrate itself (it does this automatically on the first request).
-- "Duplicate column name" errors mean the column already exists and are harmless.
ALTER TABLE users    ADD COLUMN api_enabled  TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE users    ADD COLUMN lowbal_ack_at DATETIME DEFAULT NULL;
ALTER TABLE products ADD COLUMN archived_at  DATETIME DEFAULT NULL;
ALTER TABLE products ADD COLUMN archived_by  INT UNSIGNED DEFAULT NULL;
ALTER TABLE orders   ADD COLUMN failure_reason VARCHAR(500) DEFAULT NULL;
INSERT INTO settings (setting_key, setting_value, updated_at) VALUES ('access_key_required', '1', NOW())
  ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- OPTIONAL one-time backfill: flag users who already placed orders through the reseller API as API-enabled.
-- (orders.source = 'api' is set by the reseller gateway, but a website client can also send source=api, so review the list first.)
-- UPDATE users u SET u.api_enabled = 1 WHERE EXISTS (SELECT 1 FROM orders o WHERE o.user_id = u.id AND o.source = 'api');
