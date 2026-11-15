-- Live forum feed order and bounded post ranges. Existing unique constraints are retained.
CREATE INDEX ce_forum_live_latest_idx ON ce_forum_threads(deleted_at,updated_at DESC,id ASC);
CREATE INDEX ce_forum_live_board_idx ON ce_forum_threads(deleted_at,board_id,pinned DESC,updated_at DESC,id ASC);
CREATE INDEX ce_forum_live_author_idx ON ce_forum_threads(deleted_at,author_id,updated_at DESC,id ASC);
CREATE INDEX ce_forum_live_ordinal_idx ON ce_forum_posts(deleted_at,thread_id,ordinal);
CREATE INDEX ce_forum_live_sequence_idx ON ce_forum_posts(deleted_at,thread_id,read_sequence);
CREATE VIEW ce_forum_cache_generation_effective AS SELECT name,scope,generation FROM ce_forum_cache_generation;
