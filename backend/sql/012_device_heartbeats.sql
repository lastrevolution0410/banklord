-- 0.9.5: installed versions reported by each companion (admin overview under System).
CREATE TABLE device_heartbeats (
 token_id BIGINT UNSIGNED PRIMARY KEY,
 guild_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 device VARCHAR(60) NOT NULL,
 companion_version VARCHAR(40) NOT NULL,
 addon_version VARCHAR(40) NOT NULL,
 updater_version VARCHAR(40) NOT NULL,
 last_seen DATETIME NOT NULL,
 KEY idx_guild (guild_id,last_seen),
 FOREIGN KEY (token_id) REFERENCES bridge_tokens(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO schema_migrations (name) VALUES ('012_device_heartbeats.sql');
