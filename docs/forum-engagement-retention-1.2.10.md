# Engagement & Retention Analytics — Forum Edition 1.2.10

## Production implementation

This release extends **Forum ACP → Аналитическая панель форума**. The existing anonymous page-view/events dashboard remains intact. The new engagement panel reads live (non-deleted) forum content and forum-membership roles directly in a repeatable SQL read snapshot. It does **not** use browser cookies, hashed visitor fingerprints, IP addresses, tracking events or account IDs in the API response.

- **Engagement:** authors with `forum-member`/`forum-moderator` roles and at least one live post in the selected last 7, 30, or 90 UTC calendar days (including today); unique authors, authored post total, new-topic opening posts (`ordinal=1`), replies (`ordinal>1`), daily unique contributors, authors active in the last UTC week and repeat authors active in **both** the previous and latest 7-day UTC windows. The contributor average uses distinct authors as denominator, or `null` if none.
- **Retention:** daily cohorts of accounts created in the last 90 UTC calendar days **currently holding a forum member/moderator role**. D+1/D+7/D+30 means a cohort member authored at least one live forum post **on exactly that UTC calendar day after registration**. Only cohorts whose target day is entirely in the past are included in each denominator; an ineligible/zero denominator produces `null`, not fake 0%. Cohorts created today are shown, but not marked retained.
- **Activation funnel:** among cohorts with their first 7 complete UTC calendar days elapsed, count registrations → at least one saved authored post in days 0–6 → a second subsequent saved authored post within the same window. The stage counts are monotonic and ordered by the original post's creation time. Displays percentages of registrations and first contributors.
- **Visualizations and export:** author trend graph (authors, posts, topics or replies), detailed per-day series, retention table and 90-day per-cohort rows. CSV exports daily **aggregated counts only** in UTF-8; no user IDs or post bodies. All data remains behind `forum:acp:settings` with the original per-request permissions gate.

## Accuracy and privacy boundaries

These metrics track **posting retention, not site-visit, login or session retention**; the anonymous event ledger introduced in 1.2.7 cannot identify a returning visitor or reconstruct a user-level browsing funnel. Deleted posts or threads and removed forum roles no longer count toward retroactive reports. Imported pre-existing account/post timestamps may affect dates. All database connections should be configured to use **UTC** for consistent conversion of timestamp columns; grouping is based on their `YYYY-MM-DD` calendar date prefix. Current UTC day is incomplete and excluded from cohort maturity, although current-day posting contributes to daily activity.

No additional identifiable event storage is introduced. Post author IDs and account IDs are used only inside parameterized SQL queries and the server-side read model. No changes are made to consent settings, external analytics, privacy erasure or the existing 90-day raw event / 365-day anonymous aggregate retention.

## Upgrade from 1.2.9

1. Take a verified database backup; confirm the existing Forum Edition 1.2.9 and migration `0082` are present.
2. Apply **`0083_forum_engagement_indexes.sql`**, through the standard migration runner **for the configured driver only** (`migrations/pgsql/`, `migrations/mysql/` or `migrations/sqlite/`). Index creation can consume I/O and lock resources on populated installations: schedule a maintenance window and verify capacity. No new tracking or personal event table is created.
3. Deploy the source changes, restart PHP-FPM, publish the compiled admin assets (`node admin/scripts/publish-admin-assets.mjs`) and invalidate cached JS/manifest in CDNs. Keep Forum worker and existing daily anonymous rollup settings unchanged.
4. In Forum ACP, verify the engagement section for a seeded active member, new member without contributions, mature D+7/D+30 cohorts and an author with multiple posts on the same day. Confirm the unique author isn't counted twice for that day and first topic's opening post isn't counted both as topic and reply.
5. Verify access revocation immediately hides the report, the old anonymous dashboard remains intact, and CSV contains aggregate counts only. For release, require full signed CI and staging DB/role/import/backups validation on your target PostgreSQL/MySQL installation.

## Tests

- `php tools/forum-engagement-regression.php` runs strict date-range checks and actual PHP/PDO SQLite service queries (in CI with `pdo_sqlite`).
- `python tools/forum-tests/engagement_sql_regression.py` executes the actual SQL filtering/ordering with SQLite plus migration 0083 indexes.
- `python tools/forum-engagement-browser-regression.py --browser chromium` (also `firefox` in CI) loads the real ACP bundle with a deterministic mocked API and tests cohort/retention/funnel rendering, activity period selection and permission revocation.
- Existing GA4, Matomo, Plausible, GTM, Cloudflare, consent and native analytics tests continue to run independently.

**Not yet a signed production release:** local tests do not prove PostgreSQL/MySQL query plans, production timezone/session configuration, large-corpus scan latency, backup restore or deployment readiness. Those require CI and an acceptance run against representative target data.
