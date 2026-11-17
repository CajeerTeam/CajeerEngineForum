# Disaster Recovery — Core 1.2.4, Forum Edition 0.9.6

Forum 0.9.6 adds [end-to-end disaster recovery certification, independent-catalog discovery and HTTPS RTO evidence](forum-dr-0.9.6.md). **These are CI-tested mechanisms; physical cross-host certification is not implied.**

Recovery sets are consistent, authenticated snapshots of the database, managed release files, local media/content, installed extensions, file queue and encrypted environment. The DR transport encrypts **all** snapshot data with libsodium XChaCha20-Poly1305 secretstream, not only `.env`. It authenticates each frame and requires the final frame; truncation, tampering, wrong keys, trailing data and path traversal are rejected. Files are streamed in 1 MiB chunks. Imported sets are verified with their original manifest MAC, file hashes and database checks before atomic publication. Existing backups are never overwritten.

## Prepare and operate

- Run as the trusted installation operator on Linux with PHP 8.4+, sodium, `flock` and the database backup tools. SQLite uses PDO or the existing explicitly configured Python fallback. PostgreSQL requires compatible `pg_dump`, `pg_restore`, `psql`, `createdb`, `dropdb` and a dedicated database owner with CREATEDB for drills. Use a dedicated database; do not run these commands with untrusted dumps or superuser credentials.
- Mount an off-host filesystem that supports atomic hard links and directory fsync, or export to a protected local spool and transfer the resulting file with your authenticated object storage/backup client. Keep copies across failure domains, with immutable retention and the key backed up **separately**. A file outside the project is not necessarily off-host. Restrict directories to the operator (0700); exported files are 0600. Use encrypted disks for temporary plaintext snapshots and workspaces.
- Backup uses the existing exclusive application lease. Stop external writers that do not participate in the lease. Only default local storage and supported file/database queues are covered; S3, external queues, external search services and infrastructure need independently coordinated recovery. The local backup remains plaintext except `.env`, as in 1.2.3. A bundle is encrypted in its entirety.

```sh
php bin/cajeer backup:keygen --key=/secure/cajeer.key
export BACKUP_KEY_FILE=/secure/cajeer.key
php bin/cajeer backup:create
php bin/cajeer backup:export --id=<backup-id> --file=/mnt/offsite/site.cebackup
php bin/cajeer backup:drill --file=/mnt/offsite/site.cebackup \
  --workspace=/secure/drill-unique --rto-seconds=300 --rpo-seconds=86400
```

The workspace must not exist. Use a new name for each drill. The command decrypts the external bundle into the private workspace, verifies the snapshot, restores and re-hashes every file, restores `.env`, restores the database and reads its tables. SQLite checks integrity and foreign keys; PostgreSQL restores into a randomly named `ce_dr_*` database without `--create` or `--clean` and reads all user tables. The command removes the scratch database and files on normal completion and exceptions. If the host is killed, manually remove the abandoned private workspace and **only** the identified scratch database after verifying no drill is running. PostgreSQL drills need capacity for a second database; run against a dedicated recovery instance with matching configured identity where possible. Do not allow network-capable extensions in a rehearsal environment without isolation. MySQL/MariaDB encrypted export/import and original-identity restore are supported by the existing backup driver; isolated automated drill is deliberately refused because its SQL includes database-level DROP/CREATE. Rehearse MySQL/MariaDB on an isolated instance with the same identity and use the cold restore procedure.

The drill returns a machine-readable report and nonzero exit status for a failed restore or exceeded optional budget. `restore_seconds` measures extraction, verification and data restoration; `snapshot_age_seconds` measures elapsed time since snapshot capture. These are a rehearsal duration and snapshot-age budget, not a guarantee of end-to-end service RTO or actual loss of writes. `application_boot_tested=false` explicitly distinguishes this data drill from a full application recovery. Archive reports externally; schedule create/export/drill with the existing system scheduler or cron and alert on nonzero status. Do not delete previous verified off-host copies solely because a newer export succeeded.

## Cold recovery after loss of the host

