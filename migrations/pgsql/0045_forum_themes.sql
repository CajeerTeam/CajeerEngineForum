CREATE TABLE ce_forum_theme_settings (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    revision BIGINT NOT NULL CHECK (revision >= 0),
    payload TEXT NOT NULL,
    active_payload TEXT NOT NULL
);
INSERT INTO ce_forum_theme_settings (id, revision, payload, active_payload) VALUES (1, 0, '{}', '{}');
