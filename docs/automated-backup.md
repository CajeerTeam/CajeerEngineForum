# Automated Backup & Retention (Forum Edition 0.8.4)

The automated operator uses the existing authenticated `BackupManager` recovery format v2, not a second database dump format. The recovery set includes the SQL database, code/release inventory, local attachments, public uploads, signed extension files, configuration, and supported durable queues. `.env` is encrypted in the local recovery set; the **entire** exported `.cebackup` is encrypted/authenticated using the existing streaming XChaCha20-Poly1305 transport. Local recovery sets include plaintext database/files and must be protected by filesystem permissions and encrypted disks. Keep the backup key independently of both the host and backup destination; never store it beside the bundle.

## Prerequisites and boundaries

- Use the installed Forum Edition, local authoritative storage, and PostgreSQL (recommended) or supported SQLite/MySQL. For MySQL, the existing restore-rehearsal guard remains mandatory.
- PostgreSQL queue is the recommended production/Compose default; file queue is also covered by existing backup inventory. Redis cache/rate-limit keys are disposable. **External Redis/RabbitMQ queues and external primary storage are not covered**; the existing guard rejects them before capture. Do not remove the guard: stop writers and migrate to a supported durable queue/storage policy before enabling automation. SQL-backed forum attachments remain authoritative; S3 media replicas need an independent object inventory/rebuild procedure.
- All application writers must honor the existing operation lock; single installation/host or shared POSIX lock filesystem only. Stop or coordinate external writers, DB administrators, and remote storage mutations. Backup needs an exclusive activity drain during capture, so schedule off peak. The later encrypted export/transfer uses a shared operation lock; normal application requests and workers can continue, but update/restore must wait.
- Keep deployment files, TLS credentials, the release publisher public key, backup policy, infrastructure configuration, and the backup key in separate protected recovery storage. They are outside the project snapshot. This release does not provide PostgreSQL PITR or an automated cross-host application boot drill.

## Configure

Copy `deploy/backup-policy.example.json` to an absolute canonical path outside the installation, owned by the runtime backup user, mode `0600`. Use a unique namespace for each installation, never share one namespace between sites. The policy contains no passwords or keys.

```json
{
  "namespace": "community-prod",
  "interval_seconds": 86400,
  "lock_wait_seconds": 15,
  "daily": 14,
  "weekly": 8,
  "monthly": 12,
  "min_free_bytes": 67108864,
  "require_separate_device": true,
  "prune_offsite": false,
  "destination": {"type":"file","directory":"/mnt/cajeer-offsite"}
}
```

The external directory must already exist, be private (`0700`), outside the installation, writable by the backup user, and support atomic hard links and directory fsync. By default its device must differ from `storage`; this prevents writing silently into the underlying local filesystem after a remote mount disappears. A separate disk is not necessarily off-site: mount a real remote filesystem or use S3. Set `require_separate_device=false` only for isolated tests or when independently enforcing the mount. The backup process never creates the configured mount itself.

For S3 replace `destination` with:

```json
{"type":"s3","bucket":"my-forum-backups","prefix":"recovery","region":"us-east-1","endpoint":"https://s3.example.com"}
```

Omit `endpoint` for AWS. HTTPS is mandatory for custom endpoints. Install AWS CLI **v2**, configure its standard credential provider (e.g. protected shared credentials/config files or an IAM role), and use a private bucket with least-privilege `PutObject`, `GetObject`, multipart permissions and, only if remote pruning is enabled, `DeleteObject` for the exact namespace. AWS CLI uses multipart transfers; each uploaded object is read back in bounded memory and compared to the expected full SHA-256 and byte count. No reliance on multipart ETag. Do not disable TLS validation. Configure lifecycle abort for abandoned multipart uploads; for versioned buckets, ordinary object deletion writes delete markers, not permanent deletion of previous versions. Bucket Object Lock/versioning and independent lifecycle are recommended for ransomware protection. Do not expire the protected newest copies via an unrelated lifecycle rule.

## Run and observe

```bash
php bin/cajeer backup:automation:run --policy=/etc/cajeerengine-deploy/backup-policy.json --json
php bin/cajeer backup:automation:run --policy=/etc/cajeerengine-deploy/backup-policy.json --force --json
php bin/cajeer backup:automation:status --json
```

Run every five minutes using the timer below. The durable catalog determines when a new snapshot is due (daily by default, UTC elapsed seconds). `--force` creates another snapshot, still respecting locks, capacity and recovery guards. A normal tick retries up to two pending off-site deliveries (newest and oldest). A failed upload does not invalidate the local verified backup and does not prevent the next scheduled capture; it prevents all rotation. If the encrypted spool was interrupted, the next run regenerates from the authenticated local set. A crash after remote publish but before the receipt is committed repeats the transfer of the same bytes; file publication refuses overwrite and S3 names include the exact digest. A regenerated ciphertext may leave an untracked remote object; retain it for operator review, never delete it automatically. Incomplete/untracked/manual/update recovery sets are not eligible for rotation.

The catalog is `storage/app/backups/automation/state.json`, and encrypted staging is in that same excluded/private subtree. Do not edit it, move the destination or reuse the namespace without an operator migration of backup history; a destination identity mismatch fails closed. A newly restored host without the catalog starts a new managed history and never deletes old off-site objects. Export the catalog separately for operational history (no key/secrets). Local backups are `storage/app/backups/production/<id>`. The capture manifest authenticates the automation identity; arbitrary IDs, including manual/update backups, cannot be pruned by catalog entries alone. Keep local free space for a full recovery set and encrypted staging, plus the policy safety margin. The estimator is conservative but not a disk reservation. The operator must clean incomplete and manual copies separately.

