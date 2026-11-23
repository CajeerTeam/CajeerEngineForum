-- Forum Edition 1.3.5: member-owned AI support, operator queue and curated FAQ.
CREATE TABLE ce_forum_support_tickets (
    id VARCHAR(36) PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    subject VARCHAR(160) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'open',
    needs_human INTEGER NOT NULL DEFAULT 1,
    assigned_to CHAR(36) NULL,
    revision BIGINT NOT NULL DEFAULT 1,
    created_at BIGINT NOT NULL,
    updated_at BIGINT NOT NULL,
    CONSTRAINT fk_forum_support_owner FOREIGN KEY (user_id) REFERENCES ce_users(id) ON DELETE CASCADE,
    CONSTRAINT fk_forum_support_assignee FOREIGN KEY (assigned_to) REFERENCES ce_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX ce_forum_support_owner ON ce_forum_support_tickets(user_id,updated_at,id);
CREATE INDEX ce_forum_support_queue ON ce_forum_support_tickets(status,needs_human,updated_at,id);
CREATE TABLE ce_forum_support_messages (
    id VARCHAR(36) PRIMARY KEY,
    ticket_id VARCHAR(36) NOT NULL,
    role VARCHAR(16) NOT NULL,
    author_id CHAR(36) NULL,
    body MEDIUMTEXT NOT NULL,
    sources_json MEDIUMTEXT NULL,
    created_at BIGINT NOT NULL,
    CONSTRAINT fk_forum_support_ticket FOREIGN KEY (ticket_id) REFERENCES ce_forum_support_tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_forum_support_message_author FOREIGN KEY (author_id) REFERENCES ce_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX ce_forum_support_messages_order ON ce_forum_support_messages(ticket_id,created_at,id);
CREATE TABLE ce_forum_support_faq (
    id VARCHAR(36) PRIMARY KEY,
    question VARCHAR(240) NOT NULL,
    answer MEDIUMTEXT NOT NULL,
    published INTEGER NOT NULL DEFAULT 0,
    sort_order INTEGER NOT NULL DEFAULT 100,
    revision BIGINT NOT NULL DEFAULT 1,
    updated_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX ce_forum_support_faq_order ON ce_forum_support_faq(published,sort_order,updated_at);
