CREATE TABLE ce_forum_categories (
 id CHAR(36) PRIMARY KEY, name VARCHAR(120) NOT NULL, description TEXT NOT NULL, position INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE ce_forum_boards (
 id CHAR(36) PRIMARY KEY, category_id CHAR(36) NOT NULL,
 name VARCHAR(120) NOT NULL, description TEXT NOT NULL, position INTEGER NOT NULL DEFAULT 0
,
 FOREIGN KEY (category_id) REFERENCES ce_forum_categories(id) ON DELETE CASCADE
);
CREATE TABLE ce_forum_threads (
 id CHAR(36) PRIMARY KEY, board_id CHAR(36) NOT NULL,
 author_id CHAR(36), title VARCHAR(180) NOT NULL,
 locked INTEGER NOT NULL DEFAULT 0, pinned INTEGER NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
,
 FOREIGN KEY (board_id) REFERENCES ce_forum_boards(id) ON DELETE CASCADE,
 FOREIGN KEY (author_id) REFERENCES ce_users(id) ON DELETE SET NULL
);
CREATE TABLE ce_forum_posts (
 id CHAR(36) PRIMARY KEY, thread_id CHAR(36) NOT NULL,
 author_id CHAR(36), body MEDIUMTEXT NOT NULL, ordinal INTEGER NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, edited_at DATETIME NULL
,
 FOREIGN KEY (thread_id) REFERENCES ce_forum_threads(id) ON DELETE CASCADE,
 FOREIGN KEY (author_id) REFERENCES ce_users(id) ON DELETE SET NULL
);
CREATE TABLE ce_forum_reactions (
 post_id CHAR(36) NOT NULL,
 user_id CHAR(36) NOT NULL, PRIMARY KEY(post_id,user_id)
,
 FOREIGN KEY (post_id) REFERENCES ce_forum_posts(id) ON DELETE CASCADE,
 FOREIGN KEY (user_id) REFERENCES ce_users(id) ON DELETE CASCADE
);
CREATE TABLE ce_forum_watches (
 thread_id CHAR(36) NOT NULL,
 user_id CHAR(36) NOT NULL, PRIMARY KEY(thread_id,user_id)
,
 FOREIGN KEY (thread_id) REFERENCES ce_forum_threads(id) ON DELETE CASCADE,
 FOREIGN KEY (user_id) REFERENCES ce_users(id) ON DELETE CASCADE
);
CREATE TABLE ce_forum_reports (
 id CHAR(36) PRIMARY KEY, post_id CHAR(36) NOT NULL,
 user_id CHAR(36), reason VARCHAR(500) NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'open', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 resolved_by CHAR(36)
,
 FOREIGN KEY (post_id) REFERENCES ce_forum_posts(id) ON DELETE CASCADE,
 FOREIGN KEY (user_id) REFERENCES ce_users(id) ON DELETE SET NULL,
 FOREIGN KEY (resolved_by) REFERENCES ce_users(id) ON DELETE SET NULL
);
CREATE INDEX ce_forum_board_category ON ce_forum_boards(category_id,position);
CREATE INDEX ce_forum_thread_board ON ce_forum_threads(board_id,pinned,updated_at);
CREATE UNIQUE INDEX ce_forum_post_thread ON ce_forum_posts(thread_id,ordinal);
CREATE INDEX ce_forum_report_status ON ce_forum_reports(status,created_at);
INSERT INTO ce_forum_categories(id,name,description,position) VALUES ('11111111-1111-4111-8111-111111111111','Сообщество','Обсуждения и новости',0);
INSERT INTO ce_forum_boards(id,category_id,name,description,position) VALUES ('22222222-2222-4222-8222-222222222222','11111111-1111-4111-8111-111111111111','Общие обсуждения','Добро пожаловать на форум!',0);
INSERT INTO ce_roles(id,handle,name,permissions) VALUES ('33333333-3333-4333-8333-333333333333','forum-member','Forum member',JSON_ARRAY('forum:write'));
INSERT INTO ce_roles(id,handle,name,permissions) VALUES ('44444444-4444-4444-8444-444444444444','forum-moderator','Forum moderator',JSON_ARRAY('forum:write','forum:moderate'));
