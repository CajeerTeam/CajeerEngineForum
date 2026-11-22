# CajeerEngine Forum 1.0.4 — SkiF4er ID Account Linking

## Рабочий сценарий

- `/forum/account/security` показывает статус `GET /api/v1/identity/account`, дату привязки и форму действий. Данные о внешнем `sub` и токены провайдера не передаются в JavaScript.
- **Привязка:** пользователь входит **локально** в Forum, указывает действующий локальный пароль и TOTP либо код восстановления при включённой 2FA, затем нажимает «Привязать SkiF4er ID». `POST /api/v1/identity/link/start` проверяет HttpOnly-сессию и CSRF через AuthenticationMiddleware, повторно проверяет учётные данные, проверяет отсутствие старой привязки и создаёт серверную одноразовую OIDC-транзакцию с 5-минутным сроком. Ответ содержит URL стандартного Keycloak OIDC Authorization Code + PKCE S256; браузер открывает только закреплённый URL провайдера.
- Два временных `Secure; HttpOnly; SameSite=Lax; Path=/auth/oidc` cookie привязывают OIDC callback к браузеру и безопасному пути `/forum/account/security`; произвольные return-адреса запрещены. `state`, `nonce`, подпись и audience проверяются OIDC-клиентом ядра. Сопоставление выполняется **исключительно по подтверждённым `(issuer, sub)`**, а не по email, имени или введённому пользователем UUID. Сервер повторно проверяет актуальную локальную сессию и сохраняет связь в `ce_oidc_identities`, не изменяя локальный UUID, профиль, сообщения, роли и права. При успехе callback возвращает браузер на `/forum/account/security?identity=linked`; существующая локальная сессия **не заменяется** новой.
- **Отвязка:** локальная браузерная сессия повторно подтверждается паролем и TOTP; после подтверждения действие выполняется только `POST /api/v1/identity/unlink`. Оно транзакционно удаляет mapping и отзывает активные SSO-сессии пользователя в реестре ядра. Если действующая сессия — SSO, сервер сбрасывает cookie и интерфейс возвращает пользователя на `/forum/login`; локальные сессии и пользовательские данные сохраняются.
- Для учётных записей `provisioned=true` (OIDC-only) самостоятельная отвязка недоступна до появления подтверждённого локального способа входа. Привязка к чужому `(issuer, sub)` и попытка привязать второй внешний субъект к одному локальному аккаунту отвергаются уникальными ограничениями БД. Запросы ограничены по частоте RateLimitMiddleware. Повторное использование `state`, отмена входа и прекращение инициирующей сессии не создают новую связь.

## Конфигурация и обновление с 1.0.3

1. На staging сделайте резервную копию файлов и БД. Распакуйте **только изменённые файлы**, сохранив структуру каталогов; не перезаписывайте действующий `.env`. Ядро `VERSION=2.1.0` остаётся неизменным. **Новых SQL-миграций в 1.0.4 нет**, но все штатные миграции ядра и Forum Edition должны быть уже применены.
2. Используйте [SkiF4er ID SDK Center](https://id.skif4er.ru/sdk/) для регистрации отдельного backend confidential client в realm `https://id.skif4er.ru/realms/skif4er`. Укажите **точный** HTTPS callback `https://FORUM_HOST/auth/oidc/callback`; используйте Authorization Code Flow + PKCE S256, client secret храните только в серверном `.env`. См. [настройки версии 1.0.3](forum-skif4er-id-login-1.0.3.md).
3. Убедитесь в `APP_URL=https://FORUM_HOST`, `SKIF4ER_ID_ENABLED=true`, корректных `SKIF4ER_ID_CLIENT_ID`/`SKIF4ER_ID_CLIENT_SECRET` и в работе HTTPS/доверенных reverse-proxy headers. `SKIF4ER_ID_AUTO_PROVISION=false` оставьте на этапе 1.0.4, если контролируемая регистрация внешних пользователей ещё не вводилась. Служебные токены и секреты нельзя хранить в браузере.
4. Войдите через локальную форму `/forum/login`, откройте `/forum/account/security`, привяжите SkiF4er ID, подтвердите внешнюю учётную запись и проверьте возвращение на форум. Затем выйдите из локальной сессии и войдите через SkiF4er ID. Проверьте, что UUID и сообщения остались прежними.
5. Снова войдите локально, откройте безопасность аккаунта и отвяжите SkiF4er ID. Убедитесь, что уже выпущенные внешние сессии больше не проходят `GET /api/v1/auth/me`, а локальный вход продолжает работать. Проверьте ошибки и отмену привязки, неверный пароль/TOTP, попытки привязки занятого `sub`, недоступность провайдера, отзыв исходной сессии до callback и уникальные ограничения вашей СУБД.
6. Перед production: проверьте ключи, состояние миграций и индексов, реальные HTTPS redirects, Keycloak и боевую СУБД на staging. ZIP патча — **не подписанный полный dist**, не подавайте его в `update:apply`, если updater требует подписанный релизный пакет.

## Проверки в репозитории

```bash
php tools/forum-account-linking-regression.php
php tools/forum-account-linking-reauth-regression.php
node tools/forum-account-linking-browser-regression.mjs
python tools/oidc-account-linking-sqlite-regression.py
php tools/oidc-account-linking-regression.php --require-db
php tools/forum-ski-id-login-regression.php
node tools/forum-ski-id-login-browser-regression.mjs
```

Первые тесты работают с контролируемыми HTTP/Identity doubles и проверяют **реальный код контроллера и JS интерфейса**, но не могут заменить интеграционный вход через настоящий Keycloak. Последний тест с `--require-db` выполняет PHP-операции репозитория на SQLite и требует `pdo_sqlite` и зависимости проекта. В CI это обязательная проверка. Для PostgreSQL и MySQL необходимы отдельные тестовые подключения.
