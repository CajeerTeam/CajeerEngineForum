# Forum Edition 1.4.0 — AI Assistant Stable

Core remains 2.1.0. Upgrade from 1.3.11 requires no new migrations (0091 must already be applied). Back up the database, stop the old PHP/worker processes, deploy the full new code and restart services. Do not overwrite runtime secrets or storage with source files.

## What changes in 1.4.0

- AI search, thread assistant, moderator advice, ACP insights and admin proposals check their source/permission snapshot again after admission to the bounded queue. A changed snapshot or denied ACL aborts before the provider call. A concurrent edit during the remote request is still possible; this is not a distributed transaction with OpenAI.
- The provider parser reconciles deltas with the authoritative completed output; mismatches fail without persisting a successful answer. Final-only responses retain all output messages. Potentially billed errors retain conservative accounting.
- Publication uploads the detached signatures and manifests as well as both ZIPs, SHA-256 files and the artifact index. All uploaded asset digests are checked before the draft is made public.

- Release discovery and downloading use the Forum Edition identity, canonical forum repository and conditional GitHub metadata. Stale catalogs cannot authorize downloads; diagnostics read cached metadata without a network request.

## Acceptance

Local/offline: `php tools/ai-assistant-production-regression.php`, the forum AI unit tests, the nine AI browser regressions, and `python tools/forum-tests/ai_reliability_load.py`. These are not proof of a successful real OpenAI request. The existing real PDO acceptance tests run on SQLite, PostgreSQL and MySQL/MariaDB; `forum-ai-reliability-acceptance.php` exercises the real client completion/SSE parser with injected wire frames and verifies failure accounting and queue release. The real production host, signed install/upgrade/DR, browser and staging matrices remain required.

For **publishing refs**, staging must also pass `tools/forum-ai-live-acceptance.php` against the exact verified, extracted signed dist. Configure a dedicated, restricted OpenAI project key in the GitHub Actions secret `FORUM_AI_RELEASE_TEST_KEY`; set a small provider-side budget. The smoke makes one real synthetic request to `gpt-4.1-mini`, max 128 output tokens, using a disposable in-memory SQLite database. It checks SSE, persisted messages, measured usage, released reservations and deletion. No actual member content or key is output. No network calls using this secret are made on ordinary PRs/development branches. For manual staging execution, use a verified distribution and supply the dedicated key via environment, `FORUM_AI_LIVE_ACCEPTANCE=approved-paid-synthetic-test`, then `php tools/forum-ai-live-acceptance.php /path/to/extracted/cajeerengine`. Do not place the key in shell arguments, files tracked by Git, or release assets.

## Signing and publication

Publishing refs require the independently provisioned `FORUM_RELEASE_SIGNING_KEY_B64` secret and `FORUM_RELEASE_TRUSTED_PUBLIC_KEY_B64` variable. Do not reuse a development CI key. The `v1.3.11` baseline tag must refer to the authentic prior source. `forum-ci.yml` builds the immutable source/dist, verifies Ed25519 and the checksums, runs all acceptance jobs, then seals the evidence. `forum-release.yml` downloads the passing commit's artifacts, verifies the pinned key and evidence, stages a draft and checks remote SHA-256 of each uploaded artifact. Configure independent required reviewers for the `forum-production` environment. No private key or signed production binary is supplied by this source patch.

Release status is conditional on green CI, live provider smoke, external deployment approval and signed artifact verification. A successful offline run alone is insufficient.
