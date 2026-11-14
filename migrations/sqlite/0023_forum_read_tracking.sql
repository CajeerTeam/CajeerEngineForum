-- A publication sequence is independent of ordinal and never reused within a thread.
ALTER TABLE ce_forum_threads ADD COLUMN read_sequence BIGINT NOT NULL DEFAULT 0 CHECK (read_sequence >= 0);
ALTER TABLE ce_forum_posts ADD COLUMN read_sequence BIGINT NOT NULL DEFAULT 0 CHECK (read_sequence >= 0);
UPDATE ce_forum_posts SET read_sequence=ordinal;
UPDATE ce_forum_threads SET read_sequence=COALESCE((SELECT MAX(p.read_sequence) FROM ce_forum_posts p WHERE p.thread_id=ce_forum_threads.id),0);
CREATE UNIQUE INDEX ce_forum_post_read_sequence ON ce_forum_posts(thread_id,read_sequence);
CREATE TABLE ce_forum_read_state (
 user_id TEXT NOT NULL,
 thread_id TEXT NOT NULL,
 read_sequence BIGINT NOT NULL CHECK (read_sequence >= 0),
 PRIMARY KEY (user_id,thread_id),
 FOREIGN KEY (user_id) REFERENCES ce_users(id) ON DELETE CASCADE,
 FOREIGN KEY (thread_id) REFERENCES ce_forum_threads(id) ON DELETE CASCADE
);
CREATE INDEX ce_forum_read_thread ON ce_forum_read_state(thread_id);
