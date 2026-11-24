# Forum Edition 1.3.7 — AI Moderation Advisor

Production-source implementation of on-demand AI advice for the **existing** forum moderation queue. The service does not moderate content automatically and does not create independent cases.

## Upgrade

1. Back up the application and database. Stop old workers.
2. Apply `migrations/{pgsql,mysql,sqlite}/0088_forum_ai_moderation.sql` through the usual migration runner for your database. Existing reports and submissions remain intact.
3. Deploy changed PHP source and `public/forum-assets/` plus static/compiled ACP assets, including `public/admin/assets/admin-manifest.json`.
4. Configure the OpenAI API key and enabled forum AI model/limits in the existing ACP settings. This uses the core OpenAI Responses API and the pre-existing atomic token/cost budget; no new API keys or provider tables are required.
5. Test a normal public report and a queued submission under a real moderator account. Confirm HTTP 401 for missing browser session, 403 for users without permissions, and 404/403 for board-inaccessible cases. Confirm that no decisions are made by AI.
6. Run `php tools/forum-ai-moderation-unit.php`, `python tools/forum-tests/ai_moderation_sql_regression.py`, `php tools/forum-ai-moderation-acceptance.php`, and `python tools/forum-ai-moderation-browser-regression.py` alongside the regular release CI on the target DB/browser. Stage before production.

## User experience

Open **Forum → Moderation → complaint/queued submission**, or **Forum ACP → Moderation**. Open a pending case and click **AI-анализ дела**. A confirmation dialog explains that case text (which may be private or unpublished) will be sent to the configured OpenAI provider. Nothing is uploaded until the moderator actively confirms. `GET .../ai` only reads existing advice and does not bill the provider.

The assessment classifies risk as `low`, `medium`, `high` or `uncertain`, lists possible flags (`spam`, `harassment`, `threats`, `privacy`, `phishing`, `malware`, `other`), shows grounded quotes and suggests `review`, `dismiss`, `remove`, or `escalate` as **human review options**, not commands. It never sends sanctions, deletes posts, approves submissions, or resolves reports. The existing moderation action route and audit remain the only way to decide a case. Structured output is validated, and invented quotations are discarded.

## Data flow and privacy

- Every request needs a current browser session and `forum:moderate`. Effective `thread:read` and `thread:moderate` board permissions are recalculated on each read and immediately before storing generated advice. Closed cases and deleted reported posts are excluded.
- An explicit request body `acknowledge_external_processing: true` is always required for provider calls; `include_non_public: true` must also be explicitly confirmed for private boards and unpublished submissions.
- Only the selected case's title, reason, category and bounded text are sent. Typical credentials, email addresses and IP addresses are redacted first. Attachments and other user records are **not** sent. Model context is placed in a user role and explicitly marked untrusted.
- Only structured advice, a SHA-256 fingerprint, model, reviewer and timestamp are persisted in `ce_forum_ai_moderation_reviews`. Raw case content and provider prompts are not persisted in that table. Temporary one-shot OpenAI conversations are erased using the existing transient chat mechanism.
- Results are valid for up to 30 days; expired rows are removed on subsequent assessments (opportunistic cleanup), not by a guaranteed periodic task. An edit to the post, case revision or closure invalidates the visible assessment. Former reviewers lose access when their permissions are revoked.
- Stored AI rationale can still reference case details; database backups and moderator permissions must be protected. These are imperfect model-generated suggestions, not proof of an offence.

## API

`GET /api/v1/forum/moderation/cases/{report|submission}/{uuid}/ai` returns the current recommendation or `available:false` without an OpenAI call.

`POST /api/v1/forum/moderation/cases/{report|submission}/{uuid}/ai` accepts JSON `{ "acknowledge_external_processing": true, "include_non_public": true }` with a browser session, CSRF token and moderator privileges. The same revision/content fingerprint returns the cached result without another bill; a changed case triggers re-analysis. Errors preserve the existing case and moderation workflow. Both endpoints use no-store responses.

## Release acceptance

On SQLite, PostgreSQL and MySQL/MariaDB verify migration ordering, same-case uniqueness, edit/revision invalidation, unauthorized access rejection, zero provider calls before explicit confirmation, correct billing and cost settlement, absence of persisted prompt transcripts, safety on provider failures, and manual-only decisions. All database-bearing acceptance runs must pass in CI; local unit/SQLite SQL/Chromium checks alone do not certify production readiness.
