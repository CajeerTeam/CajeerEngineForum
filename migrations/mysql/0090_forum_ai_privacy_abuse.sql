-- Forum Edition 1.3.10: durable per-member AI request burst limit and scheduled history cleanup.
CREATE TABLE ce_forum_ai_request_buckets (
    user_id CHAR(36) NOT NULL,
    window_start BIGINT NOT NULL,
    requests INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, window_start),
    CONSTRAINT ce_forum_ai_buckets_user_fk FOREIGN KEY (user_id) REFERENCES ce_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX ce_forum_ai_request_buckets_expire ON ce_forum_ai_request_buckets(window_start);
CREATE INDEX ce_forum_ai_conversations_expire ON ce_forum_ai_conversations(updated_at, busy_until);
