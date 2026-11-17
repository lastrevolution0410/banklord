-- Keep exactly the newest five inventory snapshots per guild, without touching the stock ledger/audit.
-- A tiny receipt (not a scan or item list) prevents deleted snapshots from being re-imported.
CREATE TABLE scan_import_receipts (
 guild_id BIGINT UNSIGNED NOT NULL,
 source VARCHAR(64) NOT NULL,
 source_key VARCHAR(128) NOT NULL,
 original_snapshot_id BIGINT UNSIGNED NOT NULL,
 imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (guild_id,source,source_key),
 FOREIGN KEY (guild_id) REFERENCES guilds(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO scan_import_receipts (guild_id,source,source_key,original_snapshot_id,imported_at)
 SELECT guild_id,source,source_key,id,imported_at FROM stock_snapshots;
CREATE TABLE scan_retention_candidates (snapshot_id BIGINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB;
INSERT INTO scan_retention_candidates (snapshot_id)
 SELECT old.id FROM stock_snapshots old
 WHERE (SELECT COUNT(*) FROM stock_snapshots newer WHERE newer.guild_id=old.guild_id AND newer.id>old.id)>=5;
INSERT INTO audit_events (guild_id,actor_user_id,action,entity_type,entity_id,details_json)
 SELECT s.guild_id,NULL,'scan.retention_pruned','stock_snapshot',NULL,JSON_OBJECT('pruned_count',COUNT(*),'reason','retention_last_five')
 FROM stock_snapshots s JOIN scan_retention_candidates d ON d.snapshot_id=s.id GROUP BY s.guild_id;
DELETE sl FROM snapshot_lines sl JOIN scan_retention_candidates d ON d.snapshot_id=sl.snapshot_id;
DELETE s FROM stock_snapshots s JOIN scan_retention_candidates d ON d.snapshot_id=s.id;
DROP TABLE scan_retention_candidates;
