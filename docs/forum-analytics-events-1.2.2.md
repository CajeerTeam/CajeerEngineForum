# Forum Event Tracking — 1.2.2

## Installation

Apply migration `0081_forum_analytics_events.sql` via `php bin/cajeer migrate` **before** deploying the new PHP workers. Keep the existing CajeerEngine 2.1.0 configuration and analytics consent policy. In Forum ACP, enable `analytics_enabled`; it remains disabled by default.

## Recorded events

The server records `forum_thread_created`, `forum_post_published`, `forum_post_edited`, `forum_registration_completed`, `forum_reaction_added`, `forum_reaction_changed`, `forum_reaction_removed`, `forum_search_performed` and `forum_attachment_downloaded` into `ce_forum_analytics_events`.

- Mutations are recorded only within the successful SQL transaction; a nested savepoint isolates telemetry write failures so a missing or failing metrics sink cannot block publication. Failed or review-pending publications are not counted until actually published, and identical reaction requests are not counted twice.
- First-page, nonempty searches are counted after successful validation; file downloads only after ACL check and SHA-256 verification. No search term, member ID, file name, body, board ID, thread ID, IP, cookie or other personal identifier enters the analytics ledger.
- Only `id` (random event ID), `event_name` and `created_at` are stored. Records are deleted in bounded batches after 90 days by `forum.analytics.cleanup` (the runtime jobs scheduler).
- On eligible public pages with a present core Consent Runtime and prior analytics consent, successful API actions may additionally emit **parameterless** external events through `CajeerAnalytics.emit`. External event delivery is not attempted on private/authenticated pages; an absent analytics runtime, blocked provider or revoked consent is a no-op. Read-only/operational counters remain independent of external analytics.
- The future analytics aggregation/dashboard release will surface historical counts. Operator-only manual query for validation: `SELECT event_name, COUNT(*) FROM ce_forum_analytics_events GROUP BY event_name`.

## Validation and recovery

Run `php tools/forum-event-tracking-regression.php`, `node tools/forum-tests/analytics_browser_regression.js`, `python tools/forum-tests/analytics_events_sql_regression.py`, and your existing forum CI. Confirm enabled/disabled policy, successful/rolled-back mutation, duplicate reaction and private-content tests on staging. After deploying, check `ForumDatabaseCheck` and `forum.analytics.cleanup` processing. On failure, revert PHP/JS files and restore the database from a verified pre-upgrade backup; leave applied migrations in place until an approved database rollback is performed. Real GA4/Yandex verification requires configured providers and genuine visitor consent.
