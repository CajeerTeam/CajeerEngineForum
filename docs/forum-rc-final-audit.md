# Forum Edition 0.9.9 — Release freeze and 1.0.0-rc.1 candidate

0.9.9 freezes features and database migrations (latest forum migration: 0049) while preparing a **separate** signed `v1.0.0-rc.1` release. Upstream Engine remains 1.3.0. **A release candidate is not the stable 1.0.0 release.** No independent external certification is implied by this software.

## Reproducible release sequence

1. Merge the 0.9.9 fixes after code review; ensure no outstanding release-blocking issues. Keep the main branch at edition_version `0.9.9` and check the `forum-required` status.
2. Create a dedicated release-candidate **metadata-only commit** from that reviewed code. In `release.json`, set `edition_version` to `1.0.0-rc.1`, `stability` to `release-candidate`, and the three `distribution` artifact paths to `cajeerengine-1.0.0-rc.1-...`; retain the exact same source code, migration SQL and Core VERSION (1.3.0). Verify the diff contains only approved identity/doc changes. Do **not** rename a `0.9.9` ZIP or rewrite a signed manifest.
3. Protect and push tag `v1.0.0-rc.1` to that **exact commit**. Configure `FORUM_RELEASE_SIGNING_KEY_B64` and `FORUM_RELEASE_TRUSTED_PUBLIC_KEY_B64` using a reviewed private key and an independently pinned public key. Branch runs use ephemeral signing keys and are not publishable.
4. Execute the entire `forum-ci.yml` tagged run. The canonical dist is signed once, and the staging job performs a clean disposable application install on the exact signed dist with real auth, ACL, content, media, FTS and migration rollback; a **separate physical Nginx/TLS/PostgreSQL** host report must match the same SHA-256. Certification fails without the staging witness, clean Composer audit, successful CI matrix and filesystem/S3 DR results.
5. The independent `tools/final-audit.py` rehashes every sealed input, independently validates the exact CI job topology, recovery/staging/host inputs and release identity, and executes cryptographic Ed25519 source/dist validation again. The publisher additionally uses a **publisher-provisioned external public key** and rechecks the completed GitHub Actions run/attempt.
6. Only after every mandatory CI job and `forum-required` is successful, obtain the `forum-certified-release` artifact from the exact **completed tagged** run and publish as a GitHub **prerelease** with `tools/github-publish-release.sh`. Release metadata and all signed ZIPs must remain immutable. The publisher never overwrites a previous release.

```sh
export RELEASE_TRUSTED_PUBLIC_KEY_FILE=/secure/forum-release.pub
bash tools/github-publish-release.sh /secure/forum-certified-release v1.0.0-rc.1 CajeerTeam/CajeerEngineForum
```

**No fabricated CI reports**: the certification, host acceptance, backup recovery, 24-hour soak, physical chaos and operator approval are not proven by local unit tests. The protected `forum-long-qualification.yml` and `forum-chaos-update.yml` workflows require disposable dedicated runners and an independently trusted signing key; their evidence must be reviewed separately prior to approval of stable `1.0.0`.

## Supported and unverified environments

- **Primary controlled scope:** Ubuntu Linux, systemd, Nginx, PHP 8.4 FPM, PostgreSQL 17/18, PostgreSQL-backed durable queue.
- **Second controlled scope:** Docker Compose, PHP 8.4 FPM, PostgreSQL, Redis, TLS gateway and dedicated workers/scheduler.
- **Compatibility (not separately production-certified):** SQLite for local/demo, MySQL 8.4+ and MariaDB 10.11+, Redis queue configuration.
- **Not included:** Kubernetes, multi-node HA, live cross-region DNS failover, zero downtime, external independent certification.

Never launch destructive staging or crash tests against a running production forum. Before a live promotion, independently validate backups and key escrow, confirm restorability, lock the release SHA-256, plan maintenance, verify monitoring/paging, allocate rollback headroom and require an explicit human GO decision. For any failed gate use **NO-GO** and begin a new reviewed run; do not edit evidence or override checks.
