CREATE TABLE ce_forum_extension_events (
    id VARCHAR(32) PRIMARY KEY,
    event_name VARCHAR(80) NOT NULL,
    payload TEXT NOT NULL,
    created_at BIGINT NOT NULL,
    due_at BIGINT NOT NULL,
    state VARCHAR(12) NOT NULL DEFAULT 'pending',
    targets TEXT NOT NULL,
    progress TEXT NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    lease_until BIGINT NOT NULL DEFAULT 0,
    lease_token VARCHAR(32) NULL,
    last_error VARCHAR(255) NULL
);
CREATE INDEX ce_forum_ext_event_due_idx ON ce_forum_extension_events (state,due_at,lease_until,id);
CREATE INDEX ce_forum_ext_event_gc_idx ON ce_forum_extension_events (state,created_at,id);
