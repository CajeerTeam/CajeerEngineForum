# Forum Edition 1.0.0 / CajeerEngine 2.1.0 — upgrade notes

This source overlay is intended for a **separately backed-up staging copy**, not for direct production replacement. `VERSION` and `release.json` identify Engine 2.1.0; `edition_version` remains 1.0.0. The Forum release repository and signing requirements must be preserved.

## Database migration namespace

Forum's previously applied `0015_forum_edition.sql` through `0049_forum_database_optimization.sql` filenames and content are **unchanged**. The incoming upstream migrations `0015`–`0043` are distributed as `0050_engine_analytics_hub.sql` through `0078_engine_ai_automation_ga.sql`, preserving ascending order and avoiding collisions in the integrity ledger. Back up each database and inspect `migrate:status` before attempting any migration. Never rename already-applied Forum migration records or force-repair their checksums.

Because the upstream 2.1.0 repository and the Forum 1.3.0 fork use a different migration lineage, a deployment must verify forward migrations and rollback on **each database engine used**. Postgres and MySQL migrations are not interchangeable with SQLite.

## Before deployment

1. Back up application files, secrets, database, private media and the migration ledger. Keep `.env`, `storage/`, persistent uploads and secret key files untouched.
2. Apply the changed-file archive at repository root with preserved relative paths; perform a full dependency installation using the existing lockfile.
3. Run `php bin/cajeer migrate:status` before migration, then apply migrations to a disposable clone first. Confirm the exact SQL and run `php bin/cajeer migrate` only after review.
4. Run Forum HTTP/API, identity, attachment, queue, scheduler, permissions, OpenAPI, operator/update and UI regressions. Review all 2.x environment variables and signing trust roots.
5. Verify operator-specific update/backup/rollback behavior; do not point Forum at the core CMS release feed.

This source tree is **not a certified Forum 2.1.0 dist**; run CI, live staging, and long-running qualification before a production release.
