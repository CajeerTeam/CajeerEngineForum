# Forum Edition 1.0.7 — SSO Logout & Revocation

## Два независимых действия

- **Локальный выход** в навигации форума вызывает `POST /api/v1/auth/logout`, отзывает текущий токен в `ce_api_tokens` и связанную запись `ce_user_sessions`, удаляет `ce_session`. Сессия у SkiF4er ID остаётся активной.
- **Глобальный выход** доступен только из `/forum/account/sessions`, когда текущая браузерная сессия имеет `provider=skif4er`. Он вызывает `POST /api/v1/auth/oidc/logout?return_to=%2Fforum%2Flogin` с текущим CSRF-токеном. Сервер проверяет cookie/OIDC-сессию, HTTPS, генерирует случайный `state`, получает зарегистрированный `end_session_endpoint` через защищённый discovery, отзывает локальную сессию и возвращает строго закреплённый адрес SkiF4er ID. В браузере валидация целевого домена выполняется перед переходом.

### Завершение

`GET /auth/oidc/logout/complete` принимает только тот `state`, который совпадает с пятиминутным Secure HttpOnly SameSite=Lax cookie. Для форумного входа после успешного возврата пользователь попадает на `/forum/login?logout=skif4er`; при ошибке провайдера — на `/forum/login?logout=unconfirmed`. При отсутствии или подмене state используется нейтральный адрес `/admin/#/login`; callback не проводит отзыв и удаляет cookie. Подтверждение возврата не является доказательством выхода из других клиентских приложений IdP.

### Отзыв по Back-Channel Logout

IdP отправляет **подписанный** JWT `logout_token` на `POST /auth/oidc/backchannel-logout` как `application/x-www-form-urlencoded`. Существующая реализация ядра проверяет issuer, audience, подпись JWKS, события, дату выпуска, срок действия, `sid`/`sub` и `jti`. Обработка `UserSessionRepository::applyBackchannelLogout` транзакционно сохраняет digest `jti` для защиты от повторов и отзывает только соответствующие сессии провайдера `skif4er` в `ce_user_sessions` и `ce_api_tokens`. Личные API-токены, локальные и чужие сессии не затрагиваются. Ошибка БД возвращает 503 для возможного повтора поставщиком.

## Установка и проверка

1. Установите изменённые файлы поверх полной версии 1.0.6; предварительно сохраните резервную копию приложения и базы.
2. Для OIDC нужен действующий HTTPS-клиент SkiF4er ID, зарегистрированный `https://FORUM_HOST/auth/oidc/logout/complete` как post-logout redirect и backchannel URL `https://FORUM_HOST/auth/oidc/backchannel-logout`.
3. Убедитесь в актуальности миграций ядра 2.1.0 (`0063_engine_unified_sessions.sql` и `0064_engine_oidc_global_logout.sql`). Новых SQL-миграций этой версии нет.
4. Проверьте отдельно локальный и глобальный выход, сохранение локальной сессии другого браузера, удаление именно соответствующей SSO-сессии после подписанного backchannel token, повтор того же `jti`, отказ при ошибке IdP и отсутствие доступа с отозванной cookie.

Тесты: `php tools/forum-sso-logout-regression.php`, `node tools/forum-sso-logout-browser-regression.mjs`, `php tools/oidc-global-logout-regression.php`, `python tools/oidc-global-logout-sqlite-regression.py`, а для полной БД/реального SDK — staging и обязательные CI-проверки.
