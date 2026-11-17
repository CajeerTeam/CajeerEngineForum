# Forum Edition 0.9.4 — Physical load & soak certification

This release **implements a runnable physical qualification procedure**. It does not claim that 100,000 / 1,000,000 PostgreSQL messages or 24 hours of running the forum were measured on the developer's machine. Passing **short GitHub Actions** is **not** a 24-hour capacity certificate. The independent physical run must complete and its evidence must be reviewed before making such a claim.

## Safety and prerequisites

- Use a **dedicated, disposable** PostgreSQL 17+ / Redis Docker Compose installation with no real community data, SMTP recipients, or webhook destinations. The script publishes, temporarily revokes ACL and stops services with `--faults`. Production is forbidden. The `--disposable destroy-test-services` confirmation is necessary but insufficient: review the selected project, files, network and volumes beforehand.
- Prepare a signed 0.9.4 source/dist candidate using the **independently approved Ed25519 public key**, check that the running `CE_RELEASE_SHA256` in `site.env` matches the signed dist, and keep the source checkout at **the same Git commit** as the candidate. `git` and `php` are required for evidence binding. Keep all signing secrets off the runner.
- Provision the custom `forum-benchmark` self-hosted GitHub Actions runner on that Linux host, protected `forum-load-certification` environment, and the path variables listed in `.github/workflows/forum-long-qualification.yml`: `CE_QUAL_PROJECT`, `CE_QUAL_ENV`, `CE_QUAL_DOMAIN`, `CE_QUAL_PORT`, `CE_QUAL_CA`, `CE_QUAL_EMAIL`, `CE_QUAL_PASSWORD_FILE`, `CE_QUAL_STATE`, `CE_QUAL_SIGNED_DIR`, `CE_QUAL_TRUSTED_PUB`. The project name must contain `load`, `qualification`, or `benchmark`; TLS must terminate at the selected loopback port. These paths and files are operator-provisioned; the workflow **does not install or destroy** the Compose installation.
- The Docker host needs cgroup v2 (`cpu.stat`, `memory.current`, `io.stat`) and enough free SSD, CPU and RAM to store **at least 1,110,000 additional synthetic messages and their revisions**, indexes, WAL, metrics and backups (10k + 100k + 1M runs on the same disposable installation). Run a separate warm-up smoke installation first: `deploy/container/smoke.py`. Do not run backup/update operators during the test. The test-specific rate limits must be configured during initial installation as described in `production-qualification.md`.

## How the physical workflow operates

Trigger **Forum 0.9.4 physical load and 24-hour soak certification** manually from GitHub Actions. It runs, in order, on the *same* host/release image:

| Profile | Inserted PostgreSQL posts | Sustained HTTP duration | Concurrent pool | Target RPS | Minimum measured RPS |
|---|---:|---:|---:|---:|---:|
| `10k` | 10,000 | 300 s | 32 | 30 | 20 |
| `100k` | 100,000 | 1,800 s | 64 | 50 | 40 |
| `1m-soak` | 1,000,000 | **86,400 s (24 h)** | 200 | 50 | 40 |

The synthetic PostgreSQL corpus uses actual forum tables, posts, revisions, and FTS triggers. These inserts **bypass** normal publication events; they are not claimed as a measured 1M-post publishing throughput. The HTTP workload uses verified real TLS and semantically checked forum SSR, thread API, PostgreSQL FTS, corpus SSR, corpus FTS, and authenticated ACP. Eight simultaneous writes and one additional real write every 60 seconds exercise normal publication and cache/ACL behavior. The registered watcher receives real notification events. A separate polling thread measures **acknowledgement-to-notification-visibility latency** with one-second polling resolution. This is end-to-end notification latency, **not** internal broker processing time or external email-delivery latency.

Stats include per-route fixed-memory **p50/p95/p99 upper latency bounds**, requests, status/semantic errors, throughput, queue delivery and publication p50/p95/p99, cgroup-v2 CPU consumption, memory peak and growth, per-service disk reads/writes/I/O operations, PostgreSQL xact/read/cache/temp/deadlocks/WAL/database size, active sessions, lock waits, pending durable jobs and notifications and their oldest ready ages. A dedicated periodic sampler avoids perturbing the HTTP pacer. Counters are deltas from the warmed baseline, **not** full-host capacity measurement; sampling can miss short spikes. Resource observations and per-operation histograms are included in an atomic JSON report. The 24-hour profile also tests worker SIGKILL, PostgreSQL/Redis outages and recovery **after** the uninterrupted HTTP measurement window.

The operator-selected profile budgets currently require route p95 ≤ 500 ms (conservative histogram buckets), error rate ≤ 0.1%, warmed memory growth ≤ 512 MiB *per service*, and notification delivery p95 ≤ 30,000 ms. Those are proposed gates tied to the selected test hardware; they are **not measured outcomes**. Each route must have real requests. Captured IDs and bodies of acknowledged publications must survive faults; ACL changes must remove data from both SSR and search caches and their restoration must be visible. A failure, missing cgroup/PG data, failed sample, incomplete run or broken profile fails the test.

## Evidence acceptance

Each run writes a private `10k.json`, `100k.json` or `1m-soak.json` on the runner and uploads them even on failure. **Only** after all three succeed, the workflow executes:

```bash
python3 deploy/container/certify_load.py \
  --release-dir /secure/signed-candidate --trusted-public-key /secure/trusted-release.pub \
  --small /secure/10k.json --medium /secure/100k.json --soak /secure/1m-soak.json \
  --version 0.9.4 --output /secure/load-certified.json
```

The verifier invokes the **existing cryptographic Ed25519 release verifier**, compares `SHA-256` of the certified signed dist and exact Git SHA with all three source reports, enforces version, same host/image, mandatory checks, duration, RPS, all route histograms, performance budgets, publication/queue measurements, complete sampled telemetry without gaps and the required corpus sizes. It records SHA-256 of each physical report in the final immutable-by-convention output. It never creates passing evidence from zero, and there is **no substitute dry-run mode**. Independently protect/retain the report files, logs, signing trust anchor, hardware inventory and operator approval; report hashes bind files but are not third-party attestation or proof against a malicious runner.

A 0.9.4 source-code release is **not** certified for real production traffic without successful reports from representative hardware. The default `forum-required` short CI is mandatory for code/release correctness; the operator-triggered 24-hour workflow is a separate **pre-1.0 production certification gate**, not silently claimed by `forum-required`.

No new database migration is added after 0049. CajeerEngine core remains 1.3.0. Rollback/upgrade, disaster recovery and external penetration tests remain distinct certification steps.
