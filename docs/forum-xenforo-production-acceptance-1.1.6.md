# XenForo Production Acceptance — Forum Edition 1.1.6

This release adds **executable** reconciliation, not a claim that a third-party production database has been certified. A real, complete XenForo 2.2.x / 2.3.x snapshot and a separate staging CajeerEngine Forum database are required to execute this gate. The source is read-only; the command does **not** publish any forum or activate imported accounts.

## Mandatory upgrade and source preparation

1. Back up the target database and storage. Apply `php bin/cajeer migrate` **before** starting or resuming a XenForo v5 import. Migration `0079_forum_xenforo_media_kind_width.sql` expands `ce_forum_import_map.kind` to 32 characters on PostgreSQL/MySQL; its SQLite counterpart preserves numbering because SQLite doesn't enforce VARCHAR length. The previous 20-character schema could not store `conversation_messages` / `conversation_attachments` on PostgreSQL/MySQL. The importer now checks that the database was upgraded.
2. Freeze and securely stage a complete, internally consistent, **real** XenForo 2.2 or 2.3 database, `internal_data/attachments`, and `data/avatars`. Do not reuse a snapshot name for changed source data. Keep the exact `FORUM_IMPORT_SOURCE_DSN`, prefix, `FORUM_IMPORT_SOURCE_ORIGIN` and local paths between import and acceptance. Put snapshots outside public document roots. Use a read-only DB account for the source.
3. Under maintenance with workers stopped and an exclusive operation lease, import using `forum:import:plan` and `forum:import:run` as described in `docs/forum-migration.md`. Record the resulting 64-character job ID and keep the verified recovery backup. The job must be `complete` at phase 20 before acceptance.

## Read-only reconciliation (actual source and target)

```bash
# Database credentials belong in environment/secret storage, not shell arguments.
export FORUM_IMPORT_SOURCE_DSN='mysql:host=127.0.0.1;dbname=xenforo_snapshot;charset=utf8mb4'
export FORUM_IMPORT_SOURCE_USER='readonly_import'
# Set FORUM_IMPORT_SOURCE_PASSWORD securely by the deployment environment.
export FORUM_IMPORT_SOURCE_ORIGIN='https://old-community.example'
php bin/cajeer forum:import:accept \
  --platform=xenforo --source-version=2.3.0 --snapshot=xf-frozen-01 \
  --prefix=xf_ --files=/srv/xf/internal_data/attachments \
  --avatar-files=/srv/xf/data/avatars --job=YOUR_JOB_SHA256 --json
```

The CLI requires isolated maintenance mode and independently verifies the import recovery set (authenticated manifest, hashes and database backup identity) before issuing a result. The command performs **full keyset-scanned reconciliation** across the 19 mapped phases (v5 has 20 phases total including `finalize`). It validates that every original source row has a map, the expected per-phase row counts match, imported target rows exist, imported users remain disabled, imported groups have empty permissions, boards and threads remain quarantined, and private recipients exactly match active XenForo recipients. It checks follow and reaction relations, baseline message revisions, both source and stored attachment SHA-256, and public/private attachment bindings. No original messages, credentials or raw files are written to the acceptance report.

**Skipped source data is not silently approved.** The first run returns `ok: false` with `skipped_rows_not_approved` and `checks.skip_sha256` if any source items were excluded. Review all skip reasons and counts via `forum:import:status --job=... --json`, then, only if acceptable, rerun the same acceptance command with `--expect-skips-sha256=THE_REVIEWED_DIGEST`. Any added, removed or changed skipped ID/reason invalidates that approval. A non-zero exit status blocks cutover. A successful acceptance still does **not** automatically enable imported accounts or make boards public.

## Operator gates outside the automated SQL check

- Verify login for several migrated users using the reset/claim policy; imported credentials are never copied. Review conflicts and disabled accounts.
- Test real authenticated recipient A/B and non-recipient/guest requests for imported private message and attachment URLs over HTTPS; use application APIs and live ACLs, not only direct SQL.
- Inspect mixed-language BBCode, long topics, quotations, mentions, file download headers and legacy redirects in a browser on the staging domain.
- Simulate an interrupted import **on a disposable clone**, restart with the exact same immutable source, and verify no duplicate posts, attachments, reactions or participants.
- Demonstrate full restore from the pre-import backup, and verify the restored forum search index and ACP permissions. Do not point this destructive test at production.
- Repeat separately with representative 2.2 and 2.3 exports, and test PostgreSQL plus any other production database driver. Report unsupported add-ons and file storage backends separately.

The patch contains no real XenForo export or production/staging credentials. CI's seeded SQL and PHP integration checks are regression evidence, **not** proof of a successful migration from a third-party live community.
