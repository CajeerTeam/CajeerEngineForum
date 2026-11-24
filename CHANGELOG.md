## [1.3.11] - 2026-11-24

### AI Performance & Reliability
- Durable bounded FIFO admission across forum AI features, four concurrent generations, up to sixteen queued requests, short wait and provider-wide cooldown. No queued prompt storage or automatic replay of potentially billed requests.
- Conservative accounting for ambiguous transport failures, expired token and money lease recovery in the worker, and automatic trimming of oldest history turns to keep the current request within its context cap.
- Migration `0091_forum_ai_reliability.sql` for all three database drivers; real PDO acceptance and SQLite contention tests. External CI/staging required.

## [1.3.10] - 2026-11-24

### AI Privacy & Abuse Protection
- Authenticated forum members can export their own ordinary and thread AI conversations and messages through the privacy portal, and irreversibly erase all their AI conversations with explicit confirmation. Owner scoping and session CSRF are retained.
- Account closure deletes forum AI transcripts, daily token ledgers and request buckets; if an OpenAI response is in flight, closure fails safely until the request completes. Existing moderation and legally required audit logs are preserved.
- Worker deletes idle AI conversations after 90 days (excluding running generations), and per-user minute throttle buckets after 24 hours. Scheduled cleanup handles batches to bound SQL work.
- Durable SQL-backed five requests per minute and one simultaneous generation per user apply across all forum AI entry points, in addition to existing daily/monthly and monetary budgets. The server-controlled system roles and core outbound credential scanning remain active.
- Migration `0090_forum_ai_privacy_abuse.sql` for SQLite, PostgreSQL and MySQL/MariaDB; SQL and browser regression tests in CI. External provider and staging checks are necessary before stable deployment.

## [1.3.9] - 2026-11-24

### Controlled Admin AI Actions
- Active Forum ACP panel to propose, inspect, apply or reject permitted AI changes to the forum title/description and support FAQ. AI generation uses existing OpenAI Responses, quotas, budgets and temporary transcript cleanup.
- No automated execution: database-backed, actor-owned proposals with exact before/after and rationale, explicit OpenAI disclosure, 15-minute expiration, and an approval-only second request bound to a proposal digest.
- Live permission, account status and target revision rechecked in the same SQL transaction as the real forum policy/FAQ mutation; atomic audit. FAQ creation is unpublished, and editing published FAQ preserves its visibility. No role, sanctions or ACL mutations exposed.
- Migration `0089_forum_ai_admin_actions.sql` for SQLite, PostgreSQL, MySQL/MariaDB; unit, SQL, browser and real PDO/provider-boundary acceptance added to CI. Production certification requires green external CI and staging.

## [1.3.8] - 2026-11-24

### AI ACP Insights
- On-demand AI analysis of real first-party forum rollups: per-day trends, event comparisons, verified facts and interactive ACP charts with CSV exports.
- Explicit disclosure approval for external OpenAI; operator browser session and live ACL validated before and after provider call, no personal identifiers or raw event payloads sent.
- Reuses existing OpenAI Responses API, atomic quotas/budget ledger and ephemeral search-purpose conversation with mandatory deletion after generation.
- Grounded JSON evidence identifiers, server-resolved factual values, numeric claim rejection and report-change detection; no automated actions or guessed unique-visitor measures.
- No database migration. Unit/real-PDO provider-boundary/browser regressions added to CI; production certification still requires successful CI and staging checks.

## [1.3.7] - 2026-11-24

### AI Moderation Advisor
- Existing report/premoderation cases receive moderator-initiated, human-only AI risk assessment and suggested next steps in Forum Moderation and ACP.
- Reviews use actual current case content with strict ACL, explicit disclosure for unpublished/private material, secret and email redaction, and the shared billed OpenAI Responses API/quotas.
- Persisted structured suggestions validate evidence quotes, expire after 30 days, and are invalidated by case revisions, edits, resolved status or permission changes; no sanctions or decisions are automated.
- Migration 0088 for PostgreSQL, MySQL/MariaDB and SQLite, real SQL/provider-boundary CI tests, and browser regression.

## [1.3.6] - 2026-11-23

- AI Writing Assistant: явные предложения исправления ошибок, улучшения текста, форматирования и заголовков прямо в редакторах новых тем, ответов и правок.
- Реальная генерация через существующий OpenAI Responses API с общим учётом затрат, дневными и месячными лимитами. Одноразовые транскрипты удаляются после генерации.
- Предпросмотр и подтверждение перед заменой содержимого; защита от перезаписи правок, сделанных пока модель генерирует ответ. Никакой автоматической публикации.
- Исправлена регрессия в проверке одноразового AI-запроса (неверное сравнение созданного идентификатора с null).
- Новых миграций БД нет. Полный CI и staging требуются для production-приёмки.

## [1.3.5] - 2026-11-23

- AI Support Assistant: заявки участников с персональной историей, очередь операторов и ответы с проверкой прав, CAS-ревизиями, защитой от спама и аудитом действий.
- AI по явному согласию использует только публичные сообщения через Forum AI Search. Неподтверждённые ответы, ошибки и отсутствие доступных источников оставляют обращение оператору; отзыв или изменение источника скрывает старый AI-ответ.
- Редактор опубликованных FAQ в операторском интерфейсе, REST, новая миграция `0087_forum_ai_support.sql` для SQLite, PostgreSQL и MySQL/MariaDB, проверочные тесты.
- Версия ядра остаётся 2.1.0. Подписанный production-релиз требует CI и staging.

## [1.3.4] - 2026-11-23

### Knowledge Sync & Search Quality
- Preserve immediate, transactionally consistent indexing of new/edited/deleted posts, titles and tags on SQLite via FTS5 triggers, PostgreSQL GIN expressions and MySQL/MariaDB FULLTEXT. No parallel stale AI index, cookies or migration.
- Detect missing SQLite synchronization triggers, stale FTS rows and orphan keys. Restore all three indexes and their triggers inside one database transaction; operator can run `forum:search:check`, `forum:search:repair` (only if broken) or a full `forum:search:reindex` in a write-free window.
- Recreate correct native expression/FULLTEXT index definitions for PostgreSQL and MySQL/MariaDB when rebuilding; keep all repairs restricted to operator CLI. MySQL DDL is not atomic across tables: keep writes stopped and verify afterwards.
- Improve grounded AI search using ranked post matches, per-thread diversity and term-centered post excerpts. Revalidate exact excerpts and access before sending public sources to OpenAI. Preserve existing budgets, ACL and cited post permalinks.
- Add real-PDO acceptance on SQLite/PostgreSQL/MySQL/MariaDB, standalone search-quality checks and full SQLite trigger/soft-delete regression; no new schema migration.

## [1.3.3] - 2026-11-23

### AI Forum Search
- AI search on `/forum/search` using real indexed message/thread lookup, permission-checked live publication excerpts and page-aware links.
- OpenAI Responses API streamed answer only after explicit user action; ten source cap and citation integrity report.
- Only content readable by both member and guest enters the external model; no private boards, removed posts or personal conversations.
- Migration 0086 isolates one-shot search transcripts, removes completed history and reaps interrupted sessions; reuses existing budgets and limits.
- Added real PDO acceptance gates for SQLite/PostgreSQL/MySQL/MariaDB, browser tests for Chromium/Firefox and stricter OpenAPI route-security verification.

## [1.3.2] - 2026-11-23

### Contextual Thread Assistant
- Real thread-scope summary, explanation, contextual questions and Responses API SSE, rendered in the thread UI.
- Fresh authenticated member ACL and independent guest-public ACL for every request; private, deleted and member-only topics are never sent externally.
- First and latest 23 live published posts (bounded context) with server-derived stable post links and proper pagination; no deleted text or fabricated URLs.
- Separate user+thread AI histories and delete; generic forum AI cannot access thread-scoped sessions. Reuses core model, token/day quotas and monetary holds.
- Migration `0085_forum_thread_ai.sql` on PostgreSQL/MySQL/SQLite; browser and real-SQL acceptance added to CI. Full staging and provider acceptance still required.

## [1.3.1] - 2026-11-23

### Forum AI Chat
- Реальный чат участников `/forum/ai` с SSE-потоком ответов через существующий OpenAI Responses API, историей сообщений, переименованием и удалением диалогов.
- Отдельные SQL-таблицы форумных диалогов; диалоги CMS AI и приватные публикации форума изолированы от пользовательского чата.
- Управление моделью и лимитами из ACP, проверка прав и CSRF, ежедневная квота запросов, месячная квота токенов, атомарное резервирование и общий журнал денежных расходов AI.
- Миграция `0084` для PostgreSQL, MySQL/MariaDB и SQLite; обязательные междрайверные PDO acceptance-тесты, проверка безопасности и сборки.
- Релизная квалификация в CI и staging обязательна: самостоятельная локальная сборка не считается production-сертификацией.

## 1.3.0 — Analytics & Metrics Stable (2026-11-23)

- Add a fail-closed real PDO acceptance of signed page views, same-origin rejection, single-use receipts, SQL journal, aggregate worker, transactional retry, ACP reports, bounded recalculation, disabled policy and 90/365-day retention; execute against SQLite, PostgreSQL 17/18, MySQL 8.4 and MariaDB 10.11 with independent disposable CI databases.
- Bind every native page-view receipt to the rendered path and pagination value. Re-check on consent change and retry; stale history changes can no longer record another page using an old receipt. Add PHP and Chromium/Firefox regressions.
- Keep existing GA4, Yandex Metrica, Matomo, Plausible, GTM and Cloudflare integrations and release signing gates. No new schema migration, cookie or user identifier. Signed deployment requires full CI/staging and operator approval; the source patch alone is not production certification.

## 1.2.11 — Analytics Security & Hardening (2026-11-23)

- Enforce HTTPS, exact Origin, `Sec-Fetch-Site: same-origin`, fetch mode and JSON content type for first-party page-view collection. Invalid/legacy metadata fail closed before reading analytics receipts.
- Hide registration cohort, D+1/D+7/D+30 and activation metrics when the relevant denominator is under five. Mark suppressed cohorts explicitly; ACP displays a dash or `<5`, never a fabricated zero.
- Group the live author activity stream in SQL by author and UTC day, transferring one result per active author/day rather than each individual forum post. Preserve exact topics, replies, active authors, repeat publishing and same-day first/second post funnels.
- Retry transient native collection failures at most once with the same idempotent receipt; cancel retries on consent withdrawal and do not retry permanent 4xx rejections.
- Cover strict collection metadata, grouped multi-post days, small-group redaction and ACP rendering in regressions; retain existing transactional rollups, retention jobs and signed-release checks.
- No migration. No additional cookies or unique visitor identifiers. Core remains 2.1.0.

## 1.2.10 — Engagement & Retention Analytics (2026-11-23)
- Implemented contribution-backed daily active authors, authored post volumes, new-topic vs reply counts, exact distinct contributors and consecutive-week returning authors from existing forum data; excluded CMS-only roles and account identifiers from ACP responses.
- Added D+1, D+7 and D+30 registration cohorts for members publishing a post on the exact subsequent UTC day, with maturity/zero-denominator safeguards and a sequential 7-day registration → first post → second post activation funnel. No anonymous view is represented as a unique member or inferred session.
- Added an interactive ACP author activity graph, 7/30/90-day filter, per-day table, retention and conversion KPIs, 90-day cohort breakdown and aggregated CSV export; retained the existing anonymous analytics dashboard, permission checks and other providers.
- Indexed registration and post-activity scans with driver-specific `0083_forum_engagement_indexes.sql`. Added PHP/SQLite, actual SQLite query and Chromium/Firefox regression suites and versioned release gates. Signed production certification still requires full CI and staging on the target database.

## 1.2.9 — Forum Analytics Dashboard (2026-11-23)
- Replaced the basic Forum ACP analytics table with a working first-party dashboard: authenticated settings-scoped date filters (7/30/90/365 days or a custom 1–365-day UTC range), three KPIs, responsive SVG trend charts, event-level filtering, grouped breakdowns, weekday averages and daily drilldown.
- Compare each metric with the *entire* preceding equal-length window when available within the stored 365 days, otherwise mark it unavailable; avoid invented visitors, sessions, retention rates or conversion funnels. Zero denominators do not yield fabricated percentage growth.
- Read the existing atomic UTC daily rollups and not-yet-applied raw events in one consistent snapshot; limit all SQL queries by date and permit access only under existing Forum ACP settings permission. Maintenance operations retain their separate permission, confirmation and audit log.
- Export bounded anonymous aggregated CSV on the client without downloading raw records or adding tracking dependencies. Preserve first-party consent, source retention and CajeerEngine 2.1.0. No migration required; live staging acceptance remains a deployment gate.

