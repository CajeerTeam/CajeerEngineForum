# Forum Edition 0.9.5 — Failure Recovery & Chaos Testing

This is a **destructive test suite for dedicated disposable Compose installs only**. Its code is functional, but its mere presence is not evidence of passed hardware fault tests. Never point these tools at a live forum, shared PostgreSQL, real email destination or populated production volumes. Back up and independently verify the selected test project before enabling faults.

## Runtime hardening

Both the private FastCGI ingress gate (`deploy/container/gate.php`) and Docker app health probe (`runtime.php ready`) now reject insufficient write capacity: at least 16 MiB must remain in the application `/tmp` and 64 MiB on the persistent storage filesystem. Admission fails closed (gateway 503), including for otherwise cacheable and static routes. This detects **low space where the application writes**, not low capacity in the PostgreSQL volume: monitor DB disk capacity separately. Space probes cannot guarantee that a concurrent writer will not exhaust a filesystem immediately afterward.

## Real disposable fault sequence

The existing `qualify.py` workflow still validates signed-release identity, real TLS, authenticated synthetic users, PostgreSQL, confirmed publication bodies, queue delivery, cached SSR, and ACL revocation. Use `--faults --chaos` **only** on a project containing `load`, `qualification`, `benchmark`, or `chaos` in its name. The runner now executes:

1. `SIGKILL` of worker; actual PostgreSQL-queued log-message submission while stopped, confirmation of durable pending status, worker restart and verified processed state plus notification delivery (existing `--faults` behavior).
2. PostgreSQL stop/start, Redis stop/start; readiness must be 503, writes cannot be acknowledged, confirmed publications survive, and normal publication resumes.
3. Stop/restart PHP-FPM app container; gateway rejects writes and readiness while unavailable.
4. Disconnect only the selected Compose app container from its **labeled internal backend network**. Check 503 and refused writes; reconnect/recreate app and check recovery. Container and network ownership must match the explicit project.
5. Exhaust **only** the app's bounded 512-MiB `/tmp` tmpfs, which is verified as tmpfs before writing. Verify disk-space admission gate returns 503, then delete the fill file even on an assertion failure. Do **not** fill the database, Docker host, shared volumes or production disks.
6. Start a PostgreSQL transactional DDL probe against a uniquely named throwaway table, observe an active `pg_sleep` within that transaction, `SIGKILL` the client, terminate **only that session**, and verify rollback of the table. This tests interruption/rollback semantics; it does not claim to interrupt every application migration in place.
7. Recheck every acknowledged synthetic post and reject any fault-window post; revalidate live readiness and the forum integrity checks.

Use the protected manually triggered **Forum physical load and 24-hour soak** workflow with 0.9.5 signed artifacts, private disposable installation and runner. Full 0.9.5 soak certification **rejects** reports without every new chaos check; local mocks or 60-second smoke cannot satisfy it. Report includes exact commit and signed artifact SHA-256. The 0.9.5 release itself does not imply these physical tests have already passed.

## Real interrupted update (separate isolated installation)

The host updater now has a guarded, **opt-in fault-injection pause** after `drained` (before DB migration application) and `activating` (after live activation is selected). Pauses are accepted *only* with `CE_CHAOS_DISPOSABLE=destroy-test-services` and a project name containing a distinct `chaos` component. The separate `chaos_update.py` executes the **real** offline signed 0.9.4 → 0.9.5 Compose update, waits for the atomically persisted checkpoint, sends SIGKILL to the operator process, and invokes the **production** `update.py recover` command. It checks the final journal state: `rolled_back` before activation, or `committed` when resuming activation. The operator's normal signature, migration, DB backup, integrity, HTTP and operational checks remain mandatory.

Do not reuse an updated project for a second checkpoint. Reprovision an independent pristine 0.9.4 disposable installation for each test. The old 0.9.4 app must still be able to verify the 0.9.5 release under its preinstalled trusted key. The target `release.zip`, `.sig`, `release.pub` must already be prepared, and updater `--old-env`, `--new-env`, `--state-dir` and `--ca` must refer to intentionally provisioned real files. **Rollback after new external writes is not automated.** Incomplete results must be marked failed and recovered manually with the documented operations procedure.

The protected, manually dispatched `.github/workflows/forum-chaos-update.yml` can run either checkpoint on a dedicated self-hosted runner; supply the `CE_CHAOS_*` environment variables through an approved environment and reprovision between runs. It verifies both release artifacts and the new env SHA-256 before starting.

Example manual invocation after independently provisioning a dedicated test site:

```bash
python3 deploy/container/chaos_update.py \
  --project forum-chaos --disposable destroy-test-services \
  --old-env /private/old.env --new-env /private/new.env \
  --state-dir /private/empty-state --ca /private/tls-ca.pem \
  --checkpoint activating --report /private/update-interrupt.json
```

Run `python3 deploy/container/test_chaos.py` for local cleanup, disk-gate, trust-boundary and real SIGKILL journal regressions. They do not stand in for the protected physical fault suite. No database migration follows 0049, and upstream CajeerEngine remains 1.3.0.
