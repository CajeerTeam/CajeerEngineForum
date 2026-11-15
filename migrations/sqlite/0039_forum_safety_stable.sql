-- Link a report decision to the sanction issued in the same transaction.
ALTER TABLE ce_forum_reports ADD COLUMN sanction_id TEXT NULL REFERENCES ce_forum_sanctions(id) ON DELETE SET NULL;
ALTER TABLE ce_forum_conversation_reports ADD COLUMN sanction_id TEXT NULL REFERENCES ce_forum_sanctions(id) ON DELETE SET NULL;
ALTER TABLE ce_forum_conversation_reports ADD COLUMN resolved_at BIGINT NULL;
ALTER TABLE ce_forum_conversation_reports ADD COLUMN decision VARCHAR(500) NULL;
CREATE INDEX ce_forum_conv_report_user ON ce_forum_conversation_reports(user_id,created_at,id);
