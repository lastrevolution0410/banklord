-- 008: explicit admin opt-in to automatic processing of complete guild-bank scans.
-- Existing scan.stage tokens NEVER gain stock mutation rights.
ALTER TABLE bridge_tokens MODIFY scope ENUM('scan.stage','scan.auto') NOT NULL DEFAULT 'scan.stage';
CREATE TABLE guild_scan_automation (
 guild_id BIGINT UNSIGNED PRIMARY KEY,
 enabled TINYINT(1) NOT NULL DEFAULT 0,
 enabled_by BIGINT UNSIGNED NULL,
 enabled_at DATETIME NULL,
 disabled_at DATETIME NULL,
 FOREIGN KEY (guild_id) REFERENCES guilds(id),
 FOREIGN KEY (enabled_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
