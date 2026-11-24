# Документация CajeerEngine Forum Edition

**Редакция 1.6.0 · Русскоязычный центр документации**

[Главная проекта](../README.md) · [История изменений](../CHANGELOG.md) · [Релиз 1.6.0](releases/1.6.0.md)

## Начало работы

| Руководство | Для кого |
| --- | --- |
| [Обзор продукта](forum-product.md) | Владелец сообщества, пользователь, администратор |
| [Установка](forum-installation.md) | Системный администратор, разработчик |
| [Обновление до 1.6.0](forum-upgrade-1.6.0.md) | Оператор существующего форума |
| [Заметки к релизу 1.6.0](releases/1.6.0.md) | Все участники проекта |
| [Релизная квалификация 1.6.0](forum-github-updates-stable-1.6.0.md) | Release engineer, QA |

## Управление платформой

| Тема | Технические руководства |
| --- | --- |
| Архитектура | [Forum Edition](forum-edition.md), [переход на ядро 2.1.0](forum-engine-2.1.0-upgrade.md) |
| ACP | [Единая панель](forum-unified-administration-1.4.1.md), [централизованная конфигурация](forum-platform-configuration-1.4.2.md) |
| Участники и доступ | [SkiF4er ID](forum-skif4er-id-stable-1.1.0.md), [единый доступ](forum-unified-identity-1.4.3.md), [сессии](forum-unified-sessions-1.0.6.md) |
| Миграция | [phpBB](forum-migration.md), [XenForo](forum-xenforo-production-acceptance-1.1.6.md), [Invision](forum-advanced-migration-stable-1.2.0.md) |
| Аналитика | [Стабилизация 1.3.0](forum-analytics-stable-1.3.0.md), [GA4](forum-ga4-integration-1.2.5.md), [Яндекс Метрика](forum-yandex-metrica-1.2.4.md), [согласие](forum-visitor-tracking-1.2.3.md), [удержание](forum-engagement-retention-1.2.10.md) |
| AI | [Стабилизация 1.4.0](forum-ai-stable-1.4.0.md), [чат](forum-ai-chat-1.3.1.md), [поиск](forum-ai-search-1.3.3.md), [приватность](forum-ai-privacy-abuse-1.3.10.md), [модерация](forum-ai-moderation-1.3.7.md) |
| Автоматизация | [Рабочие процессы](forum-platform-workflows-1.4.4.md), [очереди](forum-unified-queue-1.4.5.md) |
| API | [OpenAPI](../api/openapi.yaml), [расширения](extensions-platform.md) |

## Эксплуатация и выпуск

| Тема | Технические руководства |
| --- | --- |
| Развёртывание | [Контейнеры](container-deployment.md), [production-операции 1.0](forum-1.0-production-operations.md), [восстановление развёртывания](forum-deployment-recovery-1.4.9.md) |
| Безопасность | [Платформа](forum-platform-security-1.4.7.md), [политика раскрытия](../SECURITY.md) |
| Мониторинг | [Единая наблюдаемость](forum-unified-observability-1.4.6.md), [производительность](forum-platform-performance-1.4.8.md) |
| Резервирование | [Автоматические копии](automated-backup.md), [аварийное восстановление](disaster-recovery.md), [preflight](forum-update-backup-preflight-1.5.6.md) |
| Обновления | [GitHub](forum-github-release-sync-1.5.1.md), [каналы](forum-update-channels-1.5.2.md), [загрузка](forum-automatic-download-1.5.3.md), [политики](forum-update-policies-1.5.4.md), [автономный оператор](forum-autonomous-update-1.5.5.md) |
| Службы и восстановление | [systemd](forum-safe-deployment-1.5.7.md), [независимый supervisor](forum-crash-recovery-1.5.8.md), [Update Center](forum-unified-update-center-1.5.9.md), [доверие и SBOM](forum-update-security-1.5.10.md) |
| Квалификация | [E2E](forum-platform-e2e-1.4.10.md), [физическая приёмка](forum-production-qualification-1.4.11.md), [RC 1.5.11](forum-autonomous-qualification-1.5.11.md), [1.6.0](forum-github-updates-stable-1.6.0.md) |

> Исторические технические документы сохранены в исходном виде, в том числе англоязычные runbook и инструкции ядра. Их номера относятся к указанному в заголовке этапу, а не автоматически к текущей версии форума. Для перехода на 1.6.0 приоритет имеют актуальное руководство обновления и релизная процедура. При конфликте сверяйте настройки и команды с кодом конкретной установленной версии; не переносите старые примеры вслепую.
