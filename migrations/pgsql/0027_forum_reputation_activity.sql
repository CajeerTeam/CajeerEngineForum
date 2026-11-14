ALTER TABLE ce_forum_reactions ADD COLUMN reaction_type VARCHAR(12) NOT NULL DEFAULT 'like' CHECK (reaction_type IN ('like','helpful','celebrate'));
CREATE TABLE ce_forum_member_relations (
 user_id UUID NOT NULL, target_id UUID NOT NULL,
 kind VARCHAR(8) NOT NULL CHECK (kind IN ('follow','ignore')),
 PRIMARY KEY(user_id,target_id,kind), CHECK(user_id<>target_id),
 FOREIGN KEY(user_id) REFERENCES ce_users(id) ON DELETE CASCADE,
 FOREIGN KEY(target_id) REFERENCES ce_users(id) ON DELETE CASCADE
);
CREATE INDEX ce_forum_relation_target ON ce_forum_member_relations(target_id,kind,user_id);
CREATE TABLE ce_forum_achievement_rules (
 code VARCHAR(24) PRIMARY KEY, threshold INTEGER NOT NULL CHECK(threshold>=1 AND threshold<=1000000),
 enabled INTEGER NOT NULL DEFAULT 1 CHECK(enabled IN (0,1))
);
INSERT INTO ce_forum_achievement_rules(code,threshold) VALUES ('first_post',1),('active_member',100),('topic_author',25),('helpful_member',50),('veteran',365);
CREATE INDEX ce_forum_activity_post ON ce_forum_posts(created_at,id);
