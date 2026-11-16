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

`VERSION` and `release.json.version` identify the upstream engine (1.3.0); `release.json.edition_version` is 0.8.3. `ForumVersion` validates both identities. Source/dist artifact names, manifest version, release tags and updater comparisons use the edition version. The signed manifest also records `upstream_version`, checked against VERSION, release metadata and the prebuilt admin manifest. The engine API/SDK compatibility version stays unchanged. Health/admin/system diagnostics expose `edition_version`; HTTP responses include `X-CajeerEngine-Forum-Version`.

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

### 0.2.1 — Forum Node System

Run `php bin/cajeer migrate` before serving the new code. Migration 0018 preserves all existing UUIDs and initializes existing boards as open, top-level forums. The public `/forum` lists nested boards; `/forum/manage` lets administrators create/edit categories and boards, move a subtree, reorder siblings using Up/Down, and remove empty leaf nodes. All structural operations require `*` and commit with their audit entry.

A board has `parent_id` (null for category root), `node_type` (`forum` or `container`), `state` (`open`, `readonly`, `archived`). Containers cannot hold topics; existing content prevents conversion to a container. Archived/read-only boards and descendants remain publicly readable, but new threads, replies and edits are rejected even for admins. Moderation, reports, reactions and watches retain their existing behavior. **Archive is not a private forum**; node-level authorization is planned for 0.2.2.

API: `POST /api/v1/forum/categories/{id}/boards` accepts optional `parent_id`, `node_type`, `state`, `position`; `PATCH /api/v1/forum/categories/{id}` edits and optionally sorts; `PATCH /api/v1/forum/boards/{id}` edits metadata/state; `POST /api/v1/forum/boards/{id}/move` accepts `category_id`, optional `parent_id` and `position`. Position is zero-based within the destination siblings; omitted means append. Both `DELETE` endpoints only delete empty leaves/categories. Use full metadata in PATCH. Depth is capped at 16 boards. Topic and message IDs never change on subtree move.

