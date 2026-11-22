-- First-party event ledger with strictly anonymous records; retention 90 days.
CREATE TABLE ce_forum_analytics_events (
    id VARCHAR(32) PRIMARY KEY,
    event_name VARCHAR(40) NOT NULL,
    created_at BIGINT NOT NULL
);
CREATE INDEX ce_forum_analytics_event_time_idx ON ce_forum_analytics_events(created_at,id);
CREATE INDEX ce_forum_analytics_event_type_idx ON ce_forum_analytics_events(event_name,created_at);
