# CajeerEngineForum 1.3.1 — Forum AI Chat

Production implementation on CajeerEngine Core 2.1.0. This release adds a real, member-only chatbot built on the core OpenAI Responses transport, not a simulated server or an endpoint declaration.

## Upgrade from 1.3.0

1. Back up the application, database and encrypted core AI settings; stop any old workers during deployment.
2. Deploy the **complete set** of changed files in the 1.3.1 patch, including updated public Forum JS/CSS and published ACP assets. Do not overwrite unchanged 1.3.0 files.
3. Run the ordinary installer/upgrade migration command for the selected driver, including `0084_forum_ai_chat.sql`. The migration creates `ce_forum_ai_settings`, `ce_forum_ai_conversations`, `ce_forum_ai_messages`, `ce_forum_ai_usage`, `ce_forum_ai_daily`. No existing CMS AI history is migrated or exposed.
4. Configure OpenAI credentials in the existing **Core AI** ACP (or set `OPENAI_API_KEY` in the private server environment). Set verified current per-model prices and hard global/user USD budgets there. API usage is billed by OpenAI separately from a ChatGPT subscription.
5. In **Forum ACP → AI**, enable the assistant, select an eligible model (empty = inherited core model), configure daily request and monthly token limits and output tokens. It is disabled by default.
6. Verify HTTPS, session login, CSRF, model access and the exact ability to use `/forum/ai` on staging. Run the disposable database acceptance suite; production-ready requires the entire signed CI/release gate to pass, not just a local source ZIP.

## Supported workflows

- Authenticated member `/forum/ai`: start conversation, send and stream assistant output via SSE, reopen history, rename or permanently delete own conversations. SSE deltas are displayed as *text*, never HTML.
- REST: `GET /api/v1/forum/ai/status`, `GET /api/v1/forum/ai/conversations`, `GET /api/v1/forum/ai/conversations/{id}`, `PATCH` and `DELETE` the same conversation path, `POST /api/v1/forum/ai/chat` (JSON), `POST /api/v1/forum/ai/chat/stream` (SSE).
- ACP: `GET /api/v1/forum/acp/ai` and `PUT /api/v1/forum/acp/ai` with `forum:acp:settings` and browser CSRF. Updating requires the current settings revision and is audited.
- Provider errors release the reserved money/tokens and never save an incomplete assistant answer. A successfully delivered provider response is persisted together with final token accounting in a SQL transaction. Expired generation leases can recover.

## Safety, boundaries, accounting

- Forum chat uses dedicated SQL tables keyed to the authenticated **member's own user ID**, not core CMS AI conversations, admin privileges or private knowledge sources. The user ID is always derived from the browser session; clients cannot set `user_id`.
- 100 conversations per member; up to 180 stored messages per conversation, 16 recent messages in prompt context. Input maximum is 6000 UTF-8 **bytes**; max prompt JSON is 30,000 bytes. Default daily request limit 20 and monthly token limit 100,000 are ACP-configurable.
- Monthly token reservation is atomic. Request attempts that reach the provider count toward daily quotas even on transient provider failures. Shared daily/monthly monetary reservations apply in addition to forum quotas; zero/unrecognized model prices are refused to prevent unaccounted spend.
- Existing session cookies, SameSite/CSRF, IP rate limiting, browser-only OpenAPI security and `Cache-Control: private, no-store` apply. Raw OpenAI credentials are never returned to browsers or stored in forum tables.
- User messages *are sent to the OpenAI API* and stored in the forum SQL database until the member deletes the conversation or associated account is deleted. Adjust privacy notice/backups and retention rules for your jurisdiction.
- **This version does not read forum topics, private subforums, direct messages or CMS knowledge.** Contextual search and RAG are subsequent roadmap milestones. No claim of forum-wide intelligent search is made.

## Test and release gate

- `php tools/forum-ai-chat-acceptance.php` with `FORUM_TEST_DRIVER=sqlite|pgsql|mysql` (real PDO; OpenAI HTTP mocked at the transport seam only). Database and ledger operations run against actual SQL, and all failures fail CI.
- PostgreSQL/MySQL variants only run against a disposable database literally named `forum_analytics`, never a production database; CI prepares that database. SQLite runs in-memory.
- `python tools/forum-tests/ai_chat_sql_regression.py`, `php bin/cajeer api:lint --strict`, `node admin/scripts/publish-admin-assets.mjs --check`, `node --check public/forum-assets/forum.js`.
- PostgreSQL 17/18, MySQL 8.4 and MariaDB 10.11 acceptance jobs are mandatory before release promotion. Confirm end-to-end OpenAI streaming on a staging installation with a limited-budget key; mocked HTTP tests cannot certify external credentials, provider availability or production traffic.
