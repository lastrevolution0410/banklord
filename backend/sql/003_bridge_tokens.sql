-- 003: scoped bridge credentials. Raw secrets NEVER stored in database.
CREATE TABLE bridge_tokens (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 guild_id BIGINT UNSIGNED NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 label VARCHAR(80) NOT NULL,
 token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 scope ENUM('scan.stage') NOT NULL DEFAULT 'scan.stage',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 expires_at DATETIME NOT NULL,
 last_used_at DATETIME NULL,
 revoked_at DATETIME NULL,
 UNIQUE KEY uq_bridge_hash(token_hash),
 KEY idx_bridge_guild(guild_id,revoked_at),
 FOREIGN KEY (guild_id) REFERENCES guilds(id),
 FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Small in-app notification inbox, always scoped to a specific guild+user.
CREATE TABLE notifications (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 guild_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 kind ENUM('order.submitted','order.decided','order.fulfilled','order.cancelled') NOT NULL,
 order_id BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 read_at DATETIME NULL,
 KEY idx_notifications_user(guild_id,user_id,created_at),
 FOREIGN KEY (guild_id,user_id) REFERENCES guild_memberships(guild_id,user_id),
 FOREIGN KEY (order_id) REFERENCES orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
