# Forum 0.8.2 — container deployment

A single-host production profile: Docker Engine and Compose v2.24+ on Linux, TLS Nginx, PHP 8.4 FPM, PostgreSQL 17, authenticated Redis 7.4, a one-shot installer, workers and a scheduler. Use a clean, dedicated Compose project. The Linux/systemd profile remains supported separately. The changed-files ZIP is not a dist or a Docker image.

## Prepare the signed release

Build the full dist from reviewed source with the locked production Composer dependencies (PHP 8.4.1+). Sign on a trusted build machine; keep the signing private key off the Docker host. Provision the public key and independently approved SHA-256 on the Docker host.

```sh
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
php tools/build-release.php --dist --target-dir=/tmp/forum-dist --json
php bin/cajeer release:sign --package=/tmp/forum-dist/cajeerengine-0.8.2-dist.zip --secret-key=/secure/release.secret
# On the Docker host, from the matching source checkout:
sh deploy/container/prepare.sh /tmp/forum-dist/cajeerengine-0.8.2-dist.zip /secure/release.pub APPROVED_SHA256 0.8.2
```

`prepare.sh` verifies the artifact before staging it; the Docker build repeats signature, SHA-256, dist inventory, Forum Edition and version checks. Only the verified dist is copied into the final PHP image; the signing private key is never needed. The source tree's `.dockerignore` restricts build context to verification code, runtime scripts and the three approved artifact files. Base image tags are version-series tags; record the resolved image digests in the deployment inventory, and pin approved digests for repeatable production builds. Build and scan on a trusted host; this is not an independently signed OCI image publishing service.

## Secrets and site configuration

Create a private directory outside the checkout. Use generated independent passwords (24–128 characters from `A-Za-z0-9_-`; administrator password must additionally meet mixed-case/digit policy). Use a real certificate with SAN matching the domain and a corresponding private key. The database, Redis and administrator secrets are not Compose environment variables.

```sh
umask 077
mkdir -p /srv/cajeer-forum-secrets
for name in postgres-password db-password redis-password; do
  openssl rand -hex 32 > /srv/cajeer-forum-secrets/$name
 done
printf 'Aa9%s\n' "$(openssl rand -hex 24)" > /srv/cajeer-forum-secrets/admin-password
openssl rand -base64 32 > /srv/cajeer-forum-secrets/backup-key
# Install TLS certificate chain and private key as tls.crt and tls.key, mode 0600.
cp deploy/container/site.example.env deploy/container/site.env
# Edit site.env: domain, administrator email, namespace, secret directory, approved digest.
```

Back up `backup-key` independently in a protected location; it is not in the recovery set. Do not change these files to rotate credentials on an existing cluster: the initializer rejects configuration drift, not silently reconfigures databases. PostgreSQL cluster administrator and app role are distinct; the latter owns only the dedicated forum database and has CREATEDB for the inherited recovery tools, not superuser/CREATEROLE. The app role connects over an isolated Docker bridge with password authentication (no DB TLS in this single-host profile). Redis requires authentication, AOF `everysec`, and `noeviction`, capped at 256 MiB; monitor capacity. Public registration starts closed. Configure SMTP/registration via the existing operator configuration before opening registration.

## Launch

```sh
# Run from repository root; use a stable project name for the lifetime of the data.
docker compose --project-name cajeerforum --env-file deploy/container/site.env -f deploy/container/compose.yaml config --quiet
docker compose --project-name cajeerforum --env-file deploy/container/site.env -f deploy/container/compose.yaml build app gateway
docker compose --project-name cajeerforum --env-file deploy/container/site.env -f deploy/container/compose.yaml up -d gateway
docker compose --project-name cajeerforum --env-file deploy/container/site.env -f deploy/container/compose.yaml ps -a
```

`init` waits for authenticated database and Redis probes, verifies the release volume, prepares writable directories and runs the real InstallerService as UID 33. The installer seeds the forum, creates the administrator and applies the migrations. An existing completed install is checked without re-seeding; partial installation or a foreign/nonempty database fails closed. The startup gate checks the migration ledger, ecosystem and persisted runtime config; a changed release image against an existing release volume is rejected. PHP-FPM, workers and scheduler are non-root with read-only code. Nginx starts after their successful health checks, and uses a fixed internal FastCGI gate that checks private maintenance/recovery markers; it never serves arbitrary PHP or the installer. Only HTTPS is published. The public domain must point to the server and certificate be trusted by clients. Use the configured HTTPS port (443 default).

