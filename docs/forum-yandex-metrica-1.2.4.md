# Yandex Metrica Integration — Forum Edition 1.2.4

## What is operational

Forum Edition uses the already shipped CajeerEngine 2.1.0 consent-controlled direct Yandex Metrica tag. A single `mc.yandex.ru/metrika/tag.js` is initialized on a public HTTPS Forum SSR page **after affirmative analytics consent**, never on profile, private, ACP, preview, error or authenticated pages. The server must explicitly authorize a public index/board/thread document after checking Forum ACL, SEO visibility, guest status, and site configuration. The approved path is bound into the consent runtime; history-state changes cannot authorize other thread IDs.

This release adds six independently configurable JavaScript-event goal identifiers in **Forum ACP → Community settings**. Empty names disable the specific goal. Names must be 1–64 characters, start with ASCII letter, contain only ASCII letters, numbers and `_`, and be unique across these six actions:

| Forum setting | When `ym(counterId, 'reachGoal', goal)` fires |
| --- | --- |
| `forum_index_view` | Metrica hit is queued for the public forum index |
| `forum_board_view` | Metrica hit is queued for a public board |
| `forum_thread_view` | Metrica hit is queued for a public thread |
| `forum_paginated_view` | successful Metrica hit with a valid `page=2..10000`, in addition to board/thread goal |
| `forum_attachment_click` | user clicks a forum attachment download link on an approved public page |
| `forum_outbound_click` | user clicks a third-party HTTPS/HTTP link from an approved public page |

Clicks are real trusted DOM interactions, not server-generated measurements. All goal dispatches send **only the operator-configured goal name**, no IDs, search phrases, form values, content, attachment names, URLs or cookie payload from forum code. They do not assert download success or a completed external navigation. Standard page views use the inherited single `ym(id, 'hit', sanitizedLocation)` flow; manual hits and the goals share its consent, counter and route gating. Additional event rules in core Analytics Events remain independently configurable, so operators must avoid mapping them to the same Yandex goals to prevent counting the same user action twice.

## Setup

1. Configure a Yandex Metrica counter under **Core Admin → Tracking & Counters** (`metrika_id`); explicitly enable tracking and add the forum's exact HTTPS hostname to the allowlist. The counter is shared with CajeerEngine; it is not embedded again by Forum ACP. Do not enable a second copy in a theme, GTM or plugin. Direct Metrica and GTM are mutually exclusive in core settings.
2. Publish a reachable first-party privacy policy in **Core Admin → Privacy**, explaining the provider and consent category. Enable Forum ACP `analytics_enabled`. By default, this switch is off and all six custom goals are blank.
3. Create JavaScript-event goals **in the Yandex Metrica counter UI** matching your six configured names, and save the Forum ACP policy. You may configure any subset. Changing the policy is revision-checked, permission-controlled and audited. To read visits and conversion data, configure the already supported **Analytics Hub → Metrica** reporting connection using the counter ID and an authorized read-only OAuth token; synchronization is independent of frontend tag delivery. No OAuth token should be entered into Forum ACP.
4. With no consent, verify Network contains **no** Metrica resources. With analytics consent, inspect `ym(...)` calls and the counter's goal diagnostics for a public index, board, thread and pagination. Check one outbound and one attachment link. Reject or withdraw consent and confirm the runtime stops new dispatches (and requests a clean reload when required).
5. Verify authenticated users, unindexed boards, private threads, member pages, account/security routes, messages, ACP and previews do not load the optional counter. Test duplicate page views and BFCache history restores. Review the site's CSP and any external extensions separately.

## Constraints and acceptance

External provider ingestion, dashboards, installed goal configuration, real account permissions, ad blockers and network connectivity **cannot be certified by offline regression mocks**. The optional Yandex library may collect its own data independently of explicit arguments; account-level controls and compliance must be reviewed separately. Webvisor, link map and automatic link tracking remain disabled by default in the shared runtime. No Metrica API OAuth token, no server-side conversion uploads and no extra tracking of private or logged-in forum activity are introduced. First-party aggregated forum activity counters remain unchanged. No schema migration is required; upgrade existing PHP and JS assets together, then invalidate reverse-proxy caches.

Acceptance commands (real browser tests use local scripted provider stubs):

```sh
php tools/forum-analytics-integration-regression.php
python tools/forum-visitor-consent-browser-regression.py --browser chromium
python tools/forum-visitor-consent-browser-regression.py --browser firefox
node admin/scripts/publish-admin-assets.mjs --check
```

Official JavaScript API: https://yandex.ru/support/metrica/ru/objects/reachgoal and https://yandex.ru/support/metrica/en/code/counter-initialize.
