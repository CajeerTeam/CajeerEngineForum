# Forum Edition 1.4.9 — Automated Deployment & Recovery

This release changes the executing deployment, backup and update paths. No database migration or new dependency is required. Core version remains 2.1.0. The changed-files archive is not a signed distribution; build and sign a full distribution from the merged source after CI acceptance.

## Signed updates

The existing web approval / non-root operator flow now records `confirmed_at`, allows one unresolved approval at a time, and writes `running` before executing any operation. The download action requires a fresh eligible canonical GitHub release receipt, fetches through the existing secure downloader, and publishes a stage only after verifying the exact downloaded SHA-256, signature, edition and version. Apply remains a separate explicit approval. The CLI also validates approval records; failed requests are terminal unless recovery markers remain, and a killed running request is never replayed automatically. Do not edit a running request to pending. Recover the operation and reconcile its result manually before authorizing a new request.

Direct installation of a signed dist remains:

```sh
php bin/cajeer update:apply --package=/secure/cajeerengine-1.4.9-dist.zip --version=1.4.9 --sha256=APPROVED_SHA256 --dry-run
php bin/cajeer update:apply --package=/secure/cajeerengine-1.4.9-dist.zip --version=1.4.9 --sha256=APPROVED_SHA256
```

Provision the publisher public key out of band and the original protected backup key. Never execute a changed-files ZIP as a signed release.

## Compose updates

With the approved dist prepared and original/new site files retained:

```sh
python3 deploy/container/update.py apply --project forum --old-env /secure/old.env --new-env /secure/new.env --state-dir /secure/forum-update --recover-on-failure
python3 deploy/container/update.py recover --project forum --old-env /secure/old.env --new-env /secure/new.env --state-dir /secure/forum-update
```

Add `--ca /secure/ca.crt` for a private trusted CA. Without `--recover-on-failure`, use `recover` after a failure as before. Before activation the operator recovers the old version. Once new activation has started, automatic recovery resumes the selected release rather than discarding possibly accepted writes. A rollback uses a new durable `rollback_activating` checkpoint before launching the old services; subsequent recovery resumes that activation without restoring the snapshot again. An interrupted rollback before this checkpoint may repeat the restore while writers are still stopped. Unknown checkpoints and identity mismatches fail closed. A committed rollback requires explicit `rollback --yes` and can discard writes; only perform it after operator review. An unsuccessful `apply` still exits nonzero even if recovery succeeds. PostgreSQL queues only; external Redis queue snapshots are not supported.

## Linux/systemd installation

Use the existing signed host deployment procedure in `production-deployment.md`. If the operator dies after publishing authenticated files but before creating the environment or database objects, run:

```sh
sudo python3 deploy/production.py resume --version 1.4.9 --sha256 APPROVED_SHA256
```

The operator verifies the recorded artifact and live file hashes before re-entering the installer; the installer repeats the empty PostgreSQL database check. If an environment and install lock already exist, the finalization check validates protected runtime settings and database recovery prerequisites. A partially initialized site (for example, `.env` present without the installation lock) remains gated; it must be inspected and recovered explicitly, not reseeded automatically.

## Backup and restore

The backup scheduler durably reserves a unique capture ID before writing the snapshot. On the next invocation a lost catalog receipt is reconciled using the reserved ID, authenticated manifest, destination identity and full snapshot integrity checks. A verified snapshot becomes pending and goes through the existing encrypted off-site transfer pipeline. Partial sets are retained for inspection, not published or silently pruned. Catalog receipts are idempotent. A corrupted complete snapshot fails closed. The database capacity preflight now measures SQLite file/WAL size, PostgreSQL database size and MySQL/MariaDB table allocation (with a conservative SQL dump expansion allowance). These are estimates, not a guarantee of disk availability.

Existing policy, key, off-site destination and timer setup: see `automated-backup.md` and `disaster-recovery.md`. Do not delete snapshot keys, protected journals or data volumes during recovery.

## Acceptance

Run `php tools/backup-automation-regression.php`, `php tools/deployment-recovery-acceptance.php`, `php tools/update-production-hardening-regression.php`, `php tools/admin-update-center-regression.php`, `php tools/github-auto-updater-ga-regression.php`, `python3 deploy/container/test_update.py`, and `python3 deploy/test_production.py`. Run the full required CI gate with real database and Compose/systemd integration before signing and publishing. Non-root operator tests must run under the dedicated Linux operator identity. Validate backup restore in an isolated environment, not against production data.
