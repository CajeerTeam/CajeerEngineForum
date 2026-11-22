# Forum Edition 1.0.6 — Unified Sessions

CajeerEngine Forum uses the existing CajeerEngine 2.1.0 session registry. This release connects the forum's own UI and its legacy service facade to that registry. It does **not** create a separate authentication system and does not replace the OIDC implementation.

## User-facing operations

Open `/forum/account/security` → **Управление активными сессиями**, or go directly to `/forum/account/sessions`.

The list is served by the **existing** `GET /api/v1/auth/sessions` endpoint. It distinguishes `local`, `skif4er` and pre-registry `legacy` browser sessions. It reports device/browser user agent, a truncated network prefix, creation time, most recent activity, expiration and whether this is the current browser. There are no raw tokens, passwords, refresh tokens, OIDC `sid` or OIDC `sub` in the response.

Supported actions:

| UI | Existing API | Result |
| --- | --- | --- |
| Завершить сессию | `DELETE /api/v1/auth/sessions/{id}` | Revoke an owned browser session; ending current session clears cookie |
| Завершить остальные | `POST /api/v1/auth/sessions/revoke-others` | Revoke other browser sessions without ending the current one |
| Обновить защиту | `POST /api/v1/auth/sessions/rotate` | Atomically rotate current opaque cookie credential and CSRF hash |
| Завершить все | `POST /api/v1/auth/sessions/revoke-all` | Revoke all owned browser sessions and require login again |

All these APIs require a real browser `ce_session` cookie; Bearer-only requests cannot manage browser sessions. Mutations require a valid same-origin `X-CSRF-Token`. The forum does not store session tokens or OIDC credentials in localStorage or JavaScript memory. Rotation replaces the in-memory CSRF hash only after receiving the replacement HttpOnly cookie from the server.

The `Forum\Service\IdentityService` facade delegates to `AuthService` rather than directly changing `ce_api_tokens` without synchronizing `ce_user_sessions`. Personal-data export now joins the same session registry.

## Database and upgrade

No new database migrations are introduced by **Forum Edition** 1.0.6. The existing **engine** schema migrations `0063_engine_unified_sessions.sql`, `0064_engine_oidc_global_logout.sql` and `0065_engine_authentication_assurance.sql` must already have run on all deployments upgraded to CajeerEngine 2.1.0.

1. Back up the current database and retain a rollback snapshot.
2. Run `php bin/cajeer migrate` to apply any missing core migrations.
3. In rolling upgrades, keep `IDENTITY_SESSION_STRICT=false` while old tokens remain unregistered. Run `php bin/cajeer identity:sessions:status`, then `php bin/cajeer identity:sessions:migrate --yes --batch-size=100 --max-batches=10` repeatedly until no unregistered browser tokens remain. Older sessions are marked `legacy`, never inferred as SkiF4er ID from email.
4. Run `php bin/cajeer identity:acceptance --require-oidc --probe-idp --probe-site` when a real SkiF4er client and deployment are configured. Enable `IDENTITY_SESSION_STRICT=true` only after clean acceptance and rolling worker cutover; reload PHP-FPM workers.
5. From two browsers, test local and SkiF4er ID sign-ins, revoke one device, logout all other devices, rotate the current cookie/CSRF, and revoke all devices. Confirm API credentials with other names are unaffected.

This is **local application session** management. Revoking a local SkiF4er ID browser session does not by itself terminate the separate session at the identity provider. IdP-wide logout handling is a separate 1.0.7 milestone; the engine's existing back-channel support can already process verified logout notifications.

## Tests

- `node tools/forum-unified-sessions-browser-regression.mjs` — executes the actual forum UI functions with controlled HTTP and DOM adapters.
- `python tools/unified-sessions-sqlite-regression.py` — executes actual core SQLite migration SQL and token isolation / rollback invariants.
- `php tools/unified-sessions-regression.php --require-db` — runs real PHP AuthService and Forum IdentityService sessions on a disposable SQLite database.
- `php tools/identity-session-acceptance-integration.php --require-db` — tests idempotent legacy migration and strict-mode cutover.

The two PHP database suites require Composer vendor, `pdo_sqlite` and other declared runtime extensions. Tests in CI are mandatory and do not silently skip without dependencies.