Open `https://forum.example.com/forum` and `/admin/#/forum`. Check runtime:

```sh
docker compose --project-name cajeerforum --env-file deploy/container/site.env -f deploy/container/compose.yaml exec -T app php bin/cajeer forum:runtime:check --json
docker compose --project-name cajeerforum --env-file deploy/container/site.env -f deploy/container/compose.yaml exec -T app php bin/cajeer forum:ecosystem:check --deep --json
docker compose --project-name cajeerforum --env-file deploy/container/site.env -f deploy/container/compose.yaml exec -T app php bin/cajeer queue:diagnostics --json
```

Worker runs bounded batches every 5 seconds, scheduler every 30 seconds (PostgreSQL task leases). Successful batches refresh a local health marker. SIGTERM is forwarded to the active CLI process; interrupted tasks use the existing lease/retry mechanisms. Scale workers with `up -d --scale worker=2 gateway`; all share the same database, storage, Redis and release. Do not horizontally scale this single-host file-volume profile across hosts.

## Data and operation boundaries

- Named volumes: `postgres`, `redis`, `release` (verified code and installer-generated `.env`), `storage` (including private files, operational state, backups), `bootstrap_cache`, `uploads`, `extension_assets`, `keys` (backup key outside application root). Do not run `down -v` on an existing installation. Keep the same Compose project name. Normal `down`/`up` or container recreation preserves volumes. Stop services before any operator code change or restore.
- Default `CE_QUEUE_DRIVER=pgsql` stores durable jobs in PostgreSQL; Redis is still used for forum cache and distributed rate limiting. For an isolated new installation `CE_QUEUE_DRIVER=redis` enables the existing Redis queue, prefix-scoped to the site. It is explicitly **not** supported by automatic BackupManager recovery in this release. Drain/migrate external queues before backup/restore; do not switch the variable on a running installation. Redis AOF is not a substitute for a consistent backup. SQL forum outboxes remain authoritative.
- Backup creation on the default PostgreSQL queue uses the inherited CLI and PostgreSQL client utilities. Stop gateway, worker and scheduler to drain writers before a consistent backup; backup output resides in `storage`. Keep the backup key separately. Restore/code upgrades need the stopped, writable operator environment; do not invoke `update:apply` or `backup:restore` inside the read-only app container. Container image upgrades, in-place code replacement and off-site recovery automation are not part of 0.8.2. A new image with mismatched manifest will fail init rather than modify a live release. Use the existing host updater on its supported profile or a separately rehearsed offline migration/restore; secure container upgrades are a later stage.
- Installation/upgrade web routes are disabled. Signed extensions are not automatically installed; manage trusted package code offline in the writable release volume through a controlled operator session, then restart services. Built-in UI/runtime extensions already installed in a supported release remain supported; arbitrary code modifications invalidate the release check. No external PostgreSQL/Redis, ACME automation, Docker Swarm, Kubernetes or multi-host failover is provided here.
- The root of the release volume is writable only to the one-shot trusted initializer; persistent app data is writable by UID 33. The gateway has no private storage volume and serves only approved static paths. Runtime `.env` is mode 0600. Logs, metrics, backup scheduling/retention, and external queue recovery retain the existing platform boundaries. Do not mount Docker socket into containers.
- Starting with a failed first installation or changing DB/Redis credentials, public origin, namespace or queue selection is a deliberate operator recovery operation. Inspect `docker compose logs init`; restore the matching data and release or, **only for a disposable fresh environment**, remove the project volumes and retry. Never delete production data to fix initialization.

## Acceptance

`deploy/container/test_container.py` runs local topology, release-policy, supervisor and actual Nginx/TLS/FastCGI-gate tests. The `container-runtime` CI job builds and verifies the real dist and images, creates an isolated TLS site, exercises login, ACP, publications, Redis HIT and ACL invalidation, PostgreSQL-backed notifications, scheduler health, and container recreation with persistent data. It also runs with the Redis queue option. Full Docker acceptance is required before certifying this profile; local tests without Docker do not establish that result.
