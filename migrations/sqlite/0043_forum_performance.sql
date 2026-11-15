-- Live forum feed order and bounded post ranges. Existing unique constraints are retained.
CREATE INDEX ce_forum_live_latest_idx ON ce_forum_threads(updated_at DESC,id ASC) WHERE deleted_at IS NULL;
CREATE INDEX ce_forum_live_board_idx ON ce_forum_threads(board_id,pinned DESC,updated_at DESC,id ASC) WHERE deleted_at IS NULL;
CREATE INDEX ce_forum_live_author_idx ON ce_forum_threads(author_id,updated_at DESC,id ASC) WHERE deleted_at IS NULL;
CREATE INDEX ce_forum_live_ordinal_idx ON ce_forum_posts(thread_id,ordinal) WHERE deleted_at IS NULL;
CREATE INDEX ce_forum_live_sequence_idx ON ce_forum_posts(thread_id,read_sequence) WHERE deleted_at IS NULL;
CREATE VIEW ce_forum_cache_generation_effective AS SELECT name,scope,generation FROM ce_forum_cache_generation;
