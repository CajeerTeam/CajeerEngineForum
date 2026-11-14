# Forum Edition — developer & operator notes

## Architecture

`core/Forum/ForumController.php` is the HTTP adapter. `Service/` implements discussion, engagement, registration and moderation workflows, `Repository/` contains parameterized SQL, `Security/ForumAccessPolicy.php` enforces actor permissions and `Validation/ForumValidator.php` validates input. Services share the core DatabaseManager, UserRepository and PasswordHasher. Routes are declared in `core/Http/RouteRegistry.php` and registered by Application. `public/forum-assets` is the dependency-free frontend, served under `/` and `/forum/*`. Existing CMS API and admin remain available; forum owns root and `/forum/*` paths. `migrations/*/0015_forum_edition.sql` create seven tables. Forum data resides in the same database as users, not in JSON files. `api/openapi.yaml` and generated SDK operations are synchronized.

## Access model

| Role | Scopes | Access |
|---|---|---|
| Guest | none | Browse/search/profile |
| forum-member | forum:write | Topics, replies, own edits, reactions, watches, reports |
| forum-moderator | forum:write, forum:moderate | Member functions, reports, edit others, lock/pin/delete |
| admin | * | All, plus category/board creation |

Core users without forum scope can browse but must receive the appropriate role to write. All boards in this release are public. Do not store private conversations in them. Registration verifies email by default; configure SMTP before enabling it. Set `FORUM_REGISTRATION=false` if opening a private or invite-only installation. The existing core global/auth rate limiter applies; deploy additional spam controls before opening registration to untrusted public traffic.

## Deployment

Use a separate database and site directory for Forum Edition. Start from a fresh installation, or rehearse upgrade on a copy. PHP 8.4.1–8.x, Composer 2, mbstring, pdo_sqlite or pdo_pgsql, json, sodium, phar, openssl, fileinfo. PostgreSQL 17/18 is the primary database for release acceptance; SQLite 3.40+ is supported for local and smaller installations. Certification requires passing the complete CI matrix.

For PostgreSQL create a dedicated database and user, then use `php bin/cajeer install --interactive`, select PostgreSQL and enter connection details. Serve the `public/` directory only. Use HTTPS for browser cookies; HTTP is allowed only for loopback development. Nginx example below assumes a working PHP-FPM pool (adapt socket to your server). Set a real `server_name` and TLS certificate using your deployment tools.

```nginx
root /srv/cajeerforum/public;
index index.php;
location / { try_files $uri $uri/ /index.php?$query_string; }
location = /index.php {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root/index.php;
    fastcgi_pass unix:/run/php/php8.4-fpm.sock;
}
location ~* \.php(?:/|$) { return 404; }
location ~ /\. { deny all; }
```

The inherited `/install` and `/upgrade` front controllers are routed through `public/index.php`; webserver directory handling may require explicit `location ^~ /install/ { try_files $uri /index.php?$query_string; }` and equivalent upgrade mapping, or use the upstream generated Nginx configuration. Prefer CLI installation. Set APP_ENV=production, APP_DEBUG=false, APP_URL=https://your-domain. Keep `.env` and database outside the public directory. Use the inherited full DB backup tools (`backup:create`, `backup:verify`, `backup:restore`) for forum data. CMS content ZIP export is not a forum export.

## Releases

`VERSION` and `release.json.version` identify the upstream engine (1.3.0); `release.json.edition_version` is 0.2.0. `ForumVersion` validates both identities. Source/dist artifact names, manifest version, release tags and updater comparisons use the edition version. The signed manifest also records `upstream_version`, checked against VERSION, release metadata and the prebuilt admin manifest. The engine API/SDK compatibility version stays unchanged. Health/admin/system diagnostics expose `edition_version`; HTTP responses include `X-CajeerEngine-Forum-Version`.

