-- Forum Edition 1.3.1: isolated member AI chat, hard limits and operator settings.
-- Does not expose or reuse privileged CMS AI conversations.
CREATE TABLE ce_forum_ai_settings (
    id INTEGER PRIMARY KEY,
    enabled INTEGER NOT NULL DEFAULT 0,
    model VARCHAR(80) NOT NULL DEFAULT '',
    max_output_tokens INTEGER NOT NULL DEFAULT 1024,
    daily_request_limit INTEGER NOT NULL DEFAULT 20,
    monthly_token_limit INTEGER NOT NULL DEFAULT 100000,
    revision BIGINT NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO ce_forum_ai_settings (id, enabled, model, max_output_tokens, daily_request_limit, monthly_token_limit, revision) VALUES (1, 0, '', 1024, 20, 100000, 1);
CREATE TABLE ce_forum_ai_conversations (
    id CHAR(36) PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    title VARCHAR(160) NOT NULL,
    created_at BIGINT NOT NULL,
    updated_at BIGINT NOT NULL,
    busy_until BIGINT NOT NULL DEFAULT 0,
    reserved_tokens BIGINT NOT NULL DEFAULT 0,
    reservation_period VARCHAR(7) NULL,
    CONSTRAINT ce_forum_ai_conv_user_fk FOREIGN KEY (user_id) REFERENCES ce_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX ce_forum_ai_conversations_owner ON ce_forum_ai_conversations(user_id, updated_at);
CREATE TABLE ce_forum_ai_messages (
    id CHAR(36) PRIMARY KEY,
    conversation_id CHAR(36) NOT NULL,
    role VARCHAR(16) NOT NULL,
    content MEDIUMTEXT NOT NULL,
    created_at BIGINT NOT NULL,
    model VARCHAR(80) NULL,
    input_tokens INTEGER NOT NULL DEFAULT 0,
    output_tokens INTEGER NOT NULL DEFAULT 0,
    CONSTRAINT ce_forum_ai_msg_conv_fk FOREIGN KEY (conversation_id) REFERENCES ce_forum_ai_conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX ce_forum_ai_messages_order ON ce_forum_ai_messages(conversation_id, created_at, id);
CREATE TABLE ce_forum_ai_usage (
    user_id CHAR(36) NOT NULL,
    period VARCHAR(7) NOT NULL,
    used_tokens BIGINT NOT NULL DEFAULT 0,
    reserved_tokens BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, period),
    CONSTRAINT ce_forum_ai_usage_user_fk FOREIGN KEY (user_id) REFERENCES ce_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE ce_forum_ai_daily (
    user_id CHAR(36) NOT NULL,
    day VARCHAR(10) NOT NULL,
    requests INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, day),
    CONSTRAINT ce_forum_ai_daily_user_fk FOREIGN KEY (user_id) REFERENCES ce_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
