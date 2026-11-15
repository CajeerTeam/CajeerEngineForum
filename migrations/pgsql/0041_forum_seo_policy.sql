ALTER TABLE ce_forum_boards ADD COLUMN seo_index INTEGER NOT NULL DEFAULT 1 CHECK (seo_index IN (0, 1));
