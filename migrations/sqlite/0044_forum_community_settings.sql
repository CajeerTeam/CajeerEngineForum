CREATE TABLE ce_forum_community_settings (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    revision BIGINT NOT NULL CHECK (revision >= 0),
    payload TEXT NOT NULL
);
INSERT INTO ce_forum_community_settings (id, revision, payload) VALUES (1, 0, '{}');
INSERT INTO ce_roles(id,handle,name,permissions) VALUES ('a520d359-1ff2-423a-84f0-dbc796d96316','forum-acp-settings','Forum ACP settings','["forum:acp:settings"]');
INSERT INTO ce_roles(id,handle,name,permissions) VALUES ('7e79234b-a661-4c49-bba0-d60db086b59a','forum-acp-members','Forum ACP members','["forum:acp:members"]');
INSERT INTO ce_roles(id,handle,name,permissions) VALUES ('0a1fa187-a61f-4c97-add9-8d4d1cf2113c','forum-acp-audit','Forum ACP audit','["forum:acp:audit"]');
INSERT INTO ce_roles(id,handle,name,permissions) VALUES ('26d1387a-cb90-46ca-97ea-57bd0dfa36c4','forum-acp-maintenance','Forum ACP maintenance','["forum:acp:maintenance"]');
