# Invision Community 4.x / 5.x source discovery — Forum 1.1.7

This release provides a **working read-only discovery and preflight** of actual frozen source database schema, data volumes and relational integrity. It does **not** enable importing Invision records: `forum:import:run` intentionally rejects `--platform=invision` until the transactional Invision Core Import is implemented and accepted in 1.1.8.

## Preconditions

1. Obtain an **immutable** copy of an Invision Community 4.x or 5.x SQL database. MySQL/MariaDB is supported for native snapshots; SQLite is supported for preconverted test/analysis snapshots. Do not run discovery against a changing live database.
2. For encrypted/cloud/custom storage, obtain a complete export from the administrator or an authorized backup first. The CLI never downloads remote private files or assumes that a local uploads directory represents every configured storage method.
3. Restrict snapshot database credentials to **SELECT** on the imported copy. The discovery code additionally opens a consistent read-only transaction. Keep the database and exported files outside the document root.
4. Use the same snapshot label when recording reports; the label is an operator name, **not** a cryptographic source-database checksum. Preserve the underlying source backup separately.

Example:

```bash
export FORUM_IMPORT_SOURCE_DSN='mysql:host=127.0.0.1;port=3306;dbname=invision_snapshot;charset=utf8mb4'
export FORUM_IMPORT_SOURCE_USER='readonly_import'
export FORUM_IMPORT_SOURCE_PASSWORD='from-secure-environment'
php bin/cajeer forum:import:discover --platform=invision \
  --snapshot=ips-frozen-2026-11 --files=/srv/snapshots/ips/uploads --json
```

Use `--prefix=ibf_` if auto-detection sees several installations in a shared database. Otherwise the correct prefix (`''`, `ibf_`, `ips_`, etc.) is detected. You may provide `--source-version=4.7.24` or `--source-version=5.0.20` to assert the installed `core` application version. A mismatch is fatal. `--files` supplies the immutable local upload snapshot; it is **not** the destination upload directory.

## Actual checks

- Detect installed `core` and `forums` applications and distinguish 4.x vs 5.x from `core_applications.app_version`, not a user-supplied version label.
- Verify the mandatory columns of `core_members`, `core_groups`, `forums_forums`, `forums_topics`, `forums_posts`, and `core_applications`; accept known `post` / `post_content` variants.
- Count IDs and high-water marks, reject non-positive/duplicate source IDs, detect orphan topics/posts and forum parent cycles, and report deleted post authors or members with missing groups.
- Report presence and counts (not private contents) for optional private messages, follows, reputation, reactions, polls, archive posts, attachments, and file storage configuration.
- Report if an attachment snapshot is missing; never reveal `core_file_storage.configuration`, email addresses, post bodies, or database credentials.
- Produce a SHA-256 **structural/volume fingerprint** covering version, prefix, columns and counts. The fingerprint is not a digest of source row contents and must not substitute for a verified frozen backup.

`ok=false` and `blockers` mean the source is not ready for a safe import. `warnings` require operator review. Successful source discovery is not authorization to publish/migrate accounts, translate privileged roles, or bypass SSO enrollment.

## Invision 5 compatibility

This version checks the actual 5.x database columns rather than assuming that all Invision 4.x layouts still exist. The supported source layout must expose the minimum required Forums tables and identifiers. Unknown new names or altered 5.x internals fail closed with the missing-table/column diagnostic; a real 5.x snapshot is necessary to certify a particular installation. Files in S3 or another remote provider require an independently verified export.

## Tests

`php tools/forum-invision-discovery-regression.php` exercises PDO SQLite 4.x/5.x fixtures, negative layouts, relational failures, read-only write protection and actual CLI invocation. `python tools/forum-tests/invision_discovery_sql_regression.py` independently checks the native source SQL fixture. The PHP test is required in Forum CI with `pdo_sqlite` installed.
