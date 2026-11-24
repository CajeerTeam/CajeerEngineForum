# Forum Edition 1.3.8 — AI ACP Insights

## Runtime behavior

Open **Admin → Forum ACP → Аналитическая панель**. Existing 7/30/90/365-day and custom UTC reporting, event breakdown, weekday data, author activity and chart controls remain unchanged. Under **AI ACP Insights**, operators can:

1. Select a UTC date range and metric (all events, page views, actions or one listed event).
2. Click **Показать проверяемые факты** to read server-derived totals, equal-length prior-period data (only when retained), recent and preceding-week counts, peaks and event breakdown without calling OpenAI or using budget.
3. Explicitly agree to send these anonymous aggregates to the configured OpenAI provider, then click **Сгенерировать AI-объяснение**. The panel renders a short narrative, confidence framing (`observation`, `hypothesis`, `caveat`), server-derived evidence beside each claim, links back to applicable chart series, and a CSV export of the report and evidence.

The existing Forum Analytics Dashboard is the authoritative data source. It combines transactionally applied daily counters with unprocessed events without duplication and bounds ranges to the configured 365-day retention window. Previous-period comparisons are omitted, not fabricated, if the full comparison window is no longer retained. Activity is **event totals**, not visitors, logins, attributable revenue, or session retention. Current UTC day may be incomplete. Cohort-based publishing retention remains in the separate existing panel.

## Security, cost and privacy

- New routes: `GET /api/v1/forum/acp/ai/insights` (read-only facts), `POST /api/v1/forum/acp/ai/insights` (billed AI explanation). Both require a genuine same-origin browser session and `forum:acp:settings`; POST also requires CSRF and an explicit `acknowledge_external_processing: true` boolean.
- Permission is checked against **live SQL role status** before and after provider generation. Every read and generated report recomputes the authorized view.
- The OpenAI prompt contains only a bounded JSON of anonymous aggregate counts, period, event codes and aggregation methodology. There are **no** user IDs, IPs, post bodies, private thread contents, individual event records or browser tracking IDs in the prompt.
- Responses run through the existing Responses API, shared money budgets and per-user daily/monthly token quotas. The provider supplies interpretive text and evidence IDs; server resolves the actual numeric evidence and rejects nonexistent IDs, explicit ungrounded digits, oversized or malformed responses. Model output is rendered as text, not HTML. Analytics data can establish correlations, not causal explanations.
- AI transcripts are temporary `search`-purpose conversations, removed after completion or validation failure; only regular quota and cost accounting remains. An unexpected provider error never modifies forum statistics. No new database migrations, jobs, tracking cookies or external analytics collectors.

## Deployment and acceptance

No database migration is required from 1.3.7. Deploy updated PHP, `api/openapi.yaml`, generated SDK files, and both `admin/static` and published `public/admin` assets together. Confirm valid OpenAI key, model price settings, global/user budgets, browser auth and Forum ACP settings permission. For release acceptance:

```bash
php tools/forum-ai-insights-unit.php
php tools/forum-ai-insights-acceptance.php # in CI with PDO SQLite, PostgreSQL, MySQL
python tools/forum-ai-insights-browser-regression.py --browser chromium
python tools/forum-ai-insights-browser-regression.py --browser firefox
php bin/cajeer api:lint --strict --json
php tools/generate-api-sdk.php --check
node admin/scripts/publish-admin-assets.mjs --check
```

A passing source build is **not** a signed production release. Finish required CI, staging, privacy review, actual credential integration and performance qualification before publication.
