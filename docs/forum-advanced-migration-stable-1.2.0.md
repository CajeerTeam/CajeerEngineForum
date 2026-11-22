# Forum Edition 1.2.0 — Advanced Migration Stable

This release adds **source-to-target acceptance for completed Invision Community 4/5 pipeline v8 jobs**, alongside the existing XenForo 2.2/2.3 v5 acceptance. The import execution, row mappings, checkpoints and database migrations remain compatible with 1.1.11. The core remains CajeerEngine 2.1.0.

**Do not call a migration production-accepted merely because CI passes.** The 1.2.0 source archive contains no third-party real forum snapshots. Run acceptance against frozen, representative source database *and* file snapshots on a restored staging target with the production DB driver before any cutover.

## Prerequisites and recovery

1. Apply migrations (`php bin/cajeer migrate`) and check `migrate:verify` before importing; 0079/0080 from previous releases remain required on their respective database engines.
2. Make an off-host recovery copy; verify it, and rehearse a restore to a **separate** environment. Keep credentials, snapshots, and backup manifests out of the release package.
3. Stop writers to the source, freeze the database and relevant files at one consistent point in time. Use a source database account with SELECT-only permissions and a distinct target database. **Never point source and target DSNs at the same database.**
4. Enable forum maintenance, drain background workers and application nodes, and ensure no update, deployment, extension or recovery operation is active.
5. Set `FORUM_IMPORT_SOURCE_DSN`, `FORUM_IMPORT_SOURCE_USER`, `FORUM_IMPORT_SOURCE_PASSWORD`, and, for absolute link rewriting, `FORUM_IMPORT_SOURCE_ORIGIN` as documented in `docs/forum-migration.md`.

## Invision 4/5: discover → import → accept

```sh
php bin/cajeer forum:import:discover --platform=invision --snapshot=ips-frozen-01 --files=/srv/ips/uploads --json
php bin/cajeer forum:import:plan --platform=invision --snapshot=ips-frozen-01 --files=/srv/ips/uploads --json
# After checking the plan and recovery set:
php bin/cajeer forum:import:run --platform=invision --snapshot=ips-frozen-01 --files=/srv/ips/uploads --confirm=import-invision --steps=100 --json
php bin/cajeer forum:import:status --job=JOB_SHA256 --json
php bin/cajeer forum:import:verify --job=JOB_SHA256 --deep=1 --json
php bin/cajeer forum:import:accept --platform=invision --source-version=4.7.0 --snapshot=ips-frozen-01 --files=/srv/ips/uploads --job=JOB_SHA256 --json
```

Use the **actual detected source version** for `--source-version` and repeat all original source options, including `--prefix` and environment variables. A first `forum:import:accept` run can legitimately return `ok=false` with `unapproved_skipped_records` and a `skip_sha256`. Inspect the full skipped-record ledger and reasons independently before running the exact same command with `--expect-skips-sha256=...`; do not approve skips blindly. A different skip, removed file or altered reason changes the digest and revokes approval. A nonzero exit code means acceptance failed.

The Invision v8 validator reads source IDs from every supported phase and checks their mappings and target records, the precise disabled account/permissionless group policy, revision/body consistency against source content, active private conversation membership, stored attachment bytes and source-file SHA-256, and current quarantine state. It checks the verified pre-import backup before returning an acceptance result. It does **not** publish imported boards or activate users. Invision v6/v7 jobs retain their historical checkpoints, but must be re-imported as v8 if full v8 community-data acceptance is required.

## XenForo 2.2/2.3: existing acceptance

```sh
php bin/cajeer forum:import:accept --platform=xenforo --source-version=2.3.0 \
  --snapshot=xf-frozen-01 --files=/srv/xf/internal_data/attachments \
  --avatar-files=/srv/xf/data/avatars --job=JOB_SHA256 --json
```

XenForo pipeline v5 still verifies source IDs, users, topics, posts, avatars, reactions, private messages, files and approved skips; it is not replaced by the Invision check. Both validators require a **completed** import, unchanged source snapshot, isolated maintenance mode, and verified pre-import recovery set. Keep source database and file snapshots available until cutover and rollback testing finishes.

## Cutover acceptance (human approval required)

- [ ] Representative **real** XenForo 2.2 and 2.3 frozen snapshots accepted against the intended target DB driver.
- [ ] Representative **real** Invision Community 4 and 5 frozen snapshots accepted separately.
- [ ] Counts, exact skip digest and known unsupported source apps/data reviewed; no silent losses.
- [ ] Site account state, forum-tree privacy, group ACL, private conversations and file download authorization checked with browser test accounts.
- [ ] Old links, rich content, polls, images and search indexing inspected on a representative sample.
- [ ] Interrupted batch and resume demonstrated using the same source and backup; no duplicates.
- [ ] Restored off-host backup tested and rollback decision/owner documented.
- [ ] Staging security checks, CI and independent release signing/evidence admission passed.

Only after these checks and operator sign-off should application-specific activation and ACL mapping be performed. Never infer administrator rights from a source group, merge users by identical email, restore source passwords, or expose private messages via legacy URLs. This release does **not** import unrelated Invision apps (Gallery, Downloads, Commerce, etc.), unsupported custom fields/reactions, remote blobs without a frozen local copy, or a full history of poll voters.

If acceptance fails: **leave quarantine in place**. Fix the cause on a separate staging copy, restart from a verified backup if the source snapshot changed, and rerun checks. Do not edit ID mappings or check-point rows directly.

## Test execution

```sh
php tools/forum-invision-acceptance-unit.php
php tools/forum-invision-community-integration.php
php tools/forum-xenforo-acceptance-unit.php
python3 tools/forum-tests/invision_community_sql_regression.py
python3 tools/forum-tests/import_hardening_sql_regression.py
```

The PHP integration tests require PHP 8.4, PDO SQLite, mbstring and the project's dependencies. Passing synthetic fixtures validates code paths, **not** compatibility with unprovided real third-party data. CI build/signature checks likewise do not constitute a completed external migration or a production deployment.
