# Forum 1.5.3 — Automatic Download & Staging

## Enable the existing dedicated Linux operator

Provision the publisher Ed25519 public key **outside** the project, with trusted ownership and without group/other write permission. Use the dedicated non-root updater user, filesystem permissions and systemd timer described in `admin-update-center-2.0.4.md`. Configure the same environment for the operator and ACP:

```dotenv
UPDATE_CHANNEL=stable
UPDATE_SEQUENTIAL=true
UPDATE_WEB_UPGRADE_ENABLED=true
UPDATE_OPERATOR_ENABLED=true
UPDATE_AUTO_STAGE_ENABLED=true
UPDATE_AUTO_STAGE_INTERVAL_SECONDS=3600
UPDATE_TRUSTED_PUBLIC_KEY_FILE=/etc/cajeerengine/release.pub
```

Enable the existing `cajeerengine-update-operator.timer`. The timer polls independently of HTTP and scheduler jobs. The automatic mode is off by default and **never installs or creates an apply approval**. It runs only on an installed site outside maintenance/recovery when no approved operation is queued. Successful polling is repeated after 300–86400 seconds (default 3600); failure retries persist across restart (60–900 seconds). Explicitly approved operations have priority. Container host deployment automation is unchanged; the host's existing dedicated operator must be configured for automatic staging, not the web container's request handler.

## Transfer and authentication

The next channel-eligible release must be freshly confirmed against the canonical GitHub catalog. Checksum and signature are downloaded first; the publisher key authenticates the digest before any large ZIP transfer. A partial ZIP is kept in the protected version-specific download directory, bound to exact signed digest, signature identity, tag and length. HTTP Range is accepted only with the precise `Content-Range` and identity encoding. HTTP 200 restarts from byte zero; HTTP 416 clears the partial for the next attempt. Error/redirect bodies are never appended. Each request uses HTTPS, DNS pinning and the existing CDN allowlist; each asset's redirects share a single transfer deadline. The final exact size, SHA-256, Ed25519 signature, ZIP inventory, source floor and channel are checked before a receipt is committed. Staging re-verifies the copy and runtime/extension compatibility. An identical already-staged package is reused rather than copied again. The existing public verified archive is also reused when its bytes match fresh sidecars if GitHub omits the asset digest. Failed integrity checks remove partial bytes. Old incomplete files and abandoned temporary sidecars (7 days) are pruned under the download lock; published archives and approved inbox files are deliberately retained for manual retention policy.

ACP shows automatic staging status, retry time and byte progress. A stale progress record after process death is informational only; neither progress nor a partial file can approve installation. Network errors during a manually approved download leave the partial available for the next approval; interrupted manual operation journals continue to require operator reconciliation. Automatic staging can resume on the next timer poll after a process interruption. Private metadata tokens do not grant private asset downloads in this release.

Manual download remains `php bin/cajeer update:download --version=1.5.4` (substitute the actual next release); use ACP to approve installation of the staged digest. To disable, set `UPDATE_AUTO_STAGE_ENABLED=false` and restart/reload the operator environment. No migration. Signed release publication still requires the physical qualification gates.

## Regression

```sh
php tools/automatic-release-staging-regression.php
php tools/secure-release-downloader-regression.php
php tools/update-channels-regression.php
```

The local transport test injects interrupted bytes; actual GitHub/CDN Range behavior and a real compatible production dist require staging/CI with curl, Composer and database dependencies.