## 1.2.8 — Analytics Aggregation & Retention (2026-11-23)
- Store anonymous UTC counters in the durable `ce_forum_analytics_daily` table, using `0082_forum_analytics_rollup.sql` on PostgreSQL, MySQL/MariaDB and SQLite. The worker marks each raw row as aggregated in the same transaction as its rollup increment, with a database-serialized maintenance lock; retries and worker crashes cannot lose or double-count committed events.
- Serve 7/30/90/365-day ACP reports from daily rollups combined with still-unprocessed events using a consistent database snapshot. Backlogged events remain visible immediately; no per-visitor IDs, paths, account IDs or content is stored.
- Automatically retain processed raw events for 90 days and daily rollups for 365 days; never purge an unapplied raw event. Add bounded, audited ACP controls for ingestion, retention cleanup and idempotent UTC day-range recalculation from retained processed entries (max 31 days, only complete raw-retained days).
- Preserve the 1.2.7 first-party consent collector, external analytics integrations and CajeerEngine 2.1.0. Add executable SQL/SQLite regressions, ACP assets, documentation, versioned CI and release metadata. Production release still requires complete signed CI/staging acceptance.

## 1.2.7 — Native Analytics Collection (2026-11-23)
- Added first-party, anonymous, consent-gated page-view recording on public SEO-indexable guest forum index, board and thread HTML pages, including pagination. Each render gets a distinct 30-minute HMAC-signed, single-use receipt after the cache lookup; server validates HTTPS and same origin, writes no URL, IP, referrer, user-agent, account, browser or cookie identifier.
- Extended the existing transactionally recorded forum activity journal with idempotent native page views and real 7/30/90-day UTC SQL reporting. ACP settings-authorized operators can view event totals and daily trends; public API cannot read reports. Existing 90-day retention and cleanup remain active. No external analytics account or database migration is required.
- Added PHP and Chromium/Firefox regressions, release/CI gates, API documentation and static ACP assets. Publication still requires full target-database CI and independently signed release qualification.

## 1.2.6 — Alternative Analytics Providers (2026-11-23)

- Integrate consent-controlled Matomo `trackEvent`, Plausible `POST /api/event` and GTM `dataLayer.push` calls for forum index/board/thread/pagination views and real attachment/outbound clicks, on the inherited 2.1.0 tracking engine without duplicate tag loaders or server event forgeries.
- Add ACP-configurable and validated per-provider event names with per-event disable, revision-checked saving, backward-compatible policy editing and a strict, server-approved public SSR boundary. Custom events contain only operator-selected names and sanitized public URLs; private routes, queries and visitor data are excluded.
- Continue Cloudflare Web Analytics' official beacon for consented public page views and RUM only; it cannot accept custom events. Prevent beacon insertion on URLs with extra sensitive query parameters; require manual Cloudflare setup without edge auto-injection.
- Extend PHP and Chromium/Firefox browser regressions, API schema, release CI and operator instructions; retain GA4/Yandex support and CajeerEngine 2.1.0, with no schema migration. Live vendor ingestion and release certification remain external deployment checks.

## 1.2.5 — Google Analytics 4 Integration (2026-11-23)

- Add six production GA4 custom events for real public forum index/board/thread/pagination views and trusted attachment/outbound clicks; ensure there is exactly one inherited gtag.js, one standard page_view per public navigation, and no new SDK or synthetic success events.
- Add revision-controlled Forum ACP settings with unique, GA4-safe custom event names, one-click per-action disable, legacy policy update preservation, sanitized provider arguments and core analytics event rule controls; all tracking requires HTTPS, approved host, authenticated-guest public SSR ACL, and affirmative analytics consent.
- Extend PHP and Chromium/Firefox browser regression coverage and release/CI gates to 1.2.5, retaining CajeerEngine 2.1.0 without database migrations. GA4 production-property ingestion still requires independent staging verification.

## 1.2.4 — Yandex Metrica Integration (2026-11-23)

- Added six operator-configurable Yandex Metrica JavaScript goals for approved public forum index/board/thread views, paginated views, and real attachment/outbound link clicks. Sends no forum UUIDs, queries, user content or personal identifiers with custom goal events.
- Integrated per-page goal settings through revision-controlled Forum ACP community policy, validated unique goal identifiers, shared core single-tag consent runtime and strict server-approved routes; existing counter setup and response-scoped CSP remain mandatory.
- Tested opt-in, denial, duplicate views, actual link actions, private route exclusion and invalid goal configurations in PHP and browser regressions; bumped release/CI gates to 1.2.4 without changing CajeerEngine 2.1.0, adding database migrations or claiming vendor acceptance.

## 1.2.3 — Visitor Tracking & Consent (2026-11-23)

- Restrict forum tracking to explicitly enabled, anonymous, public and indexable server-rendered forum/board/thread pages. Bind a per-response approved path to the shared consent runtime so client-side URL changes cannot activate tracking on private routes, account pages, profiles, messages or ACP.
- Correct GA4/Yandex/Matomo/Plausible page-view dispatch so the initial visit is recorded once after affirmative analytics consent, with no duplicate counter activation or duplicate events after policy refresh. Support legitimate navigation between pages of one public thread, browser back/forward and BFCache restoration without counting focus events as visits.
- Limit externally shared page locations to the approved origin and path, optionally with one bounded numeric `page` parameter. Strip free-text search, sensitive parameters, fragments, credentials and arbitrary referrer paths.
- Consent refusal, withdrawal and changed policy versions continue to fail closed; server-side activity counters remain independent of optional third-party visitors. The forum analytics switch and kernel tracking configuration remain disabled by default.
- Add executable PHP checks of server gating, real Chromium/Firefox DOM regression of consent, provider requests and URL sanitization, CI wiring and an operator-facing staging checklist. No database migration; the inherited core remains CajeerEngine 2.1.0.
- Actual GA4/Yandex provider acceptance and deployment remain external staging gates, not certified by local transport mocks.

## 1.2.2 — Forum Event Tracking (2026-11-22)

- Implemented native anonymous, transaction-committed forum activity events for published threads and replies, registrations and actual reaction changes; unsuccessful and rolled-back mutations do not produce events.
- Successful first-page searches and verified file downloads create bounded first-party metrics without storing search terms, actor IDs, content, private IDs, files, IP addresses or URLs.
- Wired consent-gated event routing to the core `CajeerAnalytics.emit` runtime where it is present on an eligible public forum page; no external provider receives data without consent and no analytics SDK is duplicated.
- Added SQLite/PostgreSQL/MySQL migration 0081, schema health checks, 90-day bounded retention worker, executable PHP/JS/SQL regressions and versioned release gates.
- Event totals are stored for future native aggregation and reporting. This release does not enable tracking on authenticated or private pages and does not claim production verification of external provider delivery.

## Forum Edition 1.2.1 — Forum Analytics Integration (2026-11-22)

- Working SSR integration of public, anonymously accessible, indexable Forum pages with the existing CajeerEngine Consent Policy and counter runtime. No replacement tracker/SDK; optional analytics and external HTTP requests remain disabled until consent.
- Forum ACP community setting `analytics_enabled` (default false), revision-checked transactional update and audit; reuses core Tracking & Counters, Analytics Events, Analytics Hub and Privacy controls.
- Fail-closed origin, HTTPS, route visibility and preview checks; closed discussion areas, authenticated/private pages, ACP, member settings and private messages cannot be instrumented by the forum bridge.
- Conditional external-provider CSP on eligible rendered pages; internal render flag is removed before responding. Redis SSR cache keys incorporate the effective tracking/consent rules and runtime asset hash, avoiding stale consent after settings changes.
- OpenAPI schema, CLI/API regression tests, source + compiled Forum ACP assets and signed release identity updated. Provider delivery and site-specific production acceptance remain separate operator checks; native event statistics are planned for later 1.2.x releases.

## Forum Edition 1.2.0 — Advanced Migration Stable (2026-11-22)

- Implement read-only, source-backed Invision Community 4/5 v8 full import acceptance (`forum:import:accept --platform=invision`), rejecting missing mappings, altered source content, unsupported target or private-audience changes, and modified attachments.
- Require an exact SHA-256 acknowledgement of all skipped source records, plus existing isolated maintenance mode and independently verified pre-import backup before positive acceptance.
- Retain XenForo 2.2/2.3 v5 acceptance, fail-closed migration integrity checks, historical phase/checkpoint compatibility, and CajeerEngine 2.1.0 core; no new SQL migrations.
- Add negative integration regressions, CI admission, operational cutover/restore instructions and explicit real-data staging release criteria. Real production XenForo/Invision data has not been provided or certified.

## Forum Edition 1.1.11 — Advanced Migration Hardening

- Add index migration 0080 for fast `(job_id,kind,outcome,target_id)` ownership/recipient lookups across supported SQL drivers.
- Add executable target integrity reconciliation to each completed import (quarantine, disabled accounts, archived tree, private recipients and authors, attachment ownership); the job cannot commit completion if the checks fail.
- Add operator `forum:import:verify --job=... --deep=1`, which checks imported attachment bytes/size/SHA-256 incrementally with bounded per-file memory.
- Require verification of the recovery backup before each resumable run. Reject missing Invision v8 attachment snapshots before taking the backup.
- Correct Invision poll table discovery so unrelated optional community tables cannot be mistaken for polls.
- Add real SQLite/PDO integration regressions and fail-closed negative tests. Pipeline versions and in-progress checkpoints remain unchanged.
- Production acceptance on a representative XenForo/Invision snapshot and full CI are still required.

## [1.1.10] - 2026-11-22 — Invision Community Data (Forum Edition)

- Added v8 transactional Invision 4.x/5.x community-data phases for private member profiles, supported forum-post reactions, topic/board/member follows, private conversation participants, messages and safe rich-content rewriting. All original v6/v7 jobs retain their phase numbering and recorded schema digests.
- Invision private conversation import requires a verifiable snapshot recipient map. Deleted, left and banned recipients are not restored; any missing active recipient denies import of the whole conversation. Only imported active participants can author historical messages.
- Positive supported forum reactions and non-anonymous follows are mapped to existing live forum tables. Unsupported content or source schemas are reported or blocked rather than silently converted. Imported local accounts stay disabled with private profiles; imported boards remain hidden and readonly.
- Added full PHP/PDO SQLite integration (atomic rollback, restart and privacy) in CI, an autonomous v8 phase test, production-schema SQL rollback test, runbook and release gating updates. No new SQL migrations or changes to the CajeerEngine 2.1.0 core version.
- Source snapshots from real Invision Community installations, PostgreSQL/MySQL deploy acceptance and release-signing certification are still required before a production claim.

## [1.1.9] - 2026-11-22 — Invision Content & Media (Forum Edition)

- Implemented Invision v7 migration phases: guarded local attachments, imported closed polls with validated choices, and final HTML-to-BBCode rewrite using resolved target IDs.
- Actual file bytes are copied to the production attachment table with MIME validation and SHA-256, linked only to an imported published post and author; images enter the existing media processing queue.
- Import links, quotes, image-download references and user mentions are resolved after ID mapping; HTML executable content is rejected and only the closed forum BBCode grammar is emitted.
- Added bounded, transactional per-batch checkpointing, tested rollback and restart paths, explicit audit reasons for unsupported poll shapes, files and unavailable owners.
- Original six-phase Invision pipeline v6 remains available to resume jobs created on 1.1.8; new jobs use the append-only nine-phase v7 pipeline.
- Added PHP content unit, PHP/PDO end-to-end and real target SQLite schema regressions to CI.
- **Scope:** source poll voter histories, remote file backends, IPS-specific interactive widgets, personal messages and community data remain unsupported; import snapshots must be validated on staging before production cutover.

