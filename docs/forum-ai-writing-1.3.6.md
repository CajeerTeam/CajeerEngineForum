# Forum Edition 1.3.6 — AI Writing Assistant

## Deployment

1. Back up the complete Forum 1.3.5 installation and database. Apply this **delta** to the matching baseline. No SQL migration is needed.
2. Keep the inherited OpenAI Responses API key in the core secret store, enable Forum AI in Forum ACP, set a priced model and review member/day, month/token and monetary budgets.
3. Replace `public/forum-assets/forum.js`, `forum.css` and `index.html` together; invalidate CDN caching. Run `php bin/cajeer api:lint --strict --json`, CI and staging before opening production traffic.

## Member workflow

- The editor for a new topic, reply and existing post offers **fix grammar**, **improve clarity**, **format content**; topic editor additionally offers **suggest title**.
- The request sends exactly the current draft text, title and markup mode to the configured OpenAI API when a member presses an AI action. It sends no attached file bytes and does not fetch private threads or other users' drafts.
- A suggestion is returned as **literal text** in a separate preview. Nothing is published, saved or inserted until the user presses **Apply suggestion**. If the editor changed while generation was running, applying fails closed. Existing draft autosave continues to use its own revision model.
- Existing `forum:write`, browser session, CSRF, per-user daily/monthly quotas, core monetary ceilings and temporary lease control are enforced by the normal forum AI pipeline. Temporary `search`-purpose transcripts are deleted immediately on success, while the existing worker reaps interrupted requests.
- Formatting respects the selected `plain`, `markdown` or `bbcode` mode. Result text should be reviewed before use; the model may introduce inaccuracies. The server never performs a publish/edit call itself.

## Acceptance

Required: `php tools/forum-ai-writing-unit.php`, `php tools/forum-ai-writing-acceptance.php` (real PDO on SQLite, PostgreSQL, MySQL/MariaDB), and `python tools/forum-ai-writing-browser-regression.py --browser chromium` plus Firefox in CI. External OpenAI deliveries and production budgets require staging verification.
