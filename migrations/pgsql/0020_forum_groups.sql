CREATE TABLE ce_forum_groups (
 role_id UUID PRIMARY KEY,
 description VARCHAR(500) NOT NULL DEFAULT '',
 FOREIGN KEY (role_id) REFERENCES ce_roles(id) ON DELETE CASCADE
);
CREATE TABLE ce_forum_primary_groups (
 user_id UUID PRIMARY KEY,
 role_id UUID NOT NULL,
 FOREIGN KEY (user_id,role_id) REFERENCES ce_user_roles(user_id,role_id) ON DELETE CASCADE,
 FOREIGN KEY (role_id) REFERENCES ce_forum_groups(role_id) ON DELETE CASCADE
);
INSERT INTO ce_forum_groups(role_id,description) SELECT id,'' FROM ce_roles WHERE handle IN ('forum-member','forum-moderator');
INSERT INTO ce_forum_primary_groups(user_id,role_id)
 SELECT ur.user_id,ur.role_id FROM ce_user_roles ur JOIN ce_roles r ON r.id=ur.role_id
 WHERE r.handle='forum-member' OR (r.handle='forum-moderator' AND NOT EXISTS (
 SELECT 1 FROM ce_user_roles other JOIN ce_roles rr ON rr.id=other.role_id WHERE other.user_id=ur.user_id AND rr.handle='forum-member'));
CREATE INDEX ce_forum_group_members ON ce_user_roles(role_id,user_id);
