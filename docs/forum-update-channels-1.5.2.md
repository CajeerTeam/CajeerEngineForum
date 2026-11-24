# Forum Edition 1.5.2 — Update Channels & Compatibility

Configure `UPDATE_CHANNEL=stable` (default), `rc`, `beta`, or `dev` in the site's environment. Stable is isolated from prereleases. RC accepts RC and stable; Beta accepts Beta and stable; Dev accepts all valid channels. Stable releases permit promotion from prerelease builds. Unknown channels fail closed. Restart application, scheduler and operator after changing their shared configuration.

`UPDATE_SEQUENTIAL=true` (default) orders complete eligible published dist releases using SemVer. The check result has `latest` (highest visible version), `next` (next allowed candidate), and `upgrade_path` (ordered from/version/channel/kind steps). Only the next candidate is accepted by the GitHub downloader/approval flow. Re-check after each committed installation; stale approvals from another installed version, channel or sequential setting are refused. `UPDATE_SEQUENTIAL=false` opts out of walking available catalog releases, but never overrides a signed minimum source version. Catalog discovery is advisory: missing/purged GitHub releases cannot be invented; the signed source floor is checked once the package has been authenticated. An offline manual signed package is checked against its signed source floor rather than a network catalog.

1.5.2 declares `upgrade_compatibility` schema 1 in the authenticated `release.json` (minimum source 1.5.1, PHP >=8.4.1 <9.0, required PHP extensions, PostgreSQL 17+, MySQL 8.4+, MariaDB 10.11+, SQLite 3.40+, Redis 7+ if configured). A subsequent publisher can set a stricter `min_from` for a mandatory bridge release. For historical packages without the policy, existing signature, migration, extension and postcheck protections remain in force. The new signed policy is enforced by 1.5.2+ updater code; older updaters do not understand it, so do not skip 1.5.1 when initially rolling out 1.5.2. No migrations or new production dependencies are introduced.

```sh
php bin/cajeer update:check --json
php bin/cajeer update:prepare --package=/absolute/path/cajeerengine-1.5.2-dist.zip --version=1.5.2 --sha256=APPROVED_SHA256 --json
php bin/cajeer update:apply --package=/absolute/path/cajeerengine-1.5.2-dist.zip --version=1.5.2 --sha256=APPROVED_SHA256 --dry-run
```

The verified local-package plan exposes runtime and extension compatibility. The operator checks real database server versions and, if enabled, Redis server version (`INFO server` permission required); inaccessible dependencies or unsupported versions fail closed. Runtime checks are repeated before applying the authenticated staged bytes. Theme/plugin/module constraints use the existing enabled extension registry and target Engine API. No external server credentials are returned by the report. The ACP shows next step, final version, planned sequence and staged compatibility. Manual approval, backup, maintenance, migrations and rollback protections remain mandatory; autonomous installation is not introduced in this release. Compose image upgrades still use the separate stable-image operator and its existing recovery requirements.

Qualification: `php tools/update-channels-regression.php` plus the existing synchronization, signed download, extension, operator and physical release gates. A patch ZIP is not an officially signed dist. Full qualification on production dependencies is required before publication.
