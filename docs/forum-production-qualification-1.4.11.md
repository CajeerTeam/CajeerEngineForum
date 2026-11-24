# Forum Edition 1.4.11 — Production Qualification & Release Candidate

Executable qualification and publication gates, not a certificate of physical results. No new migration or production dependency. Forum Edition 1.4.11, engine 2.1.0. Publication on the stable channel is blocked until all gates pass.

## Disposable fixture only

Use a dedicated local Linux Docker host with cgroup v2, PostgreSQL 17+, PostgreSQL queue mode, a loopback-bound gateway, private test credentials and a TLS certificate valid throughout the soak. Never target a live forum. Disable real outbound providers and mail. Use the exact CI-signed source/dist, manifests, checksums and independently pinned publisher key. Configure the protected `forum-load-certification` environment with the paths and project variables in the physical workflows. Follow [container deployment](container-deployment.md) and [production qualification](production-qualification.md); run `deploy/container/smoke.py` to create the fixture. On this disposable fixture only set global and forum-search limits to 10000/minute. Allow ample space for corpus, indexes, WAL and logs.

| Profile | Additional verified synthetic posts | In-flight cap | Offered RPS | Minimum RPS | Duration |
|---|---:|---:|---:|---:|---:|
| 10k | 10,000 | 32 | 30 | 20 | 300 s |
| 100k | 100,000 | 64 | 50 | 40 | 1800 s |
| 1m-soak | 1,000,000 | 200 | 50 | 40 | 86400 s |

The workflow reuses the isolated fixture and adds uniquely marked corpora; the final fixture contains at least 1.11 million synthetic posts. Clients are a concurrency cap, not a claim of simultaneous activity. Each route receives an equal share of requests. Fixed limits: route p95 <= 500 ms; errors <= 0.001; no semantic mismatches; memory growth <= 512 MiB/service; publication p95 <= 500 ms; notification p95 <= 30 s. A separate writer makes a real publication every minute and performs authenticated AI status, support, analytics, integration inventory, workflow and operations probes (p95 <= 5 s). These are real HTTP reads, not live external AI generation or external delivery certification. Confirmed publications are checked through paginated API reads. Actual CPU, RAM, disk I/O and PostgreSQL telemetry must cover the full load. Evidence with weaker thresholds, short durations or inconsistent counts is rejected.

The soak requires worker kill/replay, PostgreSQL and Redis outage, PHP-FPM restart, internal network partition, isolated tmpfs exhaustion and interrupted PostgreSQL transaction rollback. Recovery of each fault must take <= 600 seconds; a clean 60-second post-fault load at the same throughput is mandatory. Rejected writes are never retried. Fault windows are separate from steady-state load.

## Release approval

1. Push to main and require the complete Forum Edition CI / `forum-required` gate, including browser/API, real DB matrices, staging and SQLite/PostgreSQL/S3 disaster recovery. Obtain the exact `forum-certified-release` artifact and record its run ID.
2. Dispatch `forum-long-qualification.yml` on that same commit with the exact signed artifact. Record its successful run ID and inspect raw reports.
3. On a separate authentic signed 1.4.10 installation prepare the candidate. Set `CE_CHAOS_SMOKE_STATE` to the existing private smoke state containing thread and reply IDs. Dispatch `forum-chaos-update.yml` with `drained` (rollback to 1.4.10); reset the old fixture and journal and dispatch with `activating` (complete 1.4.11). Both must preserve the actual acknowledged fixture reply, SSR and search. Each recovery budget is 900 seconds. Use the same signed baseline and candidate for both runs.
4. Independently approve `forum-release.yml` on the same main commit with `ci_run`, `physical_run`, `drained_run`, `activating_run`. The protected environment must enforce independent review. The publishing job fetches exact GitHub run metadata, verifies CI run attempt and existing signed CI/DR/staging evidence, independently revalidates raw physical reports and crash receipts against the CI dist SHA and commit, rechecks main, and verifies uploaded digests before making the draft public. Missing, failing or expired evidence blocks publication. Do not weaken policy to bypass it.

Run local mechanics/regression tests: `python3 deploy/container/test_rc_1411.py`, `python3 deploy/container/test_qualify.py`, `python3 deploy/container/test_chaos.py`, `python3 deploy/container/test_qualification_094.py`, `php tools/forum-qualification-regression.php`. Fixtures in policy tests are explicitly synthetic and cannot certify a release. Composer dependencies are required for actual Symfony middleware regression; Docker and signed artifacts are required for the physical tests.

## Runtime correction

Rate-limit backend/IO failure is now distinguished from genuine quota exhaustion. The global and login middleware return retryable HTTP 503 on unavailable admission storage and HTTP 429 on an exhausted quota. No Redis-to-local quota bypass is introduced. Dependency failure is recorded separately from legitimate throttling.
