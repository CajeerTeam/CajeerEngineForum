# Forum Edition 1.5.0 — Unified Platform & Production Stable

This is the 1.5.0 source release. It is **not certified merely by its version number**. The production publication workflow refuses incomplete or mismatched evidence. No migration or new production dependency is introduced.

## Qualification and signing

1. Apply the source patch to 1.4.11. Review the changes; commit to main. The authentic ancestor tag `v1.4.11` must identify Forum 1.4.11. Configure protected `forum-production` and `forum-load-certification` environments with independent reviewers, a dedicated disposable benchmark runner, publisher Ed25519 secret `FORUM_RELEASE_SIGNING_KEY_B64` and independently pinned public key variable `FORUM_RELEASE_TRUSTED_PUBLIC_KEY_B64`. Do not use a test key for publishing.
2. Wait for the main push **Forum Edition CI** run to finish successfully. It builds and signs the source and dist archives, checks locked dependencies, SQL and browser integration, clean deployment, upgrade/rollback, staging and DR. Its `forum-certified-release` artifact is the only candidate accepted by physical workflows. Record the run ID and attempt. A rerun changes the attempt; qualify again if needed. Download the exact candidate for provisioning the disposable fixtures; never rebuild the candidate separately.
3. Prepare the benchmark site using the same signed dist (`deploy/container/prepare.sh DIST.zip PUBLIC.pub SHA256 1.5.0`), a loopback binding, isolated PostgreSQL queue, and the smoke fixture described in `docs/forum-production-qualification-1.4.11.md`. Set the existing `CE_QUAL_*` variables except `CE_QUAL_SIGNED_DIR` (now fetched from CI). Dispatch **forum-long-qualification.yml** on main with `ci_run` set to the successful run ID. All three reports and the receipt must pass, including the full 24-hour soak. Verify app/worker/scheduler manifests and actual image IDs, not just the tag.
4. Provision a separate disposable chaos project with a genuine 1.4.11 installation, old/new literal site envs, backup keys and a clean journal. Set `CE_CHAOS_BASELINE_DIST` to the independently signed 1.4.11 dist ZIP (adjacent `.sig` required), `CE_CHAOS_TRUSTED_PUB` to the independent publisher public key and the other existing `CE_CHAOS_*` fixture variables. Dispatch **forum-chaos-update.yml** twice on the same main commit with the same `ci_run`, once for `drained` and once for `activating`; reset the isolated fixture between runs. Both runs verify the signed old and recovered code, data preservation and RTO. Do not run these destructive tests on production.
5. Dispatch **forum-release.yml** on that exact commit with `ci_run`, `physical_run`, `drained_run`, `activating_run`. Approve only after reviewing genuine reports. The gate revalidates signatures, checksum, artifact inventory, all CI jobs, fixed SLOs, run attempt IDs, and hashes of raw evidence. It publishes source/dist with signatures, SHA256, manifest, index and certification summaries. If upload is interrupted, rerun with the same four IDs; only missing assets are uploaded. Any different/incomplete existing asset or conflicting tag blocks publication for manual investigation. Do not use `--clobber`. Never move a published tag. Direct legacy publisher is disabled for this release.
6. Deploy the verified signed distribution via the existing operator. On Docker use `python3 deploy/container/operations.py --help` and the documented operator checks; worker and scheduler now verify the release code identity as well as their heartbeat. For a fresh install use the existing signed deployment procedure. For upgrade use the existing backup and recovery workflow. Verify health, login, publication/search, worker/scheduler, off-site backup status and integrity before routing production traffic.

## Local verification

```sh
python3 tools/forum-tests/test_production_stable_150.py
python3 deploy/container/test_rc_1411.py
python3 tools/forum-tests/stable_110_release_regression.py
php tools/forum-stable-110-regression.php
node admin/scripts/publish-admin-assets.mjs --check
php tools/build-release.php --dry-run --all --json
```

The local regressions exercise the admission logic and draft orchestration with controlled fixtures. They do not substitute for actual GitHub publishing, independently signed dist, physical Docker/PostgreSQL/Redis, E2E or a 24-hour run. No publisher secret is shipped in source or patch archives.
