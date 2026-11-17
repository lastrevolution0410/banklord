-- Migration 002. Stages untrusted addon observations only; does NOT modify guild_stock.
-- Apply AFTER 001_initial.sql in a disposable MariaDB test database first.
CREATE TABLE snapshot_source_lines (
 snapshot_id BIGINT UNSIGNED NOT NULL,
 tab_no SMALLINT UNSIGNED NOT NULL,
 slot_no SMALLINT UNSIGNED NOT NULL,
 wow_item_id INT UNSIGNED NOT NULL,
 variant_key VARCHAR(180) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 item_link VARCHAR(600) NOT NULL,
 item_name VARCHAR(200) NOT NULL,
 quantity INT UNSIGNED NOT NULL,
 PRIMARY KEY (snapshot_id,tab_no,slot_no),
 KEY idx_source_variant (snapshot_id,variant_key),
 FOREIGN KEY (snapshot_id) REFERENCES stock_snapshots(id) ON DELETE CASCADE,
 CHECK (tab_no BETWEEN 1 AND 16),
 CHECK (slot_no BETWEEN 1 AND 98),
 CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