## [1.1.8] - 2026-11-22 — Invision Core Import (Forum Edition)

- Implemented executable read-only Invision Community 4.x/5.x core source adapter for groups, users, categories/forums, topics and posts.
- Added pipeline v6 with isolated six-phase ledger, transactional batch writes, ID mappings, schema fingerprint checks and safe restart; existing import jobs retain their previous phase sequences.
- Imports users disabled and memberships with zero source administrative privileges; imported boards remain hidden and read-only.
- Excludes unapproved topics/posts; converts IPS HTML content into inert plain text without truncation or script execution.
- Extended operator CLI `forum:import:plan` / `forum:import:run` with `--platform=invision` and automatic prefix/version detection.
- Added SQLite integration regression for database writes, rollback, resume, data ownership and source-schema drift, included as an obligatory CI step.
- Source attachments, archived posts, polls, profiles, reactions and private messages are explicitly deferred, not silently represented as imported.

## [1.1.7] - 2026-11-22 — Invision Source Discovery (Forum Edition)

- Add executable read-only `forum:import:discover --platform=invision` for frozen MySQL/MariaDB or SQLite Invision Community 4.x and 5.x snapshots. Detect real table prefixes and installed `core`/`forums` application versions.
- Validate required member, group, forum, topic and post schema columns; count native entities, optional private/multimedia tables, detect invalid/duplicate IDs, orphan topics/posts, missing groups, missing authors and cyclic or missing forum parents.
- Fail closed on unsupported versions, ambiguous prefixes and incompatible schema. Report attachment snapshot requirements, table layout fingerprint and source data warnings without reading credentials or message bodies or writing into the target DB.
- Invision import execution remains disabled until the 1.1.8 core importer is implemented and tested on real source snapshots. Discovery is a preflight, not production acceptance.

## [1.1.6] - 2026-11-22 — XenForo Production Acceptance (Forum Edition)

- Fix a production-blocking schema defect: XenForo private media phase names exceed the old 20-character `ce_forum_import_map.kind` limit on MySQL/PostgreSQL. New migration `0079` expands it to 32 characters; runtime import checks the installed schema before starting.
- Add a real read-only `forum:import:accept` command that scans the complete XenForo 2.2/2.3 source snapshot and target mapping, verifies all supported phase counts and source IDs, enforces disabled imported accounts and hidden/readonly boards, checks original and imported attachment SHA-256, participant privacy and message revisions.
- Fail closed on unexpected/changed omissions: a cutover approval must match the exact SHA-256 of the skipped-record ledger; no automatic promotion or publication of imported communities.
- Add negative tests for damaged private files, unreviewed omissions, changed participants, preserved checkpoints and actual SQL migration constraints. Actual production acceptance still requires operator-provided real XenForo snapshots and staging databases.

## [1.1.5] - 2026-11-22 — XenForo Conversations & Media (Forum Edition)

- Import real XenForo 2.2/2.3 private conversations, active participants and message history into the existing Forum conversation tables. Deleted participants stay excluded; unavailable members abort that conversation rather than exposing a partial private audience.
- Import private message attachments from a verified immutable local `internal_data/attachments` snapshot, persist `message_id` and use existing membership-aware media ACL. Every invalid or missing file is reported in the import map.
- Add final transactional conversion of private message BBCode, mentions and safe private conversation/attachment links after all ID mappings exist, preserving baseline revisions.
- Version the import pipeline (v5, 20 phases) without changing checkpoints from legacy v1-v4 jobs; check XenForo conversation source schema and frozen row counts before import/resume.
- Extend legacy URL resolution for public XenForo post links without introducing public private-conversation redirects.
- Add executable v5 SQLite/PHP integration, rollback/resume, source-schema negative and privacy tests to required CI. Production acceptance requires an actual frozen 2.2/2.3 source snapshot and staging.

## [1.1.4] - 2026-11-22 — XenForo Community Data (Forum Edition)

- Реальный XenForo 2.2/2.3 migration pipeline v4 (16 фаз): аватары из локальной копии `data/avatars`, подписки на участников, первичные группы и существующие профили, реакции, группы, подписки на темы/разделы. Новые данные, mapping и checkpoint фиксируются одной транзакцией.
- Аватары перепроверяются и перекодируются через `ProfileImage::encode`; исходные метаданные удаляются, превышение лимитов и отсутствующие файлы отражаются как пропуски. Без обязательного `--avatar-files` импорт при наличии аватаров не начинается.
- `xf_user_follow` проверяется перед созданием задания; новый отпечаток схемы и неизменяемые high-water marks проверяются при возобновлении. Отсутствующие связи и пользователи не импортируются молча.
- Участникам сохраняется безопасная первичная группа источника для отображения, но роли источника создаются с `permissions=[]`; административные полномочия не копируются.
- Сохранена совместимость существующих jobs pipeline v1 (11), v2 (13), v3 (14); новые XenForo jobs v4 (16). Добавлены проверки отката, восстановления и целостности связей.

## [1.1.3] - 2026-11-22 — XenForo Content Fidelity (Forum Edition)

- Added `XenForoContent` converter used by an actual post-update phase in `ForumMigrationEngine`; existing target BBCode renderer handles formatting, quotes, mentions, links and supported headings. No remote resources are requested or inline HTML trusted.
- New XenForo imports use `pipeline_version=3`: eleven original phases, profile/reaction phases, then transactional `content_links` pass after all source-to-target IDs and attachments are known. Body, format, baseline revision, ID map and checkpoint commit together; failed batches roll back and resume safely.
- Author-attributed quotes and internal links resolve against imported post/thread/member IDs. `FORUM_IMPORT_SOURCE_ORIGIN` enables remapping absolute URLs only for the exact operator-approved source origin; relative XF links are also supported. Unmapped private/deleted targets become inert text rather than dangling old-site links.
- Conservatively converts images to safe links rather than fetching remote images. Unsupported add-on tags remain inert text; existing jobs without a v3 ledger are not rewritten. Adds executable renderer/security regressions, actual SQLite target SQL rollback checks and real PDO SQLite importer integration tests.
- Does not change CajeerEngine 2.1.0 or the SQL migration schema. External snapshots and PostgreSQL/MySQL end-to-end staging certification are still mandatory for production cutover.

## [1.1.2] - 2026-11-22 — XenForo Source Compatibility (Forum Edition)

- XenForo 2.2/2.3 frozen snapshots are checked against the native table/column requirements before any target write or backup, with actionable fail-closed diagnostics for unsupported or incomplete schemas.
- Real source layout determines file-key selection: XenForo 2.3 requires `file_key`; 2.2 supports legacy `file_hash` and mixed/upgraded layouts, with safe per-row fallback for empty keys. Attachment content is still validated against immutable local files and existing target limits.
- New import jobs record a deterministic source-schema digest and chosen attachment key, refusing continuation when the schema changed; existing 1.1.1 jobs retain their original checkpoint and plan format.
- Executable PHP unit and native PDO SQLite regressions cover missing source tables/columns, 2.2/2.3 compatibility, key selection, schema drift and old-job resume. CI now runs both suites.
- Core CajeerEngine remains 2.1.0; no target database schema migration required. Live XenForo dumps and remote attachment stores still require operator staging validation.

## [1.1.1] - 2026-11-22 — Migration Engine Upgrade (Forum Edition)

- Новые задания XenForo получают версионированную последовательность импорта: после базовых фаз выполняются `profiles` и `reactions`; старые задачи без `pipeline_version` сохраняют 11 исходных фаз и свои checkpoints.
- Импортируется содержимое приватных профилей XenForo (bio, location, website, signature) и стандартные реакции Like/Love с исходными ID и явной причиной пропуска неподдерживаемых записей. Пароли, ACL, скрытые сообщения и данные существующих аккаунтов не копируются.
- Данные дополнительных сущностей, ID-mapping и checkpoint фиксируются в одной транзакции. Проверка snapshot и контроль целостности выполняются до закрытия задачи; неизвестные фазы и несовместимые планы блокируются.
- Для существующих миграционных заданий не изменяется таблица, порядок фаз или идентификатор задания; новых SQL-миграций нет. Поддержка личных сообщений, расширенных полей и нестандартных реакций запланирована позднее.
- Требуется staging-приёмка на подтверждённых схемах XenForo 2.2/2.3, PostgreSQL и MySQL до применения в production.

## [1.1.0] - 2026-11-22 — SkiF4er ID Stable (Forum Edition)

- Проверка развёрнутого SkiF4er ID дополнена HTTPS-запросом к реальной странице `/forum/login`, проверкой точной версии Forum Edition в HTTP-заголовке и свежего frontend-скрипта, без обхода TLS или следования редиректам.
- Приёмка БД обнаруживает осиротевшие OIDC-привязки/сессии, неполные метаданные SSO и расхождения отзыва браузерного токена и реестра сессий; отсутствие данных проверки не считается успехом.
- Исправлены блокирующие исторические константы в проверке staging/certification: проверяется настоящая версия Forum Edition и ядро 2.1.0, а не прежний движок 1.3.0.
- Публикация релиза требует внешнего доверенного Ed25519-ключа, полностью повторной проверки подписей и независимой сверки сертификата для конкретного коммита; временные PR-ключи не могут стать ключами публичного релиза.
- Добавлены отрицательные регрессионные тесты и fail-closed политика для CI/релизного процесса.
- **Ограничение:** исходный патч не является подтверждением production go-live; необходимы успешная внешняя сборка, реальный Keycloak, staging и операторское одобрение.

## [1.0.9] - 2026-11-22 — Security & Integration Acceptance (Forum Edition)

- Исправлен отсутствовавший исполняемый метод TOTP `matchingStep()` для подтверждения 2FA; единый проверенный шаг используется при локальном входе, подтверждении MFA и повышении assurance.
- TOTP теперь одноразовый: атомарный CAS `two_factor_last_step` отвергает повторный код, в том числе для конкурирующих запросов, а не только неправильные значения.
- В `/api/v1/auth/2fa/enable` и `/disable` OIDC-сессия не может обойти проверенное MFA через локальный пароль; ошибка БД/реестра сессий также запрещает операцию.
- `migrate:repair` запрещает автоматическое стирание failure-маркера MySQL/MariaDB DDL, который мог частично примениться. Проверяется неизменность исходного SQL сбойной миграции; требуется проверенный бэкап и ручная инспекция схемы.
- При миграции реестра сессий выявляются и потерянные связи с удалёнными токенами (`LEFT JOIN`), включая восстановленные старые базы с отключёнными foreign keys.
- Добавлены исполняемые регрессии контроллера MFA, MySQL migration repair и поэтапного SQLite upgrade (77 миграций, сохранность форума, OIDC link, SSO revocation, replay prevention).
- Добавлен read-only live-acceptance gate для реальной конфигурации БД, миграций, минимальных прав `forum-member`, Identity, OIDC discovery и HTTPS-развёртывания. Проверки интегрированы в CI.
- **Ограничение:** без выданных credentials зарегистрированного SkiF4er ID и реального staging-окружения браузерный e2e-вход и полный production go-live не могут считаться выполненными.

## [1.0.8] - 2026-11-22 — Unified Access Control (Forum Edition)

- Реальные изменения роли, группы, ACL, административных настроек и санкций форума теперь требуют краткоживущего подтверждения той же браузерной сессии через серверный AuthenticationAssurance до выполнения операции. Права RBAC ядра и действующие форумные ACL/санкции продолжают проверяться после подтверждения; MFA не заменяет разрешения.
- Для SkiF4er ID-сессий принимается только повторная авторизация у OIDC-провайдера с проверенным MFA evidence. Локальный пароль больше не может установить достаточный assurance на SSO-сессии. Local/legacy сессии по-прежнему подтверждаются текущим паролем и TOTP при включённом 2FA.
- Клиент форума обрабатывает step_up_required и повторяет только отвергнутую сервером операцию после локального подтверждения. Для SkiF4er ID запускает строго проверенный OIDC step-up с MAC-привязанным адресом возврата на исходную страницу форума, без автоматической повторной отправки мутации.
- Обычная публикация, чтение, голосование, профиль и локальный выход не требуют дополнительной авторизации. Новых миграций БД нет; ядро CajeerEngine остаётся версии 2.1.0.
- Добавлены исполняемые регрессионные проверки маршрутов, ACL и браузерных flow, подключённые к обязательному CI. Полный E2E с настоящим Keycloak/БД требует отдельной staging-проверки.

