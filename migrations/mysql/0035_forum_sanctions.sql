-- Durable warnings and sanctions; active state is evaluated at request time.
CREATE TABLE ce_forum_sanctions (
 id CHAR(36) PRIMARY KEY,
 user_id CHAR(36) NOT NULL,
 issuer_id CHAR(36) NULL,
 board_id CHAR(36) NULL,
 source_warning_id CHAR(36) NULL UNIQUE,
 kind VARCHAR(24) NOT NULL CHECK(kind IN ('warning','posting','conversation','upload','reaction','read_only','suspension')),
 reason VARCHAR(500) NOT NULL,
 points INTEGER NOT NULL DEFAULT 0 CHECK(points BETWEEN 0 AND 100),
 starts_at BIGINT NOT NULL,
 ends_at BIGINT NULL,
 revoked_at BIGINT NULL,
 revoked_by CHAR(36) NULL,
 revoke_reason VARCHAR(500) NULL,
 FOREIGN KEY(user_id) REFERENCES ce_users(id) ON DELETE CASCADE,
 FOREIGN KEY(issuer_id) REFERENCES ce_users(id) ON DELETE SET NULL,
 FOREIGN KEY(board_id) REFERENCES ce_forum_boards(id) ON DELETE CASCADE,
 FOREIGN KEY(source_warning_id) REFERENCES ce_forum_sanctions(id) ON DELETE SET NULL,
 FOREIGN KEY(revoked_by) REFERENCES ce_users(id) ON DELETE SET NULL,
 CHECK(ends_at IS NULL OR ends_at>starts_at),
 CHECK((kind='warning' AND points>0) OR (kind<>'warning' AND points=0)),
 CHECK(board_id IS NULL OR kind IN ('warning','posting','reaction'))
);
CREATE INDEX ce_forum_sanctions_user ON ce_forum_sanctions(user_id,kind,revoked_at,ends_at);
CREATE INDEX ce_forum_sanctions_board ON ce_forum_sanctions(board_id,starts_at);
CREATE TABLE ce_forum_appeals (
 id CHAR(36) PRIMARY KEY,
 sanction_id CHAR(36) NOT NULL UNIQUE,
 body VARCHAR(2000) NOT NULL,
 status VARCHAR(16) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','accepted','rejected')),
 created_at BIGINT NOT NULL,
 decided_at BIGINT NULL,
 decided_by CHAR(36) NULL,
 decision VARCHAR(500) NULL,
 FOREIGN KEY(sanction_id) REFERENCES ce_forum_sanctions(id) ON DELETE CASCADE,
 FOREIGN KEY(decided_by) REFERENCES ce_users(id) ON DELETE SET NULL,
 CHECK((status='pending' AND decided_at IS NULL) OR (status<>'pending' AND decided_at IS NOT NULL))
);
CREATE INDEX ce_forum_appeals_queue ON ce_forum_appeals(status,created_at);
