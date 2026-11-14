ALTER TABLE ce_forum_notifications ADD COLUMN subscription_kind VARCHAR(8) NULL CHECK (subscription_kind IN ('thread','board'));
ALTER TABLE ce_forum_watches ADD COLUMN mode VARCHAR(16) NOT NULL DEFAULT 'internal' CHECK (mode IN ('none','internal','email','daily','weekly'));
CREATE TABLE ce_forum_board_watches (
 board_id UUID NOT NULL, user_id UUID NOT NULL,
 mode VARCHAR(16) NOT NULL CHECK (mode IN ('none','internal','email','daily','weekly')),
 PRIMARY KEY(board_id,user_id), FOREIGN KEY(board_id) REFERENCES ce_forum_boards(id) ON DELETE CASCADE,
 FOREIGN KEY(user_id) REFERENCES ce_users(id) ON DELETE CASCADE
);
CREATE INDEX ce_forum_board_watch_user ON ce_forum_board_watches(user_id,board_id);
CREATE TABLE ce_forum_watch_preferences (
 user_id UUID PRIMARY KEY, auto_mode VARCHAR(16) NOT NULL DEFAULT 'off' CHECK (auto_mode IN ('off','none','internal','email','daily','weekly')),
 FOREIGN KEY(user_id) REFERENCES ce_users(id) ON DELETE CASCADE
);
CREATE TABLE ce_forum_mail_delivery (
 id UUID PRIMARY KEY, user_id UUID NOT NULL, post_id UUID NOT NULL, board_id UUID NOT NULL,
 source_kind VARCHAR(8) NOT NULL CHECK (source_kind IN ('thread','board')),
 mode VARCHAR(16) NOT NULL CHECK (mode IN ('email','daily','weekly')),
 state VARCHAR(12) NOT NULL DEFAULT 'pending' CHECK (state IN ('pending','sent','cancelled','dead')),
 attempts INTEGER NOT NULL DEFAULT 0 CHECK (attempts>=0 AND attempts<=8), due_at BIGINT NOT NULL,
 lease_until BIGINT NOT NULL DEFAULT 0, lease_token VARCHAR(36) NULL,
 sent_at BIGINT NULL, last_error VARCHAR(120) NULL,
 CHECK ((lease_token IS NULL AND lease_until=0) OR (state='pending' AND lease_token IS NOT NULL AND lease_until>0)),
 CHECK ((state='sent' AND sent_at IS NOT NULL) OR (state<>'sent' AND sent_at IS NULL)),
 UNIQUE(user_id,post_id), FOREIGN KEY(user_id) REFERENCES ce_users(id) ON DELETE CASCADE,
 FOREIGN KEY(post_id) REFERENCES ce_forum_posts(id) ON DELETE CASCADE,
 FOREIGN KEY(board_id) REFERENCES ce_forum_boards(id) ON DELETE CASCADE
);
CREATE INDEX ce_forum_mail_due ON ce_forum_mail_delivery(state,due_at,lease_until);
CREATE INDEX ce_forum_mail_user ON ce_forum_mail_delivery(user_id,state,mode,due_at);
CREATE INDEX ce_forum_mail_post ON ce_forum_mail_delivery(post_id);
CREATE INDEX ce_forum_mail_sent ON ce_forum_mail_delivery(user_id,state,sent_at);
