# Autonomous Update Engine — Forum 1.5.5

Existing Linux single-node installations with a dedicated non-root update operator can opt into unattended signed patch/minor/security or all channel-compatible updates. The container image deployment operator is a separate host-controlled mechanism; do not enable in-place updates inside immutable container deployments. Service/image lifecycle automation is not part of this release.

## Configure

Keep the previous trusted Ed25519 public key outside the installation, the existing backup/recovery prerequisites, and protected updater-owned installation permissions. The updater UID must own `storage/app/updates/autonomous` (0700, created on first staging) and its private receipts (0600). The web UID must not be able to replace or rename the protected updates parent directory; only the existing shared `update-requests` approval area is for web writes. Never run the timer as root or as the PHP-FPM account.

```dotenv
UPDATE_WEB_UPGRADE_ENABLED=true
UPDATE_OPERATOR_ENABLED=true
UPDATE_AUTO_APPLY_ENABLED=true
UPDATE_POLICY_MODE=automatic_patch
UPDATE_CHANNEL=stable
UPDATE_SEQUENTIAL=true
UPDATE_MAINTENANCE_TIMEZONE=Europe/Warsaw
UPDATE_MAINTENANCE_DAYS=1,2,3,4,5,6,7
UPDATE_MAINTENANCE_START=03:00
UPDATE_MAINTENANCE_END=05:00
UPDATE_TRUSTED_PUBLIC_KEY_FILE=/etc/cajeerengine/release.pub
```

`UPDATE_AUTO_APPLY_ENABLED` defaults to false so upgrading from 1.5.4 does not silently authorize installation. `automatic_security` requires a signed security classification, `automatic_patch` permits stable patch releases, `automatic_minor` stable patch/minor releases, and `fully_automatic` all compatible releases accepted by the configured channel. Other modes never autonomously apply. `UPDATE_AUTO_CHECK_ENABLED=false` disables autonomous application. Explicit manual approvals remain governed by their existing policy. Configuration is read at each one-shot invocation; stop the timer and wait for an in-flight invocation to finish before editing host policy. Maintenance windows restrict admission, not the maximum duration of an already-started update.

Enable the existing `cajeerengine-update-operator.timer` after testing a signed staging update as the updater. Each invocation prioritizes manual requests, reconciles interrupted autonomous attempts, then checks an operator-owned stage receipt. If the window is open, it fetches fresh catalog metadata, requires the exact next version and matches any advertised digest, authenticates the ZIP again, and checks policy. The real `UpdateManager::apply()` takes the exclusive activity lease, verifies the copied ZIP, runtime, migrations and extensions, checks policy before mutation, captures a backup, installs, performs postchecks and rolls back on failure. It records authorization type `host_policy` in the protected transaction journal. No fake ACP approval is created.

A closed window, busy lease or transient catalog/verification failure is retryable without a mutation. A durable per-transition attempt is created before invoking apply. An unsuccessful attempt cannot automatically replay even if the same target is restaged. After a process interruption the exact committed report or an exact rolled-back transaction journal may reconcile the attempt; unknown outcomes block the operator. Existing active update markers always take precedence. Use the existing recovery launcher/`update:rollback` as documented in `secure-updates.md`; never remove active markers to force a retry. If no matching transaction evidence exists, stop the timer, inspect the protected attempt journal and verify the installed state, database and backups before an authorized operator archives that unresolved journal outside the updater directory. Do not retry an ambiguous attempt. Failed transitions may be investigated and explicitly approved manually after recovery; the automatic quarantine remains for that source/target transition.

Check ACP Update Center or run `php bin/cajeer update:status` as the updater to see the operator heartbeat and autonomous outcome. `php bin/cajeer update:operator:once` runs one real iteration; do not use it as a dry run. Disable unattended installation with `UPDATE_AUTO_APPLY_ENABLED=false` and stop the timer for immediate prevention of new invocations. No schema migration is required.

## Acceptance

Run `php tools/autonomous-update-regression.php` as a non-root Linux user on an isolated checkout. The regression executes real filesystem journals, operator barriers, policy and activity leases; external catalog, package verification and mutation completion use controlled test doubles, with existing signed-package regressions separate. Full physical qualification requires real GitHub/CDN, a signed compatible dist, real database and backup/restore tools, and the existing independent CI/physical gates. Do not certify live unattended installation solely on the local regression.
