# Native Analytics Collection — Forum Edition 1.2.7

A **first-party, locally hosted implementation**, independent of Yandex, GA4, Matomo, Plausible, Cloudflare or GTM. This release extends the **existing** SQL event journal introduced in 1.2.2 (migration `0081_forum_analytics_events.sql`); there is no schema migration.

## Enable / operate

1. Install the **changed files** over Forum Edition 1.2.6, including `public/forum-assets/native-analytics.js`, `core/Forum/Service/ForumNativeAnalytics.php`, both ACP JS copies and the changed route/controller classes. Use the normal application updater/deployment process, not the patch ZIP as a signed distribution.
2. Configure a strong, private server `APP_KEY` (at least 32 characters). An absent/short secret **disables view receipt generation**. Keep the secret out of logs and never change it during rolling deployment without allowing outstanding receipts to expire.
3. Apply migration `0081_forum_analytics_events.sql` if upgrading from a version prior to 1.2.2. For **1.2.6 → 1.2.7**, no SQL migration is needed.
4. Open **Forum ACP → Настройки сообщества**, enable **Аналитика публичных страниц форума**, and save. The default is OFF. The kernel privacy consent policy must remain available; analytics consent is not forced or inferred.
5. Open **Forum ACP → Собственная статистика**, select 7, 30 or 90 days; the report displays total anonymous page views, successful server-recorded actions, individual event totals, and UTC per-day series. No external provider ID is required.
6. Keep the scheduler/queue worker running. `forum.analytics.cleanup` is scheduled once per minute and deletes records older than **90 days** in indexed batches of 250, at most 5,000 rows or three seconds per job. Further minute-based jobs continue a backlog without blocking requests. During maintenance or without a live scheduler, an operator must explicitly drain the backlog.

## What is actually collected

- **Client-initiated anonymous views:** public, SEO-indexable, anonymous SSR HTML for `/forum`, `/forum/boards/{uuid}`, `/forum/threads/{uuid}`, and allowlisted `page=N` pagination. Only after the visitor grants the current analytics consent; never for private routes, previews, searches, profile pages, accounts, noindex pages, authenticated pages, or arbitrary query parameters.
- **Server-recorded community activity:** completed topic creation, published replies, edits, registration, actual reaction changes, successful forum searches and attachment downloads. These are recorded within the existing SQL transaction or on successful permitted reads, **only when the community analytics switch is enabled**.
- **Persisted fields:** a random per-event ID, a fixed enumerated event name, and a Unix timestamp. No IP, session/visitor IDs, cookies, user-agent, referrer, URL/path, board/thread identifiers, private content, query text, or account details are stored. This release deliberately does **not** claim unique visitors, sessions, geographic attribution, top URLs, funnels, user journeys, or conversion attribution.

## Integrity / failure model

A random HMAC-SHA256 receipt is signed with a subkey derived from `APP_KEY` **after Redis/HTML cache resolution**, preventing a shared cached token across visitors. It is valid for 30 minutes (+60 s clock skew), authorizes only an enumerated view kind, and is accepted once by the primary-key constraint of the existing SQL event journal. The browser posts it to `/api/v1/forum/analytics/page-view` via HTTPS, same origin, no cookies and no visitor metadata, only after the live consent runtime reports `analytics=true`. The server checks its signature, expiration, origin, transport, and current analytics policy. Requests are rate limited by the existing middleware; repeat receipts are ignored. Be aware that a user-controlled browser or script can always spoof consent or trigger page impressions for its own legitimately issued receipts: these are anonymous operational aggregates, not a billing-grade anti-fraud signal.

Collection errors do not prevent visitors from browsing; the browser sends at most two attempts. Existing post/edit audit telemetry remains isolated from publication failures. Reports are restricted to the forum `settings` ACP capability and query bounded, grouped SQL results, never raw event logs. The API has no public reporting endpoint. The 90-day cleanup is on the existing scheduler, not a synchronous web request.

## Acceptance and operations

- `php tools/forum-native-analytics-regression.php` — signed receipts, paths, public rendering and privacy gates (no database driver needed).
- `php tools/forum-event-tracking-regression.php` — SQL insert, replay, rollback, aggregation, policy gate, retention; **requires PDO SQLite** (provided by project CI, not all development machines).
- `python tools/forum-native-analytics-browser-regression.py --browser chromium` (or `firefox`) — real JavaScript in browser with isolated local request substitutes; no production traffic.
- `php tools/forum-analytics-integration-regression.php` — external providers and consent compatibility; `node admin/scripts/publish-admin-assets.mjs --check` — ACP published assets.
- Full qualification: CI on supported PostgreSQL/MySQL/SQLite, live TLS same-origin public forum, signed deployment, operator check that opt-in adds one view, opt-out adds none, and ACP report matches. Browser/PHP tests cannot independently prove a production visit or database persistence without a database driver and a configured server.