## [1.0.7] - 2026-11-22 — SSO Logout & Revocation (Forum Edition)

- В `/forum/account/sessions` добавлен отдельный глобальный выход для текущей SkiF4er ID-сессии: защищённый CSRF POST отзывает локальную сессию и возвращает проверенный URL для выхода у OIDC-провайдера. Локальный выход и завершение других устройств продолжают работать без IdP.
- Возврат от IdP в Forum Edition защищён случайным одноразовым `state`, привязанным к Secure HttpOnly SameSite=Lax cookie. Неподтверждённый callback не выдаёт успех. Административный выход сохраняет прежний адрес назначения.
- Сервер отказывает в глобальном выходе для локальной сессии, Bearer-токена, незашифрованного соединения и недопустимого `return_to`. Отзыв локальной сессии больше не выдаёт ложный успех при конкурентном завершении.
- Используются существующие проверка подписи OIDC Back-Channel Logout, защита `jti` от повтора и транзакционный отзыв `sid`/`sub` из ядра 2.1.0. Новые миграции базы не требуются.
- Добавлены исполняемые серверные и браузерные тесты; версия, CI и релизные метаданные обновлены до 1.0.7.

## [1.0.6] - 2026-11-22 — Unified Sessions (Forum Edition)

- `/forum/account/sessions` теперь отображает реальные активные браузерные сессии ядра: локальные, SkiF4er ID и перенесённые legacy, время последней активности, провайдер, устройство и маскированную сеть.
- Реальные операции завершения одной, остальных или всех сессий и атомарной ротации текущего HttpOnly-cookie/CSRF. Вызовы идут через существующий проверяемый `AuthService` и `UserSessionRepository` CajeerEngine 2.1.0, без дублирующего форума-хранилища.
- Старые обработчики `Forum\Service\IdentityService` переведены с прямых SQL-обновлений `ce_api_tokens` на единый реестр.
- Исправлены пути SQL-миграций в регрессиях после объединения ядра; новые схемы базы данных не требуются.
- Добавлены исполняемые браузерные и SQL-регрессии для Forum Edition и обновлена версия релизных проверок.

## [1.0.5] - 2026-11-22 — Account Provisioning (Forum Edition)

- Реальное создание форумного профиля при первом подтверждённом OIDC-входе через SkiF4er ID, только при явном `SKIF4ER_ID_AUTO_PROVISION=true` и открытой серверной регистрации форума.
- Атомарные создание локального UUID и связи `(issuer, sub)`, без объединения пользователей по email. Совпадение email с существующим профилем требует локального входа и ручной привязки.
- Назначение исключительно проверенной системной роли `forum-member`, подтверждение email из валидированного OIDC ID token, уникальный нераскрываемый пароль и запрет отвязки единственного метода входа.
- Общий с локальной регистрацией антиспам-лимит, блокировка конкурентного создания, повторная проверка политики под транзакцией и аудит `forum.member.register`.
- Ссылки регистрации на форуме и безопасные сообщения об ошибках; существующий локальный вход, linked SSO и админский вход остаются без изменения.
- Тестовый сценарий с реальным SQLite PDO в CI; боевой Keycloak, PostgreSQL/MySQL и production окружение требуют отдельной проверки перед выпуском.

## [1.0.4] - 2026-11-22 — Account Linking (Forum Edition)

- Форум `/forum/account/security`: реальная привязка SkiF4er ID после подтверждения локального пароля и TOTP, подтверждения у OIDC-провайдера и одноразовой browser-bound транзакции.
- Подтверждённый OIDC callback возвращает пользователя на форум, сохраняя административную привязку; отмены и ошибки отображаются без раскрытия токенов.
- Безопасная отвязка через защищённый CSRF POST с повторной проверкой пароля/TOTP: запрет для OIDC-only профилей, атомарный отзыв внешних сессий, сохранение локальных аккаунтов и сообщений.
- Проверка подтверждаемых учётных данных относится к тому же UUID, что и локальная сессия; продолжение привязки блокируется при отзыве сессии.
- Добавлены исполняемые регрессионные проверки и обновлена конфигурация релизного CI. Ядро CajeerEngine остаётся версии 2.1.0.

## [1.0.3] - 2026-11-22 — SkiF4er ID Login (Forum Edition)

- Connect the real SkiF4er ID confidential OIDC login to the Forum login page. The button is displayed only when the existing OIDC controller reports an enabled provider; local login remains available.
- Reuse the existing verified Authorization Code + PKCE S256 exchange, nonce/state checks, explicit linked `(issuer, sub)` identity, local session issuance, HttpOnly cookie, and server-side ACL. Do not trust OAuth data submitted by the browser.
- Carry a forum-only return path through a MAC bound to the random OIDC browser transaction cookie. Reject cross-origin destinations, URL authority, percent-encoded paths, path traversal, fragments and ambiguous separators. Preserve admin OIDC login separately.
- Return successful forum logins to the selected forum page. Return provider errors, replay, unlinked identities and insufficient MFA to a forum login screen with fixed, non-sensitive error messages; never expose tokens or internal exception details.
- Add regression coverage for the production controller and return-cookie path; retain core 2.1.0 and existing Forum 1.0.2 migration history. No database schema changes in this release.
- Live end-to-end login requires a registered HTTPS Keycloak client and a previously linked account. A networked staging/production OIDC handshake is not verified by these local tests.

## [1.0.2] - 2026-11-22 — Extension, Migration, API & Integration Stabilization

- Serialize extension database migrations with the shared database migration lock; recheck checksums and commit migration ledger entries in the same transaction as migration changes. Reject implicit transaction commits rather than reporting a successful migration.
- For MySQL, persist a fail-closed in-flight checksum before executing potentially non-transactional DDL; an interrupted migration will not be silently retried.
- Make development-only JSON extension migrations process-safe with an exclusive lock and durable in-flight marker; interrupted, non-transactional side effects now require explicit operator review instead of silent re-execution.
- Stage and publish extension static assets with verified source paths, size/count limits and a reversible directory swap. Reject source symlinks and protect the previously active asset set when staging fails.
- Return a bounded API validation error for malformed JSON instead of allowing JSON parsing errors to escape middleware.
- Enforce OpenAPI request limits for strings, objects, arrays, unique items, and oneOf/allOf, plus duplicate route detection, in the running middleware path.
- Apply server-side allowlists for outbound integration providers and destinations and bounded, configurable connection/payload budgets at create, update, rotation and execution time.
- Add executable stabilization regressions without changing existing core/Forum database migrations or the upstream CajeerEngine version (2.1.0).
- The supplied source archive identifies itself as Forum Edition 1.0.0, not 1.0.1. This patch does not claim a verified upgrade from an independently provided 1.0.1 artifact or independently certified production deployment.

## [1.0.0] - 2026-11-17 — Production Certified Stable (release gates)

- Stable tag `v1.0.0` has a separate fail-closed release-admission tool: genuine source/dist Ed25519 signatures, completed tagged CI and independent staging/DR audit, real PostgreSQL 10k/100k/1M+24h soak reports, both genuine SIGKILL upgrade-recovery checkpoints and a separately signed human operator GO bound to the exact input hashes.
- The GitHub release publisher refuses to publish a stable release if any required evidence is missing, has changed, or does not match the candidate commit / dist SHA-256. Stable certificates are issued only from witnessed evidence; local source builds are not certified releases.
- Updated signed upgrade acceptance and operator recovery playbooks; Core 1.3.0, database migration 0049 and application features are unchanged.
- **Release status:** the actual production certificate, complete physical evidence, official signing keys, and public release are issued only after the dedicated infrastructure runs succeed. No synthetic evidence substitutes for certification.

## [0.9.9] - 2026-11-17 — Release Candidate & Final Audit

- Freeze Forum feature development for the 1.0.0-rc.1 release branch; no new schema or feature changes.
- Require an independently executed staging clean install on canonical signed dist, with real HTTP authentication, data/ACL/FTS/media checks, and corresponding HTTPS disposable host proof.
- Gate publication on independent artifact/evidence audit bound to exact SHA-256 and GitHub CI run. Fail closed on missing, altered or incomplete staging evidence.
- Allow a separate metadata-only `1.0.0-rc.1` commit and matching signed tag without confusing prerelease with stable 1.0.0.
- The final RC cannot be declared certified until complete GitHub CI, staging and external physical soak/chaos and operator approval have succeeded.

## [0.9.8] - 2026-11-17 — Upgrade & Compatibility Certification

- Execute **real chronological updates** in the physical dist matrix (0.8.7 → 0.9.2 → 0.9.7 → 0.9.8), verifying user accounts, permissions, signed plugins, theme, FTS, media, posts and data invariants at each hop. Missing historical tags fail CI instead of being simulated by relabelling.
- Exercise rollback to the immediate predecessor and reject a correctly signed target with an invalid SQL migration. CI release candidates remain bound to the signed 0.9.8 dist bytes.
- Test systemd updates using an authentic prior release before deliberately injected migration failure; ensure TLS and existing accounts survive.
- Correct Compose recovery after interrupted preparation: resume the unchanged old release without invoking a database restore before a snapshot exists.
- The dedicated full Compose/process-kill tests require a disposable physical runner; local unit checks do not constitute physical certification.

## [0.9.7] - 2026-11-17 — Safe Production Operations

- Read-only Compose gate: verified public TLS renewal-window policy, verified off-site age and recovery-set parity, and optional deep existing ecosystem integrity scan.
- Real leased queue diagnostics: expired reservations across PostgreSQL, Redis and file backends, with Prometheus metric and alert; no autonomous retries.
- Scheduler task-staleness metrics and missing-critical-series alert rules; independently verified Blackbox HTTPS/TLS-expiry alerting; delivery/resolution integration exercises restarts of Prometheus and Alertmanager.
- Guarded subprocess timeouts, negative operator checks, durable post-crash queue recovery tests, and updated on-call instructions.
- These tests do not constitute proof that a remote production site delivered a paging notification or passed an independent audit.

## [0.9.6] - 2026-11-17 — Disaster Recovery Certification

- Added a **physical signed-current-dist** DR acceptance that takes a real managed, encrypted backup while HTTP reads and acknowledged writes continue; verifies off-site readback using the durable catalog, then completely deletes the source app and source database.
- Fetches the exact content-addressed recovery set through `backup:fetch` from independent filesystem or TLS S3, with the recovery key escrowed outside the failed host and no local backup available; restores to a different app path and fresh PostgreSQL database when selected.
- Requires authenticated portable recovery, protected maintenance state, idempotent resume, actual CA-verified HTTPS, user login, roles/ACL/hidden content, full-text search/SSR, extensions/theme, original private media and a new write on the restored site.
- Measures service RTO **through successful HTTPS read/write acceptance**, and bounds snapshot age at service availability for RPO reporting. Both reject missed limits. A test-only TLS ingress is used in CI; this does not certify production Nginx, DNS failover or a separate physical failure domain.
- Current signed dist is tested on SQLite, PostgreSQL 17/18, and an additional S3/MinIO TLS fixture; release acceptance remains fail-closed. Corrected the older cold-recovery test to actually pass its separate backup key and extension trust root to the backup process.
- No database migration, no changes to upstream Core 1.3.0. Physical cross-host recovery in an independent production-like environment is still required before claiming disaster-recovery certification.

## [0.9.5] - 2026-11-17 — Failure Recovery & Chaos Testing

- Added executable disposable-only failure injection for PHP-FPM restart, labeled backend network partition, real tmpfs ENOSPC and interrupted PostgreSQL transactional DDL, retaining worker SIGKILL and database/Redis outage recovery.
- Failed-readiness/failed-write assertions and post-fault acknowledged-write integrity are verified after every applicable disruption; all faults have bounded recovery/cleanup paths.
- Application gateway and container readiness now fail closed on critical `/tmp` or persistent storage free-space exhaustion.
- Added gated SIGKILL-after-durable-checkpoint physical update interruption and invocation of the existing signed Compose recovery operator; never silently roll back post-activation writes.
- 0.9.5 physical 24-hour certification requires all extra chaos checks, with local negative tests mandatory in short CI. Physical tests and signed full upgrade/recovery remain pending on a dedicated host.
- No new schema migration. CajeerEngine core 1.3.0 unchanged.

