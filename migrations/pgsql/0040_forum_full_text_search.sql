-- Native expression indexes are maintained by PostgreSQL in the same transaction as content.
CREATE INDEX ce_forum_fts_threads ON ce_forum_threads USING GIN ((to_tsvector('english'::regconfig,title || ' ' || prefix) || to_tsvector('russian'::regconfig,title || ' ' || prefix)));
CREATE INDEX ce_forum_fts_posts ON ce_forum_posts USING GIN ((to_tsvector('english'::regconfig,body) || to_tsvector('russian'::regconfig,body)));
CREATE INDEX ce_forum_fts_tags ON ce_forum_thread_tags USING GIN ((to_tsvector('english'::regconfig,tag) || to_tsvector('russian'::regconfig,tag)));