1. Provision a replacement host with the same project path, compatible release, UID/GID, database identity, runtime dependencies and filesystem permissions. Keep HTTP and all workers stopped. Restore external infrastructure separately. Install the original or compatible recovery-capable dist (do not run a fresh CMS install against recovered data).
2. Restore access to the key and bundle. On the replacement host set the original DB driver/path or database host/port/name and credentials through the environment or a minimal bootstrap `.env`; create the SQLite parent directory. For PostgreSQL/MySQL provision the original database server and database role; backup restore may replace the named database. The backup intentionally refuses a different identity. For the original SQLite path, the actual DB file need not exist. Use `STORAGE_DRIVER=local`, `STORAGE_LOCAL_PATH=storage/app` and the original supported queue configuration. Ensure shell environment values match the recovered `.env` before booting.
3. Import and verify. This does not modify live content or the database. Then restore with explicit confirmation. The original environment and code are restored, and the existing full migration/schema/doctor/HTTP postcheck must pass before the restore marker is removed. Maintenance remains enabled.

```sh
export BACKUP_KEY_FILE=/secure/cajeer.key
php bin/cajeer backup:import --file=/mnt/offsite/site.cebackup
php bin/cajeer backup:verify --id=<backup-id>
php bin/cajeer backup:restore --id=<backup-id> --yes
php tools/update-postcheck.php verify
# Check actual deployment, content, media, admin login, worker health and external dependencies.
php bin/cajeer maintenance:off
```

If restoration fails, keep maintenance enabled and retry the same backup ID after fixing the cause. A pending restore marker blocks reopening. Do not restore a compromised snapshot or use a key that may have leaked. Key loss makes encrypted recovery impossible. Keep an independently secured key recovery procedure and exercise it.

## Release validation

`php tools/disaster-recovery-regression.php` exercises real SQLite data, a multi-frame media file, empty files, encrypted environment, incorrect key, modified/truncated/appended bundles, no-overwrite, idempotent import, isolated drill and complete loss of the local installation. `--pgsql` runs on the explicitly disposable `cajeer_backup_test` database. The release workflow also runs `tools/disaster-recovery-dist-smoke.sh` against a Composer-equipped dist: it installs, publishes content via HTTP, exports a backup, destroys the entire installation, imports/restores, checks media and restarts the application to verify public content and admin login. These tests are release gates, not claims about an untested deployment. Run the full cold recovery on each production profile and measure service outage before accepting its RTO/RPO.

## Forum Edition 0.8.5: recover after losing the host

Use a trusted, independently obtained **0.8.5 or later dist with vendor dependencies** as the recovery tool, not PHP files from an unverified backup. Recovery is an operator-only Linux CLI operation. Run it as the intended application account (PHP-FPM/worker UID), under a private directory owned by that account. Provision PHP 8.4, the supported PDO driver, sodium, flock, and PostgreSQL 17+ client tools when applicable. Keep the recovery host isolated from public traffic, old infrastructure and outbound mail/webhooks. Trusted installed PHP extensions can execute code during application boot; the recovery process is not a plugin sandbox.

### Retrieve and authenticate a copy

Keep the original 32-byte base64 backup key in a separate secure location; mode 0600. Do not generate a replacement key. Obtain the backup ID and SHA-256 from the verified off-site catalog/object name, and use the original namespace and destination policy. `backup:fetch` does not create, delete or rotate remote objects. It streams the object into a temporary file, verifies SHA-256, then publishes without overwriting an existing file. For S3, configure AWS CLI v2 read credentials and trusted TLS as in [automated backup](automated-backup.md).

```bash
php /opt/recovery-tool/bin/cajeer backup:fetch \
  --policy=/secure/backup-policy.json --id=BACKUP_ID \
  --sha256=EXPECTED_SHA256 --file=/secure/recovery.cebackup --json
```

Use an existing private writable directory for the output. A matching digest is not a substitute for the bundle's encryption authentication and manifest MAC; those are verified during recovery.

### Destination profile and recovery

Create a new private parent directory, for example `/srv/recovered`, owned by the intended application account. Do not pre-create the `forum` child. Create `/secure/recovery-profile.json` (0600) with an explicit destination origin and, for PostgreSQL, destination credentials:

