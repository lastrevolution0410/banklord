-- 004: Accounts, invitations and member convenience. Existing stock/orders untouched.
CREATE TABLE guild_invitations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 guild_id BIGINT UNSIGNED NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 role ENUM('member','officer','bank_manager') NOT NULL DEFAULT 'member',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 expires_at DATETIME NOT NULL,
 redeemed_at DATETIME NULL,
 redeemed_by BIGINT UNSIGNED NULL,
 UNIQUE KEY uq_invitation_hash(token_hash),
 KEY idx_invites_guild(guild_id,expires_at),
 FOREIGN KEY (guild_id) REFERENCES guilds(id),
 FOREIGN KEY (created_by) REFERENCES users(id),
 FOREIGN KEY (redeemed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE user_favorites (
 guild_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 stock_id BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (guild_id,user_id,stock_id),
 FOREIGN KEY (guild_id,user_id) REFERENCES guild_memberships(guild_id,user_id),
 FOREIGN KEY (stock_id) REFERENCES guild_stock(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE user_wishlists (
 guild_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 stock_id BIGINT UNSIGNED NOT NULL,
 desired_qty INT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (guild_id,user_id,stock_id),
 FOREIGN KEY (guild_id,user_id) REFERENCES guild_memberships(guild_id,user_id),
 FOREIGN KEY (stock_id) REFERENCES guild_stock(id) ON DELETE CASCADE,
 CHECK(desired_qty BETWEEN 1 AND 1000000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE user_order_templates (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 guild_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 title VARCHAR(90) NOT NULL,
 lines_json JSON NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_templates(guild_id,user_id),
 FOREIGN KEY (guild_id,user_id) REFERENCES guild_memberships(guild_id,user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
