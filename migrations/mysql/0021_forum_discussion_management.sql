ALTER TABLE ce_forum_boards ADD COLUMN rules VARCHAR(4000) NOT NULL DEFAULT '';
ALTER TABLE ce_forum_threads ADD COLUMN prefix VARCHAR(60) NOT NULL DEFAULT '';
CREATE TABLE ce_forum_thread_tags (
 thread_id CHAR(36) NOT NULL,
 tag VARCHAR(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
 FOREIGN KEY (thread_id) REFERENCES ce_forum_threads(id) ON DELETE CASCADE,
 PRIMARY KEY(thread_id,tag)
);
CREATE INDEX ce_forum_tag_lookup ON ce_forum_thread_tags(tag,thread_id);
CREATE TABLE ce_forum_thread_redirects (
 source_id CHAR(36) PRIMARY KEY,
 source_board_id CHAR(36) NOT NULL,
 target_id CHAR(36) NOT NULL,
 FOREIGN KEY (source_board_id) REFERENCES ce_forum_boards(id) ON DELETE CASCADE,
 FOREIGN KEY (target_id) REFERENCES ce_forum_threads(id) ON DELETE CASCADE
);
CREATE INDEX ce_forum_redirect_target ON ce_forum_thread_redirects(target_id);
