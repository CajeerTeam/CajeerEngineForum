-- Forum Edition 1.3.5: member-owned AI support, operator queue and curated FAQ.
CREATE TABLE ce_forum_support_tickets (
    id VARCHAR(36) PRIMARY KEY,
    user_id UUID NOT NULL REFERENCES ce_users(id) ON DELETE CASCADE,
    subject VARCHAR(160) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'open',
    needs_human INTEGER NOT NULL DEFAULT 1,
    assigned_to UUID NULL REFERENCES ce_users(id) ON DELETE SET NULL,
    revision BIGINT NOT NULL DEFAULT 1,
    created_at BIGINT NOT NULL,
    updated_at BIGINT NOT NULL
);
CREATE INDEX ce_forum_support_owner ON ce_forum_support_tickets(user_id,updated_at,id);
CREATE INDEX ce_forum_support_queue ON ce_forum_support_tickets(status,needs_human,updated_at,id);
CREATE TABLE ce_forum_support_messages (
    id VARCHAR(36) PRIMARY KEY,
    ticket_id VARCHAR(36) NOT NULL REFERENCES ce_forum_support_tickets(id) ON DELETE CASCADE,
    role VARCHAR(16) NOT NULL,
    author_id UUID NULL REFERENCES ce_users(id) ON DELETE SET NULL,
    body TEXT NOT NULL,
    sources_json TEXT NULL,
    created_at BIGINT NOT NULL
);
CREATE INDEX ce_forum_support_messages_order ON ce_forum_support_messages(ticket_id,created_at,id);
CREATE TABLE ce_forum_support_faq (
    id VARCHAR(36) PRIMARY KEY,
    question VARCHAR(240) NOT NULL,
    answer TEXT NOT NULL,
    published INTEGER NOT NULL DEFAULT 0,
    sort_order INTEGER NOT NULL DEFAULT 100,
    revision BIGINT NOT NULL DEFAULT 1,
    updated_at BIGINT NOT NULL
);
CREATE INDEX ce_forum_support_faq_order ON ce_forum_support_faq(published,sort_order,updated_at);
