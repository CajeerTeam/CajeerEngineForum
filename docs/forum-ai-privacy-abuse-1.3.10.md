# Forum Edition 1.3.10 — AI Privacy & Abuse Protection

This release changes **runtime behavior**, not only API contracts. It requires migration `0090_forum_ai_privacy_abuse.sql` for the selected database. Apply on top of the complete 1.3.9 installation, after backup and while the old PHP workers are stopped.

## Member-owned AI data

- `/forum/account/privacy` offers `ai_conversations` and `ai_messages` in the paginated account data export. Both queries are scoped by the authenticated owner's `user_id` and do not expose CMS AI history or other users' transcripts.
- The same page exposes **Delete all AI history**, which requires a separate browser-confirmed `POST /api/v1/forum/ai/history/erase` with `{"confirmation":"DELETE AI HISTORY"}`. The server obtains the actor from the browser session, validates CSRF through normal routing and refuses to erase a conversation that is still generating. The operation deletes all **ordinary and thread** AI conversations/messages in a transaction. It does not delete cost accounting, usage ledgers, independent moderator cases, FAQ support tickets or core CMS AI history.
- Account closure erases the same AI transcripts and AI quota rows before disabling credentials. It refuses to proceed while an AI response is in flight; retry after generation. Forum posts, moderation evidence, security audit and backups retain their separate legal/operational policies.
- A periodic worker deletes **idle** AI conversations 90 days after their last update; active leases are excluded. Abandoned leases older than one hour are eligible for cleanup after the same 90-day retention period, and their token reservation is counted conservatively as consumed usage. Cleanup is bounded to 100 conversations per scheduled run. Burst counters older than 24 hours are also purged. Worker scheduling occurs through the existing durable forum scheduler, every 15 minutes, and does not depend on member visits. It can take multiple runs to drain a large backlog.

## Provider boundary and prompt injection

- OpenAI Responses requests use `store=false`, TLS validation and no callable AI tools for the forum chat. System instructions are server-owned. Third-party snippets are passed in a **JSON-escaped, explicitly untrusted user-role payload**, never in a system/developer role.
- The existing live ACL checks, source checks, citation verification and operator confirmation on admin actions remain mandatory. The model **cannot** be assumed immune to prompt injection: its text must never be interpreted as an administrative action or authoritative forum permission. Use human review for moderation and administrative changes.
- Existing `AiOutboundSafety` performs credential checks on provider-bound input. User-entered chat content is intentionally transmitted to OpenAI; deletion from the forum database **does not retract already transmitted provider requests** or remove backup snapshots. Inform participants of this before enabling the assistant.

## Abuse protection

- Atomic per-user **5 admitted AI requests per fixed 60-second UTC bucket**, in addition to existing daily requests, monthly tokens and global monetary budgets.
- At most **one active AI generation per user**, regardless of which chat, thread, search, writing, support, moderation or ACP AI entry point is used. All paths reuse `ForumAiChat::send` and thus the same throttle. Parallel requests are serialized by a locked user row and a daily usage row.
- A disabled account cannot open a new AI generation, even if a request was previously authenticated; this is rechecked under the transaction lock, also preventing races with account closure.
- Rollback on failed preflight means blocked requests are never sent to OpenAI and do not consume a quota. Once a request has reached the provider, existing billing accounting rules still apply.

## Acceptance and limitations

- `python tools/forum-tests/ai_privacy_sql_regression.py`: real SQLite DDL, ownership, atomic burst ceiling, retention selection, foreign-key cascades.
- `php tools/forum-ai-privacy-acceptance.php` with `FORUM_TEST_DRIVER=sqlite|pgsql|mysql`: actual PDO, migrations, provider stub at the external HTTP boundary, owner isolation, concurrency/retention and rate enforcement. Requires installed Composer dependencies and the matching PDO driver; non-SQLite tests require disposable DB literally named `forum_analytics`.
- `python tools/forum-ai-privacy-browser-regression.py --browser chromium|firefox`: actual user privacy-page controls, explicit confirmation and client request. The CI matrix executes these tests and the old AI suites.
- Neither test stubs nor static validation certify production network connectivity, privacy compliance or full staging readiness. Complete CI, staged upgrade, HTTPS session/CSRF verification and administrator review remain required before a production rollout.
