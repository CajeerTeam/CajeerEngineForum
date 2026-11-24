# Platform Automation & Workflows — Forum 1.4.4

## Upgrade and operation

Back up the database and pause workers/PHP processes before overlaying the changed files. Run the standard migration command for the configured driver to apply `0092_platform_workflows.sql`, then start the existing default queue worker. No second cron daemon, external service, AI key or additional credentials are required. Redis jobs are wakeups; SQL is the authoritative rule/event/history store. A non-Redis worker drains SQL directly, as does a worker whose Redis wakeup handoff fails.

`/admin/#/platform-workflows` is restricted to a global administrator (`*`) in a secure browser session. Mutation and preview require CSRF and fresh authentication assurance. Obtain the operator's UUID from Identity Center. The editor defaults to a disabled rule; use the preview before enabling it. Changes and removal use optimistic revisions. A rule edited by another authorized administrator is owned by the last approving administrator. The scheduler checks that the approving account remains active, unsuspended and privileged; support assignment additionally checks current operator privileges. Loss of either permission pauses the rule; an administrator must explicitly save it again.

## Rules

| Trigger | Action | Condition |
| --- | --- | --- |
| Support requested (creation, member reply or explicit handoff) | Assign support | Only that ticket, open, needs human, currently unassigned. |
| Schedule, 60–86400 seconds | Assign support | Oldest eligible unassigned open tickets with minimum age since last ticket update, 0–604800 seconds, max 50. |
| Schedule | Aggregate analytics | Minimum pending events, 0–1000; process at most 500 using the existing atomic rollup. |
| Schedule | Clean analytics | Existing 90-day applied raw / 365-day daily retention, no custom deletion policy. |

Preview is read-only. It shows a bounded eligible count (not a global exact count) for assignment and aggregation. An event-triggered assignment preview estimates queue eligibility; it does not claim any ticket. Cleanup preview explains fixed retention rather than estimating deleted rows. An enabled support event rule may claim a newly created ticket before optional AI assistance finishes; the support service's revision guard prevents late AI output from replacing an assigned human workflow. Already assigned tickets, closed tickets and tickets awaiting the member are not modified. The usual support queue remains the destination; the action neither sends an email nor closes a case. Existing scheduled analytics maintenance remains enabled and safe to run concurrently with workflow aggregation.

## Execution and limits

Maximum 30 rules. Event capture occurs in the same database transaction as the member support mutation, with a unique rule/revision/ticket/revision key. Only ticket IDs and revisions are queued, not messages or subjects. Deleted tickets remove pending events. Changes to a rule invalidate queued events of earlier revisions. No action emits the support-request trigger, so these actions cannot recursively activate one another. A single SQL lock serializes rule changes and execution; guarded ticket revision updates prevent overwriting another operator. SQL action, audit, run history and cursor advance/dequeue are atomic. Transient database failures roll back work; a later worker tick can pick it up. Non-database failures are recorded as failed with no arbitrary exception contents. Schedules coalesce missed intervals to one catch-up, rather than replaying all missed executions. Each wakeup processes at most ten items by default, with a three-second inter-item budget (not a hard SQL timeout). Existing database and worker timeouts still apply. Failed scheduled actions get another opportunity at the next interval. Failed event actions are recorded, not blindly retried; an administrator can use the scheduled routing rule or the existing support queue.

History exposes 100 recent metadata-only runs (`done`, `no_match`, `revoked`, `stale`, `failed`) and is pruned after 90 days in bounded batches. The core SQL audit records saves/removals/executions. History is not an audit backup. Operators should configure normal monitoring and database backups.

No arbitrary scripts, SQL, URLs, external notification delivery, privilege changes, AI tool execution, automatic moderation sanctions or destructive custom retention rules are allowed. AI background automation and human-confirmed AI actions remain available in their original interfaces.

## Acceptance

`php tools/forum-platform-workflows-unit.php`, `python tools/forum-tests/platform_workflows_sql_regression.py`, `python tools/forum-platform-workflows-browser-regression.py --browser chromium`, and `php tools/forum-platform-workflows-acceptance.php` (real PDO, disposable database only). CI requires the last script on SQLite, PostgreSQL and MySQL/MariaDB, and the browser test on Chromium and Firefox. The source delta is not a signed dist; require normal release CI and staging acceptance before publication.
