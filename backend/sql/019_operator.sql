-- Site operator (one account that runs the whole website) and site-wide settings.
-- The operator sees every guild, the central feedback inbox and the update/release
-- tools; guild admins only manage their own guild.
ALTER TABLE users ADD COLUMN is_operator TINYINT(1) NOT NULL DEFAULT 0;
CREATE TABLE site_settings (
 name VARCHAR(40) NOT NULL PRIMARY KEY,
 value VARCHAR(200) NOT NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO site_settings (name,value) VALUES ('guilds_per_account','3');
-- The account that set the website up: first admin of the oldest guild.
UPDATE users SET is_operator=1 WHERE id=(
 SELECT user_id FROM (SELECT m.user_id FROM guild_memberships m WHERE m.role='admin' AND m.status='active'
  AND m.guild_id=(SELECT MIN(id) FROM guilds) ORDER BY m.user_id LIMIT 1) AS first_admin);
INSERT IGNORE INTO schema_migrations (name) VALUES ('019_operator.sql');
