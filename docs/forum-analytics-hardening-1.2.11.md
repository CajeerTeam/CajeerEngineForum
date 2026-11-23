# Analytics Security & Hardening — Forum Edition 1.2.11

## Changes in working code

1. **Page-view ingest**: `ForumNativeAnalytics::collect()` now rejects HTTP, non-POST, non-matching `Origin`, missing or cross-site `Sec-Fetch-Site`, navigation/non-fetch `Sec-Fetch-Mode`, and non-JSON content type. The browser collector already uses same-origin HTTPS POST JSON without cookies. Receipt signatures, 30-minute expiry and DB-level one-use IDs remain enforced. Browser environments without Fetch Metadata will no longer submit accepted native page-views (fail closed). This is a count-only endpoint and does not authenticate a visitor; server-side consent is not independently verifiable because no consent cookie is transmitted.
2. **Privacy**: `ForumEngagementAnalytics` hides counts and rates for per-day registration cohorts of 1–4 people. Overall D+1/D+7/D+30 and the activation funnel are also hidden when fewer than five members are eligible. The ACP shows these as `—`/`<5` rather than zero; only authorized ACP operators may view reports. Ordinary daily action counts and forum event totals remain anonymous aggregates; no visitor profiles are created.
3. **Performance**: engagement uses `GROUP BY author_id, UTC calendar day` (PostgreSQL `TO_CHAR(created_at AT TIME ZONE 'UTC',...)`, MySQL/SQLite `SUBSTR`) over the bounded 90-day live-post set, counting contributions and opening posts directly in SQL. Per-member/day rows rather than individual posts cross the database connection. The existing `0083` index is reused, as is the member-role gate. Returning-author calculations and first/second contribution detection (including two posts on the same day) preserve the underlying activity definitions.
4. **Resilience**: existing transactionally idempotent rollup, worker retries, snapshot report reads, 90-day processed-event retention, 365-day daily-retention and permissioned recalculation are unchanged. Missing or invalid metadata is rejected before touching the database, and client-side analytics failures cannot interrupt forum browsing. The first-party collector now retries one time after 800 ms on transport failures, HTTP 429 or HTTP 5xx using the exact same one-use receipt; it does not retry invalid 4xx requests, and scheduled retries are cancelled on consent revocation. This allows recovery from a short backend outage without duplicate counters.

## Upgrade from 1.2.10

Back up the current database and deploy the changed source and matching ACP static assets through the signed release workflow. Restart PHP-FPM/workers, purge the CDN/browser cache for ACP and the native collector. No schema migration or new environment variable is required. Keep migrations 0081–0083 and the existing worker schedule in place.

## Acceptance

- Run `php tools/forum-analytics-hardening-regression.php`, `php tools/forum-native-analytics-regression.php`, and `php tools/forum-engagement-regression.php` (with `pdo_sqlite` enabled for the database phase).
- Run `python tools/forum-tests/engagement_sql_regression.py` and `python tools/forum-engagement-browser-regression.py --browser chromium` (and Firefox in CI).
- Verify with a real HTTPS deployment and populated data on PostgreSQL, MySQL/MariaDB and/or SQLite, including consent grant/withdrawal, authenticated ACP permissions, worker failure/retry and staging load tests.
- A source patch is not an independently signed production artifact; CI, staging and publisher trust-root verification are required before publishing.
