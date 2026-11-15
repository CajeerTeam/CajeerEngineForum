-- Private draft payloads and immutable text revisions. Existing text is the first available revision.
ALTER TABLE ce_forum_posts ADD COLUMN revision_number INTEGER NOT NULL DEFAULT 1 CHECK(revision_number>=1);
CREATE TABLE ce_forum_post_revisions (
 post_id UUID NOT NULL,
 revision_number INTEGER NOT NULL CHECK(revision_number>=1),
 body TEXT NOT NULL,
 body_format VARCHAR(16) NOT NULL CHECK(body_format IN ('plain','markdown','bbcode')),
 editor_id UUID NULL,
 edit_reason VARCHAR(300) NOT NULL,
 created_at BIGINT NOT NULL,
 PRIMARY KEY(post_id,revision_number),
 FOREIGN KEY(post_id) REFERENCES ce_forum_posts(id) ON DELETE CASCADE,
 FOREIGN KEY(editor_id) REFERENCES ce_users(id) ON DELETE SET NULL
);
INSERT INTO ce_forum_post_revisions(post_id,revision_number,body,body_format,editor_id,edit_reason,created_at)
 SELECT id,1,body,body_format,author_id,'Migration baseline',CAST(EXTRACT(EPOCH FROM CURRENT_TIMESTAMP) AS BIGINT) FROM ce_forum_posts;
ALTER TABLE ce_forum_conversation_messages ADD COLUMN revision_number INTEGER NOT NULL DEFAULT 1 CHECK(revision_number>=1);
CREATE TABLE ce_forum_conversation_revisions (
 message_id UUID NOT NULL,
 revision_number INTEGER NOT NULL CHECK(revision_number>=1),
 body TEXT NOT NULL,
 body_format VARCHAR(16) NOT NULL CHECK(body_format IN ('plain','markdown','bbcode')),
 editor_id UUID NULL,
 edit_reason VARCHAR(300) NOT NULL,
 created_at BIGINT NOT NULL,
 PRIMARY KEY(message_id,revision_number),
 FOREIGN KEY(message_id) REFERENCES ce_forum_conversation_messages(id) ON DELETE CASCADE,
 FOREIGN KEY(editor_id) REFERENCES ce_users(id) ON DELETE SET NULL
);
INSERT INTO ce_forum_conversation_revisions(message_id,revision_number,body,body_format,editor_id,edit_reason,created_at)
 SELECT id,1,body,body_format,author_id,'Migration baseline',CAST(EXTRACT(EPOCH FROM CURRENT_TIMESTAMP) AS BIGINT) FROM ce_forum_conversation_messages;
CREATE TABLE ce_forum_drafts (
 id UUID NOT NULL,
 user_id UUID NOT NULL,
 scope VARCHAR(24) NOT NULL CHECK(scope IN ('thread','reply','conversation','new_conversation')),
 target_id UUID NULL,
 revision_number INTEGER NOT NULL CHECK(revision_number>=1),
 payload TEXT NOT NULL,
 result_json TEXT NULL,
 updated_at BIGINT NOT NULL,
 expires_at BIGINT NOT NULL,
 PRIMARY KEY(user_id,id),
 FOREIGN KEY(user_id) REFERENCES ce_users(id) ON DELETE CASCADE,
 CHECK((scope='new_conversation' AND target_id IS NULL) OR (scope<>'new_conversation' AND target_id IS NOT NULL))
);
CREATE INDEX ce_forum_draft_owner ON ce_forum_drafts(user_id,updated_at);
CREATE INDEX ce_forum_draft_expiry ON ce_forum_drafts(expires_at);
