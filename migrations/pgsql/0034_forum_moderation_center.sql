-- Moderation queue: unpublished submissions never enter public content tables.
ALTER TABLE ce_forum_boards ADD COLUMN review_required INTEGER NOT NULL DEFAULT 0 CHECK(review_required IN (0,1));
ALTER TABLE ce_forum_reports ADD COLUMN category VARCHAR(20) NOT NULL DEFAULT 'other' CHECK(category IN ('spam','abuse','privacy','unsafe_file','other'));
ALTER TABLE ce_forum_reports ADD COLUMN decision_kind VARCHAR(16) NULL CHECK(decision_kind IN ('resolved','dismissed','deleted'));
ALTER TABLE ce_forum_reports ADD COLUMN priority INTEGER NOT NULL DEFAULT 1 CHECK(priority BETWEEN 0 AND 2);
ALTER TABLE ce_forum_reports ADD COLUMN assigned_to UUID NULL;
ALTER TABLE ce_forum_reports ADD COLUMN revision_number INTEGER NOT NULL DEFAULT 1 CHECK(revision_number>=1);
ALTER TABLE ce_forum_reports ADD COLUMN decision VARCHAR(500) NULL;
ALTER TABLE ce_forum_reports ADD COLUMN snapshot_body TEXT NULL;
ALTER TABLE ce_forum_reports ADD COLUMN snapshot_format VARCHAR(16) NULL;
ALTER TABLE ce_forum_reports ADD COLUMN resolved_at BIGINT NULL;
CREATE TABLE ce_forum_review_submissions (
 id UUID PRIMARY KEY,
 author_id UUID NOT NULL REFERENCES ce_users(id) ON DELETE CASCADE,
 board_id UUID NOT NULL REFERENCES ce_forum_boards(id) ON DELETE CASCADE,
 thread_id UUID NULL REFERENCES ce_forum_threads(id) ON DELETE CASCADE,
 payload TEXT NOT NULL,
 status VARCHAR(16) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','approved','rejected','cancelled')),
 priority INTEGER NOT NULL DEFAULT 1 CHECK(priority BETWEEN 0 AND 2),
 assigned_to UUID NULL,
 revision_number INTEGER NOT NULL DEFAULT 1 CHECK(revision_number>=1),
 decision VARCHAR(500) NULL,
 decided_by UUID NULL,
 result_id UUID NULL,
 created_at BIGINT NOT NULL,
 updated_at BIGINT NOT NULL,
 CHECK((status='approved' AND result_id IS NOT NULL) OR (status<>'approved' AND result_id IS NULL))
);
CREATE TABLE ce_forum_review_files (
 attachment_id UUID PRIMARY KEY REFERENCES ce_forum_attachments(id) ON DELETE CASCADE,
 submission_id UUID NOT NULL REFERENCES ce_forum_review_submissions(id) ON DELETE CASCADE
);
CREATE TABLE ce_forum_moderation_events (
 id UUID PRIMARY KEY,
 kind VARCHAR(16) NOT NULL CHECK(kind IN ('report','submission')),
 case_id UUID NOT NULL,
 actor_id UUID NULL,
 revision_number INTEGER NOT NULL CHECK(revision_number>=1),
 action VARCHAR(24) NOT NULL,
 detail VARCHAR(500) NOT NULL,
 created_at BIGINT NOT NULL,
 UNIQUE(kind,case_id,revision_number)
);
CREATE INDEX ce_forum_review_queue ON ce_forum_review_submissions(status,priority,created_at,id);
CREATE INDEX ce_forum_review_author ON ce_forum_review_submissions(author_id,status,created_at);
CREATE INDEX ce_forum_review_files_case ON ce_forum_review_files(submission_id);
CREATE INDEX ce_forum_mod_events_case ON ce_forum_moderation_events(kind,case_id,revision_number);
CREATE INDEX ce_forum_reports_priority ON ce_forum_reports(status,priority,created_at,id);
