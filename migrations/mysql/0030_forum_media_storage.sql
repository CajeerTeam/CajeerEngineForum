-- Derivatives are private and transactional; original bytes remain authoritative.
CREATE TABLE ce_forum_media_state (
 attachment_id CHAR(36) PRIMARY KEY,
 state VARCHAR(12) NOT NULL CHECK(state IN ('pending','ready','failed')),
 attempts INTEGER NOT NULL DEFAULT 0 CHECK(attempts>=0),
 due_at BIGINT NOT NULL DEFAULT 0,
 lease_token CHAR(64) NULL,
 CHECK(lease_token IS NULL OR state='pending'),
 FOREIGN KEY(attachment_id) REFERENCES ce_forum_attachments(id) ON DELETE CASCADE
);
CREATE INDEX ce_forum_media_due ON ce_forum_media_state(state,due_at);
CREATE TABLE ce_forum_media_variants (
 attachment_id CHAR(36) NOT NULL,
 variant VARCHAR(16) NOT NULL CHECK(variant IN ('thumbnail','medium','large')),
 mime VARCHAR(100) NOT NULL CHECK(mime IN ('image/jpeg','image/png')),
 width INTEGER NOT NULL CHECK(width BETWEEN 1 AND 1600),
 height INTEGER NOT NULL CHECK(height BETWEEN 1 AND 1600),
 size_bytes BIGINT NOT NULL CHECK(size_bytes BETWEEN 1 AND 5242880),
 sha256 CHAR(64) NOT NULL,
 file_data LONGTEXT NOT NULL,
 PRIMARY KEY(attachment_id,variant),
 FOREIGN KEY(attachment_id) REFERENCES ce_forum_attachments(id) ON DELETE CASCADE
);
-- Deliberately no attachment FK: retain a durable tombstone until remote deletion succeeds.
CREATE TABLE ce_forum_media_replicas (
 attachment_id CHAR(36) PRIMARY KEY,
 destination CHAR(64) NOT NULL,
 object_key VARCHAR(240) NOT NULL,
 state VARCHAR(12) NOT NULL CHECK(state IN ('pending','ready')),
 attempts INTEGER NOT NULL DEFAULT 0 CHECK(attempts>=0),
 due_at BIGINT NOT NULL DEFAULT 0
);
CREATE INDEX ce_forum_replica_due ON ce_forum_media_replicas(destination,due_at);
INSERT INTO ce_forum_media_state(attachment_id,state,due_at)
 SELECT id,'pending',0 FROM ce_forum_attachments WHERE mime IN ('image/jpeg','image/png','image/webp');
