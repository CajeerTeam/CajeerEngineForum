ALTER TABLE ce_forum_categories ADD COLUMN visibility VARCHAR(16) NOT NULL DEFAULT 'public' CHECK (visibility IN ('public','private','hidden'));
CREATE TABLE ce_forum_category_permissions (
 node_id UUID NOT NULL REFERENCES ce_forum_categories(id) ON DELETE CASCADE,
 subject_type VARCHAR(16) NOT NULL CHECK (subject_type IN ('everyone','guest','authenticated','role','user')),
 subject_id VARCHAR(36) NOT NULL DEFAULT '',
 permission VARCHAR(32) NOT NULL CHECK (permission IN ('node:view','thread:read','thread:create','post:reply','post:edit_own','post:edit_any','reaction:use','watch:use','report:create','thread:moderate')),
 effect VARCHAR(8) NOT NULL CHECK (effect IN ('allow','deny','never')),
 PRIMARY KEY(node_id,subject_type,subject_id,permission)
);
ALTER TABLE ce_forum_boards ADD COLUMN visibility VARCHAR(16) NOT NULL DEFAULT 'public' CHECK (visibility IN ('public','private','hidden'));
CREATE TABLE ce_forum_board_permissions (
 node_id UUID NOT NULL REFERENCES ce_forum_boards(id) ON DELETE CASCADE,
 subject_type VARCHAR(16) NOT NULL CHECK (subject_type IN ('everyone','guest','authenticated','role','user')),
 subject_id VARCHAR(36) NOT NULL DEFAULT '',
 permission VARCHAR(32) NOT NULL CHECK (permission IN ('node:view','thread:read','thread:create','post:reply','post:edit_own','post:edit_any','reaction:use','watch:use','report:create','thread:moderate')),
 effect VARCHAR(8) NOT NULL CHECK (effect IN ('allow','deny','never')),
 PRIMARY KEY(node_id,subject_type,subject_id,permission)
);