The 0.1.0 updater compared upstream engine versions. Apply the 0.1.1 changed-file archive manually to a backed-up test installation before using the new updater. Do not submit the patch ZIP to `update:apply`: it is not a signed full dist. With 0.1.1 installed, later signed releases can advance edition_version without changing upstream. Legacy forum manifests without upstream_version and ordinary CMS packages are refused by the updater. The upstream signed publication workflow is archived, not active; no production signature or deployment certification is claimed.

### Upgrade 0.1.0 → 0.1.1

1. Back up the database, application and `.env`; stop writes (maintenance/drain).
2. Overlay the changed-file ZIP at the project root. Do not replace `.env`, vendor, runtime storage or the database. No existing file is removed.
3. Run `composer install` if dependencies are not installed, then `php bin/cajeer migrate`. Existing migration files and checksums are unchanged; no forum schema migration is necessary.
4. Restart PHP-FPM/opcache and verify the version header and forum regression in a disposable checkout. Reopen traffic after checking existing topics, users and authentication.

### Transaction and error semantics

Thread creation and its first post commit together. Replies, edits, reactions, watches, reports and thread moderation acquire the parent thread lock before mutation. PostgreSQL/MySQL use row locks; SQLite reserves the writer with a no-op update before reading. Ordinals are allocated inside the lock, protected by the existing unique index. Audit for changed state is recorded in the same transaction. Multi-query board/thread/profile reads use read snapshots. Contention (deadlock, lock timeout, SQLite busy) returns HTTP 409 without leaking SQL. Clients can retry a rejected operation, but should not blindly retry a successful POST whose response was lost. Repeated reaction/watch requests and report resolution are idempotent. Unknown reports now return 404; duplicate registrations return 409; malformed page arguments return 400. Invalid input returns 422. Existing public route names and success response shapes are unchanged. Concurrent edits remain last-committed-write-wins; revision history/optimistic version checks are deferred to the content stage.

## Tests and limits

Run `php tools/forum-regression.php` after `composer install`. It boots the kernel, creates an isolated SQLite database, tests registration/login, author permissions, moderation, search, reactions, watches, reporting, CSRF, cascade and homepage. It uses a temporary database but writes inherited runtime logs/rate-limit files in the checkout, so run only in a disposable development checkout. Run `php tools/forum-core-regression.php` for actual service-level rollback, four-process concurrent replies, authorization, idempotency and migration ledger checks. `php tools/release-regression.php` and `php tools/update-regression.php` check packaging, signature verification and upgrade/rollback on a synthetic test dist. CI runs these in addition to the HTTP regression. PostgreSQL/MySQL migration SQL is supplied; test against real servers before use. SQLite `LOWER` is ASCII-only without ICU; Cyrillic case-insensitive search is limited. Current search uses bounded result pages and SQL LIKE, not a full-text index. Subscription list is saved but no email/push notifications. No private boards, nested subforums, attachments, direct messages, WYSIWYG, advanced profile fields, polls, Q&A, or import from XenForo/Invision. Moderation deletion is permanent; back up before deleting. No production performance, browser or security certification has been completed.


### 0.1.2 — Database & Installer

PostgreSQL 17+ is the primary target; SQLite 3.40+ is supported for small/local installations. MySQL/MariaDB migration compatibility is retained but is not certified by the Forum 0.1.2 test matrix. Use a dedicated PostgreSQL database, a role permitted to create tables/indexes/extensions required by existing migrations, and the `public` schema. For remote PostgreSQL, configure TLS with `--db-sslmode=verify-full` and a trusted libpq CA; `prefer` is kept for local compatibility. For SQLite use local persistent storage outside the web root, not a network filesystem or `:memory:`. The installer enables WAL and foreign keys. The web installer restricts SQLite to `storage/database/`.

Fresh install (run as the runtime user; `--interactive` prompts for passwords without placing them in shell history):

```sh
composer install --no-interaction --no-dev --optimize-autoloader
php bin/cajeer install --sqlite --interactive
# or PostgreSQL (provision the database first)
php bin/cajeer install --preset=postgres --interactive --production
```

