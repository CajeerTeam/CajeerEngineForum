-- Polls and questions are owned by existing discussions. All mutations lock the thread.
CREATE TABLE ce_forum_polls (
 thread_id TEXT PRIMARY KEY REFERENCES ce_forum_threads(id) ON DELETE CASCADE,
 question VARCHAR(180) NOT NULL,
 max_choices INTEGER NOT NULL CHECK(max_choices BETWEEN 1 AND 20),
 allow_change INTEGER NOT NULL CHECK(allow_change IN (0,1)),
 visibility VARCHAR(16) NOT NULL CHECK(visibility IN ('always','voted','closed')),
 closes_at BIGINT NULL CHECK(closes_at>0),
 closed INTEGER NOT NULL DEFAULT 0 CHECK(closed IN (0,1))
);
CREATE TABLE ce_forum_poll_options (
 thread_id TEXT NOT NULL REFERENCES ce_forum_polls(thread_id) ON DELETE CASCADE,
 option_no INTEGER NOT NULL CHECK(option_no BETWEEN 1 AND 20),
 label VARCHAR(180) NOT NULL,
 PRIMARY KEY(thread_id,option_no)
);
CREATE TABLE ce_forum_poll_votes (
 thread_id TEXT NOT NULL,
 option_no INTEGER NOT NULL,
 user_id TEXT NOT NULL REFERENCES ce_users(id) ON DELETE CASCADE,
 PRIMARY KEY(thread_id,user_id,option_no),
 FOREIGN KEY(thread_id,option_no) REFERENCES ce_forum_poll_options(thread_id,option_no) ON DELETE CASCADE
);
CREATE INDEX ce_forum_poll_tally ON ce_forum_poll_votes(thread_id,option_no);
CREATE INDEX ce_forum_poll_user ON ce_forum_poll_votes(user_id);
CREATE TABLE ce_forum_questions (
 thread_id TEXT PRIMARY KEY REFERENCES ce_forum_threads(id) ON DELETE CASCADE,
 accepted_post_id TEXT NULL REFERENCES ce_forum_posts(id) ON DELETE SET NULL
);
CREATE TABLE ce_forum_answer_votes (
 post_id TEXT NOT NULL REFERENCES ce_forum_posts(id) ON DELETE CASCADE,
 user_id TEXT NOT NULL REFERENCES ce_users(id) ON DELETE CASCADE,
 PRIMARY KEY(post_id,user_id)
);
CREATE INDEX ce_forum_answer_user ON ce_forum_answer_votes(user_id);
-- Preserve the existing notification table and constraints. Accepted answers use the same worker/inbox.
ALTER TABLE ce_forum_notification_preferences ADD COLUMN accepted INTEGER NOT NULL DEFAULT 1 CHECK(accepted IN (0,1));
CREATE TABLE ce_forum_answer_notifications (
 event_id TEXT NOT NULL,
 recipient_id TEXT NOT NULL,
 actor_id TEXT NOT NULL,
 post_id TEXT NOT NULL,
 state INTEGER NOT NULL DEFAULT 0 CHECK(state IN (0,1)),
 is_read INTEGER NOT NULL DEFAULT 0 CHECK(is_read IN (0,1)),
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CHECK(state=1 OR is_read=0),
 CHECK(actor_id<>recipient_id),
 PRIMARY KEY(recipient_id,event_id),
 UNIQUE(post_id),
 FOREIGN KEY(recipient_id) REFERENCES ce_users(id) ON DELETE CASCADE,
 FOREIGN KEY(actor_id) REFERENCES ce_users(id) ON DELETE CASCADE,
 FOREIGN KEY(post_id) REFERENCES ce_forum_posts(id) ON DELETE CASCADE
);
CREATE INDEX ce_forum_answer_notify_pending ON ce_forum_answer_notifications(state,created_at,event_id,recipient_id);
CREATE INDEX ce_forum_answer_notify_inbox ON ce_forum_answer_notifications(recipient_id,state,is_read,created_at,event_id);
CREATE INDEX ce_forum_answer_notify_actor ON ce_forum_answer_notifications(actor_id);
