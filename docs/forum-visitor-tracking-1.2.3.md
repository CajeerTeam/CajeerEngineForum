# Visitor Tracking & Consent — Forum Edition 1.2.3

## Overview

This release connects the existing CajeerEngine 2.1.0 consent runtime to publicly indexable, anonymous **Forum SSR** page views. It does not create a new analytics SDK, collect IP addresses in a new database, or bypass the existing kernel tracking and event rules.

The Forum ACP community `analytics_enabled` switch defaults to **false**. The kernel **Tracking & Counters** feature must also be explicitly enabled, the public HTTPS hostname must be in its exact host allowlist, and a valid privacy policy must be accessible. Visitors must make an affirmative analytics-category choice before any optional analytics SDK is inserted or a page view is dispatched. If policy fetching fails, optional tracking remains disabled.

## Server-side privacy boundary

Forum Edition injects the shared consent runtime only for a successful GET of an anonymous, indexable `/forum`, `/forum/boards/{uuid}` or `/forum/threads/{uuid}` page whose Forum ACL and site indexing configuration permit public access. It excludes authenticated responses, theme previews, member profiles, private or unlisted threads, internal search, login, registration, account/security pages, personal messages, ACP, and error pages. Other kernel CMS routes keep their own existing behavior.

An eligible server response includes a `data-ce-forum-approved-path` attribute on the shared consent script. Browser tracking requires an exact match of that approved path and the **current** document pathname. A client-side history rewrite or explicit `CajeerAnalytics.pageView(...)` call cannot independently authorize a visit to another forum route or an inaccessible thread.

For an eligible thread/board, only a single validated numeric pagination parameter `page=2` through `page=10000` is preserved in `page_location`. Arbitrary query strings, identifiers in query parameters, search text, invite tokens, fragments, and external referrer paths are never sent. Other page views are reduced to origin plus pathname.

## Browser lifecycle

The shared consent runtime keeps Google Consent Mode denied by default, initializes trackers only after affirmative consent, and sends one event per actual initial public view. It excludes repeated initialization, visibility/policy refresh, and repeated explicit calls for the same URL. A public page-number change or back/forward navigation is a distinct visit; browser BFCache restore rechecks consent before counting. Merely focusing the tab is not another view.

Withdrawal, expired or version-mismatched consent, or a cross-tab storage revocation denies subsequent events. After an SDK has been loaded, withdrawal requests a clean denied-state page reload because removing the original script element alone cannot undo executed third-party code. First-party forum activity counters from version 1.2.2 remain independent of the optional third-party visitor-tracking consent layer.

## Deployment and acceptance

1. Back up the deployment and verify that the active release reports Forum 1.2.3 with core 2.1.0. No new database migration is required.
2. Configure kernel Privacy & Tracking with an approved HTTPS hostname and GA4/Metрика test counters. Enable the Forum ACP analytics switch. Ensure no second GA4 or Metrika tag is installed by a custom theme or tag manager.
3. Verify a public guest thread sends **zero** third-party requests before consent, then exactly one initial page view per configured provider on grant. The same page after refresh should follow the stored consent policy; repeated client calls within a page must not double-count.
4. Test board/thread pagination, back-forward navigation and BFCache; confirm only a bounded `page` parameter appears in outbound URLs. Repeat with sensitive query strings and URL fragments.
5. Verify private and unlisted threads, profiles, authenticated routes, personal messages, account pages and ACP never receive third-party tracking. Revoke consent in the current tab and another tab; verify no further events are sent.
6. Check real provider dashboards (GA4 DebugView and Yandex Metrica diagnostics), CSP headers, cache invalidation after changing consent/tracking settings, and full production browser acceptance. The isolated test mocks do not prove third-party delivery.

Run offline regressions:

```sh
php tools/forum-analytics-integration-regression.php
python tools/forum-visitor-consent-browser-regression.py --browser chromium
python tools/forum-visitor-consent-browser-regression.py --browser firefox
python tools/consent-dom-regression.py
```

The test browser can be installed using Playwright. These tests mock third-party network requests and therefore do not certify a production provider account. For a real site, permission and retention requirements remain subject to the deployment's applicable privacy law and policy.
