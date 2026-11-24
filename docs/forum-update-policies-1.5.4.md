# Forum 1.5.4 — Update Policies & Scheduling

The host owns the policy via `.env`/service environment. The same `UpdatePolicy` is used by automatic discovery, staging, GitHub downloader, approval admission, operator and actual apply admission. The ACP shows effective settings; it cannot change the host policy. Restart PHP-FPM, scheduler and the operator after changing environment configuration. Explicit human confirmation is still required for every installation; 1.5.5 will introduce autonomous installation. Do not interpret `fully_automatic` as enabling installation in 1.5.4.

| UPDATE_POLICY_MODE | Periodic discovery | Automatic preparation |
|---|---|---|
| legacy (default) | existing auto-check flag | existing auto-stage flag |
| disabled | off | off; new download/apply requests rejected |
| notify_only | on | off |
| download_only | on | all channel-eligible releases |
| manual_approval | on | all channel-eligible releases |
| automatic_security | on | stable signed security releases only |
| automatic_patch | on | stable patch releases only |
| automatic_minor | on | stable patch/minor releases only |
| fully_automatic | on | all channel-eligible releases |

All explicit modes except disabled permit manually approved download/install, subject to the exact version blocklist, existing compatibility/signature checks, and the maintenance window for installs. `UPDATE_AUTO_CHECK_ENABLED=false` still disables periodic discovery. All automatic staging requires `UPDATE_WEB_UPGRADE_ENABLED=true`, `UPDATE_OPERATOR_ENABLED=true` and the existing trusted key/operator configuration. An automatic policy never skips an intermediate release to reach a later one. In security mode the ZIP must be authenticated before the optional boolean `security_update: true` in signed `release.json` can be trusted. Unsigned API notes and labels are never evidence. Non-security ZIPs may be downloaded for classification but are not published into the staging inbox. Existing cached packages remain subject to verification.

Example: prepare stable patches automatically and execute approved installs on Monday–Friday, 03:00–05:00 Warsaw time:

```dotenv
UPDATE_CHANNEL=stable
UPDATE_SEQUENTIAL=true
UPDATE_POLICY_MODE=automatic_patch
UPDATE_OPERATOR_ENABLED=true
UPDATE_WEB_UPGRADE_ENABLED=true
UPDATE_MAINTENANCE_TIMEZONE=Europe/Warsaw
UPDATE_MAINTENANCE_DAYS=1,2,3,4,5
UPDATE_MAINTENANCE_START=03:00
UPDATE_MAINTENANCE_END=05:00
UPDATE_DEFER_UNTIL=
UPDATE_BLOCKED_VERSIONS=
```

Days are ISO weekdays (Monday=1). Both times unset means all day on selected weekdays. Start is inclusive, end exclusive. Overnight windows belong to their starting weekday. Missing DST minutes are skipped; repeated minutes refer to both real UTC occurrences. `UPDATE_DEFER_UNTIL` is an optional exact UTC instant (`YYYY-MM-DDTHH:MM:SSZ`), and `UPDATE_BLOCKED_VERSIONS` a comma-separated list of exact SemVer versions. Invalid policy disables automatic activity and prevents execution of queued install/download requests until corrected.

Approval may be recorded outside a window; the operator leaves the original pending request unchanged and retries on the next normal poll. The request consumer rechecks policy before claiming it, and apply rechecks the window after preflight before beginning the mutating journal. If preflight runs beyond the window, the operation fails before mutation, requiring new approval. An already-started update or recovery is never killed at window end. Disabling updates or blocking the selected version terminates a pending request without executing it. Existing one-request-at-a-time safety and recovery markers remain authoritative; a pending approval occupies the queue. Backup/rollback requests themselves have no window restriction. Emergency local `update:apply --package=...` is an explicit operator-controlled path outside scheduling; all existing signature/backup/preflight checks remain mandatory. GitHub downloads, including CLI, respect the blocklist and disabled mode.

Inspect effective policy and next window through ACP or `php bin/cajeer update:check --json`. Run `php tools/update-scheduling-regression.php` as an unprivileged user on Linux for the operator regression. No migration or production dependency added. External CI and physical qualification remain required.
