# Forum Edition 1.0.0 — supported and verified environments

These statements define *supported/tested scope*, not a claim that the present checkout has completed certification. The exact supported release is the independently signed and attested `v1.0.0` dist; a changed-files ZIP and a source build are not deployable substitutes.

| Environment | Admission requirement | Scope |
| --- | --- | --- |
| Ubuntu Linux; systemd; Nginx; PHP-FPM 8.4.1–8.4.x; PostgreSQL 17 | `production-host`, full signed staging/DR evidence, normal `forum-required` | Primary single-node production candidate |
| PostgreSQL 18 | PostgreSQL 18 integration matrix plus live dist upgrade and DR acceptance | Primary DB compatibility |
| Docker Compose; PHP-FPM; PostgreSQL; Redis; TLS gateway | Compose `pgsql` and `redis` CI, operations audit and real signed update/rollback | Single-node Compose candidate; use PostgreSQL queue for snapshot-based update |
| SQLite >=3.40 | SQLite and browser/HTTP tests | Local/demo; not production capacity certified |
| MySQL 8.4 / MariaDB 10.11 | SQL search compatibility tests | No full production admission or guaranteed restore certification |
| Redis queue | Runtime test and supervised queue metrics | Supported for fresh installs; NOT approved for Compose snapshot-based upgrade/rollback |
| Multi-node/HA, Kubernetes, geo-replication | Not part of current tests | Not supported by 1.0 production certificate |

## Compatibility policy

- Forum Edition `1.0.0` runs on CajeerEngine Core `1.3.0` (`VERSION`), Engine API `1.1`. Do **not** replace `VERSION` with the Forum Edition version.
- Last Forum schema migration is `0049`; no new schema migration is introduced by 1.0.0. Use the installed application's migration ledger and never manually mark missing migrations as applied.
- Certified compatibility upgrades require genuine, immutable historical dist artifacts, not version-rewritten manifests. The CI upgrade path tests `0.8.7 → 0.9.2 → 0.9.7 → 0.9.8 → 0.9.9 → 1.0.0` and separate signed Compose/systemd upgrade and rollback. `1.0.0-rc.1` precedes stable 1.0.0 but is not in the ordinary multi-hop stable-release acceptance chain; validate any direct RC-to-stable upgrade separately and never treat RC as a rollback target.
- Marketplace/third-party extensions must pass signature trust, declared compatibility, schema checks and real startup testing. The release does not certify arbitrary third-party code.
- Capacity depends on identical hardware/OS and corpus. The 24-hour 1M-post workload and 10k/100k reports must be retained with their host fingerprint, resource telemetry and exact dist SHA-256 to claim a measured capacity.

See [operator procedures](forum-1.0-production-operations.md) and [upgrade procedure](forum-1.0-upgrade.md).
