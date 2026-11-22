# Invision Community 4.x / 5.x core import — Forum Edition 1.1.8

This implementation imports **groups, accounts, the forum/category tree, topics and posts** from a frozen, read-only snapshot. It does not yet import attachments, polls, reactions, rich HTML, archived posts or private conversations. The result remains **hidden and read-only** until a human validates its ACL mapping and content. Source passwords, session tokens, moderator/admin privileges and external identities are never copied. Imported accounts are disabled pending a controlled recovery process.

## Before importing

1. Capture and independently verify an immutable copy of the source SQL database and associated uploads. Use a **read-only source account** for MySQL; for SQLite use a copy of the database. Do not point the importer at a live production installation.
2. Run all target migrations with `php bin/cajeer migrate`, enable maintenance mode, drain application nodes and obtain the existing application operation lease (the `forum:import:run` CLI enforces this).
3. For Invision 4/5 with archives or optional applications, check the discovery warnings. Archived posts and optional data **will not** be included in this core release. Make a migration plan for them before cutover.
4. Set the following only in the server environment (do not include credentials in CLI logs):

```sh
export FORUM_IMPORT_SOURCE_DSN='mysql:host=127.0.0.1;dbname=ips_snapshot;charset=utf8mb4'
export FORUM_IMPORT_SOURCE_USER='readonly_import'
export FORUM_IMPORT_SOURCE_PASSWORD='***'
php bin/cajeer forum:import:discover --platform=invision --snapshot=ips-frozen-001 --json
php bin/cajeer forum:import:plan --platform=invision --snapshot=ips-frozen-001 --json
```

If multiple Invision installations are present in the snapshot, pass an explicit `--prefix=...`. You may use `--source-version=4.7.24` (or the exact discovered 5.x version) to pin the source version.

## Run and resume

```sh
php bin/cajeer forum:import:run --platform=invision --snapshot=ips-frozen-001 --confirm=import-invision --batch=100 --steps=100 --verify-backup=1 --json
php bin/cajeer forum:import:status --job=JOB_SHA256 --json
```

Rerun the same `forum:import:run` command until the job completes. The source transaction and recorded source schema must remain unchanged. Each batch, mapping record and checkpoint commits together. The engine creates a recovery backup **before** the first target write. Invalid snapshots, unapproved content, email conflicts and missing author mappings have explicit import outcomes. Do not change the source snapshot label, DB or prefix midway through an import.

## Scope, constraints and verification

- Source groups are imported as **permissionless** local roles. Memberships are retained, but users get only the local `forum-member` baseline and remain disabled. Do not automatically grant staff roles.
- Root forums and categories are ordered before their children. Where source `sub_can_post=0`, the node becomes a container; otherwise it is a forum. The original forum tree is always placed in a hidden, read-only imported category.
- Only approved topics and unqueued posts are imported. Post text is conservatively sanitized to non-executable **plain text**; rich HTML and embedded media are deferred to 1.1.9.
- The `ce_forum_import_jobs` phase ledger uses version **6**; existing phase versions 1–5 are unmodified. Resuming with an incompatible source schema fails closed.
- Successful completion is not production acceptance. Compare source and target counts, inspect the skipped record ledger, verify private areas/ACL, post ownership and restore the recovery backup in staging before exposing any migrated material.

Test execution in CI: `php tools/forum-invision-core-integration.php` (requires PDO SQLite and all target database migrations). The current source archive does not contain real Invision production snapshots; production data compatibility must be verified independently.
