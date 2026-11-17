# CajeerEngine Forum Edition

**Forum Edition 0.9.4 — Load & Soak Certification Tooling**, built on CajeerEngine 1.3.0. Publication requires a passing `forum-required` gate and verified signed CI certification tied to the exact commit and release artifacts.

Self-hosted forum platform by CajeerTeam. PHP 8.4.1+, CajeerEngine kernel, Symfony Components, REST API, Apache-2.0.

Repository: https://github.com/CajeerTeam/CajeerEngineForum · Upstream: https://github.com/CajeerTeam/CajeerEngine

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

CI: `.github/workflows/forum-ci.yml`; 0.9.1 added `release-candidate`, `certification` and signed publication admission; 0.9.4 adds qualification telemetry and evidence validation regressions; 0.9.6 adds destructive off-site disaster-recovery acceptance; 0.9.7 adds operator TLS/backup/integrity gates; 0.9.8 adds genuine historical multi-hop upgrade certification and a real signed systemd upgrade. For signing-key requirements and full verification see [production certification](docs/production-certification.md). Полный интеграционный тест требует Composer vendor, PDO SQLite и mbstring. Исходники поставляются без vendor и секретов; это не сертифицированный production dist. Унаследованные утилиты релиза сохраняют формат ядра, но подписанные пакеты обычной CMS не могут применяться к Forum Edition. Для обновлений используйте только выпуски своего репозитория с `edition: forum` в manifest. Номер `VERSION` — версия ядра 1.3.0; независимая версия форума (`edition_version` в `release.json`) используется сборщиком релизов и updater. Для перехода с 0.1.0 примените изменённые файлы вручную; старый updater ещё сравнивает номера ядра.

## Roadmap

Следующие этапы до Forum 1.0 после 0.9.4: **фактически выполнить** 24-часовой PostgreSQL soak на выделенном хосте и принять подписанный evidence, провести fault/recovery, off-site восстановление, операционную проверку мониторинга и полную матрицу обновления. Release pipeline 0.9.1 подтверждает CI, но не заменяет длительное испытание на реальном оборудовании.

Архитектура и исходные компоненты CajeerEngine сохранены. См. LICENSE, NOTICE и THIRD_PARTY_NOTICES.md. История upstream: [CHANGELOG](CHANGELOG.md), исходный release workflow хранится в `docs/upstream/` для справки.

Forum migration: [phpBB 3.3 operator guide](docs/forum-migration.md).