## [0.9.4] - 2026-11-17 — Load & Soak Certification

- Extended the **working** destructive Compose qualifier: PostgreSQL-backed corpus FTS, production SSR/API/ACP, concurrent writes, ACL/cache warm/invalidate/restore, verified notification delivery with p50/p95/p99 upper bounds, and the existing fault/confirmed-data checks.
- Sampled real cgroup-v2 CPU/RAM/block I/O and PostgreSQL transactions, reads/cache, temp I/O, WAL, locks, database size, durable queue and notification backlog from **running** containers. Sampling runs concurrently with the paced HTTPS workload and fails closed on missing telemetry; fixed-memory HTTP histograms remain bounded for 24-hour runs.
- Added three executable, **non-shortcuttable** certification profiles for 10k, 100k and 1M synthetic PostgreSQL posts (24-hour soak for 1M); independent Ed25519/artifact SHA-256 and Git-commit-bound evidence verification; a manually protected, dedicated self-hosted workflow.
- Added local regression tests of telemetry parsing, background observation, queue-delivery measurements, and rejection of incomplete/incorrect certification evidence; short Compose CI remains a mandatory smoke gate.
- **Important:** no PostgreSQL/24-hour physical runs are claimed by source implementation alone. Site-specific capacity certification needs the actual completed independent reports and operator signoff. No SQL migration beyond 0049; upstream Core stays 1.3.0.

## [0.9.3] - 2026-11-17 — Database & Performance Optimization

- Replaced unbounded in-memory candidate and lock maps in mark-board/mark-all read operations with snapshot-captured, disk-spillable 500-row streams, preserving deterministic locks and post-lock ACL revalidation.
- Scoped ignored-author queries to authors on the current 25-post page instead of loading every ignored member for a user.
- Pushed recipient and delivery-state filters into both notification UNION branches to prevent large cross-user inbox intermediates; the inbox update uses independent parameter sets.
- Filtered deleted PostgreSQL/MySQL FTS candidates before rank calculation without weakening subsequent live-thread/ACL checks.
- Added driver-specific inbox and unread-by-board access indexes in migration 0049.
- Added populated-database upgrade checks and query-plan evidence for PostgreSQL and SQLite.
- Performance limits remain workload- and hardware-dependent; complete production PostgreSQL profiling and 1-million-post soak certification are separate acceptance requirements.

## [0.9.2] - 2026-11-17 — Production Security Hardening

### Security fixes (working runtime)
- Strictly parse bearer/API-token credentials; invalid or ambiguous headers no longer fall back to cookies. Cookie CSRF checks remain mandatory for state-changing requests.
- Atomic owner-only API token revocation prevents cross-account token-ID probes in personal token management.
- Mask unclassified runtime errors in HTTP responses to prevent filesystem, network and credential detail disclosure (internal logger retains request correlation).
- Validate HTTPS-only public webhook destinations at creation/update and again before outbound sending; reject private/loopback/metadata addresses and invalid DNS resolutions.
- Reject client-chosen webhook secrets and generate 256-bit HMAC secrets; remove signing secrets from webhook update responses and retain one-time disclosure during provisioning.
- Reject writable/symlinked extension publisher keys; retain signed package inventory checks and update signed Forum sample extensions to the tested 0.9.x compatibility range.
- Reject raster uploads above 16 million pixels before derivative processing. Drop capabilities from PostgreSQL/Redis Compose services, while retaining the minimum entrypoint permissions to transition to unprivileged runtime users.
- Add standalone hardening regression and negative real-HTTP integration scenarios; keep all release certification gates required.

### Scope
- Forum Edition version 0.9.2; CajeerEngine upstream 1.3.0 unchanged. No database schema migrations.
- External penetration testing, production host deployment and full 24-hour soak certification are not claimed by this release.

## Forum Edition 0.9.1 — Release Certification Pipeline

- Added a single canonical source/dist candidate in mandatory CI; locked Composer audit (no advisories or abandoned packages), physical archive integrity verification and detached Ed25519 signatures. Tagged release candidates require an externally configured publisher signing key and separately provisioned trusted public key; branch/PR test candidates use a disposable CI key and cannot be published.
- Release acceptance and native Linux/systemd production-host now consume the **same signed dist ZIP bytes** instead of rebuilding independent test packages. Production acceptance is bound to the approved dist SHA-256.
- Added fail-closed GitHub Actions run-attempt matrix collection with exact logical/matrix job cardinalities, real conclusions, commit and run identifiers; `certification-evidence.py` seals those results with host acceptance, audit and source/dist signatures/checksums. `forum-required` requires successful certification.
- Release publisher now requires an independent trusted Ed25519 public key, verifies the actual detached signatures and inventories, confirms the local release tag points to the certified commit and rechecks the complete successful remote GitHub Actions run before creating a non-overwriting draft. Reports and signed artifacts ship together.
- Documented branch-protection, key provisioning and manual reproduction. No database migrations or application API changes; Engine remains 1.3.0. The release is not a substitute for 24-hour or external production certification.

## Forum Edition 0.9.0 — Production Operations Stable

- Added `deploy/container/operations.py`: a **read-only** admission check on the real, running Compose project. Verifies all required container health states, exact running app/gateway image tags, successful installer, authenticated release-volume checksums, database readiness and worker/scheduler watchdogs. Requires TLS validation (system trust or supplied CA), checks public readiness, edition version, rendered forum and ACP, and confirms protected routes remain hidden. Optional `--require-backup` enforces a recent, verified off-site backup and zero pending deliveries. Produces atomic private JSON evidence; exits nonzero on failed checks.
- Compose update/rollback now requires the same operational admission check *after* gateway activation and *before* writing a committed/rolled-back journal state. On failure traffic is stopped and recovery remains operator-controlled; an HTTP 200 alone cannot commit an unhealthy release.
- Fixed documented site.env support for `CE_LOOP_TIMEOUT_SECONDS`, `CE_RATE_LIMIT_GLOBAL` and `CE_RATE_LIMIT_FORUM_SEARCH`. Enforces the installer limits and rejects configuration drift across an update. Redis queue stays supported for fresh deployments but cannot be upgraded with the snapshot-based operator.
- Corrected background worker/scheduler loop health age to track the configured 30–3600-second batch timeout instead of a fixed 420 seconds; a failed batch still immediately invalidates its health marker.
- Added read-only `deploy/production.py verify` for signed host artifact integrity, systemd units, internal and public TLS/Forum health and optional verified off-site backup; wired the live host smoke to the gate. Added real local TLS and failed-dependency operator tests, plus physical Compose CI operational gates; update CI baseline is the authentic 0.8.7 release. No schema migration; upstream CajeerEngine remains 1.3.0.

## Forum Edition 0.8.7 — Production Reliability & Qualification

- Run paced, bounded-memory HTTPS load and soak qualification against disposable Compose installations, including real PostgreSQL corpus, concurrent writes, ACL revocation and confirmed-data validation.
- Exercise worker termination, PostgreSQL/Redis outage and service recovery with measured resource/latency thresholds and atomic JSON evidence; CI runs short qualification on both queue modes. Long and million-post profiles require dedicated infrastructure.
- Mandatory Redis readiness, fail-closed 503 on rate-limit dependency failure, bounded telemetry locks, bounded worker cycles and stale-health invalidation with retry backoff.
- No new SQL migration; upstream remains 1.3.0. Full certification requires actual passing integration evidence.

## Forum Edition 0.8.6 — Forum Observability & Monitoring

- Reuse real HTTP histograms for SSR, search and publication, with bounded labels and p50/p95/p99; worker success rather than mere process activity controls health.
- Read-only capped SQL queue and overdue-age probes, PostgreSQL connection and lock statistics, Redis cache counters, verified backup and recovery gauges. Migration 0048 adds queue probe indexes.
- Operations ACP shows real forum latency and queue state; authenticated Prometheus scrape, provisioned Grafana dashboards, TLS webhook Alertmanager and production alert rules.
- Monitoring runs independently of application processes; native SQL and container monitoring acceptance are required by CI. Engine 1.3.0 unchanged.

## Forum Edition 0.8.5 — Disaster Recovery & Restore

- Restore authenticated encrypted off-site bundles into a fresh SQLite path or newly allocated PostgreSQL 17+ database, without connecting to or replacing the source database.
- Durable private recovery workspace, digest-bound retry, maintenance-locked atomic publication, application boot/SSR/API/ACL/search probes and measured RPO/RTO receipt.
- Read-only content-addressed off-site retrieval for filesystem and S3. Physical SQLite loss/recovery regression and native SQLite/PostgreSQL new-host acceptance.
- No SQL migration; Engine 1.3.0 unchanged. External queues/storage and PITR remain separate operator procedures.

## Forum Edition 0.8.4 — Automated Backup & Retention

- Schedule verified recovery sets with durable checkpoints, bounded activity drain, capacity checks and safe retries; never prune manual/updater backups.
- Encrypted off-site filesystem and S3 multipart transport with complete readback SHA-256; UTC daily/weekly/monthly retention with two-copy floor and optional remote pruning.
- Systemd timers and a signed-image Compose backup operator; PostgreSQL queue and local authoritative storage only. Existing external queue/storage recovery guards remain enforced.
- Sanitized backup health/history in Operations ACP and Prometheus; physical SQLite backup/restore regression and native PostgreSQL/Compose + TLS S3 acceptance in CI.
- No SQL migration; Engine 1.3.0 unchanged.

## Forum Edition 0.8.3 — Secure Updates & Release Management

- Enforce signed release channel, exact approved target version and target Engine API compatibility; reject altered historical migrations before backup/apply.
- Recovery-aware preflight estimates staging/backup disk space, checks recovery prerequisites, verifies the current installation, and removes dry-run or abandoned unjournalled stages.
- Durable CLI update history and sanitized ACP progress; reject ambiguous release catalog entries.
- Offline, journalled Compose update/recovery using the installed publisher key, exact target digest, stopped writers, existing BackupManager and UpdateManager, and real TLS/SSR/ACP acceptance. PostgreSQL queue mode only; Redis queue restore remains unsupported.
- No schema changes; upstream Engine 1.3.0 unchanged. Full Docker and native database acceptance remains a required CI gate.

## Forum Edition 0.8.2 — Containerized Deployment & Runtime

- Verified signed-dist PHP-FPM image and TLS gateway; persistent PostgreSQL, Redis, application and runtime volumes.
- One-shot non-root installer, fail-closed identity and configuration checks, supervised workers and scheduler with health checks. PostgreSQL queue by default; optional Redis queue has explicit recovery limitations.

## Forum Edition 0.8.1 — Forum Production Deployment

- Deploy only verified stable Forum Edition dist; distinguish edition version from upstream VERSION throughout deployment, recovery, build and publish tooling.
- Gate public traffic until real loopback FPM health, Forum API, SSR and ACP shell are verified; check target ecosystem before updates and keep durable gate markers.
- Production host acceptance covers forum publication, ACL/FTS, notification worker and ACP load, alongside inherited security tests. Require dedicated host CI in forum-required.
- No schema migration. Host certification requires a successful physical CI run.

## Forum Edition 0.8.0 — Administration & Extensibility Stable

- Consolidate ACP policy, delegation, published theme and signed extension readiness in the operator CLI and ACP maintenance screen.
- Validate the active theme projection against the editor document; prevent conflicting imports with a database-backed start lock.
- Keep activation time on continuous extension upgrades and repeated enable, preserving eligibility for pending forum events.
- Check target extension compatibility before update and ecosystem readiness after update; block updates during incomplete imports.
- Ship compatible 1.0.1 manifests for both example plugins. Installed signed 1.0.0 packages must be upgraded or disabled before the 0.8.0 transition.
- Expand signed release acceptance to exercise ACP, theme, plugin state, migrations and rollback. No schema migration; certification requires the actual CI matrix.

## Forum Edition 0.7.7 — Migration Reliability & Ecosystem

