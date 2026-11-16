# Production certification — CajeerEngineForum 0.8.3 (Engine 1.3.0)

## Scope and admission

The target profile for certification is a **single-node Linux/systemd installation**, Nginx TLS, PHP 8.4 FPM, PostgreSQL 17+, local media, PostgreSQL queue/scheduler and file cache. SQLite dist smoke and Redis queue integration are additional release gates, not a statement that SQLite, Redis clusters, MySQL/MariaDB, S3, multi-node, arbitrary plugins or PHP 8.5 are certified deployment profiles. Local `stable:status`, `stable:lock`, `health/ready` and a version string are diagnostics, not production approval. A release is eligible only after the complete release workflow passes; operator approval of the actual environment is still required.

## Release run

1. Commit a **real** `composer.lock` resolved from the project constraints; review dependency changes. Source archives without that lock cannot produce a reproducible dist. Composer 2, PHP 8.4 with the required extensions, Node 22, a disposable Linux/systemd host and PostgreSQL 17 are required. Do not bypass a failing dependency audit or fabricate a report.
2. Run GitHub Actions `Forum Edition CI` for the reviewed candidate/tag `v0.8.3`. The required Forum CI jobs run the forum regression matrix, PostgreSQL/Redis integrations, browser checks, physical release acceptance and the dedicated production-host test. The separate release qualification and publication procedure also requires the full inherited regression suite and a clean locked Composer runtime audit; run the release scripts below on the approved commit. The real host smoke installs the exact dist with TLS, FPM, PostgreSQL and systemd; checks authentication, content and load; exercises a signed newer fixture with a failing and a successful migration; verifies that the service namespace cannot write code. A failure prevents signing/publication.
3. The host smoke runs `tools/production-acceptance.py` against loopback Nginx with the test certificate's CA and hostname verification. It cannot target an arbitrary remote host; it is destructive and must only run on a disposable server (`CE_DEPLOY_DISPOSABLE=yes`). The synthetic test account and pages are not production data.
4. `tools/certification-evidence.py seal DIR` creates `certification.json` only if `production-acceptance.json` and `dependency-audit.json` satisfy policy. Generate the seal in the protected release qualification environment **after all preceding steps passed**. `verify DIR` checks the exact source/dist SHA-256, report SHA-256 and Git commit. The signing operator or protected signing job must verify it before accessing the signing key. The publishing script verifies it again and uploads the evidence alongside the signed packages. These JSON reports are not independent cryptographic attestations; trust the protected CI run, commit/tag, signing environment and signed release archives, not a report produced by an untrusted party.

### Acceptance criteria

| Gate | Required result |
| --- | --- |
| Functional HTTP | Inherited CMS draft checks plus Forum ACP, real category/board/topic/reply, guest SSR and FTS, hidden ACL denial and restoration, actual worker notification delivery. |
| HTTP security | Static admin CSP/frame protection/nosniff/HSTS; private file and install/upgrade denial; anonymous and invalid tokens denied; malformed JSON/media rejected; secure HttpOnly SameSite cookie and CSRF; viewer write denied and disabled user token revoked. |
| Concurrency / load | 400 requests, 8 concurrent clients, four routes (public forum SSR, forum FTS, readiness-backed API health, authenticated Forum ACP), 100 requests per route; all status and semantic assertions pass. |
| Latency / throughput | Overall and each route p95 ≤ 1000 ms; ≥ 10 requests/s overall; zero errors/timeouts/429s in the load phase. |
| Abuse | 32 concurrent malformed auth requests: at most 20 allowed within the window, remaining 429 with Retry-After. Native PHP stress must admit exactly 37 of 512 attempts from 8 processes. |
| Dependencies | Composer locked production audit: no advisories or abandoned packages, zero exit code. |
| Deployment / recovery | Existing host smoke, dist cold recovery, update/rollback, PostgreSQL backup/DR and other integration regressions must pass. |

