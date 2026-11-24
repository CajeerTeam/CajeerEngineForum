# Unified Observability & Diagnostics — Forum Edition 1.4.6

## Upgrade and operation

Back up the installation and stop PHP workers and scheduler before applying changed files. Restart PHP-FPM, workers and scheduler afterward. `VERSION` remains engine 2.1.0, `release.json` identifies Forum Edition 1.4.6. There are no schema changes and no new dependency. Existing 1.4.5 cumulative telemetry files are compatible; the recent view starts collecting on upgrade.

- System ACP → Operations: existing `system:read` permission, checks and alerts, recent component table with per-operation details, manual refresh and optional 30-second auto refresh. The protected `/api/v1/system/operations` reuses the current 15-second probe cache (up to 60 seconds under contention), with an explicit sample time. A maintenance lease suppresses dependency inspection.
- `php bin/cajeer ops:report` — fresh sanitized JSON diagnostics. `ops:check` tests readiness; `ops:metrics` emits Prometheus text. `ok` continues to mean readiness; nonessential provider or background problems yield `status: degraded` and alerts, not a public HTTP readiness outage.
- `/health/live` and `/health/ready` retain existing semantics. `/metrics` remains disabled unless `OBSERVABILITY_METRICS_TOKEN` is at least 32 characters; configure a dedicated bearer token and restrict access. Load `resources/observability/prometheus-rules.yml` alongside `forum-rules.yml` in the existing Prometheus installation.

## What is actually measured

| Component | Signals |
|---|---|
| HTTP / forum | Existing fixed route histograms, status class, SSR/search/publication summaries |
| SQL | Existing PDO connection, statement and transaction timing, database readiness and forum SQL backlog |
| AI | OpenAI Responses transport attempts including preflight rejection, rate limit and failures; passive credential configured/transport availability; live SQL admission active/queued/expired and cooldown |
| Analytics | Native read/page receipt recording and rollup execution; claimed external report synchronization; actual pending native event count (capped at 10001), oldest age and external connection failure totals |
| Integrations | Provider execution, signed webhook delivery and outbound HTTP transport; active integration count and failed last-run count, no remote probe requests |
| Redis | Connection, ping, queue transition/diagnostics, cache read/write/delete; cache hit/miss, expired reservation count based on Redis server time |
| Worker and scheduler | Job outcomes including lost reservations, worker loop outcome and heartbeat, scheduler task durations and runner outcome; stale/failed built-in task ledger, including enabled update and trace tasks |

The historic cumulative Prometheus histograms remain unchanged for HTTP, SQL, scheduler and jobs. New histograms follow `cajeer_{ai,analytics,integration,redis}_duration_seconds` with bounded `operation` and `outcome` labels. Existing unknown job or route names collapse to bounded categories. Outcome `rate_limited` and `rejected` are recorded separately from transport failures; cache misses, skipped operations and HTTP 4xx are not infrastructure failures. `recent.rejected` includes HTTP 4xx and AI/provider rejection/throttling; the latter count as failed operations. Metrics are operation attempts at different layers, not a count of unique user requests; do not sum layers. A worker batch and its individual jobs are both observed. Native event instrumentation counts read/page receipt attempts, not committed business mutations inside a parent transaction.

The recent table covers the current partial five-minute UTC bucket and eleven preceding buckets (55–60 minutes). Each metric series holds at most twelve recent buckets when updated; expired buckets are ignored when read. No observations produce `null` latency/error percentage, not a synthetic zero. Old cumulative rows are not backfilled into the recent window. Recent alerts require at least 5 observations, at least 3 failures and a failure ratio of at least 20%. New gauges are `cajeer_recent_operations`, `cajeer_recent_operation_failures`, `cajeer_analytics_*`, `cajeer_ai_admission_*`.

## Privacy, failure and multi-host scope

No prompts, model names, API keys, SQL text, parameters, raw HTTP URLs, user IDs, integration IDs, Redis keys or error messages are used as new telemetry labels or fields. Provider checks are passive: no paid diagnostic AI calls or unexpected outbound requests. Configured credentials are not proof of external provider availability; zero observations are not proof of health. Optional Redis that is disabled does not raise an alert. Required Redis remains part of readiness.

Telemetry is best effort, buffered up to 128 observations per process/root, flushed at normal shutdown or worker/scheduler batch completion. A forced process kill may lose unflushed metrics, never business data. Existing bounded locks and atomic metric shard publication remain. The recent view is local to the configured storage root; scrape each deployment instance with distinct Prometheus instance labels. Do not share or sum a single metrics store across multiple scrape targets. Worker marker represents the last local worker batch, not a census of individual processes. SQL and Redis probes query configured shared backends. Optional existing OTLP trace export now includes bounded AI/analytics/integration span types with parent correlation; no content is exported. Set `OBSERVABILITY_OTLP_ENDPOINT` and existing sampling/token settings only when a trusted collector is available.

## Validation

`php tools/unified-observability-regression.php` exercises real safety rejection and webhook validation, recent-window expiry, legacy cumulative rows, corrupt report cache recovery, privacy and trace correlation. Add `--database` to use actual PDO SQLite migrations, AI admission and native analytics rollup. `php tools/queue-regression.php --redis` exercises real Redis expiry and metric export in CI. The existing required multi-database and browser acceptance gates remain mandatory for signed publication. Live OpenAI/provider service tests need operator-controlled credentials and staging; no paid provider call is made by the diagnostic suite.
