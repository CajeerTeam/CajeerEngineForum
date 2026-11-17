# Forum Edition 0.9.9 — real upgrade and compatibility certification

## Real source releases, not renamed manifest copies

The mandatory `release` matrix (SQLite, PostgreSQL 17 and 18) installs the real
0.8.7 dist obtained from the immutable historical Git tag, then invokes the
**installed application's** signed updater for `0.9.2`, `0.9.7`, `0.9.8` and the exact
signed `0.9.9` CI candidate. Each historical tree is assembled from its own
tag and locked dependencies, never from a version-edited 0.9.9 checkout. A
missing or mismatched tag is a failed certification, not a reason to skip or
replace a release with a synthetic stub. Re-signing historical archives with
a fresh disposable operator key is part of the isolated CI upgrade simulation;
it does not claim they carry their original publisher signatures.

At each hop the script verifies HTTP readiness and user/token access,
acknowledged posts, ACL boundaries, discussion/category structure, media bytes,
user-generated content, FTS/SEO, saved settings, published themes, extension
state and roles. It runs the application's real migration/postcheck logic and
keeps logs. Once 0.9.9 has passed all checks, the script invokes an explicit
rollback to the **immediately preceding** installed release (0.9.8). It then
tries another signed 0.9.9 dist containing a failing real SQL migration and
requires automatic recovery with no active transaction left. A receipt
`upgrade-chain.json` records SHA-256 of each historical dist, the CI SHA, and
the observed restored release; it is written only after all assertions pass.

## Compose and systemd

`container-upgrade` runs the real signed 0.8.7→0.9.9 Compose update on a
**disposable** PostgreSQL-backed project, verifies persisted HTTP content and
performs explicit `rollback --yes`. `chaos_update.py` is separately available
for a disposable 0.9.8→0.9.9 fixture: it SIGKILLs the actual host operator at
the fsynced `drained` or `activating` journal boundary, then invokes real
recovery and checks the release. The self-hosted runner must be provisioned
with independent keys and genuine earlier and newer signed dist artifacts.
A crash while the Compose journal is still `prepared` now reactivates the
unchanged old release without attempting a DB rollback before a snapshot.

`production-host` installs an authentic 0.9.8 dist with actual systemd, FPM,
Nginx, PostgreSQL and CA-verified HTTPS, creates and verifies forum data, and
then upgrades with the signed real 0.9.9 dist. A separate, deliberately
broken, correctly signed synthetic **future** version tests migration failure
and rollback, but is never counted as evidence that the 0.9.8→0.9.9 hop worked.

## Conditions and limitations

These are destructive tests: use an empty isolated PostgreSQL database, a
throwaway host and independent test secrets. Never run these tools against a
production installation. Rollback after activation can discard new confirmed
writes and external side effects: it remains **operator-confirmed** and is
not an automatic fallback. This release does not promise arbitrary backwards
compatibility or zero-downtime upgrades. The full physical CI and self-hosted
SIGKILL tests must actually complete before an upgrade path can be certified;
local unit tests and build verification alone are insufficient.
