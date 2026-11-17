# Forum Edition 0.9.6 — Disaster Recovery Certification

**Scope.** This release adds executable, signed-release-bound disaster-recovery acceptance, not a claim that a physical off-site host, public TLS or DNS has already been certified. The standard recovery procedure is in [disaster-recovery.md](disaster-recovery.md), with backup rotation in [automated-backup.md](automated-backup.md). The original server can be completely lost; **both** the backup encryption key and a trusted recovery tool/release must remain accessible in a distinct failure domain.

## Actual recovery procedure after source loss

1. From a trusted, reviewed recovery host, provision a fresh OS, PHP 8.4, the compatible PostgreSQL 17/18 restore clients, a fresh non-superuser destination PostgreSQL role with CREATEDB, a verified signed **same-version** Forum dist, and HTTPS certificate/key and network endpoint. Keep public traffic, worker and outbound integrations disabled. Recover S3 authoritative media, if configured, separately; the built-in cold restore supports **local authoritative media only**.
2. Load the externally escrowed original 32-byte backup key in a private 0600 regular file outside the application and off-site storage. Load a private 0600 backup policy with the *same* namespace, S3 bucket/prefix or filesystem mount. Filesystem recovery must use an actually independent mounted device (do **not** disable `require_separate_device` in production).
3. Discover backups **without a local catalog**. `backup:discover` returns bounded pages of candidate content-addressed filenames, in chronological order for the filesystem. The output has `authenticated=false`: **a listed object is not yet a valid recovery point**. For S3, the IAM identity needs scoped `s3:ListBucket` for its prefix, in addition to `GetObject`; paginated continuation tokens must be passed unchanged.
4. Choose a recovery candidate using the original off-site retention records. `backup:fetch` reads the exact object and hashes it against the content-addressed filename. `backup:recover` independently authenticates the encrypted stream and MAC, verifies all files and database contents, restores into a **new destination path and database** and boots a private application health probe. It must remain in maintenance mode after publication.
5. Provision Nginx/PHP-FPM (or reviewed equivalent), valid TLS and trusted DNS on the replacement host, and verify **actual HTTPS** readiness, administrator/member login, ACL and private uploads, forum/FTS/SSR, theme, extensions and new writes. Enable public traffic only after all tests pass. Review queued historical jobs and separately re-enable SMTP, webhook, cache, worker/scheduler and external integrations.

Example commands (adjust paths and use reviewed values; do not run against a live installation):

```sh
php bin/cajeer backup:discover --policy=/secure/backup-policy.json --limit=100 --json
# If more pages exist: --cursor=NEXT_CURSOR_FROM_REPORT
php bin/cajeer backup:fetch --policy=/secure/backup-policy.json \
    --id=BACKUP_ID --sha256=EXPECTED_DIGEST \
    --file=/secure/new-recovery.cebackup --json
php bin/cajeer backup:recover --file=/secure/new-recovery.cebackup \
    --key=/secure/escrowed-backup.key --target=/srv/replacement/forum \
    --profile=/secure/recovery-profile.json \
    --rto-seconds=3600 --rpo-seconds=86400 --yes --json
# Verify the recovered HTTPS service and credentials before maintenance:off.
```

**Never** trust names returned by `backup:discover`, accept a different key, overwrite an existing installation, or restore a PostgreSQL SQL dump into an occupied database. Do not destroy the source installation as a form of testing outside disposable environments. The original local backup catalog is *not* necessary for discovery; `backup:fetch`/`backup:recover` provide the authentication gates. Use a trusted signed recovery tool from outside the destroyed server.

## CI physical acceptance

`tools/forum-tests/dr_certification.py` is a destructive disposable-only test runner, not a production operator command. It only accepts `FORUM_DR_DISPOSABLE=destroy-test-services` and the exact PostgreSQL fixture name `forum_test_release_dr096`; S3 requires a loopback MinIO TLS fixture and a separate bucket. The test:

- Verifies the **actual signed current dist** against the trusted CI key and records its SHA-256 plus Git commit.
- Installs a real forum, signed ecosystem extensions, ACL, members, content, search and private media; produces an acknowledged write marker, then starts concurrent real HTTP reads and writes.
- Uses the production `backup:automation:run --force` with the independently stored backup key and an off-site destination, and verifies durable catalog publication. The CI filesystem destination is **simulated off-site on the same disk** (`require_separate_device=false` only for this disposable fixture). S3 uses an actual TLS MinIO service.
- Stops the source HTTP process, erases the complete original application directory including local backups, and drops the dedicated PostgreSQL source DB. Performs **catalog-free discovery**, exact off-site fetch, authenticated `backup:recover`, private maintenance check and idempotent recovery.
- Starts a *different* recovered application and a test-only TLS ingress with an ephemeral local CA. The client verifies the CA and HTTPS origin, authenticates a user, checks the acknowledged pre-snapshot publication, ACL/search/SSR/media/theme/extensions and publishes a new reply.
- Writes the operator-independent `result.json` containing `version`, `commit`, `artifact_sha256`, per-check results, and **service_rto_seconds** from the simulated disaster until the recovered HTTPS site is usable. `rpo_upper_bound_seconds` is **snapshot age at availability**, not a direct proof of zero lost posts or PITR. Both fail when configured budgets are missed; incomplete tests leave `ok=false`.

Required CI: filesystem acceptance on SQLite, PostgreSQL 17 and PostgreSQL 18, plus independent TLS S3/MinIO acceptance on SQLite. The release certification stage **downloads all four reports**, validates all checks and metrics against the same signed dist, and includes their SHA-256 in the sealed evidence. A missing, altered or failed report blocks certification and thus `forum-required`.

The TLS ingress in this test is an actual CA-verified socket but **is not the production Nginx/PHP-FPM chain**. GitHub-hosted jobs simulate a replacement host with a new path and, for PostgreSQL, a new database, on an ephemeral runner; they **do not simulate losing the physical hypervisor, cloud credentials, DNS provider or separate availability zone**. Before certifying a real installation, run the same end-to-end procedure on an independent replacement host with its own network and disk, real off-site store, production-grade ingress, preserved extension trust roots and independently escrowed keys. Record outage start, service-ready time, snapshot capture time, measured RTO/RPO, Git SHA, signed dist digest, OS and database versions, and full check evidence. A pass in short CI is necessary but insufficient.

## Known limits

- No production-grade automatic S3 authoritative media reconstruction, external queues, cross-DC PITR/WAL or automated DNS/TLS provisioning is implied. PostgreSQL database recovery creates a fresh `ce_dr_*` database; an abandoned recovery journal can leave such a database for **explicit manual** cleanup.
- Backups are coordinated with the application's exclusive operation lease and can temporarily reject new HTTP writes. Confirmed pre-snapshot writes must survive; writes acknowledged after the snapshot may be lost subject to the declared RPO. External writers bypassing the application lease must be stopped.
- The restored app deliberately keeps worker/scheduler disabled and external integrations disconnected until reviewed. Source or root-controlled trust directories must be provisioned independently.
- CI uses temporary, disposable keys and synthetic content; no real backups, recovery credentials, protected production buckets or operator signatures may be uploaded to CI artifacts.
