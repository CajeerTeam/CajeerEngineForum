# Forum Edition 1.3.11 — AI Performance & Reliability

Upgrade from 1.3.10 with a database backup, stopped old PHP workers and queue consumers, and migration `0091_forum_ai_reliability.sql` for your database. Do not mix old and new AI workers: only the new version participates in global admission. Restart PHP and the existing managed queue worker after migrating. Core version remains 2.1.0.

## Admission and backpressure

All forum AI entry points use the same SQL FIFO admission (not the CMS AI system). Four active slots and up to sixteen waiting requests are shared between web workers. Only the ticket ID, member ID, order, state and expiry are stored. The request waits for at most two seconds between polls under normal DB responsiveness; no SQL transaction remains open while waiting. Duplicate requests by the same member are rejected; queue saturation and wait expiry return 429, provider cooldown returns 503. SSE endpoints surface an error event instead of replaying the request. The queue is an admission queue, not deferred asynchronous generation. PHP/HTTP clients must not automatically replay a timed-out paid POST.

Active admission expires after 240 seconds; the provider transport has a 5-second connect limit, 90-second overall limit and low-speed guard. An explicit provider 429 honors bounded numeric Retry-After (1–120 seconds, 30-second fallback); network/5xx errors trigger a short shared cooldown. There is no automatic resubmission after uncertain delivery. Once admitted, a client disconnect does not cancel settlement: PHP continues the bounded provider operation so actual usage can be recorded if available. Queue limits are deliberately fixed to conservative values; size PHP-FPM and upstream timeouts accordingly (including DB lock delays and SSE). The SQL worker sweeps abandoned tickets. A slow database or PHP process suspended beyond the lease interval can invalidate its reservation, so monitor workers and avoid unsafe manual lease deletion.

## Context and billing

Older complete history turns are dropped from the provider input when needed to fit the existing 30,000-byte cap. The current user message, system instructions and fresh evidence remain intact; stored history is not modified. Token reservation is still conservative (serialized UTF-8 input bytes plus overhead and output cap); monetary reservation separates the input and output rates. Measured usage is settled normally. A known preflight refusal or definitive HTTP rejection releases the reservation; a delivery failure or incomplete response with uncertain usage consumes the reserved amount as `unverified`, including reserved user tokens. These are conservative estimates, not actual OpenAI invoice entries. Reconcile against provider billing in the existing cost tools.

Abandoned conversation reservations are recovered before cleanup, before a member erases a conversation, and in the scheduled AI cleanup worker. Expired money reservations are charged conservatively by the same worker. All operations are idempotent; no prompt is written to the queue. Retention of ordinary/thread history remains 90 days; abandoned temporary search transcripts remain subject to short cleanup.

## Operations

Use the existing core AI cost report for measured/unverified costs, and inspect the SQL queue without fetching member IDs: `SELECT active, COUNT(*) FROM ce_forum_ai_admission WHERE expires_at > <current Unix seconds> GROUP BY active;`. The singleton `ce_forum_ai_admission_state.cooldown_until` is a UTC Unix timestamp. Do not reset it during an outage or purge live tickets. Worker statistics include `ai_admission_cleaned` and `ai_cost_recovered`. No new external service or credentials are required.

## Acceptance

`php tools/forum-ai-reliability-acceptance.php` (disposable database, real PDO, mocked provider boundary) and `python3 tools/forum-tests/ai_reliability_load.py` (concurrent SQL contention) supplement existing tests in CI. Before deployment, test all three DB drivers, actual OpenAI credentials, SSE through the production reverse proxy, real parallel PHP workers, timeouts, rate limits, worker recovery and budget reconciliation on staging. The SQLite contention regression is not a benchmark of end-to-end OpenAI throughput.