```json
{
  "APP_URL": "https://forum.example.com",
  "DB_HOST": "127.0.0.1",
  "DB_PORT": "5432",
  "DB_USERNAME": "forum_recovery",
  "DB_PASSWORD": "REPLACE_WITH_DESTINATION_SECRET",
  "DB_SSLMODE": "disable",
  "EXTENSIONS_TRUST_DIR": "/secure/extension-trust",
  "UPDATE_TRUSTED_PUBLIC_KEY_FILE": "/secure/release.pub"
}
```

Provision the non-superuser destination role with CREATEDB (temporary for recovery) and sufficient space. Remote PostgreSQL requires `verify-full`, a matching DNS certificate and trusted libpq CA. For SQLite omit all DB fields; a new `storage/database/cajeer.sqlite` is created. Provision original extension publisher keys if the source used an external trust directory. No source DB credentials or paths are used for restore. PostgreSQL always creates a new random `ce_dr_...` database; it never uses `pg_restore --create` or drops a pre-existing database. The database must be accessible to the recovered application after publication.

```bash
php /opt/recovery-tool/bin/cajeer backup:recover \
  --file=/secure/recovery.cebackup --key=/secure/backup.key \
  --target=/srv/recovered/forum --profile=/secure/recovery-profile.json \
  --rto-seconds=3600 --rpo-seconds=86400 --yes --json
```

The tool authenticates the bundle, verifies each file and database dump, preserves the snapshot's original Forum Edition and encryption secrets, relocates DB/runtime configuration, restores the database, and boots the recovered application in a private unpublished directory. It verifies migration ledger, forum integrity, FTS, theme, extensions, SSR, public API, guest ACP denial and maintenance. No HTTP listener or worker is started. A successful result atomically publishes the directory **still in maintenance**. It writes `storage/app/recovery-receipt.json` with probe results, snapshot age and elapsed recovery time. The RPO limit rejects an old snapshot before DB creation; an RTO miss yields exit code 2 with a published but closed installation. These measurements do not include DNS/TLS provisioning, external backup download or subsequent production traffic activation.

### Resume, validate and activate

Repeat the exact command after interruption (same bundle, key, profile and budgets). The sibling `.forum.recovery` directory contains the durable journal and private diagnostic logs. It is not served over HTTP. Failed attempts can leave newly allocated PostgreSQL databases; names are recorded in the journal/receipt. Verify ownership and identity before manually removing them. Existing unrelated installations are never replaced. A completed operation returns the same receipt; changing inputs requires a new destination/workspace.

1. Inspect the receipt, protected logs and recovered configuration. Confirm the source backup's version; recovery is **not an upgrade**. Use a signed update only after recovery if a newer version is required.
2. Configure a new Nginx/PHP-FPM host for the recovered document root `public/`, grant only necessary directory traversal permissions to the gateway, install the intended worker/scheduler units but keep them stopped. Maintain an external traffic gate. Restore independent TLS keys and infrastructure credentials separately.
3. Validate `migrate:status`, `install:check`, `forum:ecosystem:check --deep`, and `forum:search:check`. In a private network, run `maintenance:off` and verify login, ACL, attachments, ACP, theme, plugins, search and a write/read round trip. Re-enable maintenance if any check fails.
4. Review queued historical jobs and external side effects before enabling workers. The tool disables external cache, SMTP settings, S3 replicas, webhook dispatch and telemetry endpoints in the recovered configuration. Reconfigure necessary services deliberately; verify fresh backup automation and off-site namespace ownership. Only then enable workers, scheduler and public traffic.

The new-host path supports SQLite and PostgreSQL with local authoritative storage and file/database queues. MySQL retains existing same-installation restore; Redis queue and external authoritative S3 recovery, PostgreSQL WAL/PITR, Docker volume adoption and automatic DNS/TLS provisioning are not implemented by this command. A container snapshot can be recovered to a standalone host under these constraints, but cannot simply be placed in an existing Compose project's volumes. Preserve the original trust material, backup key and deployment configuration outside the lost host. Test the full procedure periodically on an isolated destination.
