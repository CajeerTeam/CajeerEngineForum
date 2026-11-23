-- 1.2.10: support bounded member activity and registration cohort reads.
-- No identity events or tracking cookies are stored by this migration.
CREATE INDEX ce_forum_posts_activity_idx ON ce_forum_posts(created_at,author_id,ordinal);
CREATE INDEX ce_forum_members_cohort_idx ON ce_users(created_at,id);
