# Invision Community Data — Forum Edition 1.1.10

The live Invision 4.x/5.x **v8** migration pipeline appends six phases after the existing six core and three content phases: `inv_profiles`, `inv_reactions`, `inv_follow`, `inv_conversations`, `inv_messages`, `inv_pm_content`. Pre-existing v6 and v7 jobs retain their checkpoint indexes and plans. All target writes, ID mappings, and phase cursors commit in the same DB transaction.

## Included source data

- Import of supported public profile text from `core_members` (`pp_about_me`, `about_me`, `signature`, location, website) into **private** target profile records; external profile URLs must use HTTP(S) and cannot contain embedded credentials. Unsupported custom profile field formats are not guessed.
- Supported positive forum post reactions (`core_reputation_index`) into target `like` reactions. Non-forum items, self-reactions, duplicate reactions, or unsupported content types are reported as skipped, not assigned to arbitrary posts.
- Non-anonymous `core_follow` rows for forum topics, boards and core members. Resulting notifications use `internal` mode; the importer never sends historical email notices.
- Private conversation audiences from `core_message_topic_user_map`: inactive, departed or banned recipients are not reinstated. If an active recipient cannot be mapped, the entire conversation is skipped. Messages are accepted only from mapped active participants. Target accounts remain disabled, and imported forums remain hidden until an operator reviews and activates them.
- Private messages are imported with historical dates and revisions, followed by sanitized HTML-to-BBCode conversion; source private attachment URLs are **not** turned into public downloads.

A source containing private messages without a verifiable participant map or necessary date columns fails preflight rather than leaking data. App-specific reactions and unsupported custom fields are not silently converted. Private message file attachments are not imported in this version; inspect the skip/limitations report before cutover.

## Running

Use an operator-owned, immutable **MySQL/MariaDB** snapshot (or a dedicated SQLite test snapshot) and a maintenance window. Review `forum:import:plan` before writing anything. Example:

```bash
export FORUM_IMPORT_SOURCE_DSN='mysql:host=127.0.0.1;dbname=ips_snapshot;charset=utf8mb4'
export FORUM_IMPORT_SOURCE_USER='readonly_user'
export FORUM_IMPORT_SOURCE_PASSWORD='***'
php bin/cajeer migrate
php bin/cajeer forum:import:plan --platform=invision --snapshot=ips-frozen-01 --json
php bin/cajeer forum:import:run --platform=invision --snapshot=ips-frozen-01 --confirm=import-invision --batch=50 --steps=100 --verify-backup=1 --json
```

**Important:** the `--snapshot` label, source DSN, origin and files path must remain identical across restarts. The source adapter creates a read-only consistent transaction, fingerprints schema and row bounds, and refuses incomplete historical mapping. Migration remains subject to the existing exclusive operation lock, backup verification and maintenance-mode requirements. Run tests `php tools/forum-invision-pipeline-unit.php`, `php tools/forum-invision-content-integration.php`, and `php tools/forum-invision-community-integration.php` with `pdo_sqlite`, `mbstring`, `dom`, and the full Composer requirements installed before production deployment. Real IPS4/IPS5 exports still require on-site validation.
