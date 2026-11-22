-- XenForo v5 phase names exceed the legacy VARCHAR(20) import-map kind column.
-- Keep historical 0047 checksums intact while making new private-media mapping durable.
ALTER TABLE ce_forum_import_map ALTER COLUMN kind TYPE VARCHAR(32);