- Bounded multi-object relation batches, transaction-local statement and mapping reuse, and source snapshot plan reuse.
- Prevent overlapping imports and premature maintenance exit; validate backup presence and optional full verification, add bounded CLI runtime.
- Check import mappings, search index and active theme before completing; verify enabled extension dependency graph before import.
- ACL-safe historical topic, post and board redirects scoped to a completed job. No schema migration.

## Forum Edition 0.7.6 — XenForo & Multi-Forum Migration

- Native read-only adapters for XenForo 2.2/2.3, MyBB 1.8 and SMF 2.1; shared transactional importer, source-specific IDs and checkpoint verification.
- Import disabled accounts, non-privileged memberships, nested hidden nodes, visible topics/posts, local attachments, closed polls and compatible votes/watches.
- ACP migration command preparation, paginated job reports and backup IDs; credentials and execution remain CLI-only.
- No schema migration; preserve phpBB jobs and existing API operations. Integration tests cover source snapshots, rollback/resume, FTS and attachment safety.

## 0.7.4 — Forum Extension Platform

## Forum Edition 0.7.5 — Forum Migration Engine & phpBB

- Operator-only read-only phpBB 3.3 snapshot plan and resumable transactional import with verified recovery backup, source ID mapping and explicit skip reports.
- Import users (disabled, replacement credentials), non-privileged groups, hidden readonly tree, approved topics/posts, validated attachments, closed polls and compatible votes/subscriptions.
- Validate target integrity, preserve existing data, suppress historical mail/plugin events. Migration 0047. See [operator guide](docs/forum-migration.md).

- Transactional forum event outbox with per-subscriber progress, leased workers, retry/dead-letter recovery and Redis wakeups.
- Forum SDK and safe SSR panel slots; explicit Forum Edition compatibility and permissions.
- Installable announcements plugin with scheduling, ACP editor and deduplicated publication metrics.
- Migration 0046 for SQLite, PostgreSQL and MySQL.

## 0.7.3 — Forum Themes & Styling

- Three built-in forum designs, light/dark/system schemes and a validated design-token editor in Forum ACP.
- Separate persisted draft, SSR preview as a public reader, activation, custom design library and ten previous active versions.
- Optimistic revisions, transactional audit and existing settings delegation; theme changes participate in the SSR cache identity without invalidating draft-only changes.
- Migration 0045; preserve existing user scheme preferences, search, ACL, SEO and engine version.

## 0.7.2 — Advanced Administration

- Live community settings, optimistic revisions and audited before/after history.
- Scoped ACP roles with transactional assignment and revocation.
- Scheduled public publication pause, bounded cleanup, and search/database/Redis diagnostics.
- Migration 0044; preserve previous settings defaults and forum ACL.

## 0.7.1 — Forum ACP

- Integrated Forum ACP into the production admin SPA with live statistics, paginated operator lists, structure/ACL, groups, member administration, content actions and moderation.
- Added current-administrator checks and transactional member-update audit; self, administrative and erased-account changes are rejected.
- Existing forum services enforce ACL, revisions, moderation decisions and cache invalidation; no new migration.

## 0.7.0 — Search, SEO & Performance Stable

- Search and discovery exclude threads without a published post, before count/ranking; PostgreSQL quoted phrases verify literal tokens against a non-positional candidate query.
- Request-scoped SEO policy shares the forum database connection and cache identity; honor robots follow policy and isolate numbered/cursor HTML variants in Redis.
- PostgreSQL search has a configurable transaction-local statement timeout, respecting stricter DBA settings; cancellation yields a retryable 503 without replay.
- Release acceptance uses an authentic 0.6.6 baseline and verifies search/SSR/sitemap after installation, upgrade and rollback. PostgreSQL 17/18 CI includes real Redis lifecycle tests.
- Search index diagnostics use a coherent SQLite integrity snapshot and validate PostgreSQL index ownership and MySQL FULLTEXT column order.
- No schema migration beyond 0043; preserve the 1.3.0 engine identity and API compatibility.

## 0.6.6 — High Load & Reliability

- Shared PostgreSQL publication board locks retain exclusive topology/ACL changes; sequence allocation uses UPDATE RETURNING.
- Batch notification fan-out and bounded spam cleanup; PostgreSQL workers skip already claimed notification/cleanup rows.
- Database outages return retryable 503 without replaying writes; workers defer reservations during database outages and preserve durable state.
- Redis queue distinguishes an empty queue from an unconfirmed transition; existing lease recovery and fencing remain in place.
- Multi-process HTTP load, SQLite WAL concurrency, native PostgreSQL lock/recovery and Redis outage regressions with measured evidence.

## 0.6.5 — PostgreSQL Performance

- Live feed indexes with mixed-direction sort, partial live post ranges on PostgreSQL/SQLite, equivalent composite indexes on MySQL/MariaDB.
- Cursor pagination on latest discussions, bounded page-first joins, batched reaction counts and indexed unread ranges.
- Sixteen PostgreSQL cache generation shards per table, read as a snapshot digest; no cross-version rolling deployment.
- Migration 0043, query-plan and cursor regressions, PostgreSQL concurrent generation regression.

## 0.6.4 — Redis Runtime & Caching

- Redis-backed guest HTML/API and private unread counters; SQL snapshot generations invalidate stale content and permissions atomically.
- Shared Redis rate limits with fail-closed behavior, bounded connections, lazy queue initialization and durable SQL outbox wakeups via Redis workers.
- Migration 0042, runtime diagnostics, regression coverage and operational guidance.

## 0.6.3 — Server Rendering & SEO

- Render home, boards, discussions, public profiles, latest and question listings as real HTML with the current viewer ACL; existing JavaScript preserves interactive behavior.
- Guest canonical URLs, descriptions, Open Graph/Twitter metadata, safe JSON-LD discussion/question/profile/breadcrumb markup, correct 404 and merge redirects.
- Separate paginated forum sitemap and public RSS feed; robots.txt advertises the forum sitemap alongside CMS, configured APP_URL is the sole origin.
- Board-level indexing switch with inherited exclusions, global configuration and CMS indexing flags; migration 0041 preserves existing visibility and enables indexing by default.
- Private/authenticated and non-indexable routes use noindex and no-store. Add SSR, sitemap, migration and security regressions.

## 0.6.2 — Advanced Search & Discovery

- Combine full-text search with exact board, author, tag, UTC date and question-status filters; sort by relevance, publication date, activity or replies.
- Quoted token phrases and excluded words/phrases, stable result counts and safe visual highlighting.
- Prefix title/tag suggestions, privacy-aware author picker, related topics in the composer and optional PostgreSQL trigram fallback.
- Apply ACL, live content, bilateral blocks and ignores before ranking/counting; author discovery also respects profile/activity privacy.
- Bound search to 100 characters, 16 clauses, 100 pages and a configurable per-IP search budget. Reuse migration 0040 indexes; no new schema migration is required.
- Add SQL, browser and native-driver regressions.

## 0.6.1 — Forum Full-Text Search

- Replace forum substring search with indexed PostgreSQL GIN search (English/Russian), SQLite FTS5 and MySQL FULLTEXT; exact tag lookup remains available.
- Ranked discussion and individual-message results, safe text snippets and links to the correct message page.
- Migration 0040 backfills existing content and keeps indexes transactional. ACL and live-content checks precede pagination; private messages and unpublished submissions are excluded.
- Add `forum:search:check` and `forum:search:reindex`, API/SDK updates and lifecycle regression coverage.

## 0.6.0 — Trust & Safety Stable

- Member safety center with personal public/private report status, without moderator evidence or identities.
- Report and block private-message authors atomically; repeat reports do not consume abuse budgets.
- Atomic report resolution and sanctions, board-scoped for public cases and admin-only for private cases.
- Preserve report history and appeal workflow; migration 0039 and integrated safety regression.

## 0.5.6 — Trust & Personal Safety

- Live trust based on verified email, account age and published posts, enforced in new conversations and group invitations.
- Recipient-controlled trusted/following contact policies and preserved existing preferences.
- Bilateral blocks on new follows, reactions and answer votes; filtered activity and social notifications, including subscription email.
- Personal progress UI, profile blocking, migration 0038 and regression coverage.

## 0.5.5 — Member Privacy & Personal Data

- Dedicated privacy center links existing live visibility, communication and notification controls.
- Session-only, paginated JSON exports of personal records; current board ACL and conversation membership protect content; credentials and internal moderation evidence are excluded.
- Password/TOTP-confirmed profile clearing and irreversible account closure, including pending submissions, drafts, subscriptions, private preferences and tokens. Shared content and moderation evidence retain an anonymized foreign-key identity.
- Migration 0037 on SQLite/PostgreSQL/MySQL, account reactivation guards, integrity checks and network regression coverage.

## 0.5.4 — Anti-Spam & Abuse Protection

- Transactional account and registration limits, flood protection for publications, edits, private messages, reports, uploads and votes.
- Duplicate/link/mention screening routes suspicious public submissions to the existing review queue; configured blocked domains are rejected.
- Aggregate diagnostics and bounded expiry cleanup.

## 0.5.3 — Warnings & Sanctions

- Issue and revoke warnings and time-limited restrictions, with current board ACL and protected administrators.
- Enforce posting, conversation, upload and reaction restrictions inside publication transactions; suspend sessions and provide restricted appeals.
- Add personal sanctions, moderator management, appeal decisions and migration 0035.

## 0.5.2 — Moderation Center

- Add ACL-filtered report and publication queues, assignment, priority, evidence snapshots, private notes and versioned decisions.
- Hold pending topic/reply submissions and their files outside published content; approve atomically as the original author.
- Protect pending files and target discussions from cleanup, add author status/cancellation and moderator review UI.
- Migration 0034, API/SDK, SQL/browser regression and release acceptance coverage.

## 0.5.1 — Soft Delete & Recycle Bin

- Soft-delete forum topics and replies; restore original content and linked data with current node ACL.
- Add moderator recycle bin, administrator-only purge, open-report protection and bounded retention worker.
- Apply visibility to search, counters, activity, Q&A, notifications and attachment delivery.
- Preserve migration history and verify 0.5.0 upgrade and rollback.

# Changelog

## Forum Edition 0.5.0 — Content Platform Stable

- Retry identical draft saves safely after a lost response; freeze the composer during publication.
- Advance revision fences on attachment-only edits/removals; do not restore deleted file bytes from text revisions.
- Invalidate stale raster variants on regeneration and enforce per-variant bounds.
- Extend content integrity and physical upgrade/rollback acceptance for real 0.4.5 drafts, revisions, polls, answers and files.
- No new migrations; all existing content IDs and migration checksums are preserved.

## Forum Edition 0.4.5 — Drafts & Revision History

- Private server drafts for topics, replies and conversations, autosave, attachment lifetime and idempotent publication.
- Immutable text revisions, optimistic edit checks, comparison and restoration through normal permissions.
- Migration 0032 backfills existing messages; previous migrations remain unchanged.

## Forum Edition 0.4.4 — Polls & Q&A

- Transactional single/multiple choice polls with deadlines, private ballots, controlled result visibility and close/reopen.
- Question topics, separate answer votes, accepted answers, accepted-answer outbox notifications, ranked answers and ACL-filtered question lists.
- Preserve existing discussions; reset moved answer state and reject lossy structured-topic merges.
- Migration 0031, API/SDK, forum UI and regression coverage.

## Forum Edition 0.4.3 — Media & Storage

- Private sanitized thumbnail, medium and large derivatives, inline attachment markers and keyboard-accessible image galleries.
- Durable asynchronous processing, retries, verified private S3 original replicas and tombstone cleanup.
- Media diagnostics and recovery, migration 0030, original attachment bytes preserved.

## Forum Edition 0.4.2 — Attachment Pipeline

- Private multipart uploads, MIME and extension validation, per-user/group quotas, SHA-256 verified downloads.
- Atomic attachment binding to topics, replies and conversations, with live node ACL or membership checks and automatic expiry cleanup.
- Browser attachment selection, pending upload recovery, and deletion with current edit permissions.
- Migration 0029 on SQLite, PostgreSQL and MySQL; preserves existing messages and UUIDs.

## Forum Edition 0.4.1 — Rich Text Editor

- Add plain, Markdown and BBCode source formats to forum posts and conversation messages; preserve legacy content literally.
- Safe server rendering and authenticated preview, visual editor, formatting toolbar and source modes.
- Exclude code and escaped references from notification fanout; resolve quote links within the current authorized topic.
- Add migration 0028 and renderer regressions. Full release certification requires CI.

