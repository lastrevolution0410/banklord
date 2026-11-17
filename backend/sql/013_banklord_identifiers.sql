-- Banklord 1.0: new scan source tag; keys issued before 1.0 use an old format and are retired.
UPDATE stock_snapshots SET source='banklord-savedvariables-v2' WHERE source='wgb-addon-savedvariables-v2';
UPDATE bridge_tokens SET revoked_at=UTC_TIMESTAMP() WHERE revoked_at IS NULL;
UPDATE release_publish_tokens SET revoked_at=UTC_TIMESTAMP() WHERE revoked_at IS NULL;
INSERT IGNORE INTO schema_migrations (name) VALUES ('013_banklord_identifiers.sql');
