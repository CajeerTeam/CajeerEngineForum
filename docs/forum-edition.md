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

Core users without forum scope can browse but must receive the appropriate role to write. All boards in this release are public. Do not store private conversations in them. Registration does not verify email in this release. Set `FORUM_REGISTRATION=false` if opening a private or invite-only installation. The existing core global/auth rate limiter applies; deploy additional spam controls before opening registration to untrusted public traffic.

## Deployment

Use a separate database and site directory for Forum Edition. Start from a fresh installation, or rehearse upgrade on a copy. PHP 8.4.1–8.x, Composer 2, mbstring, pdo_sqlite or pdo_pgsql, json, sodium, phar, openssl, fileinfo. PostgreSQL is the recommended production target of upstream, not certified for this preview.

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

`VERSION` and `release.json.version` identify the upstream engine (1.3.0); `release.json.edition_version` is 0.1.2. `ForumVersion` validates both identities. Source/dist artifact names, manifest version, release tags and updater comparisons use the edition version. The signed manifest also records `upstream_version`, checked against VERSION, release metadata and the prebuilt admin manifest. The engine API/SDK compatibility version stays unchanged. Health/admin/system diagnostics expose `edition_version`; HTTP responses include `X-CajeerEngine-Forum-Version`.

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
