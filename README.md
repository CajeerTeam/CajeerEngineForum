# CajeerEngine Forum Edition

**Forum Edition 1.6.0 — GitHub Automated Updates Stable**, built on CajeerEngine 2.1.0. Publication requires a passing `forum-required` gate, physical 10k/100k/1M soak and both interrupted-update checkpoints and live GitHub updater qualification, tied to the exact signed release and commit. Certification is pending until all gates actually pass. See [release procedure](docs/forum-github-updates-stable-1.6.0.md).

See [Full Platform Integration & E2E](docs/forum-platform-e2e-1.4.10.md) for the cross-subsystem acceptance suite. See [Automated Deployment & Recovery](docs/forum-deployment-recovery-1.4.9.md) for the deployment and recovery procedures.

See [Platform Security & Privacy](docs/forum-platform-security-1.4.7.md) for deployment, credential sources, abuse controls and retention.

See [Platform Workflows](docs/forum-platform-workflows-1.4.4.md) for configuration, scheduling and execution history.

See [Unified Identity](docs/forum-unified-identity-1.4.3.md) for account access management.

See [Platform Configuration](docs/forum-platform-configuration-1.4.2.md) for runtime configuration, probes and rollback.

See [Unified Administration Center](docs/forum-unified-administration-1.4.1.md) for navigation, access and deployment.

Self-hosted forum platform by CajeerTeam. PHP 8.4.1+, CajeerEngine kernel, Symfony Components, REST API, Apache-2.0.

Repository: https://github.com/CajeerTeam/CajeerEngineForum · Upstream: https://github.com/CajeerTeam/CajeerEngine

**Upgrade 1.3.3 → 1.3.4:** [Knowledge Sync & Search Quality guide](docs/forum-knowledge-sync-1.3.4.md). No new migration. Deploy the updated PHP sources, run the mandatory FTS and AI search regressions, then `php bin/cajeer forum:search:check --json` before reopening traffic. `forum:search:repair` repairs drift while indexing is paused. Full CI/staging checks are still required.

**Upgrade 1.3.7 → 1.3.8:** [AI ACP Insights guide](docs/forum-ai-insights-1.3.8.md). No migration; deploy PHP and both ACP assets together, confirm OpenAI quotas and complete CI and staging acceptance.

**Upgrade 1.3.6 → 1.3.7:** [AI Moderation Advisor guide](docs/forum-ai-moderation-1.3.7.md). Apply migration 0088, deploy PHP and both forum/ACP assets, then pass CI and staging.

**Upgrade 1.3.5 → 1.3.6:** [AI Writing Assistant guide](docs/forum-ai-writing-1.3.6.md). No migration; deploy PHP and forum assets together, then run CI and staging acceptance.

**Upgrade 1.3.2 → 1.3.3:** [AI Forum Search guide](docs/forum-ai-search-1.3.3.md). Apply migration `0086_forum_ai_search.sql` for your database, deploy the search PHP/JS/CSS files and run the required AI-search acceptance tests. The existing `/forum/search` page gains explicit, optional OpenAI answers grounded in public posts, with server-created source links, role-isolated temporary storage and shared AI budgets. No private forum content is sent to OpenAI. Database CI, signed release qualification and staging are required before production rollout.

**Upgrade 1.3.1 → 1.3.2:** [Thread AI guide](docs/forum-thread-ai-1.3.2.md). Apply migration `0085_forum_thread_ai.sql` for your database and deploy the updated Forum PHP/JS/CSS and OpenAPI files. The thread panel uses the existing configured OpenAI API key and ACP limits. Only guest-visible public threads may be sent to the provider. Full independent staging and CI remain required.

**Upgrade 1.3.0 → 1.3.1:** [Forum AI Chat](docs/forum-ai-chat-1.3.1.md). Apply migration `0084_forum_ai_chat.sql` on your database, configure the OpenAI API key in the core AI integration, and enable the forum assistant in ACP. Includes a browser chat at `/forum/ai`, streaming responses, separate owner-only history, daily/monthly quotas, and shared monetary budgets. Requires passing database CI and staging before public deployment.

**Upgrade 0.9.3 → 0.9.4:** [physical load & soak certification](docs/forum-load-0.9.4.md). Real CPU/RAM/disk/PostgreSQL measurements, bounded concurrent publication and queue-delay probes; independent 10k/100k/1M + 24-hour signed-evidence workflow. No new migration. Long tests require a dedicated runner and are not claimed as already executed.

**Upgrade 0.9.2 → 0.9.3:** [database optimization and populated-upgrade acceptance](docs/forum-database-0.9.3.md). Apply migration 0049 after backup; schedule PostgreSQL index creation for a controlled maintenance window.

