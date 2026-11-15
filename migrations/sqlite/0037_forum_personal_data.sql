-- Retain only a tombstone identity for existing shared content; never reactivate it.
CREATE TABLE ce_forum_erased_accounts (
 user_id TEXT PRIMARY KEY,
 erased_at BIGINT NOT NULL,
 FOREIGN KEY(user_id) REFERENCES ce_users(id) ON DELETE CASCADE
);
