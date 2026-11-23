# CajeerEngineForum 1.3.2 — Contextual Thread Assistant

This release adds a **working thread AI** to the existing Forum AI Chat and OpenAI Responses API integration. It does not introduce a second OpenAI key, standalone unmetered LLM client or another chat database.

## Installation

1. Back up PostgreSQL/MySQL/MariaDB/SQLite and stop application/worker processes while applying this patch.
2. Apply exactly one migration from `migrations/{pgsql,mysql,sqlite}/0085_forum_thread_ai.sql` after `0084` through the normal migration runner. Existing `thread_id = NULL` conversation rows remain general chat; thread-scope conversations have a non-null thread UUID.
3. Deploy **all changed files** (including `public/forum-assets/forum.js`, `forum.css`, the updated PHP app and OpenAPI). Invalidate edge/static caches and restart PHP worker processes.
4. Configure the OpenAI API key in the core AI module. Enable Forum AI Chat in ACP. The same model selection, daily request limit, monthly token limit and shared provider monetary ledger apply.
5. Open a **guest-visible public topic** as an authenticated member. A panel offers summary, explanation and free-form questions with streamed responses, saved thread conversations and server-provided links to message anchors.

## Data boundaries

- Users must hold a current active account/session and current `thread:read` permission. A second guest ACL check forbids all private, hidden, authenticated-only or moved-to-private threads from sending content to OpenAI, even for moderators.
- The prompt is built from the **first plus latest 23 non-deleted posts**. This is an intentionally bounded snapshot, not guaranteed to include every message in long discussions. Post content is untrusted data in a user-role input, with a higher-priority instruction not to obey embedded commands. For follow-up prompts, only earlier member questions are included: old AI answers are retained for display but not resubmitted because they might quote posts that have since been deleted.
- The provider sees the chosen live post excerpts and the member's question; it never receives account profile information, private messages, moderator notes, external AI keys or deleted posts.
- Citation links (`/forum/threads/<id>?page=<n>#post-<id>`) are calculated from actual currently visible post positions and stored alongside the exact AI answer, so follow-up history does not silently substitute the sources of a newer answer. A thread may subsequently be edited or moved; links should not be treated as immutable evidence.
- Thread AI sessions are scoped by both user and topic. They do not appear in general Forum AI Chat or CMS AI history. Users can delete their own thread sessions even after losing read access by providing their existing conversation ID.
- Requests may fail on provider timeout, budget exhaustion or loss of access. A failed or interrupted stream must not be treated as a committed answer; refresh the conversation list to confirm persistence.

## Validation

- `python tools/forum-tests/thread_ai_sql_regression.py` — SQLite upgrade and cross-thread ownership SQL tests.
- `python tools/forum-thread-ai-browser-regression.py --browser chromium` (also Firefox in CI) — interactive summary, questions, SSE and server-derived sources.
- `FORUM_TEST_DRIVER=sqlite|pgsql|mysql php tools/forum-thread-ai-acceptance.php` — actual PDO/ACL/provider-boundary integration, no skipped assertions; the provider HTTP boundary is the only mock.
- `php bin/cajeer api:lint --strict` — routes, OpenAPI and permission descriptions.

**Not independently certified:** provider billing/response acceptance against a real key, cross-database CI, staging load/soak, operational deployment and signed release approval. Do not label the source-only patch as a certified production distribution.
