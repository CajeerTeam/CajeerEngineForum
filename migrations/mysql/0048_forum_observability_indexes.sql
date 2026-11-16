-- Bound operational queue probes without scanning completed history.
CREATE INDEX ce_forum_replica_state_due ON ce_forum_media_replicas(state,due_at);
CREATE INDEX ce_forum_review_status_created ON ce_forum_review_submissions(status,created_at);
CREATE INDEX ce_forum_report_status_created ON ce_forum_reports(status,created_at);
CREATE INDEX ce_forum_import_state_updated ON ce_forum_import_jobs(state,updated_at);