## Forum Edition 0.4.0 — Community Stable

- Enforce a shared hourly invitation budget for conversation creation and later invitations; blocked participants cannot bypass send restrictions by editing existing messages.
- Separate all private unread messages from unmuted conversation notifications, keeping the original API counter compatible.
- Check stored JPEG headers, bounds and markers before delivery and during offline integrity verification. Reject inconsistent notification/reaction references and invalid conversation counters during installation/update verification.
- Check mail batch ownership and remaining lease immediately before SMTP, and report only delivery acknowledgements committed under that lease.
- Physical 0.3.6 upgrade/rollback acceptance now seeds community profiles/images, read state, delivered notifications, sent and pending email, private messages, follows and reputation before upgrading. Verify those same data on upgrade and both rollback paths.
- No schema migration; all previous migrations and API routes are unchanged. Full release certification requires successful SQLite/PostgreSQL, HTTP and browser CI.

## Forum Edition 0.3.6 — Reputation & Activity

- Typed reactions, live public reputation and configurable achievement thresholds.
- Personal, community and following activity feeds with current ACL and profile privacy; private follow/ignore relationships.
- Additive migration 0027, profile and thread UI, API/SDK, database and HTTP regressions.

## Forum Edition 0.3.5 — Private Conversations

- Private and group text conversations, membership-isolated history and invites, owner handoff, edit, unread watermarks, search and archive/mute.
- Incoming-message preferences, bilateral blocks, transactional rate limits, reported-message snapshots and audited administrator review.
- Conversation alerts in the notification center, profile entry points, API/SDK, migration 0026 and integration regressions.

## Forum Edition 0.3.4 — Watches & Email Delivery

- Thread and direct-board watches with tracking-only, in-app, email, daily and weekly modes; automatic watches, account manager and signed unsubscribe.
- Durable per-post delivery, recipient leases, bounded SMTP, retry/backoff, coalescing, current ACL and verified-address checks; shared identity SMTP transport.
- Additive migration 0025, preserved watch modes on merge, real Mailpit integration regression and release acceptance.

## Forum Edition 0.3.3 — Notification Engine

- Transactional database outbox for replies, explicit UUID mentions and quotes, reactions and moderation state changes; worker dispatch is atomic and repeatable.
- Personal inbox, unread counter, per-type preferences, mark read and ACL-checked current post links.
- Migration 0024, OpenAPI/SDK, HTTP and physical upgrade regression coverage.

## Forum Edition 0.3.2 — Read Tracking

- Durable per-account read watermarks, unread threads and subtree counters, first-unread navigation and explicit thread/board/all acknowledgements.
- Per-thread monotonic publication sequences survive ordinal reuse; moved/merged messages get new destination sequences. Acknowledgements from stale pages cannot skip newly published replies.
- ACL-filtered queries, authenticated CSRF-protected writes, monotonic concurrent updates, migration 0023 and integrated upgrade/HTTP/browser regressions.

## Forum Edition 0.3.1 — Member Profiles

- Persistent profile details, avatar and cover, signatures, custom fields, privacy, member directory and account preferences.
- ACL-safe profile activity and private image delivery. Additive migration 0022; upstream engine version unchanged.

## Forum Edition 0.3.0 — Forum Structure Stable

- Recheck account status and current role scopes inside forum ACL and administrative transactions; serialize principal changes against user-role updates.
- Resolve reports with thread-first locks, consistent with moderation and discussion transfers.
- Reject inconsistent discussion, group and redirect data during installation and post-update verification. Preserve all existing migration checksums.
- Extend physical dist acceptance with existing private nested forums, group assignments, subscriptions, report/reaction identity and merged URLs across signed update and rollback.
- Certification is conditional on the full `forum-required` matrix; no new schema migration or API operation.

## Forum Edition 0.2.5 — Forum Navigation & Admin

- ACL-safe server breadcrumbs, collapsible public tree and category anchors.
- Transactional relative node reorder with mouse drag-and-drop and keyboard selection; reject cycles and invalid targets.
- Editable permission matrix, optimistic ACL revisions and server-side inspector with inherited rule trace.
- API/SDK updates and navigation, ACL, browser and signed upgrade regressions. No schema changes.

## Forum Edition 0.2.4 — Discussion Management

- Transactional topic move, merge, split and reply transfer; retain post IDs, reactions, reports and source subscriptions on merge.
- Bulk lock/unlock/pin/unpin/move with ACL checks in all boards and audit; redirect old merged reads with source and target ACL.
- Prefix and tag editing, exact tag search, board posting rules and integrated moderation controls; migration 0021 and regression tests.

## Forum Edition 0.2.3 — User Groups

- Role-backed custom groups, primary and secondary assignments, scoped forum rights and delegated moderation.
- Transactional group administration, role membership audit and safe deletion; preserve unrelated core roles.
- Migration 0020, six admin API operations, SDKs and forum UI; group access regressions.

## Forum Edition 0.2.2 — Node Permissions

- Inherited category and board ACLs for guests, authenticated members, roles and individual users; private and hidden nodes.
- Enforce read and mutation permissions on all forum routes; filter lists before pagination and disable shared caching.
- Transactional ACL editor, API and migration 0019. Preserve existing IDs and public access by default.
- Add resolver and live HTTP security regression; signed upgrade baseline 0.2.1.

## Forum Edition 0.2.1 — Forum Node System

- Add nested boards and containers, inherited read-only/archive state, direct counters and last activity.
- Transactional category and board management: edit, move across categories, reorder and safe deletion; preserve UUIDs and content.
- Add migration 0018 (SQLite/PostgreSQL/MySQL), validate graph and indexes in readiness, keep prior migrations intact.
- Public tree and admin controls; five new API operations and regenerated SDKs.
- Add real database and network node regressions; physical release acceptance now upgrades from 0.2.0.

## Forum Edition 0.2.0 — Forum Core Stable

- Read-only migration ledger verification with missing, pending, failed and mismatched migration detection.
- Check actual forum schema, identity columns, required indexes and system roles during installation, HTTP readiness and update acceptance. Reject damaged installs; rollback failed updates.
- File cache by default; production installations default to closed registration unless explicitly enabled. Preserve existing configuration.
- Protect forum system roles from deletion. Fix edition-version checks and isolate Composer autoload in test fixtures.
- Test physical no-dev dist on SQLite and PostgreSQL 17/18: genuine 0.1.4 baseline, signed update, preservation of content and sessions, rollback, failing migration and corrupted ledger. Require release acceptance in CI.
- No migration or public API changes. Keep upstream 1.3.0. Release admission requires successful CI evidence, not only a version label.

## Forum Edition 0.1.4 — Forum Test Infrastructure

- Add executable PHPUnit validation, access, identity and database tests.
- Run isolated installed forum over real HTTP, including SMTP, session revocation, CSRF, RBAC and rate limits.
- Run Playwright Chromium and Firefox against real pages; collect JUnit and failure diagnostics.
- Run PostgreSQL 17/18 with dedicated databases and multi-process forum regression.
- Include source-only test tooling in release packages; no schema changes.

## Forum Edition 0.1.3 — Forum Identity & Access

- Email verification for new registrations, SMTP delivery, one-time hashed tokens and password recovery.
- Session listing and revocation, password reset invalidates all access tokens.
- Two-step TOTP enrollment, single-use recovery codes and TOTP replay protection.
- Additive migration 0017; existing active users remain active. Configure SMTP before opening registration.

## Forum Edition 0.1.2 — Database & Installer

- PostgreSQL 17+ / SQLite 3.40+ installation checks; SQLite foreign keys, WAL and persistent database path validation.
- Atomic dotenv configuration, database preflight before replacement, protected installer lock and transactional first-admin/forum bootstrap. CLI and web support community name, initial category/board and registration policy.
- New `0016_forum_lookup_indexes.sql` for discussion, member, subscription and foreign-key lookup paths; earlier migration checksums unchanged.
- Migration status detects missing files and checksum changes; PostgreSQL board listing fixed. Real installer/upgrade regression suite and PostgreSQL 17/18 CI matrix.
- Edition version 0.1.2; upstream engine and API unchanged.

## Forum Edition 0.1.1 — Forum Core Refactoring

- Move live forum SQL and business workflows into repositories and transactional services. Preserve API v1 and all existing migrations.
- Serialize thread mutations, validate request/domain input, enforce actor scopes and audit state changes atomically.
- Make report resolution and engagement operations idempotent; return explicit validation, not-found and contention errors.
- Use the independent Forum Edition version for release artifacts and update comparisons; keep upstream 1.3.0 and engine API 1.1 unchanged.
- Add real database/concurrency/rollback regressions and extend release/updater tests.
- First transition from 0.1.0 requires manual overlay because the old updater compares engine versions.

## 1.3.0 — Integration Certification / Production Stable

- Real TLS production HTTP security, CMS lifecycle and concurrent mixed workload acceptance with per-route latency and throughput budgets.
- Dependency audit and artifact-bound acceptance evidence required before signing and GitHub publication.
- Hardened request throttling and static administration security headers.
- Production admission is conditional on passing the complete release CI; local stable status is not certification.

## 1.2.5 — GitHub distribution migration

- GitHub Releases REST discovery, canonical release assets and signed draft-first publication after release verification.
- Distribution URLs and contributor instructions moved to GitHub; existing updater signature trust is unchanged.

## 1.2.5 — Production Deployment

- Signed, SHA-256 pinned production deployment from a clean Composer-locked dist, without dependency resolution on the target.
- Dedicated Nginx TLS site, PHP-FPM socket, queue worker and persistent systemd scheduler timer. Runtime services mount application code read-only.
- Root-owned deployment journal and external maintenance gate, explicit recovery resume, preflight, HTTP readiness and transactional update rollback.
- Real Nginx/TLS safety regression and full PostgreSQL/systemd deployment smoke gate in release CI.

## 1.2.4 — Disaster Recovery

- Streaming authenticated encryption for off-host database, files, and environment recovery sets.
- Atomic import with full verification before publishing; no overwrite of existing recovery sets.
- Isolated SQLite and PostgreSQL restore drills with data/table checks, recovery-time and snapshot-age budgets.
- Real cold recovery release smoke: destroy installation, recover from external bundle, boot and verify content.

## 1.2.3 — Observability & Operations

- Independent liveness/readiness, protected Prometheus endpoint and operations API/CLI.
- Cross-process HTTP, database, job and scheduler histograms; queue age, backup and worker timestamps.
- Bounded rotating runtime JSON logs and W3C trace correlation.
- Real admin operational status dashboard and alert rules.

## 1.2.2 — Queue & Scheduler Reliability

- Durable queue reservations with fencing tokens, heartbeat, expiry recovery and delayed exponential retry.
- Atomic file and PostgreSQL transitions, Redis Lua transitions and offline legacy migration.
- Persistent UTC scheduler slots, file or PostgreSQL advisory locks, interrupted run history.
- Durable webhook outbox handoff, per-target jobs, stable signed event IDs and delivery receipts.
- Bounded CLI workers, graceful signals, operational recovery/diagnostics and corrected systemd units.

## 1.2.1 — Performance & Database

- Bounded editorial SQL pagination with deterministic sorting and indexed translation lookup.
- Incremental route/search projection for topology-neutral edits; full graph validation for structural edits.
- Database-side revision compare-and-swap, nested savepoints, session timeouts and SQLite foreign keys.
- Serialized schema migrations; MySQL interrupted DDL is not retried automatically.
- Production auto storage fails closed instead of switching to JSON. Admin uses paginated lists and parent search.

## 1.2.0 — Search & CMS Integration

- Search projection is synchronized with content and route changes, with PostgreSQL FTS and SQLite/MySQL or file catalog.
- Public search page and API, filtered pagination, relevance, facets, safe result rendering, real admin search.
- Rebuild replaces stale documents; import, backup, updater and scheduler use the same catalog.

## 1.1.9 — Admin & Public Rendering Completion

- Schema-driven entry and field editor, reorderable blocks, media picker, revision restore and localization workflows.
- Built-in theme selection, corrected structured block rendering, referenced media lookup, preview toolbar and optional validated HTML cache.
- Revision preconditions and staged static site export preserving the real homepage.


