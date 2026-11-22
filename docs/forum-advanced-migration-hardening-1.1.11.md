# CajeerEngine Forum 1.1.11 — Advanced Migration Hardening

Apply migration **0080** (indexed ID-map lookups) before any large import or deep integrity scan.

This release hardens the **existing executable** XenForo and Invision import pipelines. It does not change their phase numbers or existing checkpoints.

## Import recovery

Before each invocation of `forum:import:run`, the server verifies the recorded backup using `BackupManager::verify`; a manifest path alone is not accepted. Keep the application in maintenance mode, drain all nodes and retain the original source snapshot and attachment files until migration validation and cutover are complete. The legacy `--verify-backup` option remains accepted for compatibility but cannot disable verification.

If imported Invision 4/5 content has attachments, `--files=/absolute/path/to/immutable/uploads` is mandatory in both v7 and v8, before capturing the backup. When a job fails, fix the underlying issue and rerun it against the **same** snapshot and paths. Do not edit `ce_forum_import_jobs` or `ce_forum_import_map` manually, and do not silently skip damaged records.

## Inspect integrity before release

```sh
php bin/cajeer forum:import:status --job=YOUR_JOB_SHA256 --json
php bin/cajeer forum:import:verify --job=YOUR_JOB_SHA256 --json
php bin/cajeer forum:import:verify --job=YOUR_JOB_SHA256 --deep=1 --json
```

The second command checks quarantine, disabled imported accounts, category and board isolation, active private conversation audiences, message authorship and attached-file ownership. Deep mode additionally verifies the decoded bytes, declared file size, and SHA-256 of every mapped attachment **one file at a time**. Deep mode requires maintenance mode without another update/deployment lock. Failure returns a nonzero command exit status through the CLI dispatcher if `ok=false`; use the JSON report to identify the failing category and investigate before reopening the site. A successful integrity report on an **incomplete** job is not production acceptance (`complete=false`).

Before finalizing a job, the same SQL ownership and quarantine checks execute **inside the final transaction**. A failed check rolls back the completion checkpoint. Existing XenForo full-source acceptance remains `forum:import:accept` and must also be run where applicable. This target-side verification does not prove complete source fidelity, application-specific ACL equivalence or the preservation of unknown Invision data.

## Supported verification and caveats

- Source DB is read-only; checkpoints and imported data are committed atomically by the existing engine.
- The old XenForo and Invision v6/v7/v8 migration plans resume unchanged.
- Very large deep checksum scans can take significant time; they read a single attachment at a time but scan all mapped files. Schedule during maintenance.
- Verify source and target counts, operator-approved skips, database-level foreign keys, backup recovery and complete browser access tests before cutover.
- Run `php tools/forum-import-hardening-integration.php` in CI with PHP 8.4, PDO SQLite, mbstring and the project test dependencies.

A green local syntax/regression run is **not** evidence of a successful real-data production migration. Run acceptance independently against frozen XenForo/Invision 4/5 datasets and the deployment's actual database driver.
