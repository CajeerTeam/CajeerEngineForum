-- Forum Edition 1.3.7: persisted, source-bound, human-only AI moderation advice.
-- No raw reported content is retained in this table; content_hash invalidates stale advice.
CREATE TABLE ce_forum_ai_moderation_reviews (
 kind VARCHAR(16) NOT NULL,
 case_id VARCHAR(36) NOT NULL,
 revision_number BIGINT NOT NULL,
 source_hash CHAR(64) NOT NULL,
 assessment_json TEXT NOT NULL,
 model VARCHAR(80) NOT NULL,
 reviewed_by VARCHAR(36) NOT NULL,
 created_at BIGINT NOT NULL,
 PRIMARY KEY(kind,case_id)
);
CREATE INDEX ce_forum_ai_moderation_retention ON ce_forum_ai_moderation_reviews(created_at);