CI release acceptance uses the genuine 0.2.0 commit `afedece6c28798e33825b7e28e766472c5eb5b09` (override `FORUM_BASELINE_REF` only with that release's authentic commit). Node service regression runs on SQLite and PostgreSQL 17/18; network tests exercise the new HTTP operations.

### 0.2.2 — Node Permissions

After backing up, apply `php bin/cajeer migrate` before serving requests. Migration 0019 preserves all existing IDs and defaults to public visibility. In `/forum/manage`, administrators can edit category and board visibility and ACLs. `GET/PUT /api/v1/forum/{boards|categories}/{id}/permissions` reads/replaces the complete policy atomically. PUT needs `visibility` and `rules`; each rule contains `subject_type`, `subject_id` (UUID for role/user, otherwise empty), `permission`, `effect`. Omitted rules inherit.

Public nodes inherit permissions. Private nodes require an explicit local `thread:read` allow for the intended subject; hidden nodes additionally require `node:view`. Both are omitted from all discovery and direct access for unauthorized viewers. A denied ancestor cannot be traversed; a child cannot expose a hidden parent. Within a node, user > role > guest/authenticated > everyone, deny wins equal specificity, and never wins across the chain. A closer rule may override inherited deny for non-read operations; global token scopes remain the ceiling. The system administrator (`*`) can administer all nodes. ACL changes apply to existing sessions on their next request.

Supported permissions: `node:view`, `thread:read`, `thread:create`, `post:reply`, `post:edit_own`, `post:edit_any`, `reaction:use`, `watch:use`, `report:create`, `thread:moderate`. Structural changes and ACL management remain administrator-only. Archived/read-only publication state is independent of ACL. Moving a subtree changes its inherited ACL; review the destination policy before moving. Rules are never exposed by public reads; only effective permissions of the requesting actor are returned.

### 0.2.3 — User Groups

Groups reuse `ce_roles` / `ce_user_roles`; 0020 adds group metadata and a primary membership FK. Existing forum-member and forum-moderator assignments are retained. A primary group and up to 100 secondary groups may be selected; primary is descriptive, not a permission priority. Users without forum roles (including core-only administrators) can have no primary until assigned. Registration retains forum-member.

Create custom groups in `/forum/manage`, with a unique handle (stored as `forum-group-<handle>`), name, description and forum scopes. Group permissions are additive across memberships, and node ACL deny/never rules still apply. Groups cannot grant core scopes or `*`. Built-in groups are protected. A custom group with `forum:moderate` needs explicit `thread:moderate` / `post:edit_any` node grants; built-in forum-moderator retains existing global behavior subject to ACL. Assign groups in the same UI and use the existing ACL editor (group roles appear in the role selector). Remove assignments and ACL references before deleting a group.

Admin-only endpoints: GET/POST `/api/v1/forum/groups`, PATCH/DELETE `/api/v1/forum/groups/{id}`, GET/PUT `/api/v1/forum/members/{id}/groups`. PUT takes `primary_group_id` and `secondary_group_ids` (array), replacing forum memberships while preserving unrelated core roles. Audit records changes. Current role memberships and scopes are checked on each request; existing token scopes remain an upper bound, so newly granted scopes require a fresh login/token if not already included.

Migrate before serving 0.2.3. The CI baseline for release acceptance is the authentic 0.2.2 source commit.

### 0.2.4 — Discussion Management

Run `php bin/cajeer migrate` before serving the release (migration 0021). The authentic upgrade baseline is 0.2.3. From a board select threads for bulk lock/unlock/pin/unpin/move; from a thread edit prefix/tags, merge selected source topics, or select replies for a split or transfer. The moderator must have `thread:moderate` in every source and destination; destinations must be open discussion forums. Cross-board operations explicitly publish the moved content under the destination ACL. The first post cannot be transferred separately. Merges keep the target title/prefix, combine up to 10 normalized tags (otherwise reject), append source posts in their original order, and deduplicate subscriptions. New split topics have the first transferred post's author/date and inherit source prefix/tags; subscriptions remain with the source. Old merged URLs resolve only if both original board and current target are readable. Post IDs, reactions and reports remain intact. Board rules are editable as plain text in node administration; omitted rules on PATCH preserve their value. New topic tags are moderator-managed; exact tag filtering uses `/forum/search?tag=...`.

### 0.2.5 — Forum Navigation & Admin

- Open `/forum/manage` as a system administrator. The compact tree supports dragging a node handle to **Before**, **Inside**, or **After** another node. Keyboard/touch users can select a handle and activate the same target buttons. Escape cancels selection. A move is confirmed because inherited ACL and publication state may change. The server resolves the relative position under the existing tree lock; invalid moves do not modify the tree.
- Public board/thread responses include `breadcrumbs` (category, ancestors, current board), computed in the same read snapshot and filtered by the actual reader's ACL. The category crumb links to its home anchor. The public tree supports expand/collapse.
- The permissions editor displays all ten permissions for a selected guest/authenticated/role/user subject. Switching subjects retains the draft; Save replaces the entire local rule set. GET returns `revision`; supplying it on PUT rejects concurrent changes with HTTP 409. Existing API clients may omit it.
- `GET /api/v1/forum/permission-inspector?kind=board&id=<uuid>&user_id=<uuid>` (omit `user_id` for guest) requires `*`. It returns the effective ACL, account status, publication state and the matching rules along the ancestor chain. This is a fresh-login preview using current role scopes, not a simulation of a particular issued API token. Publication state, topic locking and ownership are additional runtime constraints.
- `POST /api/v1/forum/nodes/reorder` requires `*`: `{kind,id,target_kind,target_id,placement}` where placement is `before`, `inside` or `after`. Categories can only be reordered relative to categories, boards can be placed inside a category/board or before/after a board. Previous routes remain supported.
- Upgrade 0.2.4 to 0.2.5 using the usual backup and update procedure; no new migrations. Run `forum-required` including the physical release acceptance and browser suites before declaring the release certified.

### 0.3.0 — Forum Structure Stable

- Current account status and role scopes are rechecked after acquiring the write locks, including topology, permission and group administration. Read operations use the same database snapshot as their ACL checks. An already authenticated request whose account has been disabled is rejected rather than retaining a stale grant.
- Report resolution acquires the topic before the report to avoid an inverted lock order during cascade deletion; transfers recheck the report's actual topic after locking.
- `php tools/update-postcheck.php verify` runs the schema/ledger checks and a read-only forum data integrity audit (empty topics, container topics, invalid/duplicate ordinals, redirect collisions, group membership and scope integrity). The installer uses the same audit before finalizing. This is intentionally not a full corpus scan on every HTTP readiness probe. Resolve any reported data inconsistency on a backup/staging copy before retrying; do not drop user content to make the check pass.
- The physical release suite seeds private nested content and group memberships on the authentic 0.2.5 baseline, checks permissions, watches, reports, reactions and redirect identity after 0.3.0 upgrade, then after both manual and failed-upgrade rollback.
- Upgrade via a verified signed full dist or the usual backup/drain/manual overlay. No new SQL migrations. Run `forum-required` (SQLite, PostgreSQL 17/18, HTTP, browser and physical dist acceptance) before declaring this release certified.

### 0.3.1 — Member Profiles

Apply migration `0022_forum_member_profiles.sql` before serving the new code. It adds profiles, preferences, profile images, custom field definitions and values for all three DB drivers. Existing users are not copied or rewritten: absent rows are read as defaults. Backup and rollback use the same database backup as forum discussions; avatar/cover bytes are included because they are stored in the database.

- `/forum/members`: active member directory, name search, name/newest sorting, 25 results per page.
- `/forum/members/{id}`: display name, bio, location, website, avatar, cover, signature, optional group labels, custom fields and ACL-filtered topics.
- `/forum/account/profile`, `/forum/account/preferences`: authenticated profile editor, image upload and preferences. `/forum/account/privacy` opens the privacy center. Settings include profile/activity visibility, group labels, signatures in discussions, system/light/dark theme, date locale and IANA time zone. The locale setting formats dates, not the full Russian UI.
- `/forum/manage`: system administrators can create, rename, reorder and delete up to 32 custom text fields and clear profile descriptions, signatures and images. Deleting a field deletes its values. Node-scoped moderators cannot administer profiles globally.

Profile visibility is `public`, `members` (active authenticated users), or `private` (owner only). Activity visibility is independent and further restricts the topic list, which also uses node ACL. Public profile data, directory results, signatures and images are filtered at request time. Profile privacy does not anonymize existing public posts or their original account-name author labels. Group labels are opt-in; exposing them may reveal group membership. Profile field visibility is configured per field by administrators and never overrides the enclosing profile privacy. The administrator's profile moderation endpoint clears content without granting access to a private profile.

Image uploads require PHP GD (`php8.4-gd` on Debian-style deployments; already in the production deployment requirements). The API accepts JSON base64 JPEG/PNG/WebP, decoded size up to 2 MiB, at most 4 million pixels and 4096 px per side. Images are resampled and re-encoded as JPEG (avatar max 256x256, cover max 1200x480), discarding original metadata and animation/alpha. GET `/api/v1/forum/members/{id}/images/{kind}` returns JPEG with private/no-store, or 404. The endpoint uses the existing `/api/v1` browser cookie path. Missing GD produces 503 for upload only; text profiles remain usable. Do not move images into public/uploads or cache the protected endpoint at a reverse proxy.

REST API uses authenticated `GET/PATCH /forum/account/profile`, `PATCH /forum/account/preferences`, `PUT/DELETE /forum/account/images/{kind}`; public `GET /forum/members` and `GET /forum/members/{id}/images/{kind}` support optional authentication. Admin-only `GET/POST /forum/profile-fields`, `PUT/DELETE /forum/profile-fields/{id}`, `POST /forum/members/{id}/moderate-profile`. All paths are relative to `/api/v1`. Browser mutations require the existing CSRF header. PATCH fields are optional, omitted fields are retained; custom field changes use a list of `{id,value}` with empty values removing the stored value.

Verification: `ForumProfileTest`, network `test_07_member_profiles`, browser profile editor, and physical release acceptance of original 0.3.0 → 0.3.1. The CI PHP environments include GD. Full certification requires the `forum-required` matrix; local syntax or SQL-only checks are not equivalent to full HTTP/DB/browser execution.


### 0.3.2 — Read Tracking

- All legacy posts are assigned a publication sequence matching their ordinal during migration 0023. Existing accounts start with no watermark, so existing visible content is unread until acknowledged.
- `GET /api/v1/forum/unread?page=1` lists only readable unread threads. `GET` requests do not mutate state. Authenticated thread detail exposes `read_state` (`read_sequence`, `unread_posts`, `first_unread` with `id` and `page`); each returned post exposes `read_sequence` and `is_unread`. List responses include `unread_posts` and board views include `unread_threads` for the readable subtree. Guests have no personal read state.
- `POST /api/v1/forum/read-state` accepts `{"scope":"thread","id":"THREAD_UUID","post_id":"POST_UUID","sequence":3}` to acknowledge through an observed post. Pass only scope and id to explicitly mark the whole topic; use `{"scope":"board","id":"BOARD_UUID"}` for a board and readable descendants, or `{"scope":"all"}` for all readable threads. The browser sends its CSRF header. Stale moved posts return 409; repeated/older acknowledgements never rewind a cursor.
- Page view acknowledges through the last rendered post when the document is visible; opening a later page also acknowledges earlier posts. A new reply after the response snapshot remains unread. Edits do not create a new publication. Moving a whole topic retains its watermark, while split/merge/post transfer assigns fresh destination sequences. Removed posts no longer contribute to unread counts; read state is removed by cascading foreign keys when a user or thread is deleted. No time-based expiration is applied (one row per user/thread, not per post).
- Bulk acknowledgement locks candidate threads in UUID order, then the tree and actor, rechecks ACL and updates only still-readable candidates. New topics outside the captured candidate set remain unread. Very large forums should benchmark mark-all contention; operations are synchronous and transactional.
- Run migration before serving 0.3.2. Verification: `ForumDatabaseTest`, `test_08_read_tracking`, Playwright lifecycle, and physical release acceptance from original 0.3.1. The full `forum-required` matrix remains the release gate.

### 0.3.3 — Notification Engine

- Apply migration `0024_forum_notifications.sql` before serving the new release. Existing users have all five notification kinds enabled; old posts are not replayed.
- Run the normal default queue worker regularly: `php bin/cajeer queue:work --queue=default --limit=10`. Each invocation drains up to 100 pending recipient rows even with no queue jobs; keep the existing systemd worker/timer running. `forum_notifications_processed` reports processed (delivered or discarded) rows. The worker can retry after a crash without duplicating an inbox item.
- Replies notify the topic author and current watchers (excluding the sender). `@{user-uuid}` mentions and `>>{post-uuid}` quotes are explicit stable references, up to 20 distinct references per type; quotes are restricted to posts in the same thread. Reply controls insert the references. Editing existing text does not replay events. Reactions notify the post author; undo removes the corresponding notification. Moderation notifications cover actual lock/unlock/pin/unpin transitions, including bulk operations. Deleted posts/topics cascade-delete notifications.
- `/forum/notifications` provides pagination, unread count, mark-one/all and preferences. Preferences affect new/pending events; delivered items remain. Titles and current thread/page are resolved on read, never copied into event payloads. ACL is checked at dispatch and again before counts, pagination, target resolution and mark-read; unauthorized pending recipients are discarded.
- Delivery uses a database outbox row per event/recipient, with pending and delivered states. Publication and enqueue commit together. Worker promotion is atomic; concurrent workers lock the same row. The same recipient can receive different event types for one post, but repeated mentions/quotes of the same recipient within that type are deduplicated. No email delivery is added in this release.
- Verification: `test_09_notifications` and physical dist acceptance from the original 0.3.2; require the full SQLite/PostgreSQL and browser CI matrix before certification.

### 0.3.4 — Watches & Email Delivery

Run `php bin/cajeer migrate` before serving traffic. Existing watches become `internal`; no historical mail is generated. On a board or thread choose tracking only (`none`), `internal`, `email`, `daily`, or `weekly`. Board watches cover new topics directly in the board; thread watches cover replies. Independent author reply notifications remain controlled by the notification preferences. Auto-watch is opt-in and does not overwrite an existing explicit mode. `/forum/watches` manages both kinds, auto-watch and delivery counters. Email requires a verified address; existing active users can request verification via `/forum/resend-email` without changing account status.

Use existing `FORUM_SMTP_*` environment settings and HTTPS `APP_URL` (plain SMTP only on loopback for testing). A regular `php bin/cajeer queue:work --queue=default --limit=10` drains both outboxes. Email rows are committed with posts, leased for 300 seconds, retried with exponential delay (60 seconds to one hour) at most eight times; terminal errors are visible as `dead`. Each recipient gets at most one accepted batch per minute (20 immediate events or 100 digest events per message); daily digests are due at midnight UTC and weekly digests at Monday midnight UTC. Over-limit batches drain subsequently. The worker returns `forum_mail` counters. SMTP bodies contain authenticated discussion links, never copied titles or post bodies. ACL, current watch mode, account and verified address are rechecked before sending. Unsubscribe uses a 90-day HMAC token tied to user and email, with explicit POST confirmation; email modes become internal and pending mail is cancelled. An already in-flight SMTP submission cannot be recalled; SMTP acceptance followed by a crash before the database acknowledgement may cause a retry, with stable Message-ID for an identical batch. Back up the DB including delivery state; preserve APP_KEY.

Real HTTP/Mailpit coverage is `test_10_watch_delivery`; physical release acceptance covers the original 0.3.3 baseline. PostgreSQL and browser certification requires successful `forum-required` CI.

### 0.3.5 — Private Conversations

`/forum/conversations` provides direct and group text messages, search, archive, mute, recipient selection and invitations. Each active member has an independent monotonic read watermark. New or re-invited members can see only messages published since their latest invitation; leaving removes access to the history. The group owner can invite up to 20 total active participants or remove participants; when the owner leaves, ownership passes to a remaining member. Direct conversations cannot invite additional members. Blocks prevent new conversations, invitations and sends between the blocked pair, including group sends. Incoming settings apply to new conversations/invitations.

The notification center displays unread unmuted conversations directly from durable membership and message state (no asynchronous delivery required); muted conversations still show unread counts in their own inbox. Messages are plain text. The system does not implement end-to-end encryption or attachments. Only active participants may read normal conversation APIs, including administrators. `/forum/conversation-reports` is administrator-only and displays a reported-message snapshot with an audited view/decision; it never grants access to the surrounding conversation. Database operators and backups remain privileged.

Upgrade from 0.3.4: stop writes/workers, back up files and database, apply the changed files and run `php bin/cajeer migrate`, `php bin/cajeer install:check --json`, and `php tools/update-postcheck.php verify`. Migration 0026 creates the conversation tables; prior migrations are unchanged. All conversation writes are serialized through a database lock, keeping invitations, blocks and read/send decisions consistent; benchmark before scaling to very high private-message volume. Limits: five new conversations/hour, 20 invitations/hour, 30 replies or edits/minute per account, 10 reports/hour. Run `python tools/forum-tests/run.py --suite api --report storage/tmp/forum-ci/network` with Mailpit and the standard CI dependencies; `test_11_conversations` covers authorization, membership, history, reports, blocks and read state.

### 0.3.6 — Reputation & Activity

Run `php bin/cajeer migrate` before serving the new release. Migration 0027 preserves existing reactions as `like`. One reaction per member/post: like (1 point), helpful (2), celebrate (1). The legacy `likes` counter counts only likes; `reactions` and `my_reaction` expose the new types. A type change replaces the previous reaction without emitting a second notification; removing the reaction removes its notification. Self-reactions remain compatible with old clients but never contribute to reputation.

`/forum/activity` offers all, mine and following views; `/forum/leaderboard` ranks public profiles; `/forum/member-relations` manages private follows/ignores. Ignored posts are collapsed (not access-restricted) in discussions, excluded from activity feeds. The feed includes publications, not private messages, and filters current node ACL and profile/activity privacy before pagination. Public statistics and achievements count only guest-readable boards and active third-party voters. Leaderboard additionally requires public profile and activity visibility. Results are calculated from current data, so deleting or relocating content and revoking reactions immediately change the totals. Achievements are live milestones rather than irrevocable awards; administrators configure thresholds/enabled states at `/forum/manage`. Veteran uses account age in days.

Regression: `python tools/forum-tests/activity_sql_regression.py`; complete CI runs `tools/forum-tests/run.py --suite api` and physical release acceptance on SQLite and PostgreSQL. No worker or external service is needed for reputation.


### 0.4.0 — Community Stable

Upgrade from 0.3.6: stop writes and default workers, back up the application and database, apply the changed files, run `php bin/cajeer migrate:status`, `php bin/cajeer install:check --json` and `php tools/update-postcheck.php verify`, then restart. No new migration; all old checksums are unchanged. A signed full dist is required for `update:apply`; a changed-files ZIP is not a signed update package.

Community integrity checks now validate stored JPEG size, format markers and dimensions (without decoding pixels on every request), notification consistency and conversation rate-limit records. An invalid image is not served; an invalid stored image or inconsistent notification fails offline postcheck. These checks do not silently repair or delete data. If postcheck reports corruption, restore the relevant records from a trusted backup or remove/repair the invalid data through a controlled administrative operation before retrying the upgrade. Routine HTTP readiness does not scan image bodies.

Conversation creation counts each initial recipient against the same 20-invitations/hour budget as subsequent invitations; a failed batch rolls back its counters. Blocks apply to editing existing messages as well as new sends. `GET /api/v1/forum/notifications` retains `conversation_unread` (unmuted only), adds `conversation_total_unread` (all unread active conversations), and the main conversations navigation uses the latter. Existing API clients can continue using the original field.

SMTP workers refresh recipient, subscription and ACL eligibility and reject lost or nearly expired leases before starting transport. A batch abandoned before SMTP releases its claim without spending a transport retry; only acknowledged database rows count as sent. Revocation/unsubscribe cannot recall an email already being transmitted; SMTP and database acknowledgement are not one atomic operation, so a crash can still cause a retry. Keep the existing default worker running for internal notifications and subscription email.

Release acceptance: run `python tools/forum-tests/community_sql_regression.py` for disposable SQLite audit/backup/restore, and `python tools/forum-tests/release_acceptance.py --artifact <0.4.0-dist.zip> --previous <0.3.6-dist.zip> --driver sqlite --report storage/tmp/forum-ci/release/upgrade` with Composer dependencies, PDO, GD and Mailpit installed. PostgreSQL 17/18 use isolated databases in CI. The physical baseline is the 0.3.6 commit from the source archive (`eaeaa003402a36cad11ec017c7899489da2f8724`); override `FORUM_BASELINE_REF` only with a commit containing that original edition. The test seeds real profile, read-state, email and private-conversation data *before* upgrade, verifies the same IDs, content and preferences afterward, and checks manual and failed-update rollback. Run the full `forum-required` CI matrix to certify the release; a successful syntax/SQL-only check does not certify the deployed PHP application.

### 0.4.1 — Rich Text Editor

Run `php bin/cajeer migrate` before serving the new version. Existing posts and private messages remain `plain`. New writes accept `body_format` (`plain`, `markdown`, `bbcode`); edits without it retain the current format. Read responses include `body_html` and `renderer_version`. Authenticated `POST /api/v1/forum/content/preview` renders the same source grammar without publishing.

The toolbar and visual editor are available for topics, replies, edits and conversations. The visual mode serializes supported formatting to BBCode; explicit conversion from Markdown/plain can normalize source layout. Markdown supports headings, emphasis, strike, links, fenced/inline code, lists, quotes and simple tables. BBCode supports b/i/u/s, headings, quotes, lists, tables, URL and code/icode. This is a bounded subset, not a full CommonMark or legacy BBCode implementation. Raw HTML and remote images are never rendered; only http/https links are accepted. References inside formatted code or escaped literals do not enqueue notifications. Quote references resolve only within the current readable topic.

Run `php tools/forum-content-regression.php` for the renderer security and behavior tests. Browser and HTTP integration are included in the CI matrix.


### 0.4.2 — Attachment Pipeline

Run `php bin/cajeer migrate` before serving requests. Attachments are stored as base64 bytes in the database; DB backups include the bytes. Use the existing default queue worker regularly to delete expired pending files (24 hours, batches of 100). `forum_attachments_cleaned` reports the number removed. No public storage URL or extra filesystem backup is needed.

Set `FORUM_ATTACHMENT_MAX_MB` (default 5, maximum 25) and `FORUM_ATTACHMENT_QUOTA_MB` (default 100, maximum 2048). Optional `FORUM_ATTACHMENT_GROUP_LIMITS='{"forum-group-developer":{"file_mb":10,"quota_mb":250}}'` overrides limits for the current primary forum group. Secondary groups do not stack quotas. Limits: 10 attachments and 50 MiB per message, 100 pending and 1000 stored files per user. Set PHP `upload_max_filesize`, `post_max_size`, proxy body size, memory limit and MySQL `max_allowed_packet` accordingly; the application validates one multipart file per request.

POST `/api/v1/forum/attachments` with multipart `file`, then include `attachment_ids` in an existing create/edit request. IDs are owned, unexpired and single-use; failed publications leave the upload pending. GET `/api/v1/forum/attachments` lists temporary files and quotas. GET `/api/v1/forum/attachments/{id}/download` returns verified bytes with `Content-Disposition: attachment` after checking current ACL or conversation history. DELETE removes pending files or published files when the caller has current edit permissions. User deletion cascades owned attachments. Text is still required for publication.

Allowed extensions: txt, csv, pdf, png, jpg, jpeg, webp, zip, with matching detected MIME. Files are forced downloads, not rendered inline; ZIP/PDF are not extracted or sanitized and there is no antivirus claim. Configure external malware scanning if untrusted downloads are permitted. Images are inspected for dimensions and format, not fully decoded or re-encoded at this stage. Storage, variants, inline images and S3 integration are planned for 0.4.3.

### 0.4.3 — Media & Storage

Image attachments are processed by the default queue worker using GD or an absolute trusted `MEDIA_CONVERT_BINARY` (ImageMagick). The worker re-encodes raster images up to 16 MP into private thumbnail (240), medium (960) and large (1600) variants, never enlarging the source. Originals remain downloadable attachments; no raw image is served inline. Derivatives are bounded to 5 MiB each and stored transactionally in the database. Original quotas remain unchanged; derivative storage has a separate per-user limit `FORUM_MEDIA_VARIANT_QUOTA_MB` (100 MiB default, 1–2048 MiB). Usage is separately reported in pending uploads and media diagnostics. Quota exhaustion leaves the original available; retry after deleting unused media or increasing the budget. Images beyond the processing budget remain downloadable. Retry failed images using `/forum/manage`.

Use `image{attachment-uuid}` in a message to display its own attached image inline. Only attachments of that message can be embedded, and code blocks are not expanded. The gallery provides responsive images, keyboard navigation and original download. Every image request rechecks node ACL or conversation membership/history.

Set `FORUM_MEDIA_S3_REPLICA=true` and a unique `FORUM_MEDIA_S3_PREFIX=forum-production` with the existing `S3_ENDPOINT`, `S3_BUCKET`, `S3_REGION`, `S3_ACCESS_KEY`, `S3_SECRET_KEY`, `S3_PATH_STYLE` settings. Use a private bucket and HTTPS; HTTP is permitted only for explicit local/testing with `MEDIA_S3_ALLOW_HTTP=true`. Credentials require GetObject, PutObject and DeleteObject in `forum-media/<prefix>/`. The database remains authoritative; the worker replicates original bytes (not derived images), reads them back and verifies SHA-256, periodically checks ready objects, and retries failures. Deletion leaves a durable tombstone until S3 deletion succeeds. S3 keys/URLs are not exposed to users. Do not change endpoint, bucket or prefix while outstanding replica rows exist: their destination identity is pinned. Use a separate prefix for restored test databases; remote object deletion is not part of database rollback. Retain independent database backups; derivatives can be rebuilt from originals, and verified original recovery from a replica is available to administrators.

Run `php bin/cajeer queue:work --queue=default --limit=10` regularly. Each invocation processes at most one image and one replica. Images use a five-minute reservation fence so concurrent workers do not decode the same input and stale workers cannot overwrite a newer result. `forum_media` reports the outcome, `/forum/manage` displays aggregate counts and allows retry/recovery by attachment UUID. Remote operations are bounded and serialize on the selected attachment/replica; benchmark SQLite writer contention before enabling S3 on a busy forum.


### 0.4.4 — Polls & Q&A

Create a discussion with `kind: poll` and `poll: {question, options, max_choices, allow_change, visibility, closes_at}` (UTC Unix seconds or null). `kind: question` creates a Q&A topic. Existing callers default to `discussion`. Use the poll controls on the topic page; ballots are anonymous in the API (UUIDs retained in the DB for uniqueness). Results percentages use distinct voters as denominator, so multi-choice totals can exceed 100%. `PUT /forum/threads/{id}/poll/vote` accepts `choices` (option numbers), empty to withdraw when changes are allowed. The author or an authorized moderator can close/reopen via `PATCH /forum/threads/{id}/poll`; an expired deadline needs a new future timestamp to reopen. Options are immutable after creation.

`PUT /forum/threads/{id}/accepted-answer` accepts `post_id` or null to clear; `PUT /forum/posts/{id}/answer-vote` accepts `enabled`. Read access is checked on every request, voting requires current reply rights and an open writable topic, and moderation is scoped to the node. `/forum/questions` supports all/open/solved/unanswered. Answer ranking is paginated separately to preserve chronological post pages and read tracking. Moving replies clears their accepted-answer and answer-vote state; merging a structured source is rejected to avoid losing votes. Deleting a topic cascades its polls/questions. Accepted answers create a durable `accepted` notification for the answer author (unless self-accepted), delivered by the existing worker. Removing or moving the accepted answer retracts its notification. Older notification preference requests preserve the new accepted setting.

Run `php bin/cajeer migrate` before serving this release. Migration 0031 preserves all previous data. Run `python tools/forum-tests/poll_sql_regression.py` and the full forum CI matrix. The physical baseline for 0.4.4 is 0.4.3 (`db8dc4179a21d9fee7fc1d5ab506ce4051ef87c1`).

### 0.4.5 — Drafts & Revision History

Use `/forum/drafts` to reopen, delete or continue server drafts. Topic/reply/conversation composers autosave after a two-second idle delay. Each save requires its expected revision (0 creates); publication runs the normal domain mutation and saves a receipt in the same transaction. Retry the same draft ID/revision after an uncertain response. Active drafts and receipts expire after 30 days; pending attachments referenced by saved drafts are extended to that expiry, remain private and count against quotas. Explicit attachment deletion makes a draft reference unavailable; remove it before publishing.

Text history is restricted to the author or authorized forum moderators with current read and edit-any ACL; conversation history is author-only and requires current membership and history access. Restore creates a new text/format revision, without changing attached files or restoring deleted files. Migration 0032 records the current text as the first available revision; it cannot reconstruct historical edits before installation. Browser edits send expected_revision; old clients may omit it. Run migrate before serving and run the default worker regularly for expiry cleanup.


### 0.5.0 — Content Platform Stable

No new SQL migrations. Stop writers/workers, back up the database and deploy the changed files, then run `php bin/cajeer migrate:status`, `php bin/cajeer install:check --json`, and `php tools/update-postcheck.php verify`. Resume the default worker only after the checks pass.

Draft saves can safely retry an identical canonical payload with the immediately preceding revision number; a different payload or a stale older revision still conflicts. The composer freezes during restoration/publication. Attachment-only edits and removal of published attachments advance the parent revision; text restoration does not restore deleted file bytes. Retrying a derivative discards the old derived set and serves previews only after the replacement set is ready. Original files remain available under their normal ACL.

The offline integrity gate checks draft JSON/receipts, S3 ledger keys (including deletion tombstones), named derivative dimensions and existing content relationships without disclosing private text. Dangling draft targets or missing temporary files are allowed because users can lose access or explicitly delete uploads; publication must revalidate them.

The physical upgrade/rollback baseline for 0.5.5 is 0.5.4 (`701c0e106c5ea8ac869cf7383fa252e0064835a0`). The release acceptance seeds formatted polls, accepted answers, pending and published drafts, private history and image bytes/derivatives before updating. Compare these snapshots after upgrade and both manual/automatic rollback. Run the complete `forum-required` CI matrix; local SQL and isolated browser regressions do not replace full HTTP/PostgreSQL/dist acceptance. The original DB remains authoritative for attachments; S3 is a private replica, not an independent backup.

### 0.5.1 — Soft Delete & Recycle Bin

Topic moderation `delete` now retains the original rows. Individual replies can be hidden, but the first post must be deleted with its topic. The `/forum/recycle-bin` moderator page restores original UUIDs, revisions, votes, attachments and derivatives. Ordinary reads use SQL visibility views; a deleted topic hides every reply. Individually deleted replies remain hidden when their parent topic is restored. Restore and purge require the current deletion_id from the trash list or detail route, rejecting stale actions after a second deletion. Detail previews are plain source text, paginated and moderator-only. Current node read/moderation permissions are required for all recycle operations. Permanent purge is administrator-only and refuses unresolved reports. Reports remain accessible to authorized moderators. Default worker purges expired objects in batches of 100, after rechecking state and open reports; `FORUM_RECYCLE_RETENTION_DAYS` defaults to 30 (1–365). Retained objects continue to consume storage quota. Pending email and internal events are cancelled on deletion; restoring does not generate new events or change read watermarks. Delivered notifications become visible again only when the content and ACL permit. Board deletion is blocked while any retained topic exists. A merge physically removes only the emptied source topic, preserving hidden replies and redirects.

After backing up, stop writers/workers, apply the patch, run `php bin/cajeer migrate`, `php bin/cajeer install:check --json`, and `php tools/update-postcheck.php verify`. Run the default queue worker regularly. Before rolling back to 0.5.0 restore the pre-upgrade database snapshot as well as the code: old readers do not enforce deleted state.

## Moderation Center (0.5.2)

Administrators enable review per board in `/forum/manage` (default off). Non-moderators submit new topics and replies to a private queue; direct API and draft publication follow the same policy. A pending receipt contains `pending_review: true` and `submission_id`, not a published thread ID. `/forum/submissions` shows the author's own requests and outcomes. `/forum/moderation` provides ACL-filtered cases, priorities, assignment, evidence, notes and versioned decisions. All changes require the current `revision_number`; stale decisions return 409. Approval rechecks the author's permissions and the current target, publishes through the ordinary transaction, and releases reserved files atomically. Rejection/cancellation releases the files. Pending requests prevent destructive deletion of their target; resolve them first. Report snapshots are taken for new reports; historical reports have no retroactive evidence. Moderators can resolve or dismiss a report or move the reported content to the recycle bin. Private conversation reports retain their separate existing queue.

## Warnings & Sanctions (0.5.3)

`/forum/sanctions` shows your history and appeals; moderators can manage sanctions from member profiles and `/forum/manage`. Global sanctions require system administration; board-scoped warnings, posting and reaction restrictions require current board moderation ACL. Duration is 60–31536000 seconds or null for indefinite. Warnings record points, sanctions restrict actions at request time. A suspension revokes existing tokens; a subsequent login can only read its sanctions, submit an appeal, inspect the session or log out until expiry/revocation. No worker is required.

Global active warning points crossing `FORUM_WARNING_POST_POINTS` (5) cause a 24h posting restriction; crossing `FORUM_WARNING_SUSPEND_POINTS` (10) causes a seven-day suspension. Zero disables the threshold. Board-scoped warnings never trigger global restrictions. Revoking or successfully appealing a warning also revokes the sanction directly derived from it.

### Anti-Spam & Abuse Protection (0.5.4)

Account budgets are transactional rolling windows, shared by direct forum requests and draft publication. New or unverified accounts use lower thread/reply quotas. A rejected/rolled-back operation does not spend a successful action budget. Registration attempts are counted separately, even invalid ones; the default global budget is 100/hour in addition to 5/hour per source; IPv4 uses the address and IPv6 its /64, keyed with APP_KEY. The normal HTTP rate limiter remains active. Configure trusted reverse proxies correctly; never trust arbitrary forwarding headers.

The same safe renderer supplies link and mention analysis. Long normalized public/forum submissions are compared within the same account for 15 minutes using keyed fingerprints; code/quotes do not contribute to the fingerprint. Duplicate, excess links and excess mentions route to existing moderation submissions, with the reason in the case history. Configured blocked domains (including subdomains) reject publication; trusted domains are excluded from the new-account link budget, but do not override a deny rule. Public edits and private sends/edits that exceed content limits are rejected, not routed into the public queue. Private bodies and fingerprints are not stored by this subsystem. Approval reuses original author and prior charged budget, with normal sanctions and ACL checks. Staff are exempt from heuristic review but still subject to budgets and explicit domain deny rules.

Defaults and overrides are in .env.example. GET /api/v1/forum/antispam/status and /forum/manage show aggregate accepted action/registration attempt counts and review reasons for 24 hours (administrator only). QueueWorker cleans expired counters in batches of 1000. No external CAPTCHA, external content scanning, cross-account fingerprint tracking, or machine-learning classifier is enabled. This is defense in depth, not a guarantee against every spam technique.


### Member Privacy & Personal Data (0.5.5)

The privacy center provides direct navigation to current profile visibility, communication blocks, subscriptions, notifications, relationships and account security. Personal exports are session-only (not arbitrary API tokens), use private/no-store JSON and have 25 records per page. `GET /api/v1/auth/privacy/export` returns the section index; `?section=posts&offset=0` returns a page with `next_offset` or null. Each page is an independent current snapshot; avoid concurrent edits while exporting. The browser downloads each page as JSON. Attachments are metadata only; download original files via the normal authorized attachment workflow. Profile images are stored Base64. Only currently readable live board content and own messages in active memberships are exported; soft-deleted or inaccessible content is excluded. Suspended accounts can export their account data but not board content. Other participants' bodies, credential hashes, token secrets, internal moderation notes and audit/security logs are not exported. This is a user-facing portability tool, not a complete regulatory data-subject response.

`POST /api/v1/auth/privacy/clear-profile` requires the current password and, if enabled, a fresh TOTP/recovery code. It deletes profile fields and images and sets profile/activity visibility to private. `POST /api/v1/auth/privacy/erase` additionally requires `confirmation: "DELETE"`. It atomically disables the account, replaces email/name/password, removes roles, sessions/tokens, identity challenges, profile, settings, private drafts, unbound files, subscriptions, reactions/votes, read state and relations. Pending submissions are cancelled with a revision event; their reserved files are released and removed. Conversations transfer ownership using the normal leave behavior. Existing published discussions, sent messages and their files, reports, moderation evidence, sanctions, audit/security history and backups remain. Personal information written into shared messages, quotes, file contents or retained evidence is not automatically redacted; contact the administrator for a targeted removal. Original account UUID remains for referential integrity and the author label becomes neutral. The old email can be registered anew as a different account. Admin accounts must first transfer administrative access; erased accounts cannot be reactivated or assigned groups. Anonymization is irreversible in live application state; restoring a pre-erasure backup can restore old data, so operators must apply their retention and restore procedures. Migration 0037 adds only the tombstone table; historical migrations are unchanged.


### Trust & Personal Safety (0.5.6)

Migration 0038 adds a contact mode without altering existing migration checksums or losing members/none preferences. The 0.5.5 baseline is `25f00f4b3183ff72e2f857b73b5071ca65d4efeb`. Back up, deploy the changed files, run `php bin/cajeer migrate`, then run the full CI matrix.

Trust is calculated from current data, not editable reputation: verified email, at least seven complete UTC days since registration and three live published posts (including the first post of a thread). Pending/deleted posts do not count; private-board posts count only in the member's own progress. New members have 3 new conversations/hour, groups of up to 4 including owner, and 6 invitations/hour; established members have 5/20/20. Existing rolling conversation counters are retained and failed transactions do not spend them. A downgrade does not eject existing members; it prevents expanding beyond the new cap. No trust bypass of roles, ACL, sanctions or anti-spam. Installations without email verification keep unverified accounts at the new tier.

The conversation settings page shows personal trust progress, limits and four incoming modes: members (legacy default), trusted, following (recipient follows sender), none. These apply to new conversations and invitations, not existing memberships. Blocks are bilateral for private sends/edits/invitations, new follows, reactions and answer votes; both follow edges are removed. Activity and social notifications are filtered while blocked, pending social notifications are discarded by the worker and pending subscription email is filtered at delivery; moderation notices remain. Existing public content, historic reactions, votes and conversation history remain available under the existing ACLs. Already-sent mail cannot be recalled. Unblock does not restore follows; historical delivered notifications may reappear. Reports, leave, and removal actions remain available.

### Trust & Safety Stable (0.6.0)

The member safety center (`/forum/safety`) links current trust/contact controls, personal data, sessions, sanctions/appeals and the member’s own public/private report status. It never returns staff notes, moderation evidence, reporter identity to other members, or the sanction imposed on another user. Reports remain subject to the existing physical content retention policy; a resolved public report can disappear when its target is permanently purged. Historical private decisions retain unknown decision times.

Public reports support atomic resolution with a warning, posting or reaction restriction on the current board. Private-message reports can be resolved with a global sanction only by a current system administrator. The target is derived from the stored reported message, not from a client-provided user ID. The same transaction records the sanction, report outcome and audit; a failed sanction rolls back the entire decision. The existing appeal workflow can revoke the linked sanction without rewriting the historical report outcome. The reason entered for a sanction is visible to the sanctioned member; never include private evidence or the reporter’s identity in it.

Private reporting supports optional immediate bilateral blocking without duplicate rate-budget charges. An existing report is returned on retries, even after it has been decided. Reporting is still possible for a blocked conversation while the reporter is an active participant with visibility of the message. Blocking does not delete history. Attachment-only edits and group expansion obey existing contact restrictions.

Migration 0039 adds decision metadata and referential sanction links on all three database drivers without modifying historical migrations. Run the normal migration command and `php bin/cajeer install:check --json` before deployment. CI prefers an authentic 0.5.6 tag (or configured `FORUM_BASELINE_REF`); the existing 0.5.5 baseline is retained as fallback. The SQL regression explicitly upgrades 0.5.6 to 0.6.0.


### Forum Full-Text Search (0.6.1)

Migration 0040 builds indexes over existing thread titles/prefixes, public post bodies and tags. PostgreSQL uses GIN expression indexes with English and Russian dictionaries; SQLite uses FTS5 `unicode61` with stable integer keys (safe across VACUUM); MySQL/MariaDB use native FULLTEXT. MySQL has a literal whole-word fallback for short terms or when native search has no hits (e.g. stopwords or zero IDF); this fallback may scan accessible content. SQLite/MySQL do not provide PostgreSQL's morphology. Words are literal, combined within one indexed field (title and prefix together); no user-controlled FTS syntax, wildcard or advanced operators. Results prioritize title, tag, then body and rank within each source. Exact tag-only searches retain the previous chronological behavior.

`GET /api/v1/forum/search?q=...&mode=threads|posts&tag=...&page=1` defaults to one result per discussion. `mode=posts` requires a query and returns matching individual published posts, with `post_id`, `post_page`, `snippet`, and `match_type`; thread/tag matches may have null post fields. Snippets are plain text, not trusted HTML. ACL and soft-delete filtering use current source rows before paging; drafts, private messages and review submissions are never indexed. Public posts remain searchable under existing personal-block semantics. Indexes may contain soft-deleted source text until purge; no public query exposes it. Native ranks are not returned to clients.

Run `php bin/cajeer migrate` with a database backup and a write-free maintenance window (index creation can lock tables). `php bin/cajeer forum:search:check --json` verifies index presence/validity (SQLite also checks FTS integrity and source parity). `php bin/cajeer forum:search:reindex --json` rebuilds indexes; stop writers and workers first, as rebuilding can lock tables. The CMS `search:*` commands are unchanged. SQLite builds must include FTS5. MySQL/MariaDB search behavior follows server tokenizer/collation and stopword configuration; use PostgreSQL for full morphology. The existing search request rate limits and page bounds remain in force.


### Advanced Search & Discovery (0.6.2)

`GET /api/v1/forum/search` keeps the existing array response and supports `board_id`, `author_id`, `tag`, `from`, `to`, `sort`, `question`, `mode`, and `discover`. Date bounds are inclusive UTC calendar days; thread mode filters the original thread author and creation date, post mode the matching post. `sort` is `relevance`, `newest`, `oldest`, `active` (thread activity), or `replies` (live replies). `question` is `all`, `questions`, `solved`, `unsolved`, or `unanswered`. A soft-deleted accepted answer is not treated as solved. Filter-only discussion search is supported; post search requires text.

Words are conjunctive in normal search; quotes match adjacent tokens in order, `-word` or `-"two words"` excludes matches in the same indexed field. PostgreSQL retains stemming for ordinary positive terms; quoted phrases and exclusions are verified literally against case-insensitive token sequences so dictionary stop words cannot weaken them. Phrase-only queries composed of stop words may scan permitted source rows. Punctuation is tokenized. MySQL/MariaDB use literal word/phrase regex for exclusions, quoted phrases and short words to avoid native stop-word omissions, and native FULLTEXT for ordinary terms with the existing fallback. SQLite uses FTS5. The API returns `total_results`, `match_date`, `reply_count` and optional `matched_tag` per result. Empty pages have no count. Counts and ranking use authorized live candidates. The query is capped at 100 characters, 16 clauses, and 100 pages (25 per page).

`discover=suggest` returns up to eight title/tag prefix hits; `discover=similar` returns up to eight related topics based on overlapping title/tag words, with optional PostgreSQL trigram matching if no lexical hit exists. Discovery accepts only ordinary words and ignores pagination. `/forum/members?discovery=1&q=...` returns up to eight visible author matches. The forum search UI offers these suggestions, and the topic composer provides an explicit related-topic lookup. No draft text is sent automatically from the composer.

ACL/live status, bilateral blocks and ignores are evaluated before count/rank. Author filtering and discovery also check profile/activity visibility. Public posts remain readable through normal discussion routes. Author matching and suggestions do not grant any new access to content.

The existing global HTTP rate budget remains in effect. `RATE_LIMIT_FORUM_SEARCH` (default 60 per `RATE_LIMIT_WINDOW_SECONDS`, per client IP) adds a shared search/author-discovery budget using the existing file-based limiter. Distributed rate limiting is planned for Redis runtime. The existing migration 0040 is sufficient; no schema migration is needed.

Optional PostgreSQL typo discovery: a DBA can install `pg_trgm` in the database's search path and create a trigram index outside a transaction for large installations:

```sql
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE INDEX CONCURRENTLY IF NOT EXISTS ce_forum_threads_title_trgm ON ce_forum_threads USING gin (title gin_trgm_ops);
```

If the extension is absent, all other discovery and search features work. Trigram matching is a fallback for related titles, not automatic rewriting of the search query. Search snippets and visual highlights are text-only; exact lexical highlighting may not highlight all morphological variants. The 0.6.2 release adds no background indexing workers or external services.

### Server Rendering & SEO (0.6.3)

- `/forum` (and `/`), `/forum/boards/{uuid}`, `/forum/threads/{uuid}`, `/forum/members/{uuid}`, `/forum/latest` and `/forum/questions` render actual read data without JavaScript. Existing JavaScript enhances those pages; write actions still use the existing API and CSRF protection. Search, account, moderation and other interactive views remain JS-driven and noindex.
- Set `APP_URL` to the public HTTPS origin. Canonical URLs retain stable UUID paths, omit tracking parameters and use `?page=N` for distinct paginated pages. A merged thread redirects with 301 after checking source and target ACL. Missing/inaccessible/deleted content and invalid pages return 404. No tombstone is exposed for hidden content.
- Public guest pages get title, description, Open Graph, Twitter Card and JSON-LD; all authenticated HTML is noindex and private/no-store. Public and private HTML is not shared-cached. Structured data uses only rendered published content. Profile metadata does not include email, private fields or hidden activity.
- `/forum/sitemap.xml` is a sitemap index; `?page=N` returns at most 1,000 URLs (home, guest-readable boards and live threads with at least one live post). Stable UUID ordering, live database snapshots, no stored stale XML. `/robots.txt` advertises it alongside the existing CMS sitemap. Public profiles are discoverable through public links but not enumerated in sitemap. `/forum/rss.xml` contains the latest 25 guest-readable indexed discussions.
- In `/forum/manage`, administrators can set a board's **Индексация раздела и подразделов** switch. It is persisted in `seo_index` (migration 0041); disabling it affects all descendants, thread HTML metadata, sitemap and RSS without changing ACL. Existing boards default to enabled. `FORUM_SEO_INDEX=false` disables all forum indexing and discovery. `FORUM_SEO_EXCLUDED_BOARDS` optionally excludes a comma-separated UUID list and descendants. The CMS `robots_index` flag also disables forum indexing; `sitemap_enabled` controls sitemap publication. Noindex is not an access control: use private/hidden ACL to protect content.
- Upgrade from 0.6.2: back up, apply files and run `php bin/cajeer migrate`; verify `/forum`, `/forum/sitemap.xml`, `/robots.txt`, `/forum/rss.xml` and the admin board switch. No search reindex is needed. Native PostgreSQL/MySQL and HTTP integration gates are required before production deployment.

### Redis Runtime & Caching (0.6.4)

Redis is optional. Apply migration 0042 before enabling caching. On every web node and worker use the same SQL primary, installation-specific Redis namespace, queue prefix and connection configuration. Install PHP `ext-redis`; use a trusted standalone Redis 7+ service (not Redis Cluster), preferably with ACL credentials, AOF persistence and `maxmemory-policy noeviction` for queue/rate-limit durability. Do not expose Redis publicly. A dedicated Redis instance is recommended. Example:

```dotenv
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_DATABASE=0
REDIS_TIMEOUT_SECONDS=0.5
FORUM_REDIS_NAMESPACE=forum-production
FORUM_CACHE_ENABLED=true
FORUM_CACHE_TTL_SECONDS=60
QUEUE_DRIVER=redis
QUEUE_REDIS_PREFIX=ce:queue:forum-production:
RATE_LIMIT_DRIVER=redis
```

The last two driver switches are independent of forum caching; drain the old generic queue before changing drivers. `RATE_LIMIT_DRIVER=file` is the default and is not distributed. Redis rate limiting fails closed (HTTP 429) rather than silently granting each node a separate budget; SQL anti-spam limits remain authoritative. Keep the shared rate-limit store persistent, and do not evict its keys. To inspect the configuration and operation:

```sh
php bin/cajeer migrate
php bin/cajeer forum:runtime:check --json
php bin/cajeer queue:diagnostics --json
php bin/cajeer queue:work --queue=default --limit=10
```

Run the default worker repeatedly on every worker host and the existing scheduler for processed-job cleanup. Forum notification, mail, image and replica work uses deduplicated Redis wakeups for durable SQL outboxes and existing SQL leases. A missing wakeup is reconstructed from due SQL rows; failed Redis handoff falls back to direct SQL draining. Generic Redis jobs still require Redis persistence. Existing notification fencing and mail retry semantics remain in place; SMTP acceptance immediately before a process crash can still result in a duplicate delivery.

Anonymous GETs for forum home, boards, threads and latest (HTML and API) use Redis; authenticated or cookie-bearing requests, searches, profiles, RSS/sitemap, unknown query variants and errors are not cached. `X-Forum-Cache` reports HIT/MISS. Private counters are keyed by current user, permissions and SQL generation; only counts are cached. Writes invalidate via per-table generation triggers in the same SQL transaction; rolled-back writes do not invalidate. Generations are read from the primary in the same snapshot as public content, so an unavailable Redis cannot preserve a stale ACL/content key after a commit. Old keys expire automatically. Concurrent misses have a bounded wait and a fill lock, then use SQL if necessary. Redis loss alone does not prevent cache-backed reads. Time-dependent display state may age until TTL; no cache is a source of authority for mutation permissions. A generation change invalidates a broad group of cache entries, deliberately prioritizing consistency over fine-grained hit rates. Measure write contention and cache hit rates before large deployments. Rotate `FORUM_REDIS_NAMESPACE` after restoring a backup, out-of-band DDL or replacing the SQL database; MySQL direct TRUNCATE bypasses row triggers. Use row-based binlogging with MySQL. Never share a namespace across separate installations.

### PostgreSQL Performance (0.6.5)

Migration 0043 adds five live-feed and post-range indexes. PostgreSQL and SQLite
use partial indexes (`deleted_at IS NULL`); MySQL/MariaDB use composite indexes
with `deleted_at` as the leading equality column. Existing uniqueness constraints
and historical migration checksums are unchanged. Lists select the bounded page
before loading author/profile information; post reactions are grouped once for the
selected IDs. Unread counts use live post sequence ranges, including holes after
soft deletion, rather than subtracting watermarks.

`GET /api/v1/forum/latest?cursor=` returns `data.items` (up to 25 rows) and
`data.next_cursor` (string or null). Pass the returned cursor verbatim for the
next page. The query splits the timestamp tie range and strictly older range,
using the existing `updated_at DESC, id ASC` order; permissions are recomputed
for every request. The old `?page=N` response remains an array. SSR and the
interactive latest feed both use the cursor; previous numbered links still work.
The cursor is not an authorization token or a frozen snapshot across HTTP requests:
threads updated during navigation may move ahead of the cursor. Use the first page
again for refreshed activity.

PostgreSQL cache invalidation uses 16 transaction-selected shards per watched
SQL table. The effective generation digest includes every shard in a consistent
snapshot, so an older transaction committing after a newer one still invalidates
cached responses. A rollback leaves the digest unchanged. Same-shard writers
can still contend, and broad cache invalidation remains conservative. SQLite and
MySQL/MariaDB retain the existing generation mechanism behind the same view.

**Deploy 0043 in a maintenance window.** Ordinary `CREATE INDEX` runs inside the
PostgreSQL migration transaction; on large tables allow time and disk for index
construction, and adjust the deployment session's statement timeout if required.
Stop all old PHP/worker processes before migrating: 0.6.4 binaries cannot safely
share the database with 0.6.5 because the old PostgreSQL generation table no
longer changes on writes. Rollback requires restoring the matched database and
application backup, not just replacing PHP files. Change the Redis namespace
on database restore. Resume traffic only after migration and readiness checks.

```sh
php bin/cajeer migrate
php bin/cajeer migrate:status
php bin/cajeer forum:performance:check --json
php bin/cajeer forum:performance:check --analyze --json
php bin/cajeer install:check --json
```

The diagnostic checks valid indexes, returns the bounded latest-feed plan and
PostgreSQL table/index statistics (live/dead tuples, scans, bytes, autovacuum and
analyze timestamps). `--analyze` runs the read-only bounded SELECT with
`EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)` on PostgreSQL; without it only the
planner is invoked. It never enables extensions, resets statistics, changes
server settings or returns other users' SQL. Run `ANALYZE ce_forum_threads;
ANALYZE ce_forum_posts; ANALYZE ce_forum_read_state;` after a large import. Monitor
`pg_stat_user_tables`, `pg_stat_user_indexes`, and optional `pg_stat_statements`
with administrator-approved permissions; tune autovacuum from measured dead tuple
and vacuum behavior, not universal configuration values. Existing statement and
lock timeouts remain in effect. No production throughput or latency claim is made
without a representative native PostgreSQL workload.

### High Load & Reliability (0.6.6)

No schema change is required after migration 0043. PostgreSQL publications take
`FOR SHARE` on the board, allowing independent threads and authors in one board
to progress together. The existing thread and actor locks still serialize ordinal,
read-sequence and anti-spam operations. Topology and ACL changes continue to take
exclusive locks on boards (including the target board of descendant publications).
Moderation/sanction changes keep their existing exclusive locks. This does not
remove all potential deadlocks: a transaction conflict remains a 409, not an
implicit retry of a mutation. Sequence allocation uses `UPDATE ... RETURNING` on
PostgreSQL. Notifications are inserted in batches of 200, preserving active-user
and notification-preference checks; PostgreSQL notification claims skip locked rows.
Expired spam records are deleted in bounded batches (1,000 per pass), with one
statement on PostgreSQL and at most five DELETE statements on SQLite/MySQL.

A lost DB connection returns a non-cacheable 503 with `Retry-After: 5`. No write
is automatically replayed, particularly if the connection is lost during COMMIT.
Clients must reconcile the result of a write with unknown outcome before retrying.
The transaction manager discards failed connections and preserves the original
exception even if rollback fails. Queue ticks probe the DB before reserving work;
when it is unavailable they defer work to a later tick. Redis queue empty responses
are distinct from failed/unconfirmed transitions, which are reported as failures.
The next independent call can reconnect; ambiguous acknowledgments remain subject
to lease expiry and fencing, not blind retries. SQL outboxes remain durable.

#### Reproducible reliability checks

Run on disposable fixtures only:

```sh
php tools/forum-reliability-regression.php
python tools/forum-tests/reliability_sql_regression.py
# Requires composer install and PHP PDO SQLite, POSIX PHP CLI workers:
python tools/forum-tests/reliability_load.py --report storage/tmp/forum-ci/load
# Optional native PostgreSQL: use the empty, dedicated loopback forum_test_load DB.
FORUM_TEST_DRIVER=pgsql FORUM_TEST_NETWORK_DB=forum_test_load \
  python tools/forum-tests/reliability_load.py --report storage/tmp/forum-ci/load-pg
```

The HTTP smoke uses a disposable installation, four PHP workers and eight concurrent
clients by default. It mixes thread reads, cursor feed, full-text search, SSR and
unique reply writes; it verifies every acknowledged reply and subsequent ACL
revocation. `load.json` records request counts, errors, RPS and operation-level
p50/p95/p99; adjustable `--clients`, `--workers` and `--requests` keep the smoke
bounded. It is not a 200-user production capacity certificate. The SQL regression
uses a separate WAL database, 1,000 threads, 10,000 initial messages, eight processes,
640 operations, and an abruptly terminated writer. PostgreSQL CI exercises real
shared/exclusive board locks, SKIP LOCKED, batched notification fan-out and cleanup,
and terminates a disposable backend to verify reconnection. Redis CI tests the
existing recovery/lease protocol plus an unreachable endpoint and reconnection.

For production, monitor `forum:performance:check --json`, `forum:runtime:check --json`,
`queue:diagnostics --json` and the existing authenticated metrics/operations views.
Tune PHP-FPM and PostgreSQL connection budgets with measured concurrency, keep
statement/lock timeouts bounded, retain regular worker ticks, and benchmark with
production-like data before increasing worker counts. A failed Redis cache may
fall back to SQL; a Redis-backed rate limiter intentionally still fails closed.
When a database or queue is unavailable, do not use a stale cache as authorization
and do not restart/retry every job concurrently. Backoff worker restart loops.
Redis queue processing remains at-least-once, including SMTP uncertainty.

### Search, SEO & Performance Stable (0.7.0)

The forum search, guest HTML, sitemap and Redis cache share the published-content and current ACL boundary. Title/tag/filter-only results require a live post. Search reads remain snapshot-consistent; exact phrase checks are literal, while candidate selection uses the indexed language lexemes. PostgreSQL `FORUM_SEARCH_TIMEOUT_MS` defaults to 3000 (100–30000), applies per statement inside the search transaction, and never overrides a stricter DBA timeout. SQLSTATE 57014 produces a 503 with Retry-After; narrow the query before retrying. No timeout is imposed on SQLite/MySQL by this setting. Index diagnostics use a single SQLite integrity transaction (run off-peak); PostgreSQL verifies the source relation, valid full expression GIN, and MySQL verifies FULLTEXT column order.

The SEO renderer uses a request-scoped policy (configured origin, index/follow switches and excluded boards), shared with its Redis cache key. Numbered and cursor latest HTML representations have separate cache keys. The cache namespace version is v070; no global Redis flush is required for the upgrade. Private and error HTML is noindex/nofollow. Redis remains optional; the SQL snapshot is authoritative.

Upgrade from 0.6.6 with a consistent database/files backup, stop old PHP/queue processes, replace the changed files, check migration status (0043), run `php bin/cajeer forum:search:check --json`, `php bin/cajeer forum:performance:check --json`, `php bin/cajeer forum:runtime:check --json` and `php bin/cajeer install:check --json`, then restart. There are no new migrations. Keep the previous source and backup for rollback; rotate the Redis namespace when restoring an unrelated snapshot.

Certification: the `forum-required` CI aggregate must pass, including native PostgreSQL 17/18 + Redis, SQLite, MySQL/MariaDB search, browser regressions, signed physical dist upgrade/rollback from 0.6.6, and multi-process load. The smoke load is not the million-post/200-client production capacity benchmark; establish p95/p99 and saturation on representative hardware before claiming capacity.


### Forum ACP (0.7.1)

Open `/admin/#/forum` using a current administrator session. The overview, structure, members, groups, discussions, messages, moderation, attachments and audit screens use the forum API; writes use existing transactional services. The ACP read API is `/api/v1/forum/acp` (view, page, q, status, board_id, thread_id); lists are limited to 25 rows with a lookahead. Member name/status changes use PATCH `/api/v1/forum/acp/members/{id}`, reject self and administrative targets, and audit the change atomically. The original `/forum/manage` remains available. ACL rules use the existing revision check; moderation decisions use case revisions and recycle generations. Private conversation attachments are not listed in ACP. Existing system roles, account creation and token management remain in the Access screen. No schema change beyond 0043.


### Advanced Administration (0.7.2)

Open `/admin/#/forum`, then **Настройки сообщества**, **Делегирование**, **История политики**, or **Обслуживание**. Migration 0044 seeds the policy singleton and four managed roles. Back up DB and stop old workers before migration.

The policy controls SSR name/description, registration, established trust thresholds, topic/reply rate limits and base attachment quotas. Existing environment values are the defaults until saved; `FORUM_REGISTRATION=false` always disables registration, group-specific attachment limits still override the base quota. Email verification remains operator-controlled. Save requires the current revision; stale writes return 409.

Schedule publication pause using local datetime fields (stored as UTC epoch seconds). New topics/replies, draft publication and moderation approval are rejected during the window; reads, editing existing posts, private messages and safety operations remain available. Empty start disables the pause; empty end means indefinite. This is not global maintenance or a backup freeze.

Global administrators assign managed roles `forum-acp-settings`, `forum-acp-members`, `forum-acp-audit`, `forum-acp-maintenance` via the Delegation screen using the member UUID. Empty selection revokes these roles; other roles/groups are preserved. Existing custom roles can also carry these scopes and must be managed separately. Members delegates cannot change their own account or privileged accounts. Policy scheduling requires both settings and maintenance. Audit delegates see policy, maintenance and delegation history, not all moderation audit metadata.

Maintenance runs bounded cleanup of expired spam events, drafts and unbound uploads and checks indexes, query plans and Redis. Rebuild, backup and global maintenance remain operator operations. Audit records include actor and action; policy changes include before/after values. Run full CI before deployment.


### Forum Themes & Styling (0.7.3)

Run migration 0045 after backing up the installation and stopping old PHP processes. Open `/admin/#/forum` → **Оформление форума** as a global administrator or a delegate with `forum:acp:settings`. Cajeer Default, Ocean and Sand are built in; select a preset or a saved design as a starting point. Edit the name, text logo, typography, width, spacing, radius, sidebar placement and independent light/dark colors. Save the draft, then preview it or activate it explicitly. At most 12 custom designs and ten prior active versions are retained. Restore an earlier active version or select the default preset to reset. Deleting a saved library copy does not change active styling. Activating a custom design with the same name replaces its library copy.

Preview uses the saved draft and current guest ACL, renders real public content without interactive JavaScript, and is restricted to the current authorized administrator session. Choose a page, scheme and viewport; opening links within the preview navigates to the normal public page. The preview URL carries the current editor revision; stale revisions return 409. Preview responses are noindex and private/no-store and bypass Redis. Normal SSR, canonical, sitemap, JSON-LD and user light/dark/system preferences remain unchanged in behavior. Guests use the active design's default scheme. The active design is included in the SSR cache identity; draft changes do not affect the published representation. The existing Redis namespace/SQL generation safeguards still apply.

API: GET `/api/v1/forum/acp?view=themes`, PUT `/api/v1/forum/acp/theme` with `revision`, `action` (`draft`, `activate`, `restore`, `delete`), and `design`, `index` or `key` where appropriate. Changes use the existing CSRF and current-principal checks and emit `forum.acp.theme.*` audit events. Colors must be literal #RRGGBB; primary text, muted text and links require at least 4.5:1 contrast on both configured surfaces. No arbitrary CSS, PHP, JavaScript or remote asset URLs are accepted. This release uses design tokens over the existing forum renderer, not arbitrary template packages; signed theme packages and extension slots remain separate future work.

Run `python tools/forum-tests/theme_sql_regression.py`, `python tools/forum-tests/theme_browser_regression.py` and the real HTTP and Redis integration suites before deployment. Restore the coherent DB/source backup to roll back the migration.

## Forum extensions (0.7.4)

Forum plugins use the signed extension lifecycle and the Forum SDK described in [Extension Platform](extensions-platform.md#forum-extension-platform-074). Apply migration 0046 before enabling plugins. Install the optional working announcements and topic-template examples from `examples/extensions/` using the operator CLI; configure announcements in the existing Extensions administration screen. Workers deliver metadata-only forum events from the durable SQL outbox. `forum:extensions:check --json` reports event states and dead letters; `forum:extensions:retry EVENT_ID --json` retries failed subscribers without replaying completed ones.

## Forum migration (0.7.5)

See [Forum Migration Engine & phpBB operator guide](forum-migration.md) for read-only planning, backups, resumable batches, source ID mappings, ACL quarantine and cutover.

## Multi-forum migration (0.7.7)

The operator importer now accepts native XenForo 2.2/2.3, MyBB 1.8 and SMF 2.1 snapshots as well as phpBB 3.3. See [the migration guide](forum-migration.md) for supported schema fields, attachment layouts, preflight and cutover. Global administrators can inspect the job ledger and generate operator commands in Forum ACP → Миграции; source credentials and execution remain operator-only.


## Administration & Extensibility Stable (0.8.0)

**Upgrade from 0.7.7:** finish or restore any incomplete forum import; take a verified recovery backup and stop traffic and workers. Schema 0047 is unchanged. The engine remains 1.3.0. The two optional example plugins originally shipped with `forum <0.8.0`; their 1.0.1 sources now support `>=0.7.4 <0.9.0`. Do not edit installed signed manifests. Before changing the engine, prepare full copies of the example packages using the new manifests, sign them with a trusted publisher key, and use `extension:package:upgrade` while running 0.7.7. Alternatively disable incompatible packages using `extension:disable NAME`, then upgrade/re-enable them on 0.8.0. Review third-party constraints with their publishers. Drain pending extension events before upgrading under the old engine: it does not yet preserve the activation timestamp on upgrade. Do not assume disabling/re-enabling will replay events from the earlier activation.

After applying the release, run `php bin/cajeer forum:ecosystem:check --target=0.8.0 --deep --json`, `php bin/cajeer extension:runtime:boot --json`, and `php bin/cajeer forum:extensions:check --json`. The ecosystem check verifies enabled signed packages and target constraints/dependency graph, persisted community policy, theme document/projection, reserved ACP roles and incomplete imports. Deep mode also checks schema, forum integrity and FTS. It is an operator diagnostic, not a cheap health probe or a sandbox for plugins. The ACP maintenance action `ecosystem_check` provides the lightweight report with audit; only authorized maintenance administrators may run it. A new updater preflight checks the target constraints and refuses incomplete imports; new release postcheck verifies the ecosystem and FTS. The old updater does not have the new preflight, so prepare installed plugins before upgrading. Preserve the full recovery set, including installed signed packages and registry. Restart PHP and workers after a successful update.

0.8.0 retains the parameter-based theme editor and existing plugin SDK; it does not introduce arbitrary template execution, a new marketplace, or source-forum ACL conversion. Native database/Redis, signed distribution upgrade/rollback and large migration acceptance must pass in CI/staging before claiming full release certification.

## Forum Production Deployment (0.8.1)

See [production deployment](production-deployment.md) for the real Linux/systemd deployment controller, preflight, trusted signed dist, gated activation, worker/scheduler and recovery commands. Forum release identity is independent from the inherited engine and the host acceptance is required by `forum-required`. No new migration; existing schema 0047.

## Containerized Deployment & Runtime (0.8.2)

See [container deployment](container-deployment.md) for the signed-dist Compose profile, initial installation, runtime volumes, Redis and recovery boundaries.

## Secure Updates & Release Management (0.8.3)

See [secure updates](secure-updates.md) for signed channel/identity checks, read-only preflight and disk estimates, operator history and controlled Compose upgrade/rollback. The historical 0047 schema and Engine 1.3.0 remain unchanged.

## Automated Backup & Retention (0.8.4)

Use [automated backup](automated-backup.md) for scheduled authenticated recovery sets, encrypted file/S3 off-site transfer, durable retry and UTC retention. Systemd and a separate signed-image Compose operator support PostgreSQL queue/local authoritative storage. The Operations screen and metrics expose actual backup history and delivery health. Manual and updater recovery sets never participate in automated rotation. No new schema; external queue/storage recovery and full off-host application boot remain separately gated.

### 0.8.5 — Disaster Recovery & Restore

The operator can recover an authenticated 0.8.4+ recovery bundle to a new installation and new PostgreSQL database or SQLite file. See [disaster recovery](disaster-recovery.md). The recovered site remains in maintenance until deployment and acceptance are complete.

### 0.8.6 — Forum Observability & Monitoring

Forum SSR/search/publication latency, bounded SQL queue samples, Redis and PostgreSQL diagnostics, backup and recovery metrics, Operations ACP and provisioned monitoring stack. Apply migration 0048. See [Forum monitoring](forum-monitoring.md).
