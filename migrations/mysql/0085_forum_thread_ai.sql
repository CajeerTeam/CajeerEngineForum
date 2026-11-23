-- Forum 1.3.2: keep contextual AI sessions isolated from ordinary conversations.
ALTER TABLE ce_forum_ai_conversations ADD COLUMN thread_id CHAR(36) NULL;
CREATE INDEX ce_forum_ai_conversations_thread ON ce_forum_ai_conversations(user_id, thread_id, updated_at);
ALTER TABLE ce_forum_ai_messages ADD COLUMN sources_json TEXT NULL;
