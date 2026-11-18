-- Help & feedback: bug reports, feature ideas and language requests.
-- feedback_inbox lives on the official Banklord site (and on self-hosted sites
-- that do not contact the official channel). feedback_reports is the guild
-- site's own receipt list so members can follow the status of their reports.
CREATE TABLE feedback_inbox (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 kind ENUM('bug','idea','language') NOT NULL,
 title VARCHAR(120) NOT NULL,
 details TEXT NOT NULL,
 meta TEXT NOT NULL,
 site VARCHAR(255) NOT NULL,
 contact VARCHAR(254) NULL,
 status ENUM('new','seen','planned','done','rejected','duplicate') NOT NULL DEFAULT 'new',
 reply VARCHAR(1000) NOT NULL DEFAULT '',
 receipt_hash CHAR(64) NOT NULL,
 sender_hash CHAR(64) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_feedback_receipt (receipt_hash),
 KEY ix_feedback_status (status, id),
 KEY ix_feedback_sender (sender_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE feedback_reports (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 guild_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 client_key VARCHAR(40) NULL,
 source ENUM('web','ingame') NOT NULL,
 kind ENUM('bug','idea','language') NOT NULL,
 title VARCHAR(120) NOT NULL,
 remote_id BIGINT UNSIGNED NULL,
 receipt CHAR(64) NULL,
 status VARCHAR(16) NOT NULL DEFAULT 'new',
 reply VARCHAR(1000) NOT NULL DEFAULT '',
 error VARCHAR(40) NULL,
 checked_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_feedback_client (guild_id, client_key),
 KEY ix_feedback_user (guild_id, user_id, id),
 FOREIGN KEY (guild_id) REFERENCES guilds(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO schema_migrations (name) VALUES ('016_feedback.sql');
