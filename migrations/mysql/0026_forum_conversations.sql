CREATE TABLE ce_forum_conversation_lock (id INTEGER PRIMARY KEY);
INSERT INTO ce_forum_conversation_lock(id) VALUES (1);
CREATE TABLE ce_forum_conversations (
 id CHAR(36) PRIMARY KEY, owner_id CHAR(36) NULL, title VARCHAR(180) NOT NULL,
 kind VARCHAR(8) NOT NULL CHECK(kind IN ('direct','group')),
 last_sequence BIGINT NOT NULL DEFAULT 0 CHECK(last_sequence>=0), created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL,
 FOREIGN KEY(owner_id) REFERENCES ce_users(id) ON DELETE SET NULL
);
CREATE TABLE ce_forum_conversation_participants (
 conversation_id CHAR(36) NOT NULL, user_id CHAR(36) NOT NULL,
 joined_sequence BIGINT NOT NULL CHECK(joined_sequence>=1), read_sequence BIGINT NOT NULL CHECK(read_sequence>=0),
 active INTEGER NOT NULL DEFAULT 1 CHECK(active IN (0,1)), archived INTEGER NOT NULL DEFAULT 0 CHECK(archived IN (0,1)), muted INTEGER NOT NULL DEFAULT 0 CHECK(muted IN (0,1)),
 PRIMARY KEY(conversation_id,user_id), FOREIGN KEY(conversation_id) REFERENCES ce_forum_conversations(id) ON DELETE CASCADE,
 FOREIGN KEY(user_id) REFERENCES ce_users(id) ON DELETE CASCADE
);
CREATE INDEX ce_forum_conv_member ON ce_forum_conversation_participants(user_id,active,archived,conversation_id);
CREATE TABLE ce_forum_conversation_messages (
 id CHAR(36) PRIMARY KEY, conversation_id CHAR(36) NOT NULL, author_id CHAR(36) NULL, message_sequence BIGINT NOT NULL CHECK(message_sequence>=1),
 body LONGTEXT NOT NULL, created_at BIGINT NOT NULL, edited_at BIGINT NULL,
 UNIQUE(conversation_id,message_sequence), FOREIGN KEY(conversation_id) REFERENCES ce_forum_conversations(id) ON DELETE CASCADE,
 FOREIGN KEY(author_id) REFERENCES ce_users(id) ON DELETE SET NULL
);
CREATE TABLE ce_forum_conversation_preferences (
 user_id CHAR(36) PRIMARY KEY, incoming VARCHAR(8) NOT NULL DEFAULT 'members' CHECK(incoming IN ('members','none')),
 FOREIGN KEY(user_id) REFERENCES ce_users(id) ON DELETE CASCADE
);
CREATE TABLE ce_forum_user_blocks (
 user_id CHAR(36) NOT NULL, blocked_id CHAR(36) NOT NULL, PRIMARY KEY(user_id,blocked_id), CHECK(user_id<>blocked_id),
 FOREIGN KEY(user_id) REFERENCES ce_users(id) ON DELETE CASCADE, FOREIGN KEY(blocked_id) REFERENCES ce_users(id) ON DELETE CASCADE
);
CREATE INDEX ce_forum_conv_blocked ON ce_forum_user_blocks(blocked_id,user_id);
CREATE TABLE ce_forum_conversation_reports (
 id CHAR(36) PRIMARY KEY, message_id CHAR(36) NOT NULL, user_id CHAR(36) NOT NULL, reason VARCHAR(1000) NOT NULL,
 body_snapshot LONGTEXT NOT NULL, status VARCHAR(12) NOT NULL DEFAULT 'open' CHECK(status IN ('open','resolved','dismissed')),
 created_at BIGINT NOT NULL, resolved_by CHAR(36) NULL,
 UNIQUE(message_id,user_id), FOREIGN KEY(message_id) REFERENCES ce_forum_conversation_messages(id) ON DELETE CASCADE,
 FOREIGN KEY(user_id) REFERENCES ce_users(id) ON DELETE CASCADE, FOREIGN KEY(resolved_by) REFERENCES ce_users(id) ON DELETE SET NULL
);
CREATE INDEX ce_forum_conv_report_queue ON ce_forum_conversation_reports(status,created_at,id);
CREATE TABLE ce_forum_conversation_limits (
 user_id CHAR(36) NOT NULL, action VARCHAR(16) NOT NULL, window_start BIGINT NOT NULL, hits INTEGER NOT NULL CHECK(hits>0),
 PRIMARY KEY(user_id,action), FOREIGN KEY(user_id) REFERENCES ce_users(id) ON DELETE CASCADE
);
