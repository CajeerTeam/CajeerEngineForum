# Invision Content & Media — Forum Edition 1.1.9

The **v7 importer performs actual writes** to the forum database and attachment store. It operates on a frozen **read-only Invision Community 4.x/5.x database** and an immutable copy of local `uploads` files. The target remains in maintenance mode and imported sections are hidden/read-only until operator approval. No original password or administrative permissions are imported.

## Preflight and run

```bash
export FORUM_IMPORT_SOURCE_DSN='mysql:host=127.0.0.1;dbname=ips_snapshot;charset=utf8mb4'
export FORUM_IMPORT_SOURCE_USER='readonly_import'
export FORUM_IMPORT_SOURCE_PASSWORD='...'
export FORUM_IMPORT_SOURCE_ORIGIN='https://old-forum.example'

php bin/cajeer forum:import:discover --platform=invision --snapshot=ips-frozen-01 --files=/snapshots/ips/uploads --json
php bin/cajeer forum:import:plan --platform=invision --snapshot=ips-frozen-01 --files=/snapshots/ips/uploads --json
# Take the application to maintenance, hold the exclusive operation lock and review the plan.
php bin/cajeer forum:import:run --platform=invision --snapshot=ips-frozen-01 --files=/snapshots/ips/uploads --confirm=import-invision --batch=20 --steps=100 --json
```

The optional `FORUM_IMPORT_SOURCE_ORIGIN` enables remapping absolute IPS links on the exact origin to migrated thread/post/member/attachment identifiers. It must be preserved between runs, as it forms part of the immutable job identity. Existing v6 imports without this setting retain their original identity and phases.

## Supported layouts and constraints

* Invision 4/5 `core_attachments` and `core_attachments_map` with verifiable `forums_Post` relationships, local `attach_location` and `attach_filesize`; up to 25 MiB per file, 10 attachments and 50 MiB per post. The importer verifies names, paths, MIME and file SHA-256 and stores actual bytes. Images are queued for the existing media process. Files without a supported mapping or owner are **explicitly skipped**.
* Nonempty poll tables must match a verified `core_polls` / `forums_topics.poll_state` or `forums_polls` layout, with JSON or PHP-serialized option arrays decoded without objects. Polls are imported **closed**, without fabricated historical voter records. Unknown formats generate `unsupported_poll_choices` in the import ledger.
* Invision editor HTML is converted to a limited BBCode grammar (emphasis, lists, code, quotes, internal topic/post/member links and attachment download links), then rendered by `ForumContentRenderer`. Executable HTML, unknown embedded widgets, unsafe URLs and unresolvable source IDs are not copied as active content. Linked images use protected attachment URLs, not raw source storage URLs.
* Per-row state, ID mapping and checkpoints commit together. An interrupted v7 job must resume against the same immutable snapshot. **Already started v6 jobs remain six-phase imports**; never renumber old checkpoints or retrofit a completed core job without a fresh isolated import.

**Not supported in 1.1.9:** cloud/remote uploads without a local frozen copy; IPS poll vote-history formats, IPS plugin widgets, archived posts, private conversations, profiles, reactions and follows. A production cutover requires operator review of all skipped rows, privacy and ACL, target integrity and rollback testing with real Invision 4.x/5.x snapshots. Local unit and SQL tests do not establish production acceptance.
