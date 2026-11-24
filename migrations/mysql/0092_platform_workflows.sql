-- Forum 1.4.4: durable, bounded administrative workflows.
CREATE TABLE ce_platform_workflow_lock (id INTEGER PRIMARY KEY, revision BIGINT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO ce_platform_workflow_lock(id,revision) VALUES(1,0);
CREATE TABLE ce_platform_workflows (
 id VARCHAR(36) PRIMARY KEY, name VARCHAR(120) NOT NULL, owner_id VARCHAR(36) NOT NULL,
 trigger_name VARCHAR(32) NOT NULL, action_name VARCHAR(32) NOT NULL, operator_id VARCHAR(36) NULL,
 condition_value INTEGER NOT NULL, interval_seconds INTEGER NOT NULL, enabled INTEGER NOT NULL,
 revision BIGINT NOT NULL, next_due BIGINT NOT NULL, updated_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX ce_platform_workflow_due ON ce_platform_workflows(enabled,trigger_name,next_due);
CREATE TABLE ce_platform_workflow_events (
 id VARCHAR(36) PRIMARY KEY, rule_id VARCHAR(36) NOT NULL, rule_revision BIGINT NOT NULL,
 ticket_revision BIGINT NOT NULL, ticket_id VARCHAR(36) NOT NULL, created_at BIGINT NOT NULL,
 CONSTRAINT fk_platform_workflow_ticket FOREIGN KEY(ticket_id) REFERENCES ce_forum_support_tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE UNIQUE INDEX ce_platform_workflow_event_dedupe ON ce_platform_workflow_events(rule_id,rule_revision,ticket_id,ticket_revision);
CREATE INDEX ce_platform_workflow_event_due ON ce_platform_workflow_events(created_at,id);
CREATE TABLE ce_platform_workflow_runs (
 id VARCHAR(36) PRIMARY KEY, rule_id VARCHAR(36) NOT NULL, rule_revision BIGINT NOT NULL,
 trigger_name VARCHAR(32) NOT NULL, action_name VARCHAR(32) NOT NULL, status VARCHAR(20) NOT NULL,
 affected INTEGER NOT NULL, created_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX ce_platform_workflow_run_time ON ce_platform_workflow_runs(created_at,id);
CREATE INDEX ce_forum_support_unassigned ON ce_forum_support_tickets(status,needs_human,assigned_to,updated_at,id);
