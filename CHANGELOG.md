# Changelog

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
