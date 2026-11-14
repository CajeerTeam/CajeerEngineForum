-- Transactional notification outbox and inbox share a durable row per recipient.
CREATE TABLE ce_forum_notifications (
 event_id CHAR(36) NOT NULL,
 recipient_id CHAR(36) NOT NULL,
 actor_id CHAR(36) NOT NULL,
 post_id CHAR(36) NOT NULL,
 kind VARCHAR(16) NOT NULL CHECK (kind IN ('reply','mention','quote','reaction','moderation')),
 moderation_action VARCHAR(16) NULL CHECK (moderation_action IN ('lock','unlock','pin','unpin')),
 state INTEGER NOT NULL DEFAULT 0 CHECK (state IN (0,1)),
 is_read INTEGER NOT NULL DEFAULT 0 CHECK (is_read IN (0,1)),
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (recipient_id) REFERENCES ce_users(id) ON DELETE CASCADE,
 FOREIGN KEY (actor_id) REFERENCES ce_users(id) ON DELETE CASCADE,
 FOREIGN KEY (post_id) REFERENCES ce_forum_posts(id) ON DELETE CASCADE,
 CHECK ((kind='moderation' AND moderation_action IS NOT NULL) OR (kind<>'moderation' AND moderation_action IS NULL)),
 CHECK (state=1 OR is_read=0),
 PRIMARY KEY (recipient_id,event_id)
);
CREATE INDEX ce_forum_notify_pending ON ce_forum_notifications(state,created_at,event_id,recipient_id);
CREATE INDEX ce_forum_notify_inbox ON ce_forum_notifications(recipient_id,state,is_read,created_at,event_id);
CREATE INDEX ce_forum_notify_page ON ce_forum_notifications(recipient_id,state,created_at,event_id);
CREATE INDEX ce_forum_notify_post ON ce_forum_notifications(post_id,kind,actor_id);
CREATE INDEX ce_forum_notify_actor ON ce_forum_notifications(actor_id);
CREATE TABLE ce_forum_notification_preferences (
 user_id CHAR(36) PRIMARY KEY,
 reply INTEGER NOT NULL DEFAULT 1 CHECK (reply IN (0,1)),
 mention INTEGER NOT NULL DEFAULT 1 CHECK (mention IN (0,1)),
 quote INTEGER NOT NULL DEFAULT 1 CHECK (quote IN (0,1)),
 reaction INTEGER NOT NULL DEFAULT 1 CHECK (reaction IN (0,1)),
 moderation INTEGER NOT NULL DEFAULT 1 CHECK (moderation IN (0,1)),
 FOREIGN KEY (user_id) REFERENCES ce_users(id) ON DELETE CASCADE
);
