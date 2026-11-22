# Forum Edition 1.2.1 — Forum Analytics Integration

## Purpose and scope

The forum now uses the **existing** CajeerEngine 2.1.0 privacy and analytics runtime. There is no second tag loader, credential store, tracking API or Analytics Hub. On a successful public forum SSR response, the server adds the shared consent runtime only if all of the following are true:

1. The database-backed Forum ACP community policy has `analytics_enabled = true` (default is **false**).
2. The resolved page is anonymously accessible and indexable under the forum SEO/ACL policy; it is not an account, ACP, private conversation or preview response.
3. The request is HTTPS and the exact host is present in the core Tracking & Counters host allowlist.
4. The core tracker is enabled and has valid configured counters. All configuration files and assets can be read.

The shared consent runtime reads the versioned consent decision and does not load external tags without applicable consent. When consent is refused, revoked or invalidated by a settings revision, no new optional tracking is permitted. The forum does **not** forward member UUIDs, post bodies, private messages, query parameters, tokens or private URLs to providers.

In 1.2.1 **only page views** are emitted by the inherited browser runtime for eligible pages. Forum-specific write events and native internal analytics are separate 1.2.2+ milestones; this release must not be described as already supporting them.

## Enable and verify

1. Make a backup and update the Forum Edition source to 1.2.1. There is no new SQL migration; the extra community flag is stored with the existing revisioned policy JSON in the database, and older policy JSON is read with `false` by default.
2. In the shared core admin UI configure **Tracking & Counters** (`#/tracking`): enable a real provider, add the exact HTTPS site host to `hosts`, and verify the corresponding counter ID. Set up **Privacy** (`#/privacy`) and **Analytics Events** (`#/analytics-events`) using the same operator account and appropriately scoped permissions.
3. In **Forum ACP → Настройки сообщества** set **Аналитика публичных страниц форума → Разрешить при согласии посетителя**, and save. The write is protected by the existing `forum:acp:settings` permission, optimistic revision check, and audit. Operators without kernel permissions are shown no shortcuts into protected kernel controls.
4. Open an anonymously accessible, indexable `/forum` home, `/forum/boards/{uuid}` or `/forum/threads/{uuid}` page in a fresh private browser session over HTTPS. Until consent, network inspection must show no requests to `mc.yandex.ru`, `googletagmanager.com`, Matomo or Plausible. Accept analytics consent and confirm the configured vendor counter loads and receives a page view. Test withdrawal and page refresh.
5. Visit a restricted discussion, account settings, a personal conversation and the ACP; **none** must receive the forum analytics boot script, even with a previously accepted consent.
6. Change the counter or consent policy and verify the new revision invalidates old consent and old SSR Redis cache versions. Check the browser CSP for approved origins only on eligible pages.

## Failure behavior

Invalid or unreadable core tracking/consent policy, missing consent assets, unapproved host, plain HTTP, disabled forum flag, authenticated or unindexable content: **no forum analytics insertion**. External API failures must not affect discussion rendering or forum writes. Provider-specific report sync uses the existing Analytics Hub (`#/analytics-hub`); report credentials and their synchronization are managed by the core, not by Forum ACP.

## Validation

Run `php tools/forum-analytics-integration-regression.php`, `php tools/consent-regression.php`, `php tools/forum-seo-unit-regression.php` and the full Forum CI. The unit regression checks actual policy writes, conditional script insertion, HTTPS/host filtering, response-level CSP and cache policy versioning. It uses test request/response implementations only when Composer is not installed. A real site with provider credentials is required to claim working external collection; CI or unit tests alone do not prove delivery to vendor analytics.
