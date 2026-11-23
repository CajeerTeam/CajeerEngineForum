# Forum Edition 1.3.0 — Analytics & Metrics Stable

CajeerEngine Core remains **2.1.0**. Forum Edition 1.3.0 stabilizes the already-installed first-party analytics features (0081–0083), not a new metric product or an additional visitor-tracking SDK. **No new database migration** is introduced by this release.

## Real implementation

- `ForumNativeAnalytics::enhance()` emits a fresh, HMAC-signed, one-use page-view receipt after the HTML cache lookup. The shipped `native-analytics.js` now binds each page view to **both** the server-approved public path and the actual `page` query value. On initial load, later consent grant and retries it rejects a changed path, another page number, duplicate parameter or unexpected query. This prevents stale receipt reuse after `history.pushState`/`replaceState`. It sends no visitor ID or cookie.
- The collector accepts only HTTPS POST with matching Origin, same-origin Fetch Metadata and JSON, using the existing DB primary key to deduplicate a signed receipt. Without granted browser consent it never attempts collection; the server does not independently verify the client's consent decision, so configure and audit the consent UI and policy at deployment.
- `ForumAnalyticsEvents`, `ForumAnalyticsRollup` and `ForumAnalyticsDashboard` use the existing SQL ledger, transactional worker state, UTC per-day counts, 90-day processed-raw retention, 365-day rollup retention, bounded 31-day repair and a consistent read snapshot. Disabled analytics never writes new optional read events; a failed business transaction rolls its audit events back.
- `ForumEngagementAnalytics` continues to calculate author contribution, mature retention cohorts and publication funnels from actual users and retained posts, with small-cohort suppression. No unique browser visitors or sessions are inferred from anonymous events.

## Automated acceptance — mandatory release gate

`tools/forum-analytics-stable-acceptance.php` is **executable**, fails on missing PDO drivers and operates only on isolated disposable databases. It tests the actual service methods and SQL (not mocked API responses) on:

| CI job | Database | Isolation |
| --- | --- | --- |
| `test` | PDO SQLite | in-memory schema from migrations 0081, 0082 and 0083 |
| `postgres` | PostgreSQL 17/18 | dedicated `forum_analytics` DB, real migration runner |
| `mysql-search` | MySQL 8.4 / MariaDB 10.11 | dedicated `forum_analytics` DB, real migration runner |

Assertions cover permission-independent server data-plane properties: signed one-use page views, correct Origin denial, transactional publication/read events, pending-to-applied counter continuity, worker idempotency, live ACP report, bounded repair, rollback, disabled analytics, historical retention and engagement query execution. Existing CI additionally covers authenticated ACP permission enforcement, browsers (Chromium and Firefox), CSP/consent, other analytics providers, migration acceptance, load, recovery and signed-artifact promotion. **A failed database acceptance step fails its required CI job.**

For developer verification with Composer dependencies and `pdo_sqlite`, run:

```sh
composer install --no-interaction
FORUM_TEST_DRIVER=sqlite php tools/forum-analytics-stable-acceptance.php
php tools/forum-native-analytics-regression.php
python3 tools/forum-native-analytics-browser-regression.py --browser chromium
php tools/forum-engagement-regression.php
php tools/forum-analytics-rollup-regression.php
php tools/build-release.php --dry-run --all --json
```

Never override the PostgreSQL/MySQL disposable database allowlist (`forum_analytics`) to point at production. This acceptance changes analytics configuration and writes/deletes test records, so **do not run against an operational forum**.

## Production deployment / operator release decision

1. Verify source SHA and a **publisher-signed** release artifact against the independently pinned Ed25519 trust root. A source-only changed-files ZIP is not a signed release.
2. Require the GitHub `forum-required` aggregate gate to succeed, including SQLite, PostgreSQL, MySQL/MariaDB, Chromium/Firefox, live staging, recovery and release certification. Review artefact identity, release SHA and approvals; the long physical soak and chaos qualifications are separate manual jobs and cannot be inferred from a successful short CI run.
3. Back up database and files, preserve `.env`, run pending migrations 0081–0083 if upgrading from an older version, restart PHP-FPM and workers, and invalidate the versioned native analytics JS at the CDN.
4. Validate HTTPS, signed page views, consent grant/revoke, exact-path and `page` filtering, blocked private pages, index/board/thread navigation, real publication events and ACP statistics on staging.
5. Observe journal backlog, worker retries, repair and retention; cross-check daily totals before and after aggregation and inspect DB errors/slow queries. Verify every externally configured analytics provider on its own account when in use.
6. Promote only with an operator GO decision and recovery plan. If failures occur, restore the previous signed application and verified database backup; never roll back a database schema by dropping analytics tables on a live forum.

The source archive is a set of code changes. It cannot, by itself, prove a 24-hour load soak, successful external-service ingestion, the complete GitHub CI matrix, or a real production cutover.
