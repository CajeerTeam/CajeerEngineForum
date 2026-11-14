# CajeerEngine Forum Edition

**Forum Edition 0.3.1 — Member Profiles**, built on CajeerEngine 1.3.0. Release certification requires all `forum-required` CI jobs to pass.

Self-hosted forum platform by CajeerTeam. PHP 8.4.1+, CajeerEngine kernel, Symfony Components, REST API, Apache-2.0.

Repository: https://github.com/CajeerTeam/CajeerEngineForum · Upstream: https://github.com/CajeerTeam/CajeerEngine

## Quick start / Быстрый запуск

```bash
composer install --no-interaction
php bin/cajeer install --sqlite --interactive
php -S 127.0.0.1:8080 -t public
```

Откройте http://127.0.0.1:8080/ — главная форума. Для локального запуска используйте loopback. На сервере настройте HTTPS, PHP-FPM и document root `public/`. Не публикуйте корень репозитория и не используйте встроенный PHP server в production.

- `/forum` — категории и разделы, `/forum/latest` — последние темы.
- `/forum/login`, `/forum/register` — вход и регистрация. Для запрета регистрации задайте `FORUM_REGISTRATION=false` в `.env`.
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

CI: `.github/workflows/forum-ci.yml`. Полный интеграционный тест требует Composer vendor, PDO SQLite и mbstring. Исходники поставляются без vendor и секретов; это не сертифицированный production dist. Унаследованные утилиты релиза сохраняют формат ядра, но подписанные пакеты обычной CMS не могут применяться к Forum Edition. Для обновлений используйте только выпуски своего репозитория с `edition: forum` в manifest. Номер `VERSION` — версия ядра 1.3.0; независимая версия форума (`edition_version` в `release.json`) используется сборщиком релизов и updater. Для перехода с 0.1.0 примените изменённые файлы вручную; старый updater ещё сравнивает номера ядра.

## Roadmap

Следующие этапы: права на отдельные разделы, вложенные подразделы, полноценные уведомления/непрочитанные темы, личные сообщения, WYSIWYG и безопасные вложения, опросы/Q&A, мягкое удаление и история правок, защита от спама и подтверждение email, поиск с полнотекстовым индексом, SEO-серверный рендеринг, локализация, production-тесты и подписанные релизы.

Архитектура и исходные компоненты CajeerEngine сохранены. См. LICENSE, NOTICE и THIRD_PARTY_NOTICES.md. История upstream: [CHANGELOG](CHANGELOG.md), исходный release workflow хранится в `docs/upstream/` для справки.
