# Forum Observability & Monitoring (current 0.9.8)

The Forum Edition extends the existing protected `/metrics` endpoint, `ops:report`, and **System** (`/admin/#/system`) in ACP. Engine version remains 1.3.0. Queue monitoring was introduced with migration 0048; an existing 0.9.6 installation already contains subsequent migrations through 0049. The four indexes cover replica state/due time, review/report status and creation time, and import checkpoint state/time. Index creation can take time and locks on large databases: use the normal maintenance/backup/update procedure, not an online migration claim.

## What is measured

- `cajeer_forum_request_duration_seconds`: aggregate of the real HTTP route histograms for `ssr` (`forum.home`, `forum.page`, `public.home`), `search` (forum search API), and `publication` (thread creation, replies, post editing). No second measurement or double count. The `outcome` label is the HTTP status class, not a database commit or successful moderation decision. Grafana plots successful 2xx p50/p95/p99 over a five-minute rate window. ACP shows cumulative counts and upper histogram bucket bounds since counters were last reset. A 202 review submission counts as an HTTP success, not a confirmed published post.
- `cajeer_forum_queue_items{queue=...}` and `cajeer_forum_queue_count_capped`: fixed SQL queues (notifications, accepted-answer notifications, mail, extension events, media, replicas, moderation submissions, reports, dead mail/extensions, failed media, running imports). Values cap at 10001; `capped=1` means **at least** 10001. Never use this as an exact total above the cap. No per-user, per-board, per-job, or raw URL labels.
- `cajeer_forum_queue_oldest_age_seconds`: elapsed age for notification/moderation timestamps, delay from the earliest positive due time for scheduled work (future due time yields 0), or age of the oldest running import checkpoint. Legacy media with `due_at=0` is counted but has no known age; it does not mean 1970. Pending includes leased work and future scheduled digests. This is not a count of eligible reservations.
- `cajeer_forum_sample_timestamp_seconds` and `cajeer_dependency_up{component="forum_database"}`: actual SQL sample time and probe status. A failed sample has no queue values, not fake zero counts. SQL uses read snapshots, indexed bounded counts, a four-second cooperative budget and PostgreSQL per-statement timeout. The separate existing core queue probe may still examine full queue history. Tune database timeouts and retention for unusually large installations.
- `cajeer_forum_postgres_connections`, `cajeer_forum_postgres_max_connections`, `cajeer_forum_postgres_lock_waiters`: PostgreSQL server-wide client connection count/limit and visible lock waits from `pg_stat_activity`. PostgreSQL limits visibility of other roles' details; do not grant superuser to the app. For comprehensive cluster-level visibility use a separate restricted exporter/monitoring account. These metrics are omitted on SQLite/MySQL.
- `cajeer_forum_cache_requests_total{result="hit|miss|coalesced"}`, `cajeer_forum_redis_used_memory_bytes`, `cajeer_forum_redis_maxmemory_bytes`: actual namespaced cache counters and Redis server memory from existing runtime cache diagnostics. Coalesced is a miss followed by another worker's cache fill, not a separate request; do not sum all three as a hit ratio denominator. `maxmemory=0` means no Redis limit. Disabled cache emits no counters; outages emit a failed dependency probe and omit stale values. Redis rate-limiting/queue dependency status is also in the existing core probes.
- `cajeer_dependency_probe_duration_seconds`, `cajeer_worker_last_success_timestamp_seconds`: observed dependency duration, successful worker completion (not just heartbeat). Failed cycles do not reset last success. Existing HTTP, job, SQL and scheduler metrics and JSON request/trace logs are preserved.
- Existing backup and automation timestamps, pending off-site bundles; new `cajeer_forum_recovery_duration_seconds`, `cajeer_forum_recovery_snapshot_age_seconds`, `cajeer_forum_recovery_objectives_met` from the verified cold recovery receipt. Recovery metrics are absent until a receipt exists; they are not periodic DR drill evidence. Backup timestamp means last verified local copy, off-site timestamp means verified remote copy. Alert thresholds use the default daily schedule; adjust for another policy.

The report is cached for 15 seconds; a concurrent sampler can reuse a report at most 60 seconds old. Maintenance or an exclusive application lock prevents new SQL probes. The HTTP histograms persist in `storage/logs/observability` and can reset after restore, reinstall or explicit removal; Prometheus `rate()` handles counter resets. Shared storage aggregates PHP processes on a host: scrape **one endpoint per shared metric store**; scraping multiple replicas that share it duplicates rates. Independent per-host stores can be scraped with separate targets. Metrics are telemetry, not an audit ledger.

## 1. Verify application-side monitoring