## 1.1.8 — Media Library

- SQL-backed metadata with explicit legacy migration; folders, tags, collections, filtered pagination.
- Streamed uploads, safe replacement, image variants, reference checks, private downloads and signed S3 replicas.
- Portable import/export and Admin SPA media workflows; recovery and verification CLI.

## 1.1.7 — Public Routing Runtime (2026-11-12)

- Transactional SQL route index and deterministic page trees; canonical paths, locale prefixes, redirect history, and published-ancestor visibility.
- Protected route inspection API, CMS editor tree and parent selection, canonical SEO, sitemap pagination and hreflang.
- Explicit 404/410 pages, redirect safety, and import/upgrade integration.

## 1.1.6 — Extension Platform (2026-11-12)

- Signed extension inventory, dependency ordering, guarded package deployment and backup recovery.
- Runtime actions, admin pages, jobs, scheduler tasks, and active theme rendering.

## 1.1.5 — Import/Export Engine (2026-11-12)

- Portable ZIP v3 exports with checksummed media files and bounded extraction; checksummed JSON v2 remains supported.
- Preview and confirm an import plan with locale-aware identity mapping, conflict policies, CMS repositories, history and backup recovery.
- Admin upload/download and operator-executed import requests.


## 1.1.4 — Production Updater & Backup (2026-11-12)

- Ed25519 release signatures, verified staging, exclusive operation lease, durable update journal, automatic recovery and CLI rollback.
- Authenticated recovery sets with database dumps, full managed code/data inventory and encrypted environment; exact restore and integrity validation.
- Protected web upgrade requests executed by an explicitly selected CLI operator; no web code replacement.
- Database and failure-injection regression scenarios; external storage/queues require separately validated recovery.

## 1.1.3 — OpenAPI & Security (2026-11-12)

- Single route registry and strict OpenAPI 3.1.1 parity for 119 core operations; runtime JSON input validation.
- Correct middleware interface, mandatory authorization and current account/role restrictions; browser HttpOnly session with CSRF.
- Web setup token, MIME validation, SSRF-resistant outbound HTTP, safe public rendering.
- Production apply/rollback and destructive import suspended pending safe recovery and transactional import.
- API/SDK regression checks and HTTP integration smoke cases.

## [1.1.2] - 2026-11-12

- Clean source/dist packaging: allowlisted files, deterministic ZIP output, checksums and verified archive inventory.
- Production Admin SPA published from a single source; read-only asset checks and no fallback repair.
- Strict release verification and clean Git staging build with locked Composer dependencies.
- Installation lock acquired before work and completion marker written only after final checks.
- Regression tests and SQLite/PostgreSQL dist installation smoke pipeline.

## [1.1.1] - 2026-06-19

### Added
- Production static Admin SPA assets in `public/admin/assets/admin-app.js` and `admin-app.css`, backed by `admin/static` source files.
- Bearer login/logout, token storage, route guards and 401/403 handling in the browser Admin UI.
- Working Admin screens for dashboard, content types, content entries, media upload, CMS theme/navigation/redirects, users/RBAC/API tokens, extensions runtime, updates/maintenance/rollback, search and import/export.
- Strict Admin asset manifest with production quality checks for JS/CSS size and entrypoints.

### Changed
- `admin/scripts/publish-admin-assets.mjs` now publishes generated Nuxt assets when present or production static assets from `admin/static`, and fails `--check` when assets are incomplete.
- `php bin/cajeer admin:assets --check` and `AdminAssetPublisher` now validate real production Admin assets instead of accepting tiny preview fallback files.
- `fix:admin-assets` restores Admin assets from `admin/static` instead of writing a minimal placeholder page.
- Admin bootstrap metadata now exposes all product sections: CMS, media, updates, search, import/export and extension runtime.

### Fixed
- Source archives now include the same production Admin UI source used to populate `public/admin`, so dist assets can be regenerated deterministically.

## [1.1.0] - 2026-06-19

### Added
- Import/export v2: full content import for content types and entries, media file embedding/restoration, safe users/roles import and import diff reports.
- Update hardening: code rollback snapshots, removed files support, post-update migrations, post-update doctor and maintenance mode during apply.
- CLI/API commands for `import:diff`, `update:rollback` and maintenance mode control.
- Admin SPA operations for Import/Export, Updates, Maintenance and Rollback.

### Changed
- Web Upgrade now exposes rollback snapshot handling and maintenance status.
- Public entrypoint returns HTTP 503 while `storage/app/maintenance.json` exists.
- Release/update metadata moved to 1.1.0.

### Security
- Downgrade is blocked by default unless `--force` is explicitly used.
- Update apply protects runtime data and creates a code-level rollback snapshot before copying artifact files.

## [1.0.8] - 2026-06-19

### Added
- CMS product layer: public path rendering, homepage resolution, page path support and default `pages` content type bootstrap.
- Database-backed CMS repositories with file fallback: theme config, navigation menus, redirects and preview tokens.
- Public `/sitemap.xml`, `/robots.txt` and `/preview/{token}` routes.
- Admin UI CMS screen for theme settings, primary navigation and redirects.
- CLI commands `cms:bootstrap` and `cms:sitemap`.

### Changed
- Public renderer now passes SEO, canonical URL, theme and navigation context into templates.
- Default templates now render site navigation, meta description, robots, canonical and OpenGraph tags.
- Static export uses public CMS paths instead of raw slugs.

### Security
- Draft preview requires generated time-limited preview tokens for public `/preview/{token}` URLs.


Все заметные изменения CajeerEngine фиксируются в этом файле. Формат совместим с Keep a Changelog, версия проекта следует SemVer.

## [1.0.7] - 2026-06-19

### Added
- Product Admin UI hardening: static SPA in `public/admin` with login, token storage, route guards, dashboard, content type CRUD, content entry CRUD, media upload, users/roles, API tokens, extensions, system and updates screens.
- Protected Web Upgrade session gate: `/upgrade` now requires `UPDATE_WEB_UPGRADE_ENABLED=true` and `UPDATE_WEB_UPGRADE_SECRET` before ZIP upload/apply actions.
- Admin asset manifest now tracks `admin-app.js` / `admin-app.css` as production static assets while keeping legacy preview aliases.

### Changed
- Release builder excludes patch manifests and update runtime artifacts from source/dist release plans.
- OpenAPI metadata and product release metadata updated for 1.0.7 Admin UI hardening.
- Dist requirements now validate the production Admin app asset instead of the old preview script.

### Security
- Browser-based update apply is disabled by default and requires an explicit one-session unlock secret. CLI update remains the recommended production path.

## [1.0.6] - 2026-06-18

### Добавлено
- Release UX: `release:build --source/--dist/--all`, `release:artifacts`, `release:checksums` с ZIP artifacts, `.sha256` файлами и GitFlic-ready artifact index.
- `core/Update/UpdateManager.php` как рабочий update backend: check/plan/prepare/apply, проверка manifest/SHA256, protected-path filtering.
- CLI updater: `php bin/cajeer update`, `update:prepare`, `update:apply` с обязательным backup-before-update по умолчанию.
- Web Upgrade Wizard `/upgrade` и thin-loader `upgrade.php`: загрузка ZIP artifact, dry-run apply, сохранение plan и безопасное применение обновления.
- Release manifest v3 внутри artifacts с install/upgrade инструкциями, списком файлов, checksums и GitFlic metadata.

### Изменено
- `ReleaseBuilder` разделён на полноценные source/dist режимы и больше не ограничивается dry-run планом.
- Native CLI fallback теперь поддерживает release/update команды без `vendor/autoload.php`.
- Stable checks, OpenAPI/Admin metadata, bundled update metadata и docs обновлены до 1.0.6.

### Безопасность
- Update apply не перезаписывает `.env`, `storage/app/installed.lock`, `public/uploads`, SQLite database и runtime update/backup/support directories.
- Перед реальным применением update создаётся backup runtime-данных, если явно не указан `--no-backup`.

## [1.0.5] - 2026-06-18

### Добавлено
- Server automation layer: `doctor`, `fix`, `fix:permissions`, `fix:env`, `fix:security`, `fix:admin-assets`, `fix:storage`.
- Nginx config generator с default и aaPanel presets.
- systemd scheduler/worker generators.
- `post-install:report` и alias `backup:create`.

## [1.0.4] - 2026-06-18

### Добавлено
- Реальный Web Installer на `/install` и `public/install/index.php`: проверка окружения, выбор БД, генерация `.env`, проверка подключения, запуск миграций, создание первого администратора, генерация секретов и создание `storage/app/installed.lock`.
- CLI installer `php bin/cajeer install` с режимами `--interactive`, `--sqlite`, `--preset=sqlite`, `--preset=postgres`, `--preset=production`, `--preset=aapanel`.
- SQLite quick start через `storage/database/cajeer.sqlite` для demo/local/small installations.
- Native CLI fallback для `install` и `install:check`, чтобы установка могла стартовать даже без Symfony Console.
- Root `install.php` как безопасный thin-loader для простых хостингов, если проект ещё не установлен.

### Изменено
- `InstallerService` стал общим product backend для Web Installer и CLI Installer.
- Добавлена поддержка SQLite в DB connection, schema health checks, миграциях и базовых репозиториях seed/admin creation.
- ReleaseBuilder готовит имена `cajeerengine-1.0.4-source.zip` и `cajeerengine-1.0.4-dist.zip`; dist-режим исключает dev-only директории.

## [1.0.3] - 2026-06-18

### Исправлено
- Удалено поле `version` из корневого `composer.json`, чтобы `composer validate --strict` проходил без предупреждения Composer.
- Минимальная Composer platform PHP-версия поднята до `8.4.1`, что соответствует требованиям Symfony 8.1.
- Admin UI metadata обновлены до `1.0.3`; TypeScript закреплён на существующей стабильной версии `5.9.3`; добавлено требование Node `>=20.19.0`.
- Обновлены runtime fallbacks, release checks, OpenAPI metadata, SDK metadata и bundled update metadata до `1.0.3`.
- Удалены устаревшие активные маркеры старых 0.x-версий из README, `.env.example`, Admin metadata и runtime fallbacks.

### Эксплуатация
- Для production/dist-релиза по-прежнему требуются настоящие `composer.lock`, `vendor/autoload.php` и lock-файл Admin UI, создаваемые на release host.
- PHP extension `fileinfo` отмечен как рекомендуемый для строгой MIME-проверки media uploads.

## [1.0.2] - 2026-06-18

### Исправлено
- Добавлены prebuilt Admin assets и проверка их готовности.
- Добавлен native fallback для эксплуатационных CLI-команд без `vendor/autoload.php`.
- Исправлен retry/repair failed migrations.
- Добавлены `security:harden`, bundled update metadata и dist release checks.
- Удалены демонстрационные manifest-only расширения и runtime-файлы из source-архива.

## [1.1.0] - Extension Runtime

### Added
- Runtime-загрузка включённых расширений из `modules/`, `plugins/`, `themes/`.
- `ExtensionProviderInterface` и `AbstractExtensionProvider` для product-level PHP providers.
- `ExtensionContext` с доступом к root path, manifest, config, container, events, logger и runtime paths.
- Lifecycle hooks `register()`, `boot()`, `install()`, `uninstall()`.
- Выполнение runtime events/hooks через `extension:event:dispatch` и kernel event dispatcher.
- Автозагрузка PHP-кода расширений из `src/` и optional `autoload.php`.
- Publishing assets расширений в `public/extensions/{slug}`.
- File/PDO migrations расширений с состоянием в `storage/app/extensions/migrations.json`.
- API endpoints `/extensions/runtime`, `/extensions/assets/publish`, `/extensions/migrate`.
- CLI команды `extension:runtime`, `extension:assets`, `extension:migrate`.
- `make:extension` теперь генерирует рабочее runtime-расширение с provider, assets и migration.

### Changed
- `extension:install` теперь может запускать lifecycle, migrations и assets publishing.
- `extension:uninstall` теперь может вызывать provider uninstall lifecycle и удалять опубликованные assets.
- Engine API обновлён до `1.1` при сохранении совместимости с `^1.0` manifest constraints.
