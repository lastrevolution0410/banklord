-- WoW Guild Bank, initial migration. MariaDB 10.5+ / InnoDB / utf8mb4.
-- UTC timestamps, IDs are opaque server IDs; never trust client supplied guild roles.
CREATE TABLE users (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 email VARCHAR(254) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,
 display_name VARCHAR(100) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE guilds (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(120) NOT NULL,
 realm VARCHAR(100) NOT NULL,
 region VARCHAR(8) NOT NULL DEFAULT 'EU',
 wow_flavor VARCHAR(32) NOT NULL DEFAULT 'mop_classic',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_realm (region, realm)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE guild_memberships (
 guild_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 role ENUM('member','officer','bank_manager','admin') NOT NULL DEFAULT 'member',
 status ENUM('active','disabled') NOT NULL DEFAULT 'active',
 joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (guild_id,user_id),
 FOREIGN KEY (guild_id) REFERENCES guilds(id),
 FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE characters (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 guild_id BIGINT UNSIGNED NOT NULL,
 name VARCHAR(64) NOT NULL,
 realm VARCHAR(100) NOT NULL,
 verification_status ENUM('unverified','verified','revoked') NOT NULL DEFAULT 'unverified',
 UNIQUE KEY uq_character (guild_id,realm,name),
 FOREIGN KEY (guild_id,user_id) REFERENCES guild_memberships(guild_id,user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE items (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 wow_flavor VARCHAR(32) NOT NULL,
 wow_item_id INT UNSIGNED NOT NULL,
 variant_key VARCHAR(180) NOT NULL DEFAULT '',
 name VARCHAR(200) NOT NULL,
 UNIQUE KEY uq_item (wow_flavor,wow_item_id,variant_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE guild_stock (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 guild_id BIGINT UNSIGNED NOT NULL,
 item_id BIGINT UNSIGNED NOT NULL,
 physical_qty BIGINT UNSIGNED NOT NULL DEFAULT 0,
 reserved_qty BIGINT UNSIGNED NOT NULL DEFAULT 0,
 stock_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
 confidence ENUM('unknown','verified','stale','conflict') NOT NULL DEFAULT 'unknown',
 observed_at DATETIME NULL,
 UNIQUE KEY uq_guild_item (guild_id,item_id),
 FOREIGN KEY (guild_id) REFERENCES guilds(id),
 FOREIGN KEY (item_id) REFERENCES items(id),
 CHECK (reserved_qty <= physical_qty)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE orders (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 guild_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 client_key VARCHAR(100) NOT NULL,
 request_fingerprint CHAR(64) NOT NULL,
 status ENUM('submitted','partial','reserved','rejected','partially_fulfilled','fulfilled','cancelled') NOT NULL DEFAULT 'submitted',
 note VARCHAR(500) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_request (guild_id,user_id,client_key),
 KEY idx_order_status (guild_id,status,created_at),
 FOREIGN KEY (guild_id,user_id) REFERENCES guild_memberships(guild_id,user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE order_lines (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 stock_id BIGINT UNSIGNED NOT NULL,
 requested_qty BIGINT UNSIGNED NOT NULL,
 approved_qty BIGINT UNSIGNED NOT NULL DEFAULT 0,
 fulfilled_qty BIGINT UNSIGNED NOT NULL DEFAULT 0,
 decision ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
 UNIQUE KEY uq_order_stock (order_id,stock_id),
 FOREIGN KEY (order_id) REFERENCES orders(id),
 FOREIGN KEY (stock_id) REFERENCES guild_stock(id),
 CHECK (requested_qty > 0),
 CHECK (approved_qty <= requested_qty),
 CHECK (fulfilled_qty <= approved_qty)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE stock_ledger (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 guild_id BIGINT UNSIGNED NOT NULL,
 stock_id BIGINT UNSIGNED NOT NULL,
 order_line_id BIGINT UNSIGNED NULL,
 event_type ENUM('reserve','release','fulfill','manual_adjust','scan_reconcile') NOT NULL,
 physical_delta BIGINT NOT NULL DEFAULT 0,
 reserved_delta BIGINT NOT NULL DEFAULT 0,
 actor_user_id BIGINT UNSIGNED NULL,
 operation_key VARCHAR(100) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_ledger_op (guild_id,operation_key),
 KEY idx_stock_ledger (stock_id,id),
 FOREIGN KEY (guild_id) REFERENCES guilds(id),
 FOREIGN KEY (stock_id) REFERENCES guild_stock(id),
 FOREIGN KEY (order_line_id) REFERENCES order_lines(id),
 FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE stock_snapshots (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 guild_id BIGINT UNSIGNED NOT NULL,
 source VARCHAR(64) NOT NULL,
 source_key VARCHAR(128) NOT NULL,
 observed_at DATETIME NOT NULL,
 imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 quality ENUM('unverified','partial','complete','rejected') NOT NULL DEFAULT 'unverified',
 status ENUM('pending','reconciled','conflict','rejected') NOT NULL DEFAULT 'pending',
 UNIQUE KEY uq_source (guild_id,source,source_key),
 FOREIGN KEY (guild_id) REFERENCES guilds(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE snapshot_lines (
 snapshot_id BIGINT UNSIGNED NOT NULL,
 tab_no SMALLINT UNSIGNED NOT NULL,
 slot_no SMALLINT UNSIGNED NOT NULL,
 item_id BIGINT UNSIGNED NULL,
 quantity BIGINT UNSIGNED NOT NULL DEFAULT 0,
 PRIMARY KEY (snapshot_id,tab_no,slot_no),
 FOREIGN KEY (snapshot_id) REFERENCES stock_snapshots(id),
 FOREIGN KEY (item_id) REFERENCES items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE audit_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 guild_id BIGINT UNSIGNED NOT NULL,
 actor_user_id BIGINT UNSIGNED NULL,
 action VARCHAR(70) NOT NULL,
 entity_type VARCHAR(40) NOT NULL,
 entity_id BIGINT UNSIGNED NULL,
 details_json JSON NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_audit (guild_id,created_at),
 FOREIGN KEY (guild_id) REFERENCES guilds(id),
 FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE login_attempts (
 key_hash CHAR(64) PRIMARY KEY,
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 window_started_at DATETIME NOT NULL,
 KEY idx_login_window (window_started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
