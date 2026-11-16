# Forum Migration Engine — phpBB 3.3 (0.7.5)

The importer is an operator-only CLI, not the CMS Portable ZIP importer. It accepts a **frozen copy** of a phpBB 3.3 database in MySQL/MariaDB or a SQLite copy with the same tables. Keep the source DB and attachment directory immutable for the whole import. Do not point it at the live forum. Use a dedicated SELECT-only source database account; the adapter also sets read-only transaction mode. The SQLite source is opened with `query_only`. Source credentials are provided in environment variables, never saved to the target or passed on the command line.

## Preparation and execution

1. Upgrade the target to 0.7.5, run `php bin/cajeer migrate`, configure backup keys (`php bin/cajeer backup:keygen --key=/secure/backup.key` as documented), and verify a backup/restore drill. Stop all target PHP/queue/scheduler processes on **every** node and enter operator maintenance. Do not run other writers or deployments during import. Use a staging installation first.
2. Freeze phpBB database and `files/` directory, use a stable snapshot label. Set `FORUM_IMPORT_SOURCE_DSN='mysql:host=127.0.0.1;dbname=phpbb_snapshot;charset=utf8mb4'`, `FORUM_IMPORT_SOURCE_USER`, `FORUM_IMPORT_SOURCE_PASSWORD` securely in the process environment. Alternatively `FORUM_IMPORT_SOURCE_DSN='sqlite:/absolute/path/source.sqlite'`. Never store credentials in Git or shell history. Source database and target database must be distinct.
3. Plan (no target writes):

```sh
php bin/cajeer forum:import:plan --snapshot=community-2026-11 --prefix=phpbb_ --files=/srv/phpbb-snapshot/files --json
```

4. Inspect source counts and unsupported entities. Use exactly the same DSN, snapshot label, prefix and files directory on every run. The job ID is the hash of these source identifiers and source version; it is **not** a content checksum. Do not change these parameters to resume. If source data changes, restore target backup and start again with a new immutable snapshot. The final check compares counts/high-water marks, but cannot detect edits preserving those values.

```sh
php bin/cajeer maintenance:on
php bin/cajeer forum:import:run --snapshot=community-2026-11 --prefix=phpbb_ --files=/srv/phpbb-snapshot/files --confirm=import-phpbb --batch=100 --steps=50 --json
```

The first run captures and verifies a complete recovery backup through the existing BackupManager before any import writes. Each batch commits data, ID map and checkpoint together. On a process failure or uncertain commit rerun exactly the same command; the committed checkpoint determines the next batch. `--steps=1` is the default; use a bounded number of steps or repeat in an operator script. `--batch=1..500`, posts capped at 20, attachments one at a time, watches/votes paginated by source user. Status and ID mapping need no source credentials:

```sh
php bin/cajeer forum:import:status --job=JOB_ID --json
php bin/cajeer forum:import:map --job=JOB_ID --kind=topics --id=123 --json
```

Wait for `job.state=complete`. `results` counts mapped/skipped source entities by reason; `relation_rows` counts actual imported vote/watch rows. For watches and votes the map is per parent, not per individual membership; compare relation counts with source totals. Keep the job and backup IDs. A successful CLI exit means the requested batch ran, not necessarily that the import is complete.

## Review and cutover

- The new root category and every imported board are **hidden**, readonly and SEO-disabled. No automatic ACL translation. Review source ACLs and create appropriate target group/user rules through ACP before opening any board. Set parent and child visibility, read/write permissions, SEO, and state deliberately. Keep confidential boards hidden. The original site tree and accounts are not merged or replaced.
- Every new account is disabled, with a random replacement password hash, private profile/activity, no verified email, and only normal `forum-member` plus non-privileged imported groups. Do not activate source banned/inactive accounts without manual review; source bans are not translated. For approved accounts activate through ACP, use existing password reset and email verification. Source founders/admins receive no special rights. Existing email collisions are skipped, not linked; authors without a mapped account become anonymous/null. Never change existing account credentials to resolve a collision.
- Review the report: unapproved/deleted/moved topics, unapproved posts, links, unknown parents, empty topics, invalid emails, unsupported attachments and private messages are not imported. phpBB s9e XML/legacy BBCode is converted to literal text, not arbitrary HTML; formatting and embedded media/quote links may require manual repair. Post order follows source post IDs; timestamps retained (account timestamps are limited to MySQL TIMESTAMP range). Source polls are closed; compatible registered votes and internal subscriptions are imported. Guest votes, private messages, ACL rules, bans, custom profiles, remote media, avatars and source credentials are not imported.
- Attachment source directory must be a frozen trusted local copy. Files use the existing type/size/MIME allowlist, basename-only paths, SHA-256 and base64 private storage. Missing/unsafe/unsupported files and files exceeding post limits are reported. The importer does not copy private-message or orphan files. Large imports require sufficient database/disk space and appropriate MySQL `max_allowed_packet` (base64 adds overhead).
- The importer uses target FTS triggers, revisions, sequences, media state and cache generation triggers. It does not enqueue historical mail, notification or extension publication events. No separate counter rebuild is required for dynamic counters. At completion the target integrity check must pass. Run `php bin/cajeer forum:search:check --json` and `forum:performance:check --json` after leaving maintenance in the isolated installation; check search/SSR/sitemap visibility, attachment downloads, and test opening a permitted board. Keep target hidden from public traffic until checks pass.
- Legacy ID map is available for future redirect integration. No automatic public redirects from phpBB URLs yet. If aborting, use `php bin/cajeer backup:restore --id=... --yes` with the saved backup ID (consult `backup:restore` CLI help for operator confirmation); this restores the full target recovery set, not just imported rows. Do not resume unrelated writes before restore. After review, leave maintenance and restart workers.

## Testing and limits

`php tools/forum-migration-regression.php` exercises the actual importer on a disposable SQLite target and a synthetic phpBB-compatible snapshot, including failure/resume, hidden content, email collision, attachments and votes. `python tools/forum-tests/migration_sql_regression.py` exercises the exact target SQL on SQLite. Real phpBB MySQL/MariaDB dumps and PostgreSQL/MySQL targets must be tested in staging before production cutover. This version does not implement online delta migration, automatic ACL equivalence, an ACP wizard or large-scale performance certification.
