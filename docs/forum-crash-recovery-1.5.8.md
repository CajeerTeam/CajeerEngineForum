# Forum Edition 1.5.8 — automatic rollback and crash recovery

The host updater has two different recovery actions. A nonterminal transaction is rolled back under the exclusive application and child-process leases; a terminal `committed` or `rolled_back` transaction is finalized without copying the backup again. The private transaction journal is authoritative if `active.json` lags. The original maintenance state is restored only after a verified terminal decision. A failed autonomous target is quarantined across source versions and artifact digests; unattended staging and applying stop at that version. Sequential and signed compatibility requirements are not bypassed. A manually approved installation remains a separate operator decision.

## Provision the independent supervisor (Linux/systemd)

For an existing installation, stop the updater timer and wait for the current operation to finish. Resolve all active journals before installing 1.5.8. Apply the patch using the normal approved process; this version's new journal protocol applies to subsequent updates, not to an in-progress 1.5.7 transaction. Keep automatic applying disabled until the supervisor and the 1.5.7 service policy/Polkit/loopback HTTP gate are installed and tested. From the verified 1.5.8 tree:

```sh
sudo install -d -o root -g root -m 0755 /etc/cajeerengine
sudo install -o root -g root -m 0644 tools/update-supervisor.php /etc/cajeerengine/update-supervisor.php
sudo install -o root -g root -m 0644 examples/systemd/cajeerengine-update-operator.service /etc/systemd/system/cajeerengine-update-operator.service
sudo systemctl daemon-reload
```

Create `/etc/cajeerengine/update-recovery.env` as root (0600) with `BACKUP_KEY_FILE=/etc/cajeerengine/backup.key` pointing to the **existing** original key readable by `cajeer-updater` (not the key value). Use the actual configured key path; do not regenerate it. Adjust the service root if the installation is not `/var/www/cajeerengine`. Start the timer with `sudo systemctl enable --now cajeerengine-update-operator.timer`. The supervisor lives outside the replaceable code tree, runs as the dedicated non-root UID and never downloads recovery code. Keep this host file under administrative control and update it only from verified releases.

## Recovery behavior

- A surviving child process or another exclusive operation defers recovery. The independent supervisor reads only UID-private journals and checks the exact transaction identity.
- For pre-mutation crashes or terminal cleanup, the complete live CLI is used. For a nonterminal mutation, the supervisor launches the previously copied recovery entrypoint, which authenticates the original backup manifest before loading its code. It never loads a mixed application tree to choose recovery.
- The per-transaction retry receipt allows at most three automatic attempts, with 60/120/180-second cooldowns. After exhaustion inspect systemd logs, protected transaction journal and backup; maintenance stays active for unresolved work. Correct the underlying fault and use the original saved `recover.php` as the dedicated UID, with the original backup key environment, or the live `update:rollback --transaction=ID` only when live code is intact. Do not delete markers or attempt receipts blindly. No automatic rollback of an already finalized commit is authorized.
- Unknown autonomous outcomes without matching durable evidence remain blocked. A failed version requires explicit administrative investigation before manual override; do not remove the private attempt file as a routine retry. Recovery is not a bypass of the release signature, database backup, or service lifecycle policy.

The local regression exercises real file journals, exclusive locks, and independent supervisor processes. Full signed dist update, real systemd/Polkit, database restore and physical SIGKILL acceptance remain required before production certification. Existing Compose orchestration and its physical gates remain in place; this supervisor targets the Linux host path.
