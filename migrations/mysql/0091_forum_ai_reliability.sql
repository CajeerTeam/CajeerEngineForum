-- Durable bounded AI admission; no user content is stored in the queue.
CREATE TABLE ce_forum_ai_admission_state (
 id INTEGER PRIMARY KEY, sequence_no BIGINT NOT NULL, cooldown_until BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO ce_forum_ai_admission_state (id,sequence_no,cooldown_until) VALUES (1,0,0);
CREATE TABLE ce_forum_ai_admission (
 id VARCHAR(32) PRIMARY KEY, user_id CHAR(36) NOT NULL UNIQUE,
 sequence_no BIGINT NOT NULL UNIQUE, active INTEGER NOT NULL, expires_at BIGINT NOT NULL,
 FOREIGN KEY (user_id) REFERENCES ce_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX ce_forum_ai_admission_fifo ON ce_forum_ai_admission(active,sequence_no);
CREATE INDEX ce_forum_ai_admission_expiry ON ce_forum_ai_admission(expires_at);
CREATE INDEX ce_forum_ai_conversations_busy ON ce_forum_ai_conversations(busy_until,user_id);
