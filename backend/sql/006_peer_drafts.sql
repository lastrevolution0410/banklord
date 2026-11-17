-- Ingame addon messages are UNTRUSTED. Never create an order or reservation from this table.
CREATE TABLE peer_order_drafts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 guild_id BIGINT UNSIGNED NOT NULL,
 imported_by BIGINT UNSIGNED NOT NULL,
 sender VARCHAR(120) NOT NULL,
 message_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 transfer_code TEXT NOT NULL,
 code_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 sender_claim_verified TINYINT(1) NOT NULL DEFAULT 0,
 received_at DATETIME NOT NULL,
 imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 status ENUM('unverified','dismissed') NOT NULL DEFAULT 'unverified',
 UNIQUE KEY uq_peer_inbox(guild_id,sender,message_id),
 KEY idx_peer_guild(guild_id,imported_at),
 FOREIGN KEY(guild_id) REFERENCES guilds(id),
 FOREIGN KEY(imported_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
