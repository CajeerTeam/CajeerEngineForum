-- Preserve the legacy incoming preference and its CHECK constraint.
ALTER TABLE ce_forum_conversation_preferences ADD COLUMN contact_mode VARCHAR(9) NOT NULL DEFAULT 'members' CHECK(contact_mode IN ('members','trusted','following'));
-- Existing blocks must also remove historical follow edges. No relationship is restored on unblock.
DELETE FROM ce_forum_member_relations WHERE kind='follow' AND EXISTS (
    SELECT 1 FROM ce_forum_user_blocks b
    WHERE (b.user_id=ce_forum_member_relations.user_id AND b.blocked_id=ce_forum_member_relations.target_id)
       OR (b.blocked_id=ce_forum_member_relations.user_id AND b.user_id=ce_forum_member_relations.target_id)
);
