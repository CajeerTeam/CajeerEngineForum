-- Preserve original rows and every dependent object until explicit purge.
ALTER TABLE ce_forum_threads ADD COLUMN deleted_at BIGINT NULL;
ALTER TABLE ce_forum_threads ADD COLUMN deletion_id VARCHAR(36) NULL;
ALTER TABLE ce_forum_threads ADD COLUMN deleted_by VARCHAR(36) NULL;
ALTER TABLE ce_forum_threads ADD COLUMN delete_reason VARCHAR(500) NULL;
ALTER TABLE ce_forum_posts ADD COLUMN deleted_at BIGINT NULL;
ALTER TABLE ce_forum_posts ADD COLUMN deletion_id VARCHAR(36) NULL;
ALTER TABLE ce_forum_posts ADD COLUMN deleted_by VARCHAR(36) NULL;
ALTER TABLE ce_forum_posts ADD COLUMN delete_reason VARCHAR(500) NULL;
CREATE INDEX ce_forum_thread_trash ON ce_forum_threads(deleted_at,id);
CREATE INDEX ce_forum_post_trash ON ce_forum_posts(deleted_at,id);
CREATE VIEW ce_forum_live_threads AS SELECT t.* FROM ce_forum_threads t WHERE t.deleted_at IS NULL;
CREATE VIEW ce_forum_live_posts AS SELECT p.* FROM ce_forum_posts p JOIN ce_forum_threads t ON t.id=p.thread_id WHERE p.deleted_at IS NULL AND t.deleted_at IS NULL;
