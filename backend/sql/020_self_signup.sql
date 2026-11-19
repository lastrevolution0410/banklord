-- Self sign-up for guild leaders (operator switch, off by default) and locking a guild.
ALTER TABLE guilds ADD COLUMN locked_at DATETIME NULL;
INSERT IGNORE INTO site_settings (name,value) VALUES ('self_signup','0'),('signups_per_day','20');
INSERT IGNORE INTO schema_migrations (name) VALUES ('020_self_signup.sql');
