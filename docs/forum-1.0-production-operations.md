# Forum Edition 1.0.0 — production release, certification and on-call runbook

## Release status and authority

`1.0.0` in `release.json` names a candidate stable source tree. It becomes **Production Certified Stable** only when independently verified evidence exists, the publisher signs real source and dist bytes with a provisioned (not generated in CI) Ed25519 signing key, both Docker and systemd host acceptances succeed, and a separate operator signs a final GO. This project has no external accredited certification; the resulting certificate describes **internal physical test scope** only.

A release may NOT be called certified on the strength of `--dry-run`, local unit tests, an incomplete 24-hour run, a self-signed disposable CI key, or a locally constructed JSON report. Separate workflows `forum-long-qualification.yml` and `forum-chaos-update.yml` use disposable dedicated runners; they do not become successful simply because the short `forum-ci.yml` workflow passed.

## Complete, independent issuance

Run the mandatory `forum-ci.yml` on **immutable** `v1.0.0` with `FORUM_RELEASE_SIGNING_KEY_B64` and `FORUM_RELEASE_TRUSTED_PUBLIC_KEY_B64` provisioned externally, require `forum-required`, and download `forum-certified-release` from that exact completed run/attempt. The certificate `certification.json` binds the commit, GitHub run and SHA-256 of the exact source/dist, plus Composer audit, real host, staging, DR and matrix reports. Match publisher trust root out of band.

On a dedicated, isolated PostgreSQL host with verified disposable image and signing root, execute the protected 10k (>=300 seconds), 100k (>=1800 seconds) and 1M (>=86400 seconds) real qualification profiles using the same signed dist and *identical host*. Keep the physical `10k.json`, `100k.json`, `1m-soak.json` with measured p50/p95/p99, error budgets, CPU, memory growth, I/O, PostgreSQL and queue telemetry. `deploy/container/certify_load.py` rejects incomplete, mismatched, short or unobserved runs. On separately restored disposable installations execute `forum-chaos-update.yml` twice (`drained` and `activating`) with separate journals. Preserve `recovery.json` as `chaos-drained.json` and `chaos-activating.json`.

Place the verified primary CI evidence and those five physical reports in ONE read-only release evidence directory. Keep signing keys **outside** it. On a review workstation, create `operator-approval.json` with exactly:

```json
{
  "schema": 1,
  "decision": "GO",
  "version": "1.0.0",
  "commit": "<exact 40-hex Git commit>",
  "dist_sha256": "<exact 64-hex dist SHA-256>",
  "reviewed_at_utc": "<YYYY-MM-DDTHH:MM:SSZ>",
  "input_sha256": {"<each of eight required filenames>": "<exact file SHA-256>"},
  "checks": {
    "load_and_24h_soak_reviewed": true,
    "chaos_recovery_reviewed": true,
    "restore_dr_reviewed": true,
    "oncall_and_rollback_ready": true
  }
}
```

All **eight** required input filenames are defined in `tools/stable-certification.py`: `certification.json`, `10k.json`, `100k.json`, `1m-soak.json`, `chaos-drained.json`, `chaos-activating.json`, `cajeerengine-1.0.0-source.zip` and `cajeerengine-1.0.0-dist.zip`. Sign the **unaltered bytes** of `operator-approval.json` with the offline operator Ed25519 key and publish base64 of the detached 64-byte signature in `operator-approval.json.sig`. Do not use the same key as the release publisher. The signature checker uses ext-sodium. Keep `STABLE_OPERATOR_TRUSTED_PUBLIC_KEY_FILE` separately pinned and controlled.

```bash
export RELEASE_TRUSTED_PUBLIC_KEY_FILE=/secure/publisher.pub
export STABLE_OPERATOR_TRUSTED_PUBLIC_KEY_FILE=/secure/operators.pub
python3 tools/stable-certification.py issue --directory /secure/forum-certified-release \
  --trusted-public-key "$RELEASE_TRUSTED_PUBLIC_KEY_FILE" \
  --operator-public-key "$STABLE_OPERATOR_TRUSTED_PUBLIC_KEY_FILE"
python3 tools/stable-certification.py verify --directory /secure/forum-certified-release \
  --trusted-public-key "$RELEASE_TRUSTED_PUBLIC_KEY_FILE" \
  --operator-public-key "$STABLE_OPERATOR_TRUSTED_PUBLIC_KEY_FILE"
bash tools/github-publish-release.sh /secure/forum-certified-release v1.0.0 CajeerTeam/CajeerEngineForum
```

