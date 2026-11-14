-- Preserve historical messages as literal text.
ALTER TABLE ce_forum_posts ADD COLUMN body_format VARCHAR(16) NOT NULL DEFAULT 'plain' CHECK (body_format IN ('plain','markdown','bbcode'));
ALTER TABLE ce_forum_conversation_messages ADD COLUMN body_format VARCHAR(16) NOT NULL DEFAULT 'plain' CHECK (body_format IN ('plain','markdown','bbcode'));
