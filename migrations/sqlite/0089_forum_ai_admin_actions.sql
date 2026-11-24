-- Forum Edition 1.3.9: short-lived, actor-bound human-approved AI changes.
-- Approval and target mutation are committed with audit in one transaction.
CREATE TABLE ce_forum_ai_admin_proposals (
 id VARCHAR(36) NOT NULL PRIMARY KEY,
 user_id VARCHAR(36) NOT NULL REFERENCES ce_users(id) ON DELETE CASCADE,
 action_name VARCHAR(32) NOT NULL,
 payload_json TEXT NOT NULL,
 before_json TEXT NOT NULL,
 expected_revision BIGINT NULL,
 reason TEXT NOT NULL,
 model VARCHAR(80) NOT NULL,
 state VARCHAR(16) NOT NULL,
 created_at BIGINT NOT NULL,
 expires_at BIGINT NOT NULL,
 decided_at BIGINT NULL,
 result_json TEXT NULL
);
CREATE INDEX ce_forum_ai_admin_user ON ce_forum_ai_admin_proposals(user_id,created_at);
CREATE INDEX ce_forum_ai_admin_pending ON ce_forum_ai_admin_proposals(state,expires_at);
