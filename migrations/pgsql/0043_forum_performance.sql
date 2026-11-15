-- Live forum feed order and bounded post ranges. Existing unique constraints are retained.
CREATE INDEX ce_forum_live_latest_idx ON ce_forum_threads(updated_at DESC,id ASC) WHERE deleted_at IS NULL;
CREATE INDEX ce_forum_live_board_idx ON ce_forum_threads(board_id,pinned DESC,updated_at DESC,id ASC) WHERE deleted_at IS NULL;
CREATE INDEX ce_forum_live_author_idx ON ce_forum_threads(author_id,updated_at DESC,id ASC) WHERE deleted_at IS NULL;
CREATE INDEX ce_forum_live_ordinal_idx ON ce_forum_posts(thread_id,ordinal) WHERE deleted_at IS NULL;
CREATE INDEX ce_forum_live_sequence_idx ON ce_forum_posts(thread_id,read_sequence) WHERE deleted_at IS NULL;

-- Split the global write lock into 16 transaction-selected segments per table.
-- A digest of ALL segments is read under the same snapshot as the cached data.
CREATE TABLE ce_forum_cache_generation_shards (
 name VARCHAR(80) NOT NULL REFERENCES ce_forum_cache_generation(name) ON DELETE CASCADE,
 shard SMALLINT NOT NULL CHECK (shard BETWEEN 0 AND 15),
 generation VARCHAR(32) NOT NULL,
 PRIMARY KEY(name,shard)
);
INSERT INTO ce_forum_cache_generation_shards(name,shard,generation)
SELECT name,s,md5(random()::text || clock_timestamp()::text)
FROM ce_forum_cache_generation CROSS JOIN generate_series(0,15) AS s;
CREATE VIEW ce_forum_cache_generation_effective AS
SELECT g.name,g.scope,md5(g.generation || string_agg(s.generation,'' ORDER BY s.shard)) AS generation
FROM ce_forum_cache_generation g JOIN ce_forum_cache_generation_shards s ON s.name=g.name
GROUP BY g.name,g.scope,g.generation
HAVING COUNT(*)=16;
CREATE OR REPLACE FUNCTION ce_forum_cache_bump() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 UPDATE ce_forum_cache_generation_shards
 SET generation=md5(random()::text || clock_timestamp()::text)
 WHERE name=TG_TABLE_NAME AND shard=mod(txid_current(),16);
 IF NOT FOUND THEN RAISE EXCEPTION 'Missing forum cache generation shard for %', TG_TABLE_NAME; END IF;
 RETURN NULL;
END;
$$;