Use the installed forum and its existing dedicated `OBSERVABILITY_METRICS_TOKEN` (at least 32 characters). The Linux and Compose production installers generate one. If absent, generate a random token (e.g. `openssl rand -hex 32`) and set it in the private `.env`; restart long-lived processes. Do not put it in web assets, URLs or the shell history. Set up a separate secret file, not the complete `.env`.

```bash
php bin/cajeer migrate
php bin/cajeer migrate:status
php bin/cajeer ops:report --json
```

`/health/live` and `/health/ready` are existing probes. `/metrics` returns 404 if disabled and 401 for absent/wrong authorization. Use TLS; never send a metrics token over public HTTP. For an authenticated check, put the `Authorization: Bearer TOKEN` header in a protected curl config file, then `curl --fail --config /secure/metrics-curl.conf https://forum.example.com/metrics`. `GET /api/v1/system/operations` requires `system:read`; it does not return the metrics token. Open `/admin/#/system` (or Forum ACP → Maintenance → Monitoring for global admins), refresh manually or opt into 30-second refresh. No root privileges or shell execution are exposed to ACP. If an alert shows only a failed probe, use `ops:report` and private logs for diagnosis.

The Linux and container gateways can block **all** public traffic during maintenance (or stop entirely during an update). In that case Prometheus `up=0` is expected; use scheduled Alertmanager silences for known maintenance. Do not disable the deployment gate to expose metrics. Once the gateway is back, scrape resumes. Independent monitoring is deliberately not run inside application PHP-FPM.

## 2. Start the monitoring stack (separate host recommended)

The Compose deployment contains Prometheus, Alertmanager and Grafana. It works against any reachable TLS Forum Edition URL; no Docker socket, forum SQL password, or forum storage is mounted. It does not expose Prometheus/Alertmanager/Grafana publicly: the published ports bind to loopback. Run with Docker Compose v2 on a trusted Linux monitoring host. Images use explicit tested-target tags in the compose file; mirror/pin digests according to your supply-chain policy.

Create a private directory `/srv/forum-monitor-config` (0700, outside the repository). The following three files contain only their corresponding value and an optional trailing newline:

| File | Contents | Container reader UID (rootful Docker) |
|---|---|---|
| `metrics-token` | Existing forum metrics token | 65534 |
| `alert-webhook` | HTTPS URL of your operator's Alertmanager-compatible webhook endpoint | 65534 |
| `grafana-password` | Unique strong Grafana admin password, at least 16 characters | 472 |

Set file permissions 0400 or 0440 with appropriate group; no world access or group write. Run configuration as the operator who can read them; chown the files to the runtime UIDs after configuration if needed. With rootless Docker map the container UIDs to host IDs and verify readability. The HTTPS webhook receives standard Alertmanager JSON (`firing`/`resolved`, labels and annotations); use a real on-call receiver/bridge that understands that format, not an arbitrary email/Telegram URL. URL credentials belong in its protected file, not the Compose environment. Custom TLS CA bundle must trust **both** the forum and webhook server (and their intermediate chains); system CA bundle is the default. No `insecure_skip_verify` option.

```bash
sudo python3 deploy/monitoring/configure.py \
  --config-dir /srv/forum-monitor-config \
  --target forum.example.com:443
# optional: --ca-file /secure/combined-ca.pem
# run as root for this example, preserving file secrecy
sudo chown 65534:65534 /srv/forum-monitor-config/metrics-token /srv/forum-monitor-config/alert-webhook
sudo chown 472:472 /srv/forum-monitor-config/grafana-password
sudo chmod 0400 /srv/forum-monitor-config/{metrics-token,alert-webhook,grafana-password}
export CE_MONITOR_CONFIG_DIR=/srv/forum-monitor-config
docker compose -p forum-monitor -f deploy/monitoring/compose.yaml config --quiet
docker compose -p forum-monitor -f deploy/monitoring/compose.yaml up -d
```

Set `CE_MONITOR_CONFIG_DIR` in the operator environment used by Docker (if sudo is needed, preserve only this variable). Use `CE_PROMETHEUS_PORT`, `CE_ALERTMANAGER_PORT`, `CE_GRAFANA_PORT` for loopback port overrides. Default URLs on the monitoring host are `http://127.0.0.1:19090`, `:19093`, `:13000`. Use SSH tunnels or a separately secured reverse proxy for remote access; do not bind them publicly without authentication and TLS. Grafana login `admin` uses the protected password only for initial creation; change it using Grafana's password workflow after initialization. Data volumes persist across `down`/`up`; **do not use `down -v` on production**. Prometheus keeps 30 days or 10 GB (whichever is reached first); Grafana has a persistent database. Keep independent backups of monitoring configuration and volumes if required.

