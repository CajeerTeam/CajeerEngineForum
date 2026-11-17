# Release Certification Pipeline — Forum Edition 0.9.1+ (current 0.9.2; Engine 1.3.0)

This is a **release admission mechanism**, not third-party certification of a production installation. No schema/API changes. Real end-to-end deployment and sustained workload certification remain separate responsibilities. It replaces the outdated 0.8.3 publication instructions. The changed-files ZIP is not an installable or signed distribution.

## Mandatory GitHub Actions path

1. Protect `.github/workflows/forum-ci.yml`, `composer.lock`, signing configuration and release tags with review/branch protection. Set **`forum-required`** as a required status check. The `Forum Edition CI` workflow runs all existing SQLite/PostgreSQL/MySQL, browser, release, host, Compose, backup and monitoring suites.
2. `release-candidate` installs **locked production dependencies**, runs `composer audit --locked --no-dev --format=json --abandoned=fail` with a nonzero failure exit, builds source/dist once and performs archive inventory verification. It signs both exact ZIPs with Ed25519 and verifies the signatures against a public key. All results are uploaded together as `forum-signed-candidate`.
3. The release acceptance matrix (`sqlite`, `pg17`, `pg18`) and physical Nginx/systemd/PostgreSQL host **download that same candidate dist** rather than rebuilding it. The host produces `production-acceptance.json` with `artifact_sha256` matching the candidate ZIP.
4. After all test matrices complete, `certification` uses GitHub Actions `actions:read` to fetch **each actual job**, including expanded matrix variants, from the **same run attempt**. `collect-ci-jobs.py` refuses missing, duplicate, skipped, failed, or unfinished jobs, unexpected names and mismatched logical `needs`. It records the checkout commit, run/attempt, repository, event and tested job outcomes in `ci-jobs.json`.
5. `certification-evidence.py seal` requires all job results, a clean full Composer audit, successful host admission, source/dist signature verification, sidecar manifests, ZIP checksums and artifact index. It produces `certification.json` bound to exact SHA-256 of each input, the Git commit, GitHub run ID/attempt and CI matrix. `verify` re-evaluates everything, including signatures. `forum-required` **fails** if certification fails.
6. The `forum-certified-release` CI artifact contains the matching signed archives and all evidence; no private signing key, credentials or response bodies are included. Reports are evidence from a protected workflow, not independent cryptographic attestations of the CI system.

### Key provisioning and publication

A normal branch/PR run uses a disposable CI-only signing key and is **not publishable**. For a tag `v0.9.2` to be eligible, provision:

- GitHub Actions **secret** `FORUM_RELEASE_SIGNING_KEY_B64`: the *single-line base64 text* from a pre-generated Ed25519 private key file (`php bin/cajeer release:keygen --secret-key=/secure/release.secret --public-key=/secure/release.pub`). Limit access to reviewed release workflows. Never print or upload the private key.
- GitHub Actions **repository variable** `FORUM_RELEASE_TRUSTED_PUBLIC_KEY_B64`: the public key's single-line base64 text from `/secure/release.pub`, approved independently of the candidate. Restrict who may edit this variable and approve its fingerprint out of band.
- Publisher environment `RELEASE_TRUSTED_PUBLIC_KEY_FILE=/secure/release.pub`: an **operator-provisioned** local copy. Do not take the trust anchor from the download (`ci-release.pub` is only an identity hint and must match the external key).

The tagged run requires these values and rejects missing/mismatched keys. After `forum-required` succeeds, download **`forum-certified-release` from that same completed tag run**, verify asset hashes through trusted CI transport, check the reviewed local tag, then publish:

```sh
export RELEASE_TRUSTED_PUBLIC_KEY_FILE=/secure/release.pub
bash tools/github-publish-release.sh /secure/forum-certified-release v0.9.2 CajeerTeam/CajeerEngineForum
```

`gh` must have permission to read GitHub Actions run/jobs and create releases. The publisher re-fetches the successful **completed** run/attempt and checks `certification`, `forum-required` and the exact complete matrix against the sealed report. It cryptographically verifies both ZIPs against the externally trusted key and refuses branch/PR evidence, altered bytes, re-signed packages, an incorrect tag/commit or an existing release (including drafts). After uploading a draft, it checks each remote asset's name, size and GitHub digest (when available) before publication. A failed upload leaves a draft for manual inspection; it is never overwritten automatically.

For an offline **candidate build only** (not a replacement for CI certification):

```sh
export RELEASE_SIGNING_KEY_FILE=/secure/release.secret
export RELEASE_TRUSTED_PUBLIC_KEY_FILE=/secure/release.pub
bash tools/release-pipeline.sh /secure/forum-candidate
php tools/verify-signed-release.php /secure/forum-candidate /secure/release.pub
```

The offline builder runs the real Composer audit and signs archives, but cannot produce `ci-jobs.json` or `certification.json` without a completed trusted CI run and real host report. Do **not** fabricate or transplant these files. `certification-evidence.py seal` is restricted to its own GitHub run identity. The publisher checks the remote run again before publishing.

## Admission requirements

| Gate | Required outcome |
| --- | --- |
| Source and dist | Correct Forum 0.9.2/Engine 1.3.0 internal manifests; strict inventory, locked dependencies, sidecars and SHA-256; detached Ed25519 signatures verified |
| Supply chain | Locked production Composer audit with **zero advisories**, **zero abandoned packages** and no suppressed findings |
| Actual CI | Exact 17 matrix/required jobs (including candidate, excluding certification/final gate), all succeeded in same attempt; final `forum-required` also passed |
| Disposable Linux host | Real TLS, FPM, PostgreSQL 17, SSR/API/ACP, auth/ACL, concurrency, migration/update/rollback, exact dist digest |
| Integration | Existing database, browser, Compose PostgreSQL/Redis, recovery/S3 and monitoring suites successfully completed |
| Final admission | Trusted publication key, signed files bound to commit and GitHub run/attempt, completed GitHub workflow success, successful `certification` and `forum-required` |

The host HTTP acceptance tests 400 TLS requests, four distinct routes, zero semantic/HTTP errors, p95 no higher than 1000 ms and at least 10 requests/s. This is a **bounded CI baseline**, not a 1-million-post/24-hour capacity claim. Do not run destructive acceptance against live production data. Separate recovery drills, SLOs and production operational approval remain required; see [production qualification](production-qualification.md) and [production operations](production-operations.md).

## Maintenance and failure behavior

On any security advisory, missing proof, skipped CI matrix, failed host acceptance, unsigned/malformed candidate, changed SHA-256 or untrusted signing key: **NO-GO**. Fix the cause in a reviewed commit and run the full workflow again. Use the latest successful run attempt and certified artifact only. Do not use `--clobber`, bypass branch protection, edit evidence JSON or silently regenerate signatures after certification: the exact-byte seal will reject it. Rotate keys via a separately approved process and re-run CI; never put private keys in artifacts.
