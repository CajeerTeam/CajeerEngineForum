-- Accelerate imported-user ownership and recipient lookups during reconciliation.
-- Safe for in-progress and completed import jobs; no checkpoint or ledger rewrite.
CREATE INDEX ce_forum_import_map_target_lookup ON ce_forum_import_map(job_id,kind,outcome,target_id);