Retention uses UTC calendar days, ISO weeks and calendar months, keeping the latest verified copy in each configured bucket and at least the two newest verified copies. Before pruning, it verifies the latest local and external recovery set and checks the candidate's authenticated local provenance and external digest. At most ten candidates retire per tick. `prune_offsite=false` (default) rotates local copies only; set it true to delete expired managed off-site objects as well. A durable `retiring` state permits resuming deletion without guessing after a crash. No prune happens while pending deliveries exist or verification fails. No bucket-wide delete or removal of unknown files. A retention policy is not a guarantee against an operator manually deleting files or external lifecycle expiring them.

Operations ACP (`/admin/#/system`) shows recent managed copies, version, size, state, last verified local/off-site timestamps and pending count. Existing `ops:report` and Prometheus expose sanitized backup status (no keys, paths, endpoints or credentials). The status command returns non-zero for pending/stale/failed cycles. A stopped timer becomes stale after one hour. Set existing `OBSERVABILITY_BACKUP_MAX_AGE` (see config/observability.php) to match your capture schedule. The new alerts flag pending delivery and stalled automation; monitor the service timer itself as well, especially before its first successful run. A verified copy is not an application restore drill.

## Linux/systemd production profile

With the deployment from 0.8.1, install the example service/timer from `deploy/` into `/etc/systemd/system/`. Edit `RequiresMountsFor`/`ReadWritePaths` for your real off-site mount; for S3 omit the mount dependency, leave the app storage writable, and use an operator-owned AWS CLI v2 configuration readable by `cajeerengine`. `BACKUP_KEY_FILE` must be provisioned as in the existing deployment. Use the same runtime user and group; do not run PHP as root.

```bash
sudo install -o cajeerengine -g www-data -m 0600 deploy/backup-policy.example.json /etc/cajeerengine-deploy/backup-policy.json
# edit policy and mount path before enabling
sudo install -m 0644 deploy/cajeerengine-backup.service deploy/cajeerengine-backup.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now cajeerengine-backup.timer
sudo systemctl start cajeerengine-backup.service
sudo systemctl status cajeerengine-backup.service
```

The timer is persistent and retries the normal cycle every five minutes. The sample unit starts only when the deployment's workers gate is enabled. Backup and deployment/updater use the same application activity lock; if busy, retry later. For other layouts, adapt WorkingDirectory, PHP path, user, policy path and writable paths. For S3 with `ProtectHome=true`, set `AWS_SHARED_CREDENTIALS_FILE` and `AWS_CONFIG_FILE` to protected files outside home, using a systemd drop-in; no secrets in arguments.

## Docker Compose production profile

After upgrading the installed volume to **0.8.4** through `deploy/container/update.py`, prepare the same approved signed dist and use the same active `site.env`. Create a host backup configuration directory with `policy.json` readable by UID 33, mode `0600`, directory mode `0700`; for file storage, set `destination.directory` to `/offsite` in the policy. The real remote mount is on the host. For S3, optional `credentials` and `config` files are read-only mounted into the separate backup image; provide a private empty offsite bind directory if not using file storage. The backup image has AWS CLI v2; PHP-FPM/worker/scheduler do not get it or the off-site credentials.

```bash
python3 deploy/container/backup.py build --project cajeerforum \
  --env-file /srv/forum-config/site.env --config-dir /srv/forum-backup-config \
  --offsite-dir /mnt/cajeer-offsite
python3 deploy/container/backup.py run --project cajeerforum \
  --env-file /srv/forum-config/site.env --config-dir /srv/forum-backup-config \
  --offsite-dir /mnt/cajeer-offsite --force
```

Use the same trusted host operator as `update.py`: the launcher shares its host project mutex and refuses Redis queue mode. It uses the separate `backup.compose.yaml` overlay, does not publish ports, runs one-shot as UID 33, read-only code, no Docker socket, and verifies the installed release against the signed image before backup. The existing volumes (including keys) are reused. Rebuild the backup image after each release upgrade. The base Compose file and `site.env` format are unchanged; do not add backup config variables to site.env. Install/adapt the example `deploy/container/cajeerengine-container-backup.service` and `.timer` to schedule it on the Docker host. Stop the timer during operator maintenance if required. Do not use `docker compose down -v` on production.

## Recovery

Download the exact object (S3 `aws s3 cp s3://... /secure/recovery.cebackup`) and check its digest against the object filename or trusted catalog. For file storage use the mounted object directly. With the original separate backup key and compatible database identity/configuration, run:

```bash
php bin/cajeer backup:import --file=/secure/recovery.cebackup --json
php bin/cajeer backup:drill --file=/secure/recovery.cebackup --workspace=/secure/drill --rto-seconds=3600 --rpo-seconds=86400 --json
# after shutting down writes and reviewing the recovery plan:
php bin/cajeer backup:restore --id=BACKUP_ID --yes --json
```

Import verifies transport authentication, manifest MAC and the database/files; restore replaces code, data and configuration and keeps maintenance enabled. The existing drill verifies actual data/files in isolation, but does not boot the application. Full off-host application recovery belongs to 0.8.5. No key rotation or external queue restore is implied by successful backup.

## Certification

Run `php tools/backup-automation-regression.php` (physical SQLite recovery, retries and retention); CI also runs the same engine with native PDO and a real PostgreSQL Compose install, plus TLS S3 multipart upload/readback/delete against a disposable MinIO service. Full production-host and Compose upgrade gates remain required. Local SQLite and filesystem tests do not certify a real PostgreSQL, Docker or remote S3 deployment.
