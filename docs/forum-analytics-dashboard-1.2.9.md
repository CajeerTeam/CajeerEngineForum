# Forum Analytics Dashboard — Forum Edition 1.2.9

Production source integration of a first-party, permission-scoped dashboard using the event ledger and daily aggregation introduced in 1.2.7–1.2.8. This release does **not** use external trackers for its own reporting and does **not** claim that individual visitors, sessions, attribution or conversion funnels can be computed from anonymous counters.

## Upgrade from 1.2.8

1. Back up the database and confirm migration **0082** is already applied and healthy on the target engine. 1.2.9 adds no tables, indexes or migrations.
2. Deploy only the 1.2.9 source changes through the repository's signed CI release process. Restart PHP-FPM to load `ForumAnalyticsDashboard`, and refresh/redeploy `admin/static/assets/admin-app.js` and `admin-app.css` without an old browser/CDN cache.
3. Open **Admin → Forum ACP → Аналитическая панель** as an administrator or holder of `forum:acp:settings`. The old `view=analytics&days=7|30|90|365` read model remains supported for existing integrations.
4. Check 7/30/90/365-day summaries and an explicit custom interval; confirm that the selected UTC range and CSV match expected applied rollups **plus** pending raw events. With 365 days selected, the comparison must be shown as unavailable, not zero.
5. With a separate maintenance role, verify that event ingestion and recalculation controls still require `forum:acp:maintenance`. Check the audit log after each write operation. Test permission revocation and logout: retained dashboard data must disappear on the next refresh.

## Reports and controls

- **UTC date filters:** preset 7, 30, 90 or 365 calendar days including today; custom inclusive `from` + `until`. Both dates must be provided for a custom range, neither may be in the future, and the first day must be within the retained 365 days. The API rejects invalid dates and out-of-retention ranges with 422.
- **Overview KPI:** total recorded events, public page views and forum actions. Counts are anonymous, not unique visits. Trends compare the exact preceding equal-length window only if *every* day of that window is retained. Zero baselines are not treated as infinite growth.
- **Drilldown:** daily UTC series (including zero days), per-event totals, previous-window changes, overview and selected-event SVG graphs, category contribution and normalized weekday averages. Existing service-side event names are reused, not synthesized. No confidential identifiers, page paths, browser fingerprints or free-text queries are persisted or returned.
- **Export:** *download on the operator's computer* of a UTF-8/BOM CSV containing date plus numeric daily totals for the known event types. No raw event records, URLs or account IDs are provided in the export; no additional endpoint or external call is made.
- **Operations:** active aggregation backlog, retention settings, last rollup/recalculation, and the original separately authorized aggregate, cleanup and 31-day rebuild maintenance controls.

## Data consistency and limits

The dashboard executes read-only date-bounded prepared SQL queries over `ce_forum_analytics_daily` and unprocessed rows of `ce_forum_analytics_events` in a single repeatable-read snapshot. A worker transaction applies both increments and `aggregated_at` flags atomically. A committed event therefore contributes to one data stream at a time. Historic rollups are retained for 365 UTC days, processed raw events for 90 days. A requested comparison outside the 365-day window is **unavailable**, not partial, zero, or inferred. As with 1.2.8, restoring expired rollups requires a verified database backup (the raw ledger cannot recover expired source data).

A user with `forum:acp:settings` may inspect statistics but cannot invoke maintenance operations without the additional maintenance permission. Existing forum policy opt-in, HTTPS/signed receipt verification, private-page exclusions and external-provider consent rules remain unchanged.

## Verification

- `php tools/forum-analytics-dashboard-regression.php`: date-boundary tests plus real PDO SQLite service integration when `pdo_sqlite` is installed.
- `python tools/forum-tests/analytics_dashboard_sql_regression.py`: actual SQLite schema 0081/0082, daily + pending counts, comparison and atomic ownership transition with rollback.
- `python tools/forum-analytics-dashboard-browser-regression.py --browser chromium` (also `firefox` in CI): tests the **real** ACP frontend with mocked API, charts, filters, custom date submission, aggregated CSV download, permission gating and browser exceptions.
- Existing forum analytics/consent/rollup browser and PHP tests remain in the Forum CI gate.

Before claiming a signed **production release**, the repository CI must pass with real PDO SQLite and the target production SQL driver; verify on a staging deployment using representative anonymized event volume, multiple concurrent workers, current CSP policies, the static ACP production build and database performance. Local PHP syntax / browser transport tests alone do not establish production certification.
