# Google Analytics 4 Integration — Forum Edition 1.2.5

Forum Edition 1.2.5 integrates **live, consent-controlled browser events** with Google Analytics 4 (GA4) through the CajeerEngine 2.1.0 Google tag and Analytics Hub. No duplicate `gtag.js`, extra SDK, additional database table, API proxy or server-side GA4 secret is required. Unlike Yandex Metrica goals, GA4 custom events require no advance registration to be collected. This release does not invent conversions from server logs or treat link clicks as completed downloads.

## Enable the integration

1. In the **Google Analytics** account, create a GA4 web data stream for the HTTPS forum origin; copy its Measurement ID (`G-...`). Configure the stream's data-retention and privacy settings. In the GA4 web stream, review **Enhanced Measurement** and disable *page views based on browser history events* if using the Forum's manual `page_view` history tracking, to prevent duplicate standard pageviews. Review automatic outbound-click/file-download measurement separately because Forum-specific custom actions and GA4 enhanced-measurement events have different names.
2. In **Core Admin → Privacy**, publish a working privacy policy with analytics category consent. In **Core Admin → Tracking & Counters**, enable direct GA4, enter the `G-...` Measurement ID, and allowlist the exact lowercase HTTPS forum hostname. `GTM` and direct GA4 cannot be active together in the existing core configuration. Do not install an additional Google tag in themes, plugins or GTM. The core GA4 initialization already uses `send_page_view:false` and Google Consent Mode with default denial.
3. In **Forum ACP → Community settings**, enable **Analytics for public forum pages**. This switch is **off by default**. Six GA4 custom events have usable names by default after the forum and core tracking switches are enabled and the visitor has affirmatively granted analytics consent. Event names can be changed in ACP; an empty value disables one specific event. Custom event names must be unique, lowercase `a-z`, `0-9`, `_`, start with a letter, at most 40 characters, and must not be GA4-reserved or generic built-in event names. Policy saves are revision-checked, permission-controlled and audited. Unmodified older clients preserve already stored GA4 settings.
4. Optional: Use **Core Admin → Analytics Events** to manage the global event policy. A disabled core event pipeline, a disabled or remapped matching custom rule, or `allow_custom:false` without a matching enabled rule prevents the corresponding forum GA4 event. Do not assign the same event name to the automatic core events and Forum events. GA4 DebugView can be enabled under Core Tracking, then disabled after setup. Optional **Analytics Hub → GA4** reporting uses a separately authorized Google Analytics Data API connection; there is no GA4 credential in Forum ACP. The GA4 property resource/credentials are entered only in Analytics Hub.
5. Use GA4 Realtime and DebugView on a **real deployed HTTPS site** to confirm event reception. If events should count as GA4 key events (conversions), configure them as key events in GA4 itself. Browser submission is not proof of acceptance or immediate reporting.

## Actual events

| ACP field / GA4 event by default | Real interaction |
| --- | --- |
| `forum_index_view` | A newly recorded, approved public forum index pageview |
| `forum_board_view` | A newly recorded, approved public board pageview |
| `forum_thread_view` | A newly recorded, approved public thread pageview |
| `forum_paginated_view` | An approved board/thread pageview with valid `page=2..10000` (in addition to its board/thread event) |
| `forum_attachment_click` | A trusted click on a matching Forum attachment download route on an approved public document |
| `forum_outbound_click` | A trusted click on an HTTP(S) external link on an approved public document |

The standard GA4 `page_view` is sent through the **existing** CajeerAnalytics event pipeline once per initial approved view, permitted page-number change, back/forward history navigation or BFCache restore. Additional forum GA4 custom events send **only** `{send_to: 'G-...'}` (and the operator-configured static event name): no user ID, forum UUID, query, title, body, attachment filename, link target, search term or raw referrer is supplied as a custom event parameter. Standard `page_view.page_location` uses only the current origin + approved forum path + optional bounded numeric page; sensitive search parameters and fragments are discarded. Google may itself collect other standard information under its SDK settings; this page does not promise that the provider receives no browser metadata.

User-driven link events mean a **click**, not a successful attachment delivery or completed external navigation. The existing automatic `site_file_download` / `site_outbound_click` events remain separately configurable, so GA4 reports can show multiple differently named events for one action; use consistent event definitions rather than summing them as independent conversions. The runtime skips an overlapping event name when a matching inherited automatic rule already emitted it. No session, registration, post body or authenticated member action is sent to Google by these new forum-specific events.

## Consent, security and lifecycle

Only anonymous, indexable, ACL-authorized SSR GET responses at `/forum`, `/forum/boards/{uuid}` and `/forum/threads/{uuid}` can carry the server-authorized tracking descriptor. HTTPS, exact allowlisted host, core tracking switch, Forum switch and a positive valid analytics consent record are all required. The shared runtime forbids private/unindexed threads, member profiles, authenticated pages, search, account/security routes, messages, ACP, previews, error responses and client-side route hopping. No Google script is inserted before consent; denial or expired/withdrawn consent prevents subsequent dispatch, and withdrawal requests a clean denied-state reload after an SDK has loaded. A policy revision invalidates previous stored consent as already enforced by the core. CSP for an approved response continues to derive trusted Google origins from the core TrackingConfig.

## Release acceptance

Run the included tests after applying **all changed PHP, JS, and admin asset files** and invalidating reverse-proxy caches:

```sh
php tools/forum-analytics-integration-regression.php
python tools/forum-ga4-browser-regression.py --browser chromium
python tools/forum-ga4-browser-regression.py --browser firefox
python tools/forum-visitor-consent-browser-regression.py --browser chromium
python tools/forum-visitor-consent-browser-regression.py --browser firefox
node admin/scripts/publish-admin-assets.mjs --check
php bin/cajeer api:lint --strict --json
```

The browser tests substitute local no-network providers to inspect real event dispatch and browser gestures. They **do not** validate Google network ingestion, the real GA4 account, data refresh in Analytics Hub, GA4 retention/consent compliance, a production CSP, an ad blocker, or a completed production certification. A separately configured live property and operator-run staging verification are required before claiming provider acceptance. No database migration is introduced; CajeerEngine remains 2.1.0.

References: [Google Analytics gtag.js events](https://developers.google.com/analytics/devguides/collection/ga4/events), [single-page application pageviews](https://developers.google.com/analytics/devguides/collection/ga4/views), [gtag.js config and `send_page_view`](https://developers.google.com/tag-platform/gtagjs/configure), and [recommended events](https://developers.google.com/analytics/devguides/collection/ga4/reference/recommended-events).