The CLI also supports `--app-name`, `--category-name`, `--board-name`, `--registration=open|closed`, and `--db-sslmode`. Web: run `php bin/cajeer install:web-token`, then open `/install/` over HTTPS and enter the token, database, community and administrator details. The token expires after 30 minutes and is consumed on successful completion. The configured name is rendered in the forum and `closed` disables signup on both the API and UI. The installer accepts only an origin URL (no path); non-loopback production URLs must use HTTPS. Install with the same Unix user used by PHP-FPM/CLI operations; `.env` and newly created SQLite files are private (0600). SQLite defaults remain relative to the project so backups can be restored to a different directory.

A failed install does not create `installed.lock`. Once initial installation has begun, public HTTP routes remain closed (API returns 503) until the install lock is committed; `/install/` remains available. If schema migrations and administrator creation have succeeded but final verification did not, fix the reported problem and rerun with the same database, existing active administrator and password. The first-admin, initial section settings and audit are transactional; a retry does not overwrite existing section names or duplicate the administrator. Configuration already written is retained for recovery. Existing `.env` values are parsed and normalized to literal values on installation; comments/interpolations are not retained. For manually managed environment configuration use `--no-env` with the correct selected driver. Never delete the install lock to upgrade; `--force` no longer bypasses it. Use the installer only for initial setup or an interrupted initial setup.

Upgrade from 0.1.1: back up files and database, stop writers, overlay the changed-file ZIP, then run:

```sh
php bin/cajeer migrate
php bin/cajeer migrate:status
php bin/cajeer install:check --json
```

`0016_forum_lookup_indexes.sql` adds indexes only. Existing migration files and their SHA-256 hashes remain unchanged. PostgreSQL/SQLite DDL and ledger application are transactional; a failed migration can be retried after correcting the cause. A missing or altered applied migration blocks further migrations. The changed-file ZIP is not a signed updater package.

Verification on a disposable environment: `php tools/forum-installer-regression.php`, `php tools/forum-core-regression.php`, `php tools/forum-regression.php`. PostgreSQL tests run using separate empty `FORUM_TEST_INSTALL_DB`, `FORUM_TEST_UPGRADE_DB`, and `DB_DATABASE` with `FORUM_TEST_DRIVER=pgsql` and connection variables; CI provides disposable PostgreSQL 17/18 services. The installer regression exercises failure rollback, initial setup, interrupted setup resume, token protection, dotenv round-trip, data preservation and migration checksum checks. CI results must pass before deployment.

### 0.1.3 — Forum Identity & Access

Upgrade: back up files and DB, deploy the changed files and run `php bin/cajeer migrate` before accepting traffic. Migration 0017 is additive. Existing active users and the installer-created administrator stay active; new forum registrations are `pending` by default and cannot log in until confirmed. `FORUM_REQUIRE_EMAIL_VERIFICATION=false` explicitly opts out (e.g. trusted local demo); it does not activate existing pending accounts.

Configure SMTP in `.env` (use a trusted provider, a verified sender, and HTTPS `APP_URL`):

```dotenv
APP_URL=https://forum.example.org
FORUM_REQUIRE_EMAIL_VERIFICATION=true
FORUM_SMTP_HOST=smtp.example.org
FORUM_SMTP_PORT=587
FORUM_SMTP_SECURITY=starttls
FORUM_SMTP_FROM=forum@example.org
FORUM_SMTP_USERNAME=forum@example.org
FORUM_SMTP_PASSWORD='your-provider-secret'
```

