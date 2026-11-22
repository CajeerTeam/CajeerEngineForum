-- XenForo conversation_attachments is longer than the legacy 20-character kind column.
-- DDL is intentionally isolated for MySQL's implicit-commit migration discipline.
ALTER TABLE ce_forum_import_map MODIFY COLUMN kind VARCHAR(32) NOT NULL;
