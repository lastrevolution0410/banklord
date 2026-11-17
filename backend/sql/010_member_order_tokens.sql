-- 0.9.0: personal companion tokens for in-game orders (no inventory authority).
ALTER TABLE bridge_tokens MODIFY scope ENUM('scan.stage','scan.auto','member.orders') NOT NULL DEFAULT 'scan.stage';
