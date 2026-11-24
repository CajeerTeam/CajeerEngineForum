# Unified Queue & Scheduler (Forum Edition 1.4.5)

The forum's workflow, notification, extension, mail, image, replica, analytics, AI retention, draft, attachment, recycle-bin and anti-spam background operations now share the registered queue handlers. The existing SQL records remain authoritative; queue jobs are bounded wakeups, not copies of domain data. A failed transport no longer causes direct SQL dispatch outside the queue. The scheduler dispatches eligible work every minute; default workers also discover pending work, maintaining the existing installations that run only workers. Maintenance and periodic reconciliation tasks retain the existing scheduler ledger.

Recurring wakeups have deterministic UTC slot identities and an optional `_coalesce_key`. While a previous wakeup for the same name/key is pending, delayed or reserved, producers reuse it. Terminal jobs are retained by the existing retention policy, and a later slot can create a new wakeup. Coalescing is only for empty recurring wakeups (not independent business events or delayed payloads). Do not use it for one-off business operations. Missed slots are not replayed individually. Pending SQL rows are rediscovered after recovery. Old pre-upgrade jobs remain readable and executable; new wakeups use a distinct key namespace.

## Deployment

1. Back up storage and the database. Stop all old scheduler and worker processes before replacing files; do not mix 1.4.4 and 1.4.5 producers.
2. Apply the changed files over the installation root. The upstream `VERSION` remains 2.1.0; Forum Edition is 1.4.5 in `release.json`. No new migration or queue data conversion is required from a fully migrated 1.4.4.
3. Keep `QUEUE_DRIVER=file` for one host or use `pgsql`/`redis` under their existing durability requirements. For multi-host PostgreSQL use `SCHEDULER_DRIVER=pgsql` and shared domain storage where required. Redis requires durable persistence and no eviction.
4. Restart default workers, any named-queue workers and the scheduler. Verify:

```sh
php bin/cajeer queue:diagnostics --json
php bin/cajeer scheduler:due --json
php bin/cajeer scheduler:run --json
php bin/cajeer queue:work --queue=default --limit=10
php bin/cajeer queue:recover --all --limit=100
```

Recovery checks every discovered queue each minute (up to 100 abandoned reservations per queue per run; CLI limit 1–1000 per queue). A recovered job returns to pending or becomes failed once its attempt budget is exhausted. Normal errors use exponential retry backoff. A successful handler with an uncertain completion acknowledgement is not explicitly failed/requeued; its lease is left to expire if the acknowledgement did not commit. This provides at-least-once delivery, not exactly-once effects; external recipients and job handlers still require idempotency. Keep job timeout below the lease and bound external calls.

## Administration

In `/admin/#/system`, **Unified Queue & Scheduler** shows actual queue counts, ready-job age, reservation duration, scheduled tasks, states and last successful dispatch. Use the named queue selector to list failed job metadata (no payload, token or raw error text). Only wildcard administrators can retry failed jobs or trigger the scheduler; normal read access uses `system:read`. Manual retry resets the attempt budget; review downstream effects first. The CLI remains suitable for large queues and maintenance.

## Qualification

Run `php tools/queue-regression.php`, `php tools/runtime-queue-resilience-regression.php` and `php tools/forum-unified-queue-regression.php` (requires PDO SQLite). The Redis and PostgreSQL variants of queue-regression exercise the same coalescing and recovery behavior on their real backends. External signed release certification and staging are still required; local tests do not constitute production acceptance.
