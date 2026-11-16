# Production Operations Stable — Forum Edition 0.9.0

This release closes the gap between a successful **build/deployment** and a demonstrably healthy **running** service. No new database schema or endpoint is introduced. The upstream engine remains 1.3.0; the independent Forum Edition release advances from 0.8.7 to 0.9.0. Apply these changed files to the matching 0.8.7 checkout, rebuild a complete signed dist, and deploy only the resulting verified artifact. The changed-files archive itself is not an installable or signed dist.

## Read-only live operations gate (Docker Compose)

Use the **same approved** `site.env` that started the application, with a local Unix Docker socket. Run from the trusted operator checkout, not from inside a container. Always use the **same dedicated Unix operator UID** for update, backup and operations commands on that Compose project; their intentionally private host lock cannot be shared across different UIDs. The process never modifies application state or performs test writes. Credentials and environment contents are not included in the report.

```bash
python3 deploy/container/operations.py \
  --project community-prod \
  --env-file /secure/forum/site.env \
  --ca /secure/forum/tls-ca.pem \
  --require-backup \
  --report /secure/reports/forum-operations.json
```

`--ca` is optional only when the server's real TLS certificate chains to system trust. Hostname verification is **always** enforced. The DNS name, port, expected version and pinned SHA-256 are read from `site.env`; the gate connects to the configured local bind address and cannot silently fall back to an unrelated public server. The report directory must exist, and any existing report must be a private regular file (mode `0600` recommended). The replacement is written with an atomic rename and directory fsync; process exit status is `0` for success, `1` when a required check fails and `2` for invalid invocation/environment.

The gate checks actual running states/health of PostgreSQL, Redis, PHP-FPM, worker, scheduler and gateway; exact app/gateway **running image tags**; and a completed installer. It then enters the running, read-only PHP container and calls its release manifest/checksum verification, database/migration readiness and both background loop health checks. Across verified HTTPS it checks `/health/ready`, `/api/v1/health` (including independent Forum Edition version and database readiness), the real SSR forum page, the ACP frontend, and blocked access to `/.env`, `/install/` and `/upgrade/`. Failures are reported by phase, rather than exposing SQL errors, tokens or response bodies. This is a short read-only acceptance; it does not simulate a high-load community or prove application data recovery.

`--require-backup` additionally executes the installed `backup:automation:status` against the running app, requiring a currently healthy verified off-site backup, nonzero last off-site success time and no pending delivery. Do not use it until the first configured backup cycle succeeds. Without the flag, the gate still checks the database, application and services; for regular unattended production monitoring, use the flag and alert on a nonzero exit. Backup status does not substitute for periodically restoring an actual recovery set to a **separate** host and verifying its application boot and RTO/RPO. Never test restore against the live production database.

## Linux/systemd host gate

The separate managed-host production profile now has its own real read-only admission command:

```bash
sudo python3 deploy/production.py verify --ca /etc/cajeerengine-deploy/tls/fullchain.pem
# After backup automation is configured and has produced a verified off-site copy:
sudo python3 deploy/production.py verify --require-backup
```

It verifies the existing signed dist bytes against the trusted publisher key and installed file hashes, the committed host journal and release identity, root-owned maintenance/worker gates, active PHP-FPM/worker/scheduler timer/Nginx units, internal readiness and forum API/SSR/ACP, public HTTPS with proper certificate and hostname validation, and protected routes. Its nonzero exit leaves the running deployment unchanged. The host acceptance smoke suite runs this command after actual signed deployment and an upgrade. This is distinct from the Compose operator; use the command corresponding to your deployment topology.

## Deployment / rollback

The existing signed update operator `deploy/container/update.py` now performs the same live gate **after** starting gateway and **before** recording the `committed` or `rolled_back` journal state. It retries transient Compose health transitions, up to 90 seconds, and fails closed if services, manifest integrity, database readiness or public HTTPS never converge. The gateway is stopped if activation fails; don't delete an unresolved journal or force a new apply. Use `recover` to finish an interrupted activation, or `rollback --yes` only after accepting loss of writes since the update snapshot. The journal remains authoritative; preserve it together with the old trusted image, keys and recovery backups.

An approved `site.env` supports `CE_LOOP_TIMEOUT_SECONDS=30..3600`, `CE_RATE_LIMIT_GLOBAL=1..1000000` and `CE_RATE_LIMIT_FORUM_SEARCH=1..1000000` alongside the existing release and site parameters. These are *literal integers*; changing them during an update is forbidden by the identity comparison. The worker/scheduler health age is now the configured maximum batch duration plus 65 seconds; on a failed batch the marker is removed immediately. Setting an excessively large batch duration weakens the ability to detect stuck work; tune based on real job timings.

The `pgsql` queue remains mandatory for the snapshot-based automatic update and automated container backups. A fresh installation can explicitly opt into `redis` queue, but its outstanding work is not included in a safe snapshot-based rollback. Do not use this operator to upgrade or back up a Redis-queue installation.

## Acceptance before calling a host production-ready

Run the project's local unit suites (`test_operations.py`, `test_update.py`, `test_container.py`) and the signed release verifier. On a **disposable** Compose environment run `smoke.py`, the destructive `qualify.py` with its exact `destroy-test-services` confirmation, and the live `operations.py` gate. For a recovery-supported PostgreSQL queue installation, run the real backup service, verify the off-site status and repeat `operations.py --require-backup`. The repository CI runs these checks in the supported queue profiles and also tests a signed 0.8.7 → 0.9.0 upgrade/rollback against a real PostgreSQL installation.

A successful local unit suite cannot certify a live environment by itself. No claim of production host acceptance or off-site restore is made unless the corresponding live scenarios have actually passed for that host and signed artifact.
