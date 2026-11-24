# Forum Edition 1.3.9 — Controlled Admin AI Actions

## Runtime workflow

Forum ACP → **AI-действия**. Sign in with a browser session and a live `forum:acp:settings` privilege; for FAQ operations also require `forum:acp:members`. Configure the core OpenAI API key, activate Forum AI and budgets. The administrator selects an action, optionally chooses an already published FAQ, writes instructions, and explicitly consents to sending the selected public context to OpenAI.

`POST /api/v1/forum/acp/ai/actions` generates a structured change suggestion through the existing billed OpenAI Responses API. It **does not modify** the site. The response contains the exact before/after state and digest. Only selected public community copy and published FAQ content are sent to OpenAI, never unpublished FAQ or credentials. Temporary AI transcripts are removed.

`GET /api/v1/forum/acp/ai/actions` lists the owner's most recent 40 proposals; `GET /api/v1/forum/acp/ai/actions/{id}` returns a specific current proposal. Proposals last 15 minutes. To act, inspect the proposal and explicitly send `POST /api/v1/forum/acp/ai/actions/{id}/decision` with `{ "decision": "approve" | "reject", "digest": "<the exact 64-character preview digest>" }`. Browser CSRF/session checking is enforced at the route middleware.

Approval uses the **existing** community policy save or support FAQ save methods. In one database transaction it revalidates account status, current scopes, stored source revision, current content, proposal state and digest before execution. Applied/rejected decisions are persisted and audited; replaying a decision fails. Changing community settings concurrently invalidates a prepared proposal. Draft FAQs are created as **unpublished**; FAQ edits retain publication status. AI has no actions for bans, moderation decisions, role changes, user data or ACL.

The migration `0089_forum_ai_admin_actions.sql` must be applied for the active SQL driver (PostgreSQL, MySQL/MariaDB, SQLite). Actor-owned proposals are removed on account deletion and closed/expired proposals older than 30 days are periodically pruned during new proposal writes. The independent audit log remains under the platform's retention policy.

## Acceptance

Run `php tools/forum-ai-admin-actions-unit.php` and `python tools/forum-tests/ai_admin_actions_sql_regression.py` locally. In CI, `php tools/forum-ai-admin-actions-acceptance.php` must pass with `FORUM_TEST_DRIVER=sqlite|pgsql|mysql`, plus Chromium/Firefox `tools/forum-ai-admin-actions-browser-regression.py`. These simulate only external HTTP to OpenAI; the PHP acceptance exercises real SQL, ACL, token accounting and policy/FAQ mutation. Do not certify the release until CI, staging, backup and release signing succeed.
