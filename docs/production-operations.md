# Production Operations Stable — Forum Edition 0.9.7 (updated)

This documentation covers the production admission gate introduced in 0.9.0 and hardened in 0.9.7. No new schema migration or public endpoint is introduced in 0.9.7. Core remains 1.3.0. Apply the 0.9.7 changed-files archive only to the matching 0.9.6 checkout; rebuild and sign the complete release before deployment. The changed-files archive is not a deployable dist. The changed-files archive itself is not an installable or signed dist.

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

Run the project's local unit suites (`test_operations.py`, `test_update.py`, `test_container.py`) and the signed release verifier. On a **disposable** Compose environment run `smoke.py`, the destructive `qualify.py` with its exact `destroy-test-services` confirmation, and the live `operations.py` gate. For a recovery-supported PostgreSQL queue installation, run the real backup service, verify the off-site status and repeat `operations.py --require-backup`. The repository CI runs these checks in the supported queue profiles and retains physical upgrade/rollback regressions; the specific baseline and current release are declared in the CI workflow.

A successful local unit suite cannot certify a live environment by itself. No claim of production host acceptance or off-site restore is made unless the corresponding live scenarios have actually passed for that host and signed artifact.

## 0.9.7 operational admission and safe recovery

Use the read-only operator gate on a trusted host (never in the public web process):

```bash
python3 deploy/container/operations.py --project community-prod \
  --env-file /secure/forum/site.env --require-backup --require-integrity \
  --tls-min-valid-days 14 --backup-max-age-hours 30 \
  --report /secure/reports/forum-operations.json
```

The TLS gate validates the actual SNI certificate chain and hostname, then rejects
certificates expiring inside the operator renewal window (7 days by default).
`--tls-min-valid-days` accepts 0–90, and `--backup-max-age-hours` accepts
1–168. Off-site checks reject stale and future-dated success timestamps, pending
transfers, and a latest recovery-set ID that has not arrived off-site. They
**do not** test that the remote object can be restored; schedule independent
restore drills. The default 30-hour window assumes daily delivery; set it to
match an approved schedule. Only `--require-backup` enables the off-site gate.

`--require-integrity` runs the **real** `forum:ecosystem:check --deep` in the
running app, including full schema, content, search, ACL and extension checks.
This can be slow and can create database load: run during a maintenance window
or on a restored staging clone of a large forum. It is deliberately *not*
part of HTTP readiness or automatic update admission. Exit nonzero means the
operator must investigate, not repair or delete data automatically.

`cajeer_queue_expired_reservations` reports expired, not-yet-recovered leases
from the configured file/PostgreSQL/Redis queue without changing queue state.
`cajeer_scheduler_task_stale` reports last-success gaps for monitored tasks.
Inspect queue diagnostics, worker logs and task history before using the
existing, bounded `php bin/cajeer queue:recover --queue=default --limit=100`;
this command only releases expired reservations, and never retries terminal
failed jobs. Recoveries preserve lease fencing; do not blanket-retry jobs with
external side effects. Alertmanager firing **and resolved** delivery is tested
by the disposable HTTPS receiver integration, including a monitoring-service
restart while an alert is active. Verify escalation and receiver credentials
against each actual production endpoint separately.

To recover after an unclean deployment restart: verify the persisted update
journal, restore the exact signed image, use the operator `recover` command if
needed, then run the read-only operations gate and inspect expired leases.
Never delete journal/lock files or disable readiness to force traffic.

The independent monitoring stack also probes the actual public HTTPS
certificate using Blackbox Exporter and alerts on less than 14 days validity.
This catches expiry without waiting for a manually invoked operations gate;
monitor the monitoring host itself and verify that notifications reach the
intended operator receiver.
