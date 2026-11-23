# Analytics Aggregation & Retention — Forum Edition 1.2.8

Production server-side aggregation for the **existing, first-party, anonymous** events introduced in Forum 1.2.2/1.2.7. This is implemented with database transactions and a running queue worker, not just configuration or an analytics endpoint. External providers (GA4, Yandex, Matomo, Plausible, Cloudflare and GTM) remain independent.

## Upgrade from 1.2.7

1. Back up and verify the forum database. **Stop all old 1.2.7 queue workers and the scheduler before the schema/code cutover**: their old unconditional 90-day cleanup must not run against a backlog awaiting 1.2.8 aggregation. Deploy the 1.2.8 changed files using the approved release workflow.
2. **Apply migration 0082** for your configured primary driver *before* restarting application or queue processes:
   - `migrations/pgsql/0082_forum_analytics_rollup.sql`
   - `migrations/mysql/0082_forum_analytics_rollup.sql`
   - `migrations/sqlite/0082_forum_analytics_rollup.sql`
   Use the existing engine migration runner so migrations are recorded/checksummed. Do not run the three driver variants against the same database.
3. Restart PHP workers and **only 1.2.8 worker instances**, then the scheduler/queue. The minute-based `forum.analytics.rollup` job automatically processes pre-upgrade 1.2.7 events, then subsequent events, in transactions. Pending `forum.analytics.cleanup` queue entries are handled by the new worker; do not allow 1.2.7 worker binaries to consume them during the cutover. A large pre-upgrade backlog may require multiple ticks to drain; raw data is not deleted before being applied.
4. Open **Forum ACP → Собственная статистика**, choose 7/30/90/365 days, verify the number of unprocessed events trends down. Verify daily totals match the original event ledger during backfill. The report includes unprocessed events immediately, even before the first worker tick.
5. If worker scheduling is suspended, an operator with `forum:acp:maintenance` can run **Обработать до 1000 событий** repeatedly via ACP or restore normal processing. Monitor queue failures and DB lock contention.

## Database schema and exactly-once processing

- `ce_forum_analytics_events` keeps the existing random event ID, fixed event name and timestamp, plus nullable `aggregated_at`. NULL means **pending**, including all pre-migration 1.2.7 rows; a non-NULL value means durably applied to the daily counter.
- `ce_forum_analytics_daily(day,event_name,total,updated_at)` contains a unique `(UTC day start, event name)` counter. It cannot identify a visitor, source URL, forum post, account, search term or personal action.
- `ce_forum_analytics_state` has exactly one lock row, used as a database transaction lock across aggregation, recalculation and pruning. No Redis lock or volatile cursor is needed for correctness.
- Each bounded aggregation batch increments the relevant day counters and marks the **same selected raw event IDs** applied within one SQL transaction. Any failure rolls back **both** operations; a retried job sees only unapplied IDs. Concurrent forum writes are safe: newly inserted rows stay pending until selected. PostgreSQL, MySQL/MariaDB and SQLite use their native transactional locking and upsert forms.
- Reports read the daily counter and SQL-grouped pending raw events in one consistent snapshot. A batch cannot be visible in both sources or neither source; the report remains correct with a worker backlog.

## Retention

- Raw events: **90 days**. The worker deletes expired raw records in indexed batches of **500** only after successful aggregation. Pending events are never pruned, including if the worker is offline beyond 90 days.
- Daily aggregates: **365 UTC calendar days** including today. Expired daily rows are deleted independently from the original event ledger.
- Cleanup is repeated at each normal minute-based wakeup; each job is bounded by event batch size and elapsed time. The maintenance view offers an explicit single cleanup batch. Retention is fixed in this release, not user-configurable, to keep historical claims predictable.
- No real-time visitors, unique users, cookie/session identifiers, IPs, URLs or individual member attribution are inferred from these counters. Backups and retention of backed-up data follow the separate operational backup policy.

## Recalculation and integrity

Operators holding `forum:acp:maintenance` can select **from** and **until** UTC dates in ACP and request a recalculation of **1–31 complete calendar days**. Dates must be within the complete retained raw-event window; older days may lack raw events and therefore cannot be reconstructed faithfully. The operation is transactional, serialized with aggregation and audited. It rebuilds the counters only from **already-applied** raw events, leaving pending rows to be included by live reports or a subsequent worker batch. This avoids swallowing a new event received during the rebuild. On any SQL error the old counters remain intact.

Historical aggregates older than the raw retention period are still available through the 365-day report, but **cannot be rebuilt** after their source events expire. Restore from a verified database backup if that history is corrupted. Recalculation does not access visitor or content data.

## Tests and deployment acceptance

- `python tools/forum-tests/analytics_events_sql_regression.py` and `python tools/forum-tests/analytics_rollup_sql_regression.py` validate the actual SQLite migration, SQL upsert, transactional rollback, reconstruction and backlog protection.
- `php tools/forum-analytics-rollup-regression.php` uses a real PHP PDO SQLite database to test the implementation including a deliberately failed batch, retries, duplicate prevention, live pending views, bounded recalculation and retention. CI must provide `pdo_sqlite` (exit code 77 indicates unavailable local extension).
- `php tools/forum-event-tracking-regression.php`, `php tools/forum-native-analytics-regression.php`, plus Chromium/Firefox consent suites guard historical behaviour.
- Production qualification: execute migrations and the complete signed pipeline with the target PostgreSQL 17+/18+ or MySQL 8.4+/MariaDB 10.11+ database, verify two concurrent worker instances and live forum traffic, inspect sampled pre/post migration daily counts and queue throughput, exercise backups/restore and ACL enforcement. Local SQL/browser tests do not independently certify this production environment.
