-- Forum Edition 1.3.10: durable per-member AI request burst limit and scheduled history cleanup.
-- User IDs are never stored in the analytics system; this throttle is private AI infrastructure.
CREATE TABLE ce_forum_ai_request_buckets (
    user_id VARCHAR(36) NOT NULL REFERENCES ce_users(id) ON DELETE CASCADE,
    window_start BIGINT NOT NULL,
    requests INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, window_start)
);
CREATE INDEX ce_forum_ai_request_buckets_expire ON ce_forum_ai_request_buckets(window_start);
CREATE INDEX ce_forum_ai_conversations_expire ON ce_forum_ai_conversations(updated_at, busy_until);