Load profile is a bounded CI baseline, **not a capacity guarantee**. Each request opens a TLS connection; latency includes the request and semantic checks. The isolated host changes the global rate limit to 10,000 and auth to 20 per window (600 s configured for the test); production defaults remain 120/20 per 60 s. Test settings are not shipped in the release artifact. Rate-limit behavior is checked separately. No HTTP response bodies, tokens, passwords or cookies are written to reports. Run your own long-duration, mixed read/write, peak-load and recovery tests on a realistic staging copy; select thresholds based on the target hardware, data volume, extensions and service-level objectives. Do not run destructive acceptance against a live site.

### Manual reproduction on a disposable host

From the reviewed checkout, with a clean Git tree and committed lock:

```bash
composer audit --locked --no-dev --format=json --abandoned=fail > /tmp/dependency-audit.json
bash tools/release-pipeline.sh /tmp/ce-release
cp /tmp/dependency-audit.json /tmp/ce-release/dependency-audit.json
bash tools/release-smoke.sh /tmp/ce-release/cajeerengine-0.8.3-dist.zip sqlite
# Configure CE_DB_USER and CE_DB_PASSWORD for a disposable PostgreSQL 17 server.
export CE_DEPLOY_DISPOSABLE=yes CE_CERT_REPORT=/tmp/ce-release/production-acceptance.json
sudo --preserve-env=CE_DB_USER,CE_DB_PASSWORD,CE_DEPLOY_DISPOSABLE,CE_CERT_REPORT \
  bash deploy/host-smoke.sh /tmp/ce-release/cajeerengine-0.8.3-dist.zip
```

Run all remaining release workflow tests as well; the commands above alone are not complete certification. The host script uses fixed disposable paths, role/database and ports; never invoke it on a real installation. For a failing run, inspect stdout/stderr and the host report inside `/var/lib/ce-deploy-smoke` if using `CE_DEPLOY_KEEP=yes`; never copy private environment or keys to artifacts. A passed release run emits the three reports. The publication environment should require review and protect tags, credentials and workflow modifications.

## Operator go/no-go

- Verify the signed dist, trusted signing key and checksum; review the GitHub Actions run and report matching the artifact.
- Validate server prerequisites, TLS chain and expiration, DNS, database/storage capacity, firewall, non-root service identity and filesystem permissions. Confirm no debug output, secrets or installer are exposed.
- Execute encrypted off-host recovery drill on a copy with the actual database and media, record acceptable RTO/RPO, and verify restore of content and login. Keep key and backup outside the host.
- Test your real extensions, integrations, SMTP/webhooks, caches and queue workers with expected load and failure scenarios. Check monitoring/alerts and backup retention.
- Confirm update from the currently deployed version on staging, rollback and maintenance access. The CI's newer fixture demonstrates update machinery; it is not a substitute for a migration from your own actual old installation.
- Record responsible operator and approval. Any missing/failing requirement is NO-GO; keep maintenance enabled until recovery and verification succeed.

Forum 0.8.1 added the required `production-host` job to `forum-required`; Forum 0.8.2 additionally requires `container-runtime` (PostgreSQL and Redis queue modes). These jobs build a physical dist and exercise disposable systemd and Docker hosts. Their actual successful evidence, not a version string or dry-run, is required. Build/publish scripts use `edition_version` and the CajeerEngineForum repository. Publication remains a separate operator/CI action.

Forum 0.8.3 adds required `container-upgrade`: build authentic 0.8.2 and 0.8.3 signed dist/images, install the old version, verify dry-run leaves it serving, apply through the operator controller, verify persistent HTTP data, and explicitly roll back to the old release. The separate release acceptance job exercises failure injection and recovery.

### 0.8.5 cold recovery acceptance

The release matrix includes a separate source-loss scenario: create content, ACP delegation, theme, signed plugins and media on the baseline dist; export a recovery bundle; remove the source installation (and its disposable PostgreSQL database); recover to a fresh destination; check maintenance, login, ACL, content, search, attachments and new writes over HTTP. Run on native SQLite and PostgreSQL 17/18. The local filesystem regression independently exercises real SQLite dump restoration, authentication failures, off-site retrieval and non-overwrite safety. A passing data-only test is not equivalent to the full native application acceptance.