The issuer **refuses to overwrite** an existing certificate. The publisher rechecks original physical reports and separate approval signature, remote tag/CI attempt and remote SHA-256 of every uploaded asset before making its draft public. Any failure is **NO-GO**; do not hand-edit a report or weaken mandatory checks. Release private signing keys, escrowed backup keys and actual production credentials never belong in artifacts or logs.

## Daily and on-call checks

- **Before promotion and daily:** `python3 deploy/container/operations.py --project FORUM_PROJECT --env-file /secure/forum.env --ca /secure/ca.pem --require-backup --require-integrity --tls-min-valid-days 7 --report /secure/operations.json` (Compose), or `python3 deploy/production.py verify --ca /secure/ca.pem --require-backup` (systemd). Review all negative checks, backup age, signature/installed release hash, background services and TLS validity.
- **Metrics & alert delivery:** verify Prometheus/Alertmanager and Blackbox HTTPS/TLS target with a controlled staging-only firing/resolved event. Investigate missing critical series, stale worker/scheduler timestamps, queue lease backlog, age of unprocessed notifications, database errors, disk space, missing or stale off-site backups and loss of HTTPS before opening traffic.
- **Queue incident:** preserve queue and lease state, capture timestamps, stop competing workers if necessary, restart through the supervisor and verify retries/idempotency on a harmless test event. Do not delete stuck jobs blindly, truncate SQL tables or forcibly bypass lease fencing.
- **Integrity alarm:** enter maintenance if ACL, FTS, upload privacy, signed file identity or migration ledger fails. Preserve logs and a backup; examine with `php bin/cajeer forum:ecosystem:check --deep`. Do not auto-repair permissions or overwrite installed packages during an untriaged incident.
- **Storage exhaustion:** stop write paths and background tasks without touching confirmed posts, restore free space by removing only identified disposable caches/logs and review off-site backups. Re-run readiness and postcheck; do not clear durable queues, signed checkpoints or backups as emergency free-space operations.

## Total-host recovery

1. Provision an **independent replacement host**, fresh database, HTTPS origin and off-site trusted same-release dist. Keep public writes, outbound jobs and webhook delivery disabled. Obtain the original **escrowed 0600** backup key and protected off-site policy from separate failure domains.
2. Discover backups without a local catalog (these are **untrusted candidates**), fetch the exact content-addressed object, authenticate and restore into an empty target:

```bash
php bin/cajeer backup:discover --policy=/secure/backup-policy.json --limit=100 --json
php bin/cajeer backup:fetch --policy=/secure/backup-policy.json \
  --id='<exact discovered backup id>' --sha256='<exact discovered object sha256>' \
  --file=/secure/recovery.cebackup --json
php bin/cajeer backup:recover --file=/secure/recovery.cebackup \
  --key=/secure/escrowed-backup.key --target=/srv/replacement/forum \
  --profile=/secure/recovery-profile.json --yes --json
```

3. In private maintenance mode verify signed release integrity, migration ledger, users/roles/ACL, private media, FTS, login, TLS, new write/read roundtrip and background queue behavior. Measure RTO **until HTTPS is usable** and RPO as snapshot age; compare with the approved service budgets. Only then allow users and external deliveries.
4. Review `docs/forum-dr-0.9.6.md`, `docs/disaster-recovery.md`, `docs/automated-backup.md` for supported recovery profiles and external authoritative media limitations. Never restore over an occupied production database or treat a discovered object name as authenticated data.

## Release blockers

Missing real 24-hour evidence, unknown Git commit, invalid trusted key, wrong SHA-256, failed physical checkpoint, unverified off-site restore, unsafe extension, incomplete alerts, missing human GO, or absence of authentic historical release tags is a blocking error. A published GitHub tag/release cannot retroactively be marked certified by editing source files or generating a new detached signature.
