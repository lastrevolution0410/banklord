-- Guild order rules: auto-expiry of old orders, weekly piece limits per role
-- (above the limit an officer or admin approves) and view-only bank tabs.
CREATE TABLE guild_order_rules (
 guild_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 expiry_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 member_weekly_limit INT UNSIGNED NOT NULL DEFAULT 0,
 manager_weekly_limit INT UNSIGNED NOT NULL DEFAULT 0,
 viewonly_tabs VARCHAR(40) NOT NULL DEFAULT '',
 updated_by BIGINT UNSIGNED NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY (guild_id) REFERENCES guilds(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE orders ADD COLUMN needs_approval TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER recipient;
ALTER TABLE guild_stock ADD COLUMN viewonly_qty BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER reserved_qty;
INSERT IGNORE INTO schema_migrations (name) VALUES ('017_order_rules.sql');
