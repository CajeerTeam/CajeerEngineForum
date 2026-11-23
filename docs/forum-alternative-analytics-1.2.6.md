# Alternative Analytics Providers — Forum Edition 1.2.6

This release wires **live browser activity** on anonymous, indexable public forum pages to Matomo, Plausible and Google Tag Manager (GTM), and to Cloudflare Web Analytics for page views and RUM. It reuses the CajeerEngine 2.1.0 consent runtime, tracker settings and CSP: no duplicate client SDK, visitor tracking database, backend proxy or migration is introduced.

## Production setup

1. Configure **Core ACP → Tracking & Counters**. Enable counters for **exact HTTPS hosts** and configure **one** of:
   - **Matomo**: valid Site ID and HTTPS base URL (the `<base>/matomo.js` and `<base>/matomo.php` endpoints must be reachable). For a self-hosted Matomo, add its exact HTTPS origin to `CONSENT_SCRIPT_ORIGINS` and allow it in your CSP. Events use Matomo category `Forum` and action names from the Forum ACP; `requireConsent` and cookie disabling remain active.
   - **Plausible**: site domain and HTTPS base URL (default `https://plausible.io`). The existing core sends real browser POST requests to `/api/event` with `Content-Type: text/plain`, valid JSON payloads, no credentials and a minimized public URL. Add custom-event goals in the Plausible dashboard if you need them in goal reports.
   - **Google Tag Manager**: container ID `GTM-...`. GTM is **mutually exclusive** with direct Metrica, GA4, Matomo, Plausible, Cloudflare and advertising tags in Core ACP. Configure **Custom Event** triggers for `ce_forum_index_view`, `ce_forum_board_view`, `ce_forum_thread_view`, `ce_forum_paginated_view`, `ce_forum_attachment_click`, `ce_forum_outbound_click` and the usual `ce_page_view`, with your desired GA4/Plausible/other destinations. The application sends to `dataLayer` and does not automatically publish/configure your external GTM container. Only after **analytics + marketing + functional** consent does the container load.
   - **Cloudflare Web Analytics**: 32-character public site token. **Disable Cloudflare's automatic beacon injection** and use the manual tracker managed by the core. Official Cloudflare beacon is loaded after analytics consent only on server-approved public pages, excluding URLs containing any query or fragment not preserved by the URL sanitizer. Cloudflare currently **does not support custom events**; it provides normal page views and Core Web Vitals / RUM, not forum-specific goals. Do not proxy synthetic events into `/cdn-cgi/rum`.
2. Enable **Forum ACP → Community policy → Analytics on public pages**. Default is OFF. Configure up to six names for each of Matomo, Plausible and GTM. Defaults: `forum_index_view`, `forum_board_view`, `forum_thread_view`, `forum_paginated_view`, `forum_attachment_click`, `forum_outbound_click`. An empty name disables that specific provider event. Names must be unique and meet the `^[a-z][a-z0-9_]{0,39}$` policy with reserved prefixes/names excluded.
3. For operators using **Core ACP → Analytics Events**, keep rules enabled for each mapped destination (or `allow_custom` enabled). A disabled or remapped core event rule prevents its forum event from firing. The core's general `page_view` and automatic outbound/download events are separate, with matching names deduplicated.
4. Run the actual public HTTPS forum as a **guest** on an allowed host and give affirmative consent. Test `/forum`, a public board/thread and `?page=2`, then click an attachment or external link. Test opt-out, consent revocation, accounts/ACP/private threads, duplicate back navigation and blocked cookies. Examine Network, Matomo Live Visitors, Plausible Goals and GTM Preview/Tag Assistant. Core `CajeerTracking.getStatus()` reports loader state, **not vendor ingestion**.

## Privacy and dispatch rules

- The server must have independently verified that an SSR request is **GET over HTTPS**, a matching host, **guest**, public and indexable by ACL, and a public `/forum`, `/forum/boards/<UUID>` or `/forum/threads/<UUID>` route. Preview, profiles, private messages, restricted discussions and ACP are excluded. Client history rewrites cannot increase authorization.
- The existing consent version, runtime and script loader are authoritative. A missing/invalid provider configuration or withdrawn/expired consent blocks all provider events. The Core ACP provider IDs and allowed hosts remain mandatory.
- For Matomo and Plausible, only a sanitized URL (public origin/path plus a bounded numeric `page`) is forwarded. Custom events do not include thread IDs as parameters, link destinations, titles, search strings, member IDs or attachment names. GTM only receives a validated `ce_`-prefixed event name for forum actions.
- When an automatic download/outbound event has exactly the same configured destination as the forum event, the forum-specific call is suppressed to avoid duplicate conversions. Client-side events reflect real **link clicks**, not a completed download. Server-side metrics remain authoritative for successful operations and are not sent to third parties retroactively.
- Cloudflare's beacon reports directly based on the browser document. To avoid exposing non-public query details, its loader is suppressed if the raw page URL differs from the sanitized public URL (including extra query fields, invalid page numbers and fragments). The GTM container has the same restriction because it can inspect the document URL. Cloudflare may still observe the client's full request as a proxy before consent if your site is proxied through it; Core beacon controls do not disable Cloudflare edge logs.

## Test commands

```bash
php tools/forum-analytics-integration-regression.php
python tools/forum-alternative-analytics-browser-regression.py --browser chromium
python tools/forum-visitor-consent-browser-regression.py --browser chromium
python tools/forum-ga4-browser-regression.py --browser chromium
node admin/scripts/publish-admin-assets.mjs --check
```

The browser tests substitute the third-party network endpoints and validate the **real production JS calls** and payloads. They do **not** prove third-party reception. Production sign-off additionally requires real account property configuration, staging HTTPS/CSP tests, and the repository's signed release admission.
