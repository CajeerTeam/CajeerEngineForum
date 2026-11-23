-- 1.3.3: isolate one-shot search evidence from user AI conversations.
-- All existing 1.3.1 conversations are normal; 1.3.2 thread conversations
-- are backfilled as thread purpose based on their thread_id.
ALTER TABLE ce_forum_ai_conversations ADD COLUMN purpose VARCHAR(12) NOT NULL DEFAULT 'chat';
UPDATE ce_forum_ai_conversations SET purpose = 'thread' WHERE thread_id IS NOT NULL;
CREATE INDEX ce_forum_ai_conversations_purpose ON ce_forum_ai_conversations(user_id, purpose, busy_until);
