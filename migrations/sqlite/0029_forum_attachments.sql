-- Private, transactional attachment bytes; no public filesystem paths.
CREATE TABLE ce_forum_attachments (
 id TEXT PRIMARY KEY, owner_id TEXT NOT NULL,
 post_id TEXT NULL, message_id TEXT NULL,
 filename VARCHAR(180) NOT NULL, mime VARCHAR(100) NOT NULL,
 size_bytes BIGINT NOT NULL CHECK(size_bytes>0 AND size_bytes<=26214400),
 sha256 CHAR(64) NOT NULL, file_data TEXT NOT NULL,
 created_at BIGINT NOT NULL, expires_at BIGINT NOT NULL,
 CHECK(post_id IS NULL OR message_id IS NULL),
 CHECK(created_at>=0),
 CHECK((post_id IS NULL AND message_id IS NULL AND expires_at>created_at) OR ((post_id IS NOT NULL OR message_id IS NOT NULL) AND expires_at=0)),
 FOREIGN KEY(owner_id) REFERENCES ce_users(id) ON DELETE CASCADE,
 FOREIGN KEY(post_id) REFERENCES ce_forum_posts(id) ON DELETE CASCADE,
 FOREIGN KEY(message_id) REFERENCES ce_forum_conversation_messages(id) ON DELETE CASCADE
);
CREATE INDEX ce_forum_attachment_owner ON ce_forum_attachments(owner_id);
CREATE INDEX ce_forum_attachment_post ON ce_forum_attachments(post_id);
CREATE INDEX ce_forum_attachment_message ON ce_forum_attachments(message_id);
CREATE INDEX ce_forum_attachment_expiry ON ce_forum_attachments(expires_at);
