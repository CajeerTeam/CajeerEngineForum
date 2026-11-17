# Forum Edition 1.0.0 — signed upgrade and rollback procedure

## Prerequisites (GO/NO-GO)

1. Obtain the approved **dist**, detached Ed25519 signature, SHA-256 and per-artifact manifest from the **same** published `v1.0.0` release. Compare the trusted release key fingerprint with a key obtained through an independent channel; reject unsigned, source, or changed-files ZIPs for runtime upgrade.
2. Confirm `tools/stable-certification.py verify` and the exact GitHub tagged run/attempt succeeded. The published approval must bind the complete CI/staging/DR evidence and physical soak/chaos reports to the new dist SHA-256. Missing evidence = **NO-GO**.
3. Require a recent verified **off-site** backup, verified 0600 backup key escrow outside the source host and a completed replacement-host recovery drill. Verify free disk headroom for both releases, the backup and DB snapshots. Record current release, installed migration ledger, public URL, certificate expiry, operator and maintenance window.
4. Notify users and stop external writers. Check alert delivery, worker/queue drain and maintenance readiness before making changes. Keep the previous signed dist and independent backup readable during upgrade.

## Docker Compose (single node)

Use the existing operator (never `docker compose up` with an arbitrary new image). Prepare both complete environment files, exact signed ZIP and private trust root. This is a sample command structure; replace only the **paths** and project name with the provisioned site's values:

```bash
python3 deploy/container/update.py apply --dry-run \
  --project FORUM_PROJECT --old-env /secure/forum-old.env --new-env /secure/forum-100.env \
  --state-dir /secure/forum-update-state --ca /secure/ca.pem
python3 deploy/container/update.py apply \
  --project FORUM_PROJECT --old-env /secure/forum-old.env --new-env /secure/forum-100.env \
  --state-dir /secure/forum-update-state --ca /secure/ca.pem
python3 deploy/container/operations.py \
  --project FORUM_PROJECT --env-file /secure/forum-100.env --ca /secure/ca.pem \
  --tls-min-valid-days 7 --require-backup --require-integrity --report /secure/forum-post-upgrade.json
```

Check real HTTPS readiness, authenticated ACP, protected attachment denial, new topic/reply, FTS, notifications, log/error rate, backup freshness, scheduled tasks and TLS expiry before ending maintenance. Record release SHA-256 and post-upgrade audit. Redis queue installations cannot use this snapshot-based upgrade operator; follow a separately reviewed and tested migration plan.

**On interruption:** do not delete/replace `state-dir`, DB snapshots or the updater's lock. Run `python3 deploy/container/update.py status ...` with identical paths, then `python3 deploy/container/update.py recover ...` and require a passing operations gate before traffic resumes. A journal in `prepared` must not restore a DB snapshot that has not yet been created. A committed update may already contain new writes; automatic rollback would lose them. `rollback --yes` is a **destructive operator decision** with an explicitly verified recovery point and accepted data-loss impact. Never use it simply to clear a stuck gate.

## Linux/systemd

The host operator is `deploy/production.py`, executed by the trusted root-owned checkout (not by arbitrary downloaded code). Verify the signed dist, perform deployment dry-run, then `deploy` under an approved maintenance plan; `status`, `verify --require-backup` and `resume --version ... --sha256 ...` are the supported post-interruption actions. Use the installed service identities and read-only host audit before reopening gateway traffic. Record signed SHA-256 and audit receipts. See `docs/production-deployment.md` and `docs/forum-upgrade-certification.md` for the on-host configuration, privilege boundaries and physical test expectations.

## Failure rules

NO-GO if the signature, release certificate, version/migration ledger, externally escrowed backup, public TLS, read/write/ACL checks, queue drain, monitoring or available disk budget fail. Stop traffic where required, preserve the intact journal and logs, and recover using the official operator. Do not drop or copy a live database as a workaround. A migration failure is an error to investigate, not a reason to manually edit migration ledgers.