`FORUM_SMTP_SECURITY=tls` supports implicit TLS (465); `plain` is limited to localhost/127.0.0.1 without authentication (Mailpit/local development). SMTP TLS verifies the server certificate and hostname; authentication uses AUTH PLAIN inside TLS. Mail is sent synchronously after transaction commit. If SMTP fails, the account remains recoverable; audit event `auth.identity_mail_failed` contains no link or credentials. Configure SMTP before opening public registration. `/forum/resend-email` and `/forum/forgot-password` allow a retry after 60 seconds; responses are generic even for nonexistent accounts. Links expire after 24h (verification) or 30 minutes (reset); only SHA-256 token hashes are stored. Confirmation also requires the registration password to prevent pre-registered account takeover. Email links put the token in a URL fragment, removed from the address bar by the UI before submission. Use a fresh request if the link expires. Reset invalidates all tokens, preserves TOTP and does not reactivate disabled accounts.

`/forum/account` includes security and active sessions. Session revocation is scoped to the current user's login sessions; revoke-all requires password and current second factor when enabled. API tokens are not included in session listing/revoke-all. Existing token API remains unchanged; password reset and 2FA changes revoke all tokens (including API tokens). TOTP enrollment uses `POST /api/v1/auth/2fa/enable` (returns `secret`, `setup_token`, `otpauth_uri`, `expires_in`; no credential change), then `POST /api/v1/auth/2fa/confirm` with `password`, `setup_token`, `two_factor_code`. The confirmation code cannot be used again; wait for the next 30-second code or use a recovery code to log in. Recovery codes are shown once; store them securely. Disabling 2FA requires password and unused TOTP/recovery code. The enrollment response intentionally changes from 0.1.2: clients must implement confirmation. TOTP secrets follow the existing database storage model; secure DB and backups. No QR rendering library is required: the account page offers the manual secret and the API provides an otpauth URI.

Verification and reset requests use existing auth IP rate limits plus per-account mail cooldown. Production multi-instance shared rate limiting, asynchronous mail and external penetration testing are later operational work. Tests: `php tools/forum-identity-regression.php` with Mailpit at `127.0.0.1:1025` / HTTP `127.0.0.1:8025`; use a disposable Mailpit and DB. The CI tests the HTTP identity lifecycle with SQLite and PostgreSQL.

Session timestamps are now written and interpreted in UTC; on a legacy installation that ran non-UTC PHP/DB time zones, revoke old sessions at upgrade to avoid ambiguous stored expiry timestamps. Login also has a per-account rate limit using the existing file-backed rate limiter.

### 0.1.4 — Forum Test Infrastructure

The `Forum Edition CI` workflow runs the existing engine/Forum regressions plus PHPUnit, real network HTTP and Playwright. All checks are required by the aggregate `forum-required` job. Configure that job as a required status check in the repository's branch protection/ruleset; workflow code alone cannot enforce repository settings. No new database migrations or API changes in this release.

**Local commands (disposable checkout; never point tests at production):**

```sh
composer install --prefer-dist --no-interaction
vendor/bin/phpunit --fail-on-warning --fail-on-risky --log-junit storage/tmp/forum-phpunit.xml
vendor/bin/phpstan analyse core/Forum --level=3 --no-progress
php tools/forum-core-regression.php
# Local Mailpit (no real mail delivery):
docker run --rm -p 127.0.0.1:1025:1025 -p 127.0.0.1:8025:8025 axllent/mailpit:v1.27
# In a separate terminal:
python tools/forum-tests/run.py --suite api --report storage/tmp/forum-ci/network
python -m pip install -r tools/forum-tests/requirements.txt
python -m playwright install chromium firefox
python tools/forum-tests/run.py --suite browser --browser chromium --report storage/tmp/forum-ci/chromium
python tools/forum-tests/run.py --suite browser --browser firefox --report storage/tmp/forum-ci/firefox
```

