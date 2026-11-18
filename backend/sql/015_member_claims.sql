-- Joining by in-game confirmation: a bank manager's addon vouches for a guild
-- member's companion key; the companion later proves the key by signature.
CREATE TABLE member_claims (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 guild_id BIGINT UNSIGNED NOT NULL,
 public_key CHAR(64) NOT NULL,
 char_name VARCHAR(64) NOT NULL,
 char_realm VARCHAR(100) NOT NULL,
 approved_by BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NULL,
 nonce CHAR(64) NULL,
 nonce_expires DATETIME NULL,
 redeemed_at DATETIME NULL,
 welcome_hash CHAR(64) NULL,
 welcome_expires DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_claim (guild_id,public_key),
 KEY idx_claim_key (public_key),
 KEY idx_welcome (welcome_hash),
 FOREIGN KEY (guild_id) REFERENCES guilds(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO schema_migrations (name) VALUES ('015_member_claims.sql');
