# CajeerEngineForum 1.3.3 — AI Forum Search

## Installation

Update **all files included in the delta ZIP** from the original 1.3.2 tree. Run migration `0086_forum_ai_search.sql` for the configured PostgreSQL, MySQL/MariaDB or SQLite driver before upgrading PHP workers. The upstream engine remains 2.1.0. Apply 0084 and 0085 first if upgrading from an older version. Refresh `/forum-assets/forum.js` using the updated `edition_version` cache key.

Configure the OpenAI API key through the existing core AI settings, and enable Forum AI under Forum ACP. Search uses the same model, daily request cap, monthly token ceiling and shared currency budget as Forum AI Chat. The API key is never sent to the browser.

## User experience

The existing `/forum/search` page displays a separate **AI-поиск по форуму** panel to logged-in visitors who have entered a query. Users explicitly initiate an external OpenAI request. Sources stream first, followed by the answer deltas and a final citation-integrity status.

GET `/api/v1/forum/ai/search?q=<question>` returns search evidence **without calling OpenAI**. POST `/api/v1/forum/ai/search` returns a complete AI answer; POST `/api/v1/forum/ai/search/stream` streams `sources`, `delta`, `done` or `error` SSE messages. All three require a browser session; the POST methods additionally require CSRF. No tokens or private post bodies are sent to the browser or provider.

### Evidence and access controls

- The engine reuses indexed `ThreadRepository::search()` with both `posts` and `threads` matching, rather than a separate approximate index or fabricated recommendations.
- Only the intersection of the **current user's ACL** and the **anonymous public-reader ACL** can become OpenAI evidence. Private, logged-in-only, deleted, ignored/blocked-author and inaccessible forum content cannot be used as a source. Search ACL is evaluated again on each request.
- Snippets originate from live published posts in a consistent database read snapshot, never from generated model output. Result list is limited to 10 cited sources; input terms and context bytes are bounded.
- Real, page-aware post permalinks are constructed on the server and reconstructed from validated IDs in the browser. The model is never trusted to invent URLs.
- Source text is sent to Responses API as **untrusted user material**, never a system message. The assistant is instructed not to follow forum-post instructions and to answer only using supplied excerpts.
- Numeric `[N]` markers are checked against actual source numbers. This verifies **citation integrity**, not whether each factual assertion is logically entailed by the cited source; readers can open the excerpts to verify claims.
- With no public evidence, the server returns an explicit no-evidence answer and **does not contact OpenAI or charge the quota**.

### Privacy and temporary sessions

Search is one-shot. Migration 0086 adds `purpose` to forum conversations, migrating existing thread chats to `thread` while normal chats remain `chat`. Generated search requests are stored temporarily under `search` to reuse transactional quotas and usage accounting; on successful completion the temporary user/assistant messages are erased. Expired/interrupted search leases are reaped by the existing AI chat transaction and a dedicated 15-minute scheduled SQL cleanup job (100 rows per batch; max 500 per tick). Other forum AI endpoints cannot list or read search sessions. Standard AI chat and in-thread AI histories are unchanged.

## Verification and operational acceptance

Required CI tests: `tools/forum-ai-search-acceptance.php` on SQLite, PostgreSQL and MySQL/MariaDB; full SQLite migration/FTS test `tools/forum-tests/ai_search_sql_regression.py`; Chromium and Firefox `tools/forum-ai-search-browser-regression.py`. Strict OpenAPI lint and existing AI-chat/thread-AI regression are also required.

**Go-live conditions:** run CI against real configured PDO engines, verify public/private boards and index freshness on staging, test a valid OpenAI key and provider outage, review quota and budget alerts, check SSE behind the deployment proxy, and approve the signed artifact. Offline browser stubs and SQL-only tests are not a substitute for those steps.
