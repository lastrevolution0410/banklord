-- 005: Guild-scoped replenishment policies. No existing stock or orders are modified.
CREATE TABLE guild_restock_rules (
 guild_id BIGINT UNSIGNED NOT NULL,
 stock_id BIGINT UNSIGNED NOT NULL,
 minimum_qty INT UNSIGNED NOT NULL,
 target_qty INT UNSIGNED NOT NULL,
 updated_by BIGINT UNSIGNED NOT NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(guild_id,stock_id),
 FOREIGN KEY(guild_id) REFERENCES guilds(id),
 FOREIGN KEY(stock_id) REFERENCES guild_stock(id),
 FOREIGN KEY(updated_by) REFERENCES users(id),
 CHECK(target_qty >= minimum_qty),
 CHECK(target_qty <= 1000000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
