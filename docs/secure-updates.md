# Secure release operations — current Forum Edition 0.9.3

Forum Edition and Engine versions are independent: the current Forum Edition is **0.9.3 / Engine 1.3.0** (signed update controls originated in 0.8.3). A changed-files ZIP is not a deployable signed dist. See [release certification](production-certification.md) and use a verified signed dist from the matching approved CI run. Build the full dist from reviewed source and locked Composer dependencies, sign it with the offline publisher key and independently approve its SHA-256. Never trust a key provided by the candidate ZIP. GitHub discovery is advisory, not authorization to execute code; draft, incomplete and ambiguous releases are not offered. The stable channel rejects prerelease packages, including a correctly signed prerelease. Explicit `UPDATE_CHANNEL=rc`, `beta` or `dev` is required for those channels (dev accepts all). The signed release metadata must agree with the version/channel and Engine API.

## Existing Linux / manual installation

Stop ingress and drain application processes, workers and scheduler before applying an update. Keep the existing trusted publisher public key outside the application and the original backup key protected separately. The target must be a newer signed Forum dist; the old updater from 0.8.2 is not retroactively upgraded merely by downloading this archive. Use the trusted 0.8.3 deployment controller/source for the preliminary checks and the existing installed updater for the transition. Once 0.8.3 is installed, the new checks run automatically for subsequent updates.

```sh
php bin/cajeer update:check --json
php bin/cajeer update:apply --package=/srv/releases/cajeerengine-0.9.1-dist.zip --version=0.9.1 --sha256=APPROVED_SHA256 --dry-run --json
php bin/cajeer update:apply --package=/srv/releases/cajeerengine-0.9.1-dist.zip --version=0.9.1 --sha256=APPROVED_SHA256 --json
php bin/cajeer update:status --json
php bin/cajeer update:history --limit=20 --json
```

From 0.8.3 onward dry-run validates the current installation, signed candidate, historical migrations, active extensions (Forum and target Engine API), recovery prerequisites and estimated disk space. It does not run target code, rebuild routes, enter maintenance, apply SQL or retain a staged package. It may create operation-lock files, log diagnostics and clean abandoned unjournalled stages. Estimates include the compressed ZIP, extracted files, backup inventory and database size, with headroom; remote database filesystem space and quotas require independent operator checks. A successful preflight is not a reservation of free space. An actual apply repeats all checks, captures and verifies a recovery set, writes a durable journal, replaces files, applies migrations, runs a fresh-process postcheck and commits or restores. Do not modify historical migration files. Stage and transaction directories containing a real journal are retained for recovery/audit.

The CLI and ACP wizard expose sanitized active progress and last report. The CLI history lists up to 100 journals. The browser does not execute PHP deployment operations. `update:rollback` recovers an interrupted active update; a rollback of a committed update requires `--yes` and **discards all subsequent database/file writes**. External mail or webhook effects cannot be undone. If live PHP is damaged, use the original trusted recovery launcher under the transaction directory with the original `BACKUP_KEY_FILE`; see existing recovery documentation. Do not remove active/maintenance markers manually. Do not rotate publisher/backup keys during an update. Rotation is an explicit out-of-band operator procedure, not trust-on-first-use from downloaded packages.

## Controlled Compose upgrade (PostgreSQL queue only)

Keep the existing project name and all persistent volumes. Retain both exact old app/gateway images and the old `site.env`. Do not change secrets, domain, namespace, queue driver or service topology in the same operation. Redis remains cache/rate-limit service, not the recovery authority. Automatic rollback is **not** supported for Redis queue or external storage; the operator rejects Redis queue mode before stopping services. Do not use `down -v`.

From the updated trusted source checkout, prepare the approved target dist and publisher key with `deploy/container/prepare.sh`. Copy the deployed site file to `site.next.env` and change **only** `CE_RELEASE_VERSION` and `CE_RELEASE_SHA256`. Both site files must contain literal assignments in the documented format. Use a private, persistent operator journal directory per project and the same dedicated OS operator. The controller also takes a host-wide project lock and rejects nonlocal Docker endpoints. Run on the local Docker host using the same Docker context/project and an operator with Docker access; never mount the Docker socket in app containers.

```sh
python3 deploy/container/update.py apply --project cajeerforum \
  --old-env /srv/forum-config/site.env --new-env /srv/forum-config/site.next.env \
  --state-dir /srv/forum-config/update-083 --dry-run
python3 deploy/container/update.py apply --project cajeerforum \
  --old-env /srv/forum-config/site.env --new-env /srv/forum-config/site.next.env \
  --state-dir /srv/forum-config/update-083
```

For self-signed staging TLS, add `--ca /secure/ca.crt`. Production uses the host CA store by default. Dry-run verifies the candidate against the **installed image's** publisher key, checks the live release identity, recovery capability and ecosystem, and performs capacity checks without changing live code/data or stopping services. The actual update builds the new verified app/gateway images before stopping traffic, records its intent durably, stops ingress and drains all writers, and runs the existing signed updater in a one-shot writable init container using the old image. The old release has a root-owned code volume; only the offline operator normalizes code ownership to the existing UID 33, while long-lived runtime mounts remain read-only. PostgreSQL/Redis data and storage volumes are not recursively chowned or recreated. The installed publisher key is pinned in the keys volume; changing a candidate key requires a separate explicit rotation procedure.

The updater commits code/database changes with the existing verified backup and recovery journal. The host then records activation intent **before** allowing new workers or ingress. It verifies new image/volume identity, installer configuration and schema, starts FPM and waits for readiness, starts background services and waits for successful cycles, then opens gateway and checks real TLS, forum edition, SSR and ACP. There is downtime. No automatic rollback is attempted after activation starts: new writes or external side effects may have occurred.

On an interrupted or failed operation, use the original site files and journal:

```sh
python3 deploy/container/update.py status --project cajeerforum --old-env /srv/forum-config/site.env --new-env /srv/forum-config/site.next.env --state-dir /srv/forum-config/update-083
python3 deploy/container/update.py recover --project cajeerforum --old-env /srv/forum-config/site.env --new-env /srv/forum-config/site.next.env --state-dir /srv/forum-config/update-083
```

Before activation, `recover` restores the old release if an update committed or is interrupted, or simply restarts the unchanged old release. During activation it resumes the selected target; the gateway stays stopped on failure. After a committed update, explicitly request `rollback --yes` with the same arguments only after accepting loss of later writes and external effects. The operator validates the matching transaction's versions and digest before using its backup. Do not manually start services during a pending operation. If recovery fails, leave the gateway stopped, retain both images/volumes/journals, and use the original authenticated recovery bundle/operator procedure. Do not run an unrelated update until the existing journal is resolved. Archive a finished host journal and use a new private directory for the next release; after successful update, use the new site file for subsequent Compose commands. A fully lost host requires off-site recovery, not this local journal.

## Verification

The release CI requires authentic previous dist update/rollback, production host, and real Compose runtime; the required `container-upgrade` job exercises a signed upgrade and rollback from the authentic 0.8.2 baseline. `deploy/container/test_update.py` checks orchestration, immutable site identity, journal transitions and recovery decisions without Docker. `tools/update-regression.php` tests signed ZIP, real backup and rollback (synthetic dist only for controlled failure injection); `tools/github-release-regression.php` tests catalog channel and ambiguity. A passing source ZIP or dry-run alone is not certification of full production readiness.
