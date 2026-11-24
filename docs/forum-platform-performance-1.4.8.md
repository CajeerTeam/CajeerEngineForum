# Platform Performance & Scalability — Forum Edition 1.4.8

This update changes the executing SQL, cache, analytics, queue and metrics paths. No new migration, queue format or dependencies. Existing ACL checks, exact totals, soft-delete rules, lease ownership and transactional analytics semantics are retained.

- **Search:** single-source candidates have unique post/thread IDs; omit the partition ranking sort, retain exact total and page order. Multi-source ranking remains unchanged.
- **Notifications:** each outbox index supplies at most the requested number of pending rows; merge and limit both branches, rather than sorting the entire backlog. Conversation alerts use one conditional aggregate for muted and total unread messages.
- **Analytics:** up to 100 daily groups per upsert, up to 500 event IDs per marker update. All chunks, counters and state commit together, or roll back together. The retention policy is unchanged.
- **Redis cache:** lookup and hit/miss counters share one Lua round trip; statistics failures do not prevent reads. Cache TTL varies between 85% and 100% of the configured (5–300 s) TTL. SQL generations and ACL protections remain authoritative; caller-owned transactions bypass public representation caching.
- **File queue:** reuse pending records already decoded by recovery while holding the same lock. Rescan only if recovery changed state or a delayed job became ready during the scan; no new index files or lost reservations.
- **Redis queue:** connect only when a queue operation is needed; reconnect on the next independent operation after failure, never replay unconfirmed transitions. Diagnostics pipeline up to 32 queues at a time.
- **Observability:** bounded labels and rolling histograms remain; SQL samples no longer force synchronous writes every 128 observations. HTTP completion, scheduler and worker boundaries flush buffered samples. The shared lock-wait budget per metrics flush is 50 ms; metrics remain best-effort, not a business ledger.

## Deployment and acceptance

Stop workers/scheduler, back up and overlay the patch on 1.4.7; restart PHP-FPM and background services. No migration is needed. Review the existing `ops:report`, Prometheus and `forum:performance:check` diagnostics. Keep Redis on a private trusted network with its existing ACL and TLS/network policy. The cache uses EVAL, as did the previous lock-release path.

Run the new SQL and PHP regressions and existing `forum-required` CI gates, including real PostgreSQL/MySQL/Redis, HTTP load, queue and analytics tests. On staging, use the same traffic profile before/after: mix anonymous cache hits/misses, authenticated unread counts, search, publication, AI requests and analytics/worker processing. Track p95/p99 HTTP latency, SQL execution and waits, Redis latency/hit ratio, ready-job age and analytics lag. No throughput or latency SLA is asserted without this measurement. Telemetry may drop samples during contention or process termination; business durability is independent.
