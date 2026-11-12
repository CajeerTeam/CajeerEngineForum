# Forum Edition — developer & operator notes

## Architecture

`core/Forum/ForumController.php` — forum service/controller layer, using core DatabaseManager, UserRepository and PasswordHasher. Routes are declared in `core/Http/RouteRegistry.php` and registered by Application. `public/forum-assets` is the dependency-free frontend, served under `/` and `/forum/*`. Existing CMS API and admin remain available; forum owns root and `/forum/*` paths. `migrations/*/0015_forum_edition.sql` create seven tables. Forum data resides in the same database as users, not in JSON files. `api/openapi.yaml` and generated SDK operations are synchronized.

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

The engine VERSION remains 1.3.0 for compatibility with inherited tooling; `release.json` contains edition_version 0.1.0. Release checks use the same engine version, so future published updates must advance engine VERSION and the edition version consistently. `GitHubEndpoints` points to CajeerEngineForum. The catalog ships empty, and signed package verification requires an `edition: forum` field. The upstream signed publication workflow is archived, not active. The source ZIP is not a signed production distribution. Do not install ordinary CMS update packages.

## Tests and limits

Run `php tools/forum-regression.php` after `composer install`. It boots the kernel, creates an isolated SQLite database, tests registration/login, author permissions, moderation, search, reactions, watches, reporting, CSRF, cascade and homepage. It uses a temporary database but writes inherited runtime logs/rate-limit files in the checkout, so run only in a disposable development checkout. CI is provided but not executed in this environment. PostgreSQL/MySQL migration SQL is supplied; test against real servers before use. SQLite `LOWER` is ASCII-only without ICU; Cyrillic case-insensitive search is limited. Current search uses bounded result pages and SQL LIKE, not a full-text index. Subscription list is saved but no email/push notifications. No private boards, nested subforums, attachments, direct messages, WYSIWYG, advanced profile fields, polls, Q&A, or import from XenForo/Invision. Moderation deletion is permanent; back up before deleting. No production performance, browser or security certification has been completed.
