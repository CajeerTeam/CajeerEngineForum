# Forum Edition 1.5.1 — GitHub Release Synchronization

The existing `UpdateResolver`, ACP check action, `update:check`, scheduler discovery and verified downloader now share the same bounded catalog synchronization. The updater still requires an approved, independently signed dist; this release does not implement autonomous installation.

- The canonical repository is `CajeerTeam/CajeerEngineForum`. Up to ten pages (100 entries each) are read with per-page ETags. `Link` is parsed for the next sequential page; URLs are reconstructed locally and never followed from response data. An over-limit or incomplete synchronization fails closed rather than publishing a truncated catalog.
- A complete snapshot is atomically replaced only after all pages validate. HTTP 304 reuses the corresponding cached page and advances the successful check timestamp. A valid empty list removes obsolete releases. Older single-page caches are retained for offline display but the first successful 1.5.1 sync re-fetches them unconditionally.
- An exclusive non-blocking filesystem lock serializes ACP, CLI and scheduler synchronization on a shared storage volume. Multi-host deployments must share this storage and support reliable file locking (or use one discovery host).
- A maximum of three attempts per page for network errors and 408/500/502/503/504, with exponential jitter, a 30-second synchronization budget (apart from OS DNS resolution), and `UPDATE_HTTP_TIMEOUT` (1–15 seconds per HTTP call). Exhausted transient failures persist an exponential 30–900 second cooldown. 429 and primary/secondary GitHub throttling honor `Retry-After` and quota reset headers, including before any catalog exists. The conservative default cooldown is 60 seconds; a headerless 403 is classified as forbidden. Cooldown persists across process restarts. The normal scheduled polling interval remains six hours by default; the next scheduled or manual request after the cooldown may retry.
- Optional `UPDATE_GITHUB_TOKEN_FILE` points to an absolute, operator-protected secret file (0600 or 0640); alternatively set `UPDATE_GITHUB_TOKEN` in a protected runtime environment. Never set both. Use a minimally privileged read-only token for the canonical repository. The token is sent only to `api.github.com`, not to asset redirects, logs or API diagnostics. Metadata cached under a different token is not reused for conditional requests. Private asset downloading is not implemented by this change; use public signed assets or the established offline signed-package installation path. For PHP-FPM, CLI, and scheduler ensure the same credential source is loaded; in Compose provision the protected file in a shared mount and set the path in the application runtime configuration before starting services.
- ACP shows synchronized page count, catalog timestamp and cooldown. `metadata_status` fresh/not_modified means all pages were checked successfully; cached/stale/unavailable is not an installation authorization. Download revalidates discovery and verifies the signed bytes separately.

## Verification

```sh
php tools/github-release-sync-regression.php
php tools/github-release-regression.php
php tools/automatic-release-discovery-regression.php
php tools/secure-release-downloader-regression.php
php bin/cajeer update:check
```

No new DB migrations or mandatory environment changes. Signed publication still requires independent CI and physical qualification with the authentic 1.5.0 rollback baseline. The tests use a controlled GitHub transport to reproduce 304, rate limiting and outage conditions; live GitHub access and real deployment must be verified separately.
