# Unified Administration Center — Forum 1.4.1

Deploy the changed files together (including the published static assets). No database migration is required; core VERSION remains 2.1.0. `/admin/` is the shared entry point; `/admin/#/forum` remains compatible. Direct links include `/admin/#/forum/analytics`, `/admin/#/forum/ai`, `/admin/#/forum/moderation`, `/admin/#/forum/support`, `/admin/#/forum/settings`, and `/admin/#/system`. Back/forward and reload retain the selected route. The old `/forum/support/operator` remains available.

The overview reads existing forum counts (global administrators only) and existing core metrics (`system:read`). Failed reads are shown as errors, not invented zero metrics. Delegated forum administrators see only permitted sections. Identity/account controls remain available to authenticated users. Scope-filtered menus are not security boundaries: every API continues enforcing existing server permissions, ACL, session, CSRF and revision checks. Navigation and explicit refresh re-read the session's current permissions.

The System page displays the existing bounded queue samples (including capped counts), oldest item age, PostgreSQL connections and lock waiters, Redis statistics and forum HTTP latency buckets. Auto-refresh is opt-in, pauses when the document is hidden and does not overlap requests.

Forum support uses the existing ticket queue and FAQ APIs, with status filters, transcript, public evidence links, revision-bound replies, claim/reopen/resolve and FAQ create/update/delete. Ticket and CMS support data remain distinct. No automatic mutations occur on navigation.

## Acceptance

- `node admin/scripts/publish-admin-assets.mjs --check`
- `python tools/forum-unified-admin-browser-regression.py --browser chromium` (also `--browser firefox` in CI).
- Run the existing Forum ACP, analytics, identity and AI UI suites and complete the required production CI/staging checks. Browser regressions use controlled API responses; real server behavior is verified by the existing integration suites. A passing local UI test is not production certification.
