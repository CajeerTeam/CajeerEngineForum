# Forum Edition 0.9.3 — Database & Performance Optimization

Forum Edition **0.9.3**, CajeerEngine Core **1.3.0**. This release contains **real runtime query changes** and forward migration **0049** for PostgreSQL, SQLite and MySQL/MariaDB. It does not certify a production capacity or replace a 24-hour soak run.

## Changes to live requests

- Mark board/all read: capture only the thread IDs visible in one initial database snapshot, streaming them into a 1 MiB memory-bounded `SplTempFileObject` (spills to a private OS temporary file). Reacquire thread row locks in ascending UUID order in 500-row batches, then lock topology, resolve fresh ACL and upsert marks in 500-row batches **inside one write transaction**. Newly published topics after the captured snapshot are not included; deleted/moved/hidden topics are rechecked after row locking. The atomicity contract is unchanged. This removes PHP arrays proportional to all thread IDs, but large requests still acquire many row locks and should be monitored.
- Post rendering: resolve ignored authors only for the authors on the current page (maximum 25 posts), rather than fetching all ignore relations. All original privacy checks and existing reaction/quote queries remain in effect.
- Notification inbox: constrain **each** UNION branch to `recipient_id` and `state=1` before the join to posts/threads/ACL. Unread-count queries additionally constrain `is_read=0` inside each branch; the write-side mark uses a **separate parameter list** because it updates a single recipient rather than two UNION arms. The notification delivery queue is not changed.
- Pagination and FTS: prefilter physically deleted threads/posts **inside** indexed PostgreSQL/MySQL candidate subqueries before ranking, while retaining the subsequent live-thread/ACL checks, exact total counts, deleted-post holes, publication order, Russian/English FTS expression and PostgreSQL transaction-local search timeout. The real production FTS/ACL query is profiled against a populated PostgreSQL database; queries are never silently truncated.

## Index migration and deployment

- `0049_forum_database_optimization.sql` adds a recipient-first **delivered accepted-answer notification index** and an **activity-ordered board index** for unread lists. Existing normal notification index `ce_forum_notify_page` is reused (verified by SQLite planner); no redundant replacement is created.
- The migration is append-only and uses the existing `MigrationRunner` checksum, lock and transaction behavior. It creates indexes on existing large tables. **PostgreSQL `CREATE INDEX` is not concurrent inside this runner**: index creation can block writes. Plan a maintenance window, drain writes, take and verify a consistent backup, reserve disk/headroom, run `php bin/cajeer migrate`, then confirm `php bin/cajeer doctor`/operational readiness. Do not apply a changed-files ZIP as an installable signed dist.
- Apply the same migration for SQLite or MySQL using the supported installer/updater. MySQL DDL can be nontransactional; an interrupted upgrade requires schema inspection and recovery per existing migration policy.
- Monitor index size, dead tuples, I/O, slow searches, lock waits and `pg_stat_statements` if independently enabled by the DBA. No extension or server-side observability setting is installed automatically by this release.

## Repeatable physical tests

In CI (`.github/workflows/forum-ci.yml`):

1. **SQLite:** `python tools/forum-tests/database_optimization_sql_regression.py` creates an actual 0.9.2 schema, inserts **8,000 threads, 24,000 posts and 4,300 notifications**, fingerprints source rows before and after migration 0049, verifies foreign keys, uses SQLite `EXPLAIN QUERY PLAN` for notification and unread indexes, and exercises allowed/blocked inboxes, unread holes and batched marking of more than 7,000 threads.
2. **PostgreSQL 17 and 18:** `php tools/forum-performance-pg-regression.php` builds the prior schema with real `MigrationRunner`, populates **25,000 threads and 100,000 posts**, upgrades that data using migration 0049, compares row/sequence totals, checks the applied migration ledger and idempotent second run, performs real `EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)` for unread-board, FTS and unread range, and saves plans, including **actual ACL-filtered full-text search**, to `storage/tmp/forum-ci/forum-database-093-plans.json`. It also streams/marks more than **37,000 captured topics** through the real repository and verifies that a topic published after snapshot capture remains unmarked. Live recipient inbox and mark/read operations are exercised, with an additional native plan at `storage/tmp/forum-ci/forum-inbox-093-plan.json`. Existing contention, search-timeout, seek, queue and worker checks still run.
3. Existing PHP integration tests, SQLite and MySQL/MariaDB search regression, API/ACL tests, signed release certification, and hosting acceptance remain required.

## Performance acceptance limitations

Successful queries and index visibility do **not** establish p95/p99 budgets on production hardware. Approve 0.9.3 only after inspecting PostgreSQL 17/18 CI artifacts, query plans, migration duration, disk growth and write-blocking window on an equivalent staging instance. There was **no PostgreSQL server in the local preparation environment**; the physical PostgreSQL acceptance must pass in CI before deploying. The 1-million-post/24-hour capacity test belongs to 0.9.4.
