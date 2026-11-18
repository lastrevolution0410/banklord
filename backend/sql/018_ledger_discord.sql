-- Bank ledger (gold and item log kept beyond the 25 entries WoW shows per tab),
-- bank gold balance, item prices from TSM/Auctionator, Discord notifications,
-- and urgent orders.
CREATE TABLE guild_ledger (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 guild_id BIGINT UNSIGNED NOT NULL,
 kind VARCHAR(8) NOT NULL,
 action VARCHAR(20) NOT NULL,
 player VARCHAR(80) NOT NULL,
 wow_item_id INT UNSIGNED NULL,
 item_name VARCHAR(200) NULL,
 quantity BIGINT UNSIGNED NOT NULL DEFAULT 0,
 tab_no TINYINT UNSIGNED NULL,
 tab_to TINYINT UNSIGNED NULL,
 happened_at DATETIME NOT NULL,
 uploaded_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_ledger_time (guild_id, happened_at),
 KEY idx_ledger_player (guild_id, player),
 FOREIGN KEY (guild_id) REFERENCES guilds(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE guild_bank_money (
 guild_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 copper BIGINT UNSIGNED NOT NULL DEFAULT 0,
 observed_at DATETIME NOT NULL,
 updated_by BIGINT UNSIGNED NULL,
 FOREIGN KEY (guild_id) REFERENCES guilds(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE guild_stock ADD COLUMN unit_price BIGINT UNSIGNED NULL AFTER viewonly_qty,
 ADD COLUMN price_source VARCHAR(16) NULL AFTER unit_price, ADD COLUMN price_at DATETIME NULL AFTER price_source;
CREATE TABLE guild_discord (
 guild_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 webhook_url VARCHAR(255) NOT NULL,
 events VARCHAR(80) NOT NULL DEFAULT 'order,approval,shipped,restock',
 updated_by BIGINT UNSIGNED NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY (guild_id) REFERENCES guilds(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE discord_outbox (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 guild_id BIGINT UNSIGNED NOT NULL,
 message VARCHAR(1900) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 sent_at DATETIME NULL,
 attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
 KEY idx_discord_pending (sent_at, id),
 FOREIGN KEY (guild_id) REFERENCES guilds(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE orders ADD COLUMN priority TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER needs_approval;
INSERT IGNORE INTO schema_migrations (name) VALUES ('018_ledger_discord.sql');
