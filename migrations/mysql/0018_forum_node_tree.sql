-- Preserve existing node and discussion IDs; existing boards remain open forums.
ALTER TABLE ce_forum_boards ADD COLUMN parent_id CHAR(36) NULL;
ALTER TABLE ce_forum_boards ADD CONSTRAINT ce_forum_board_parent_fk FOREIGN KEY(parent_id) REFERENCES ce_forum_boards(id) ON DELETE RESTRICT;
ALTER TABLE ce_forum_boards ADD COLUMN node_type VARCHAR(16) NOT NULL DEFAULT 'forum' CHECK (node_type IN ('forum','container'));
ALTER TABLE ce_forum_boards ADD COLUMN state VARCHAR(16) NOT NULL DEFAULT 'open' CHECK (state IN ('open','readonly','archived'));
CREATE INDEX ce_forum_board_parent ON ce_forum_boards(category_id,parent_id,position,id);
CREATE TABLE ce_forum_tree_lock (id INTEGER PRIMARY KEY CHECK (id=1));
INSERT INTO ce_forum_tree_lock(id) VALUES(1);
