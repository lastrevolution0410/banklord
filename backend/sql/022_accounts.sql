-- Operator manages accounts: lock/unlock, last sign-in, and a small log of what the operator did.
ALTER TABLE users ADD COLUMN locked_at DATETIME NULL, ADD COLUMN last_login_at DATETIME NULL;
CREATE TABLE operator_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 actor_user_id BIGINT UNSIGNED NOT NULL,
 action VARCHAR(40) NOT NULL,
 target_user_id BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_opev_target (target_user_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO schema_migrations (name) VALUES ('022_accounts.sql');
