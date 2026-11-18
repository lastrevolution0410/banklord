-- Character that receives an order by in-game mail (set by in-game orders).
ALTER TABLE orders ADD COLUMN recipient VARCHAR(120) NULL AFTER note;
INSERT IGNORE INTO schema_migrations (name) VALUES ('014_order_recipient.sql');
