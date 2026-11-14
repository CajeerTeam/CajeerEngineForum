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

`VERSION` and `release.json.version` identify the upstream engine (1.3.0); `release.json.edition_version` is 0.4.0. `ForumVersion` validates both identities. Source/dist artifact names, manifest version, release tags and updater comparisons use the edition version. The signed manifest also records `upstream_version`, checked against VERSION, release metadata and the prebuilt admin manifest. The engine API/SDK compatibility version stays unchanged. Health/admin/system diagnostics expose `edition_version`; HTTP responses include `X-CajeerEngine-Forum-Version`.

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
- `/forum/account/profile`, `/forum/account/preferences`, `/forum/account/privacy`: authenticated profile editor, image upload and preferences (the three URLs use the same editor). Settings include profile/activity visibility, group labels, signatures in discussions, system/light/dark theme, date locale and IANA time zone. The locale setting formats dates, not the full Russian UI.
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
