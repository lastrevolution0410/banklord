-- 0.9.1: signed automatic releases (web self-update + client update channel).
CREATE TABLE schema_migrations (
 name VARCHAR(80) PRIMARY KEY,
 applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO schema_migrations (name) VALUES ('001_initial.sql'),('002_scan_staging.sql'),('003_bridge_tokens.sql'),
 ('004_members_and_comfort.sql'),('005_procurement.sql'),('006_peer_drafts.sql'),('007_item_localizations.sql'),
 ('008_automatic_scans.sql'),('009_scan_retention.sql'),('010_member_order_tokens.sql'),('011_release_publishing.sql');
CREATE TABLE release_publish_tokens (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 created_by BIGINT UNSIGNED NOT NULL,
 label VARCHAR(80) NOT NULL,
 token_hash CHAR(64) NOT NULL UNIQUE,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 last_used_at DATETIME NULL,
 revoked_at DATETIME NULL,
 FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE release_publications (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 manifest_sha256 CHAR(64) NOT NULL,
 web_version VARCHAR(40) NOT NULL,
 addon_version VARCHAR(40) NOT NULL,
 companion_version VARCHAR(40) NOT NULL,
 updater_version VARCHAR(40) NOT NULL,
 web_applied TINYINT(1) NOT NULL DEFAULT 0,
 published_by_token BIGINT UNSIGNED NOT NULL,
 published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 details_json JSON NULL,
 FOREIGN KEY (published_by_token) REFERENCES release_publish_tokens(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