In Grafana open the **CajeerForum** folder: `Forum · HTTP & infrastructure` and `Forum · Queues, backup & recovery`. The datasource is provisioned with UID `cajeer-prometheus`. On a new installation some panels correctly show no data until the relevant operations occur. Check Prometheus Targets and Rules, and query `up{job="cajeerengine"}`, `cajeer_forum_sample_timestamp_seconds` and `cajeer_forum_queue_items` before accepting the deployment. Confirm Alertmanager routing and receiver delivery; a firing Prometheus alert is not proof that a notification arrived. The test runner below covers a real HTTPS receiver using the actual exporter.

To change configuration/keys, recreate the monitoring containers (`up -d --force-recreate`) after atomically updating configuration and file permissions: file bind mounts may refer to old inodes. Rotate the forum token and monitoring token together; during rotation a short scrape outage is expected. The Grafana UI admin password is stored in its database after first initialization, not automatically rotated by replacing the secret file.

## 3. Alerts and operator action

`resources/observability/prometheus-rules.yml` retains engine alarms; `forum-rules.yml` adds failed/stale forum samples, successful search/publication/SSR latency, overdue forum queues, dead letters, capped counts, moderation age, import checkpoint age, PostgreSQL pressure/locks, Redis memory/unavailability, successful worker age, local/off-site backup age, recovery objectives and disk percentage. Thresholds are starting values (p95 300ms SSR, 500ms search/publication, queue delay 300s, worker success age 300s, daily backups). `for:` delays prevent brief transients from paging; compare observed ranges and SLOs before changing. Pending moderation is a workflow alert, not a server crash.

- Dependency down: check actual service, connectivity, credentials, locks and maintenance; never expose metrics or DB to the public as a workaround.
- Queue delay/dead letters: inspect worker logs and `forum:extensions:check`, the existing queue diagnostics and failed-job API; fix handler errors before retry. Avoid blanket retries of external side effects.
- PostgreSQL waits/connections: inspect `pg_stat_activity` with a separate monitoring role; do not terminate sessions automatically. Redis memory: inspect limits and cache namespace; do not flush the server or remove durable queues blindly.
- Backup stale: inspect `backup:automation:status`, verify local and off-site copy; restore drill and cold recovery are documented in `automated-backup.md` and `disaster-recovery.md`. Absence of off-site/recovery series means no configured/recorded observation, not success. For an installation requiring off-site backup, configure the timer and watch `backup_automation`/missing series explicitly.
- Import stalled: inspect `forum:import:status` and the operator log. Finish/recover the import before removing maintenance.

## 4. Tests

```bash
php tools/observability-regression.php --database
php tools/forum-observability-regression.php --database
python tools/forum-tests/observability_sql_regression.py
python deploy/monitoring/test_monitoring.py
# disposable rootful Linux Docker host, PHP PDO SQLite and openssl
python deploy/monitoring/test_monitoring.py --integration
```

The final integration runs actual Prometheus, Alertmanager and Grafana containers, tests the unchanged rules with promtool (firing and resolution), scrapes an authenticated HTTPS proxy to a disposable real PHP/SQLite exporter, checks actual SQL metrics and Grafana provisioning, and receives a firing and resolved webhook. It uses shortened test-only evaluation/routing intervals. It is a required CI job, not a claim that the test ran on every production host. Production throughput, cross-host clocks, TLS network paths, receiver escalation and SLOs still require site-specific qualification.

## 0.9.8 operator metrics and restart proof

New queue metric `cajeer_queue_expired_reservations` counts unexpired-vs-expired
leases without running recovery. `cajeer_scheduler_task_stale{task=...}` exposes
stale successful task cycles. Prometheus alerts now detect expired leases,
stale scheduler tasks, and missing essential worker/queue/off-site series while
the exporter still scrapes successfully. **Missing telemetry is not zero.**
The HTTPS Prometheus→Alertmanager integration keeps its test-only receiver up
while restarting Prometheus and Alertmanager after firing, then proves a
resolved event is delivered. This exercise is destructive only to disposable
monitoring containers. For production TLS certificate expiry and backup
freshness, use the independent read-only operator gate in
[production operations](production-operations.md); exporters do not know the
public site's certificate chain lifetime by themselves.

### Public TLS certificate monitoring

0.9.8 adds a private `prom/blackbox-exporter` service and a generated
`blackbox.json`. The exporter independently probes
`https://<approved-target>/health/ready` with SNI/hostname and the protected
CA bundle. Its loopback-only Prometheus scrape exposes `probe_success`,
`probe_ssl_earliest_cert_expiry` and `up{job="forum-tls"}`. The corresponding
alerts fire for expiry inside 14 days, failed HTTPS/TLS checks or an unavailable
probe exporter. No blackbox port is published to the internet. Recreate the
monitoring containers after regeneration to apply updated bind-mounted config.
To qualify a production setup, validate DNS routing from the monitoring host,
CA rotation and actual receiver delivery. The separate operator command can
set its own TLS renewal threshold and must be run on an external schedule.
