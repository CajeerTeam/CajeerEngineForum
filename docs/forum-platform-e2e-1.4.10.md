# Full Platform Integration & E2E — Forum Edition 1.4.10

The new acceptance journey runs the installed application on a disposable loopback
HTTP server. It uses the published forum and ACP assets, three isolated browser
contexts, real cookies, CSRF, local reauthentication, migrated SQLite, actual
scheduler/worker CLI processes and read-only database verification. No application
API is intercepted. The journey does not send customer data or contact OpenAI;
the unconfigured/disabled provider and role denial paths are verified. Provider
success, PostgreSQL/MySQL/Redis, SMTP, signed updates, S3 recovery and production
host deployment remain in their existing independent required CI matrices.

## Execute

Use a clean development checkout with PHP 8.4, PDO SQLite, required extensions,
Composer dependencies, Python and Playwright. Never point the suite at production.

```sh
composer install --prefer-dist --no-interaction
python -m pip install -r tools/forum-tests/requirements.txt
python -m playwright install --with-deps chromium firefox
python tools/forum-tests/test_platform_e2e_harness.py
python tools/forum-tests/platform_e2e.py --browser chromium --report storage/tmp/forum-ci/platform-chromium
python tools/forum-tests/platform_e2e.py --browser firefox --report storage/tmp/forum-ci/platform-firefox
```

The installer creates a temporary site; existing `.env`, databases, uploaded
files and credentials are not copied. Update discovery is disabled in the fixture
so scheduler execution does not call GitHub. The test has no arbitrary base URL
option. The fixture creates synthetic users using the real administrator API and
session-bound password confirmation. Each browser uses independent cookie state.

Checks cover real workflow preview and creation, publication and search, anonymous
analytics ledger and rollup, private support request, cross-account denial,
CSRF denial, scheduler dispatch and worker execution, replay/idempotency, stale
revision rejection, operator reply/resolve through ACP, member-visible response,
SQL audit and operational worker state, AI-disabled and integration/analytics/queue
RBAC. The harness tests real HTTP static routing, asset identity and JUnit skips.

Reports contain JUnit, install/HTTP/worker logs and browser traces. These are
synthetic test evidence, not production support bundles; do not publish traces
from real accounts. Any failure or skip returns nonzero; a fixture startup failure
also returns nonzero. Missing Composer or PDO support must not be interpreted as
success. In `forum-ci.yml`, both browser matrix jobs must pass, along with the
existing required release, SQL, host, container, monitoring and staging jobs.

No new schema or runtime dependency. Local smoke tests and mocked-transport
browser tests are not evidence that the live platform journey has passed.
