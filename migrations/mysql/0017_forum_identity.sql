CREATE TABLE ce_identity_challenges (
    user_id CHAR(36) NOT NULL,
    purpose VARCHAR(20) NOT NULL,
    token_hash VARCHAR(64) NOT NULL UNIQUE,
    secret VARCHAR(128) NULL,
    issued_at BIGINT NOT NULL,
    expires_at BIGINT NOT NULL,
    PRIMARY KEY (user_id, purpose),
    FOREIGN KEY (user_id) REFERENCES ce_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX ce_identity_expiry_idx ON ce_identity_challenges(expires_at);
ALTER TABLE ce_users ADD COLUMN two_factor_last_step BIGINT NULL;
