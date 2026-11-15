-- Short-lived, transactional abuse counters; no message bodies or raw IPs.
CREATE TABLE ce_forum_spam_events (
 id UUID PRIMARY KEY,
 user_id UUID NULL,
 subject VARCHAR(64) NOT NULL,
 action VARCHAR(24) NOT NULL,
 created_at BIGINT NOT NULL,
 amount BIGINT NOT NULL CHECK(amount>0),
 fingerprint VARCHAR(64) NULL,
 reason VARCHAR(100) NULL,
 FOREIGN KEY(user_id) REFERENCES ce_users(id) ON DELETE CASCADE
);
CREATE INDEX ce_forum_spam_subject ON ce_forum_spam_events(subject,action,created_at);
CREATE INDEX ce_forum_spam_action_time ON ce_forum_spam_events(action,created_at);
CREATE INDEX ce_forum_spam_expiry ON ce_forum_spam_events(created_at);
CREATE TABLE ce_forum_spam_lock (id INTEGER PRIMARY KEY);
INSERT INTO ce_forum_spam_lock(id) VALUES(1);
