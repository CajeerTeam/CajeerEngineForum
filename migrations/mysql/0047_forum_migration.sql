-- Durable operator import ledger. No source credentials or private payloads.
CREATE TABLE ce_forum_import_jobs (
 id VARCHAR(64) PRIMARY KEY,
 source_version VARCHAR(32) NOT NULL,
 category_id VARCHAR(36) NOT NULL,
 backup_id VARCHAR(64) NOT NULL,
 plan_json TEXT NOT NULL,
 phase INTEGER NOT NULL DEFAULT 0,
 cursor_id BIGINT NOT NULL DEFAULT 0,
 subcursor_id BIGINT NOT NULL DEFAULT 0,
 state VARCHAR(16) NOT NULL DEFAULT 'running',
 created_at BIGINT NOT NULL,
 updated_at BIGINT NOT NULL
);
CREATE TABLE ce_forum_import_map (
 job_id VARCHAR(64) NOT NULL,
 kind VARCHAR(20) NOT NULL,
 source_id BIGINT NOT NULL,
 target_id VARCHAR(36) NULL,
 outcome VARCHAR(16) NOT NULL,
 reason VARCHAR(100) NOT NULL DEFAULT '',
 PRIMARY KEY(job_id,kind,source_id),
 FOREIGN KEY(job_id) REFERENCES ce_forum_import_jobs(id) ON DELETE CASCADE
);
