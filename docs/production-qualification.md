# Production Reliability & Qualification (established in 0.8.7, expanded through 0.9.5)

**Disposable environments only.** Qualification is destructive: it seeds synthetic records, publishes replies, temporarily hides a category and kills/stops services. Never run against an existing community. Use a signed, verified 0.9.5 dist, not the historical 0.8.7 example ZIP; see [release certification](production-certification.md) and the [0.9.5 chaos safety procedure](forum-chaos-0.9.5.md). The signed release, Compose project, TLS CA, admin credentials and smoke state must belong to the same isolated installation. Use a dedicated host/project and no real SMTP/webhook credentials. Stop any backup/update jobs during the experiment. Do not interpret a synthetic or SQL-only test as certification of a production host.

## Short mandatory profile

Build/sign the dist and install the Compose stack as in `container-deployment.md`. On a fresh dedicated test installation set `CE_RATE_LIMIT_GLOBAL=10000` and `CE_RATE_LIMIT_FORUM_SEARCH=10000` in site.env before first init (per-IP budgets, not an auth bypass). These optional trusted installer settings are persisted and checked on restart; do not raise public limits to these values without your own abuse analysis. Run `deploy/container/smoke.py` first to create the test fixture. Then:

```sh
python3 deploy/container/qualify.py \
  --project forum-container-ci --env deploy/container/site.env \
  --domain forum.test --port 18443 --ca /secure/tls.crt \
  --email container-admin@example.invalid --password-file /secure/admin-password \
  --state /secure/container-state.json --version 0.9.5 \
  --disposable destroy-test-services --clients 8 --seconds 60 --rate 10 \
  --min-rps 5 --max-p95-ms 1000 --max-error-rate 0.001 \
  --seed-posts 10000 --faults --report /secure/qualification.json
```

Both PostgreSQL and Redis queue modes run this scenario in mandatory `container-runtime` CI. The runner checks the exact local HTTPS port and application identity, uses the real PostgreSQL schema (including FTS and revisions), performs concurrent publications through the actual API, validates paginated posts, measures real SSR/search/API/ACP requests, and tests guest ACL after cache warmup. It stores an atomic, machine-readable report even on failure. No tokens, SQL credentials or response bodies are recorded. Separate fault windows are not counted as normal SLO traffic. A rejected write is never automatically retried. The exact confirmed IDs and bodies are compared, and deliberately rejected writes must be absent. After stopping PostgreSQL or Redis it waits for 503 readiness and checks that writes fail closed; after restart it requires readiness and verifies content again. Worker SIGKILL/stop, publication with no worker, and recovery of its notification exercise durable work. The worker may be forcibly stopped; the test does not claim an exactly-once external side effect. Every fault must recover or the test fails. The final deep ecosystem check is executed by the real CLI.

## Large and 24-hour profiles

On a dedicated suitably provisioned cgroup-v2 Linux Docker host, use the new **0.9.5 physical certification workflow** to run three enforced profiles (10k, 100k, 1M/24h). See [the 0.9.4 load procedure (expanded by 0.9.5 chaos)](forum-load-0.9.4.md) for full commands, per-route latency, PostgreSQL/CPU/RAM/I/O measurements and fail-closed signed-evidence acceptance. The operator-run 1M profile uses `--profile 1m-soak --seed-posts 1000000 --clients 200 --seconds 86400 --rate 50 --min-rps 40 --max-p95-ms 500 --max-growth-mib 512 --max-queue-p95-ms 30000 --faults --chaos`. Adjust tested rate, pool size, memory and latency targets to a documented host; `clients` is maximum in-flight requests, not proof that 200 requests are simultaneously active. The HTTP generator uses pacing, bounded futures and fixed-size histograms; p95/p99 are conservative bucket upper bounds. One real reply is posted every minute during long runs, in addition to the initial concurrent burst; production admission/moderation remains enabled. Corpus creation uses bounded SQL transactions, complete thread/post/revision records and the production indexes/triggers; it deliberately bypasses notifications and ordinary publishing for historical synthetic data. A separate local SQL-only scale regression is available:

```sh
python3 tools/forum-tests/reliability_sql_regression.py --threads 10000 --posts-per-thread 100 --workers 8 --operations 1000 --report /secure/sql-qualification.json
```

This creates a temporary SQLite database, verifies 1M rows and FTS/revision parity, performs concurrent writes and reads and kills an uncommitted writer. It is not PostgreSQL or HTTP performance evidence. Use a fresh installation for each full run. Ensure adequate disk for corpus, indexes, WAL, backups and logs. The full 24-hour/1M PostgreSQL profile is an operator-run acceptance, not an automatic promise of passing SLO. Measure with real hardware and record CPU, RAM, disk, image IDs, version, policy, traffic targets and report. Memory growth compares warmed baseline with sampled cgroup memory, including filesystem cache; it is a conservative resource budget, not a precise heap leak detector. Sampled values can miss transient peaks. Fault recovery durations include service stop/start. Review PostgreSQL, Redis, PHP logs, metrics, backup history and restore evidence as part of qualification. Existing production-host, signed upgrade/rollback, backup-S3, cold recovery and monitoring CI jobs remain mandatory. Do not claim full certification until the actual reports pass.

## Runtime hardening

- Readiness checks mandatory Redis (distributed rate limiting or queue). Cache-only Redis can degrade independently. Redis admission outages return 503 without bypassing the distributed quota; quota exhaustion still returns 429.
- Metrics lock waits are bounded per shard (250ms); a contended sample may be dropped rather than blocking a business response. Shard updates use atomic rename without synchronous disk flush; crash can lose telemetry, never committed business data. A later uncontended sample is recorded normally.
- Container background loops invalidate stale success on failure, exponentially back off to 60s, and bound a complete CLI batch with GNU timeout (600s default; CE_LOOP_TIMEOUT_SECONDS 30–3600 when configured for the worker process). TERM is forwarded; after 15s the watchdog kills an unresponsive child. Existing durable job leases govern recovery. Graceful Docker stop can still terminate active work; idempotence and at-least-once contracts remain.

No new schema migration in 0.9.5 (existing 0049 remains required). The updater, signed packages, systemd profile, backup and recovery formats remain compatible. A full 0.9 qualification additionally requires long-profile and recovery results on representative hardware; it cannot be inferred from short CI or local tests.

The existing Linux/systemd host smoke also runs a 60-second paced HTTPS workload against the real forum, in addition to its original 400-request acceptance and rollback checks. The disposable host raises both global and forum-search quotas; production defaults are unchanged.