**Upgrade 0.9.1 → 0.9.2:** [production security hardening and negative tests](docs/forum-security-0.9.2.md). No schema migration; verify webhook URLs, signed extension publisher key permissions and Compose service capabilities before promotion.

**Upgrade 0.9.0 → 0.9.1:** [mandatory release certification pipeline and signing](docs/production-certification.md). No schema migration.

**Upgrade 0.8.7 → 0.9.0:** [production operations: live admission checks, deployment reconciliation and backup assurance](docs/production-operations.md). No schema migration.

**Upgrade 0.8.6 → 0.8.7:** [production qualification, failure recovery and soak testing](docs/production-qualification.md). No new migration.

**Upgrade 0.8.5 → 0.8.6:** [forum metrics, monitoring stack and Operations ACP](docs/forum-monitoring.md). Apply migration 0048 (monitoring indexes).

**Upgrade 0.8.3 → 0.8.4:** [automated backups, retention and encrypted off-site storage](docs/automated-backup.md). No new schema migration.

**Upgrade 0.8.2 → 0.8.3:** [secure updates](docs/secure-updates.md). No new schema migration.

**Upgrade 0.7.7 → 0.8.0:** [prepare installed signed plugins, verify ecosystem readiness and recover safely](docs/forum-edition.md#administration--extensibility-stable-080). No new schema migration.

## Quick start / Быстрый запуск

```bash
composer install --no-interaction
php bin/cajeer install --sqlite --interactive
php -S 127.0.0.1:8080 -t public
```

Откройте http://127.0.0.1:8080/ — главная форума. Для локального запуска используйте loopback. На сервере настройте HTTPS, PHP-FPM и document root `public/`. Не публикуйте корень репозитория и не используйте встроенный PHP server в production.

- `/forum` — категории и разделы, `/forum/latest` — последние темы. Публичные страницы содержат серверный HTML. `/forum/sitemap.xml` — индекс карт сайта, `/forum/rss.xml` — RSS.
- `/forum/login`, `/forum/register` — вход и регистрация. Для запрета регистрации задайте `FORUM_REGISTRATION=false` в `.env`.
- `/forum/safety` — личный центр безопасности: жалобы, доверие, блокировки, санкции и апелляции.
- `/forum/manage` — жалобы и управление форумом. Администратор создаёт категории/разделы, модератор обрабатывает жалобы и темы.
- `/admin/` — существующая админка ядра, управление пользователями, ролями, медиа, CMS и расширениями.
- `/api/v1/forum` — публичный каталог; `/api/openapi.yaml` в репозитории — контракт API.

Для уже установленной **тестовой копии** CajeerEngine 1.3.0: сделайте резервную копию файлов и БД, перенесите исходники Forum Edition без перезаписи `.env` и runtime, выполните `composer install` и `php bin/cajeer migrate`. Назначьте существующим пользователям роль `forum-member` или `forum-moderator` (администратор уже имеет полный доступ). Не устанавливайте эту редакцию поверх production-сайта без тестирования.

## Возможности

Категории и разделы; темы и сообщения с пагинацией, стабильным порядком и текстовым редактором; публичные профили; последние обсуждения; реакции «Нравится»; список подписок; поиск; жалобы, закрытие/открытие, закрепление/открепление и удаление тем. Права и сессии проверяет ядро; форум использует CSRF, HttpOnly cookie, ограничение запросов и параметризованные SQL-запросы. Пользовательский текст отображается без исполнения HTML.

## Развёртывание и тестирование

Поддерживаемый для разработки профиль — SQLite. Предусмотрены миграции PostgreSQL 17+ и MySQL 8.4+/MariaDB 10.11+, но требуется отдельная интеграционная проверка на этих СУБД. См. [руководство Forum Edition](docs/forum-edition.md) и [установка ядра](docs/install.md).

```bash
php bin/cajeer api:lint --strict --json
php tools/generate-api-sdk.php --check
php tools/forum-regression.php
node --check public/forum-assets/forum.js
```

Forum Edition **1.4.0**: AI Assistant Stable; see [AI acceptance and signed release](docs/forum-ai-stable-1.4.0.md). No new database migrations beyond 0091.

Forum Edition **1.3.11**: bounded SQL AI admission, provider cooldown, conservative billing and scheduled recovery; requires migration `0091_forum_ai_reliability.sql`. See [AI Performance & Reliability](docs/forum-ai-reliability-1.3.11.md).

Forum Edition **1.3.10**: self-service export and erasure of AI history, 90-day idle transcript retention, database-backed per-minute and concurrent-generation limits, account-close protection and untrusted-context boundary checks. Requires migration `0090_forum_ai_privacy_abuse.sql`; see [AI Privacy & Abuse Protection](docs/forum-ai-privacy-abuse-1.3.10.md).

CI: `.github/workflows/forum-ci.yml`; 0.9.1 added `release-candidate`, `certification` and signed publication admission; 0.9.4 adds qualification telemetry and evidence validation regressions; 0.9.6 adds destructive off-site disaster-recovery acceptance; 0.9.7 adds operator TLS/backup/integrity gates; 0.9.8 adds genuine historical multi-hop upgrade certification and a real signed systemd upgrade; 0.9.9 adds a fail-closed final staging and independent certified-artifact audit gate. For signing-key requirements and full verification see [production certification](docs/production-certification.md). Полный интеграционный тест требует Composer vendor, PDO SQLite и mbstring. Исходники поставляются без vendor и секретов; это не сертифицированный production dist. Унаследованные утилиты релиза сохраняют формат ядра, но подписанные пакеты обычной CMS не могут применяться к Forum Edition. Для обновлений используйте только выпуски своего репозитория с `edition: forum` в manifest. Версия 1.2.0 добавляет полноценную read-only приёмку Invision v8 по неизменяемому снимку исходной БД и целевым данным, защиту пропусков и контроль файлов при сохранении приёмки XenForo; см. docs/forum-advanced-migration-stable-1.2.0.md. Версия 1.1.11 усиливает проверку целостности миграций, корректность Invision v8 вложений, проверку резервной копии и корректную классификацию опросов; см. docs/forum-advanced-migration-hardening-1.1.11.md. Версия 1.1.10 добавляет рабочий перенос профилей, поддерживаемых реакций, подписок и приватных переписок Invision через v8; см. docs/forum-invision-community-data-1.1.10.md. Версия 1.1.9 добавляет рабочий перенос локальных вложений, проверяемых опросов и безопасное преобразование форматированных сообщений Invision (v7), с сохранением старых v6-заданий; см. docs/forum-invision-content-media-1.1.9.md. Версия 1.1.8 добавляет работающий импорт основных сущностей Invision 4/5 через `forum:import:plan` и `forum:import:run` с транзакционным продолжением, скрытыми форумами и отключёнными аккаунтами. Расширенные данные сообщества (личные переписки, реакции и подписки) остаются следующими этапами; см. docs/forum-invision-core-import-1.1.8.md. Версия 1.1.7 добавляет настоящую read-only проверку снимка Invision 4/5 через forum:import:discover, включая определение версии/префикса, сверку схемы, связей и файлов. Выполнение импорта Invision остаётся закрытым до 1.1.8; см. docs/forum-invision-discovery-1.1.7.md. Версия 1.1.6 добавляет реальную сверку миграции XenForo через `forum:import:accept` и обязательную миграцию схемы 0079 перед импортом на PostgreSQL/MySQL; результаты на реальных снимках XenForo требуют отдельной staging-приёмки. Версия 1.1.5 переносит личные переписки XenForo, сообщения, вложения в переписки и защищённые внутренние ссылки с транзакционным продолжением через pipeline v5; обязателен неизменяемый файловый снимок для вложений, см. docs/forum-migration.md. Версия 1.1.4 переносит аватары из неизменяемого локального снимка XenForo, подписки на участников и первичные группы без выдачи чужих прав; для аватаров используйте --avatar-files=/path/to/data/avatars (см. docs/forum-migration.md). Версия 1.1.3 включает исполняемый завершающий проход по сообщениям XenForo: безопасный BBCode, цитаты и упоминания, ссылки на импортированные объекты, транзакционное продолжение (см. docs/forum-migration.md). Версия 1.1.2 проверяет таблицы/колонки XenForo 2.2/2.3 до импорта, выбирает корректный ключ вложения и фиксирует отпечаток схемы для новых заданий; см. [миграции форума](docs/forum-migration.md). Версия 1.1.1 расширяет исполняемый импорт XenForo: новые фазы профилей и реакций с транзакционными checkpoints и сохранением старых заданий; см. [миграции форума](docs/forum-migration.md). Версия 1.1.0 усиливает production-приёмку SkiF4er ID, проверяет реальную версию форума на HTTPS-сайте и требует доверенную подпись публикации (см. [руководство 1.1.0](docs/forum-skif4er-id-stable-1.1.0.md)). Версия 1.0.9 устраняет повторное использование TOTP, запрещает обход MFA провайдера через локальный пароль в чувствительных операциях, блокирует небезопасный MySQL migrate:repair и добавляет проверку миграций и состояния развёртывания (см. [Security & Integration Acceptance](docs/forum-security-acceptance-1.0.9.md)). Версия 1.0.8 требует подтверждение с MFA для изменений ролей, ACL, санкций и критичных настроек ACP (см. [руководство Unified Access Control](docs/forum-unified-access-1.0.8.md)). Версия 1.0.7 добавляет локальный и глобальный выход через SkiF4er ID, подтверждение возврата по одноразовому state и защиту от повторного Back-Channel Logout; см. [руководство по SSO Logout](docs/forum-sso-logout-1.0.7.md). Версия 1.0.6 предоставляет единый список локальных и SkiF4er ID сессий и управление устройствами в `/forum/account/sessions` через реестр ядра; см. [руководство по Unified Sessions](docs/forum-unified-sessions-1.0.6.md). Версия Forum Edition 1.0.5 добавляет регистрацию через SkiF4er ID с ролью `forum-member`, проверкой политики и подтверждённого email, безопасным отказом при совпадении email и антиспам-защитой; см. [руководство Account Provisioning](docs/forum-account-provisioning-1.0.5.md). Версия Forum Edition 1.0.4 добавляет безопасную самостоятельную привязку и отвязку SkiF4er ID в `/forum/account/security`; см. [руководство Account Linking](docs/forum-account-linking-1.0.4.md). Версия 1.0.3 включает внешний вход на форум через SkiF4er ID с использованием OIDC ядра; сведения об установке и ограничениях — в [инструкции по SkiF4er ID Login](docs/forum-skif4er-id-login-1.0.3.md). Версия 1.0.2 включает исполняемые исправления расширений, миграций, OpenAPI и ограничений интеграций; см. CHANGELOG.md. Номер `VERSION` — версия ядра 2.1.0; независимая версия форума (`edition_version` в `release.json`) используется сборщиком релизов и updater. Для перехода с 0.1.0 примените изменённые файлы вручную; старый updater ещё сравнивает номера ядра.

## Roadmap

Следующие этапы до Forum 1.0 после 0.9.4: **фактически выполнить** 24-часовой PostgreSQL soak на выделенном хосте и принять подписанный evidence, провести fault/recovery, off-site восстановление, операционную проверку мониторинга и полную матрицу обновления. Release pipeline 0.9.1 подтверждает CI, но не заменяет длительное испытание на реальном оборудовании.

Архитектура и исходные компоненты CajeerEngine сохранены. См. LICENSE, NOTICE и THIRD_PARTY_NOTICES.md. История upstream: [CHANGELOG](CHANGELOG.md), исходный release workflow хранится в `docs/upstream/` для справки.

Forum migration: [phpBB 3.3 operator guide](docs/forum-migration.md).

## 1.0.0 stable release process

The supported deployments, signed-release verification, mandatory external physical certification and explicit recovery/rollback procedures are described in [production operations](docs/forum-1.0-production-operations.md), [upgrade guide](docs/forum-1.0-upgrade.md) and [compatibility matrix](docs/forum-1.0-compatibility.md). A source tree at version 1.0.0 is **not** proof that the stable release has passed physical production certification. Publication requires the independently witnessed and signed evidence.

## Core upgrade to CajeerEngine 2.1.0

This source tree uses CajeerEngine 2.1.0 with Forum Edition 1.3.0 and its independent signed release policy. See [Analytics & Metrics Stable 1.3.0](docs/forum-analytics-stable-1.3.0.md) for cross-driver checks and operator cutover gates. See [Analytics Security & Hardening 1.2.11](docs/forum-analytics-hardening-1.2.11.md) for strict collection, cohort suppression and SQL optimization. See [Engagement & Retention 1.2.10](docs/forum-engagement-retention-1.2.10.md) for author-activity cohorts, maturation and registration-to-publication funnel. See [Dashboard 1.2.9](docs/forum-analytics-dashboard-1.2.9.md) for filters, comparisons and anonymous exports. See [Aggregation & Retention 1.2.8](docs/forum-analytics-aggregation-1.2.8.md) for migration 0082, daily rollups, safe rebuild and retention operations. See [Native Analytics 1.2.7](docs/forum-native-analytics-1.2.7.md). See [Alternative Analytics Providers 1.2.6](docs/forum-alternative-analytics-1.2.6.md). See [GA4 Integration 1.2.5](docs/forum-ga4-integration-1.2.5.md) for consent, custom events and deployment acceptance. See [Yandex Metrica Integration 1.2.4](docs/forum-yandex-metrica-1.2.4.md) for goals, consent and deployment checks. See [Visitor Tracking & Consent 1.2.3](docs/forum-visitor-tracking-1.2.3.md), [Forum Event Tracking 1.2.2](docs/forum-analytics-events-1.2.2.md), [Forum Analytics Integration 1.2.1](docs/forum-analytics-integration-1.2.1.md) and [upgrade notes](docs/forum-engine-2.1.0-upgrade.md). A source patch is not a signed distribution, and production deployment requires independent staging and integration verification.