The network/browser runner installs a temporary source tree with the actual InstallerService, starts PHP on a random loopback port, and uses a fresh SQLite file. It never reads the checkout's `.env` or copies runtime storage; the temporary installation and PHP process are removed even on failure. Test mail goes only to the local Mailpit server. To run PostgreSQL, create a **new empty** database on a disposable local PostgreSQL 17/18 instance, e.g. `forum_test_network`. Export `FORUM_TEST_DRIVER=pgsql`, `FORUM_TEST_NETWORK_DB=forum_test_network`, `DB_HOST=127.0.0.1`, `DB_PORT`, `DB_USERNAME`, `DB_PASSWORD`, `DB_SSLMODE=disable`. The fixture refuses non-loopback hosts, other database name prefixes and nonempty public schemas. The DB is not automatically dropped; drop/recreate the disposable DB before repeating. `FORUM_TEST_CORE_DB` and `FORUM_TEST_PHPUNIT_DB` must name separate empty `forum_test_*` databases for core and PHPUnit PostgreSQL tests. The legacy installer and identity scripts retain their separate database variables; see CI for the complete setup.

Network tests cover the HTTP status/error contract, pending email lifecycle via SMTP, token replay, password reset, TOTP and recovery code replay, session isolation, unauthorized edits, moderation, search injection/wildcards, cookie flags, CSRF, cascade deletion and IP rate limiting. Browser tests perform real registration, verification, discussion creation, XSS-as-text checks, replies, reactions, watches, password reset, 2FA, session revocation and administrator moderation. Existing identity regressions also exercise expired challenges. CI retains JUnit XML, PHPUnit forum coverage, installation/HTTP logs and failure screenshots/Playwright trace (synthetic users only) for seven days. Browser jobs use SQLite; HTTP and transactional regressions also run on PostgreSQL 17/18. Coverage is reported rather than equated with production certification. HTTPS/reverse-proxy behavior and MySQL/MariaDB certification are outside this release.

### 0.2.0 — Forum Core Stable

For a manual 0.1.4 upgrade, back up application, configuration and database, stop web writes/workers, overlay the changed-file archive at the project root, install locked dependencies, run `php bin/cajeer migrate`, `php bin/cajeer install:check --json`, and `php tools/update-postcheck.php verify`. Restart PHP-FPM/opcache, verify `/api/v1/health` and existing discussions before resuming service. Do not run the installer again. No new SQL migration is introduced. Existing `.env` values are unchanged. New production installs default to file cache and closed registration.

A full dist is built with `composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader` followed by `php tools/build-release.php --all --json`. Test that physical artifact, not the working checkout, with a running loopback Mailpit:

```bash
python tools/forum-tests/release_acceptance.py --artifact storage/releases/cajeerengine-0.2.0-dist.zip --report /tmp/forum-release-clean
python tools/forum-tests/release_acceptance.py --artifact storage/releases/cajeerengine-0.2.0-dist.zip --previous /path/to/cajeerengine-0.1.4-dist.zip --report /tmp/forum-release-upgrade
```

The previous artifact must be a genuine 0.1.4 dist, not a relabelled copy of 0.2.0. CI builds the baseline from input archive commit `47838a81a5f3122cde3bc2558c7e2212bdf2d723`; if repository history was rewritten, set repository variable `FORUM_BASELINE_REF` to the authentic 0.1.4 commit. CI fails if the baseline cannot be built or the version is wrong. PostgreSQL acceptance uses `--driver pgsql` plus `FORUM_TEST_RELEASE_DB=forum_test_release_*`, `DB_HOST=127.0.0.1`, `DB_PORT`, `DB_USERNAME`, `DB_PASSWORD`. Use a dedicated empty database owned by a disposable test role with CREATEDB, and matching `pg_dump`, `pg_restore`, `psql` clients. The test performs destructive restore against that isolated database; never use production credentials or database names.

`forum-required` requires SQLite/PHPUnit, PostgreSQL, browser and physical release jobs. Configure GitHub Rulesets to require that status. The version number is not proof of certification: publish only after these jobs pass, review logs, and rehearse upgrade in staging. For production TLS, deployment, full disaster recovery and load certification see later roadmap stages.
