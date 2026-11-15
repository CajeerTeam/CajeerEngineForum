-- Native InnoDB full-text indexes; backfill and maintenance are managed by the database.
ALTER TABLE ce_forum_threads ADD FULLTEXT INDEX ce_forum_fts_threads (title,prefix);
ALTER TABLE ce_forum_posts ADD FULLTEXT INDEX ce_forum_fts_posts (body);
ALTER TABLE ce_forum_thread_tags ADD FULLTEXT INDEX ce_forum_fts_tags (tag);
