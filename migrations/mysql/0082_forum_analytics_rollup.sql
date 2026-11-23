-- UTC daily first-party rollups; no visitor, URL or account information.
-- Worker consumes unapplied rows atomically; two independent retention windows.
ALTER TABLE ce_forum_analytics_events ADD COLUMN aggregated_at BIGINT NULL;
CREATE INDEX ce_forum_analytics_pending_idx ON ce_forum_analytics_events(aggregated_at,created_at,id);
CREATE TABLE ce_forum_analytics_daily (
    day BIGINT NOT NULL,
    event_name VARCHAR(40) NOT NULL,
    total BIGINT NOT NULL CHECK (total >= 0),
    updated_at BIGINT NOT NULL,
    PRIMARY KEY (day,event_name)
);
CREATE TABLE ce_forum_analytics_state (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    lock_version BIGINT NOT NULL DEFAULT 0,
    last_aggregated_at BIGINT NOT NULL DEFAULT 0,
    last_recalculated_at BIGINT NOT NULL DEFAULT 0
);
INSERT INTO ce_forum_analytics_state(id,lock_version,last_aggregated_at,last_recalculated_at) VALUES (1,0,0,0);
