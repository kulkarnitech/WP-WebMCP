# WebMCP Layer for WordPress: live-site testing process

This runbook is for validating the plugin after installation on a real WordPress site. It separates safe production smoke checks from destructive or high-volume tests that must run on a staging clone.

## 1. Safety rule

Use this order:

1. Take a full database and files backup and confirm that it can be restored.
2. Clone the site to staging (same PHP, WordPress, cache, proxy, WooCommerce, PMPro, BuddyPress/BuddyBoss versions).
3. Run the complete test matrix on staging with disposable users and content.
4. Install the same plugin build and settings on production.
5. Run only the production smoke checks in section 6.

Do not run concurrency, rate-limit exhaustion, coupon/order creation, activity posting, or malformed-input fuzzing against a live production site. Never use a real payment gateway, real customer account, or real personal data for test fixtures.

## 2. What the site must provide

### Access and observability

- HTTPS, because WooCommerce mutations require a same-origin `X-WP-Nonce`.
- An administrator account with `manage_options` access to **Settings → WebMCP Layer for WordPress**.
- A disposable test administrator, author/editor, subscriber, and anonymous browser session. Do not use the site owner’s account for repeat tests.
- A way to inspect PHP/web-server/WAF logs and WordPress debug logs. Enable verbose logging only on staging; redact cookies, nonces, tokens, email addresses, and payment data before sharing logs.
- Backup/restore access, WP-CLI or an equivalent hosting control panel, and the ability to clear the site/object cache.
- The proxy/CDN configuration, especially whether `REMOTE_ADDR` is the real client IP. The plugin intentionally does not trust `X-Forwarded-For` by default.

### Version and integration baseline

Record these before testing:

| Item | Record |
| --- | --- |
| WordPress/PHP/plugin versions |  |
| WooCommerce version and HPOS status |  |
| Paid Memberships Pro version and membership levels |  |
| BuddyPress or BuddyBoss version and enabled REST components |  |
| Persistent object cache (Redis/Memcached) or database transients |  |
| CDN/WAF and cache rules for `/wp-json/` |  |
| Site URL and REST base | `https://example.com/wp-json/webmcp/v1` |

### Disposable fixtures

Create and record IDs for:

- One public post or page.
- One password-protected post and one private/draft post.
- One PMPro-restricted post, one non-member user, and one member user (if PMPro is active).
- One published, purchasable WooCommerce product; one non-purchasable/out-of-stock product; one test coupon; and a disposable cart session.
- One public BuddyPress/BuddyBoss activity item, one private group, and one user who cannot post (if the community integration is active).

Keep a small fixture sheet with IDs, expected visibility, and the account that owns each session.

## 3. Configure a safe baseline

On staging, open **Settings → WebMCP Layer for WordPress** and record a screenshot/export of the settings before changing them.

Recommended starting values:

- Master switch: enabled.
- `site_search` and `site_get_post`: Public only if the site intentionally wants public discovery; otherwise require `read`.
- Woo cart view/add: `read` and nonce-protected.
- Woo cart remove/coupon: leave at the migrated `read` default; use Public only after an explicit product-owner decision.
- `bp_activity_create`: disabled; when testing it, enable temporarily and keep `publish_posts`.
- PMPro and community read tools: `read` unless the site deliberately exposes them publicly.
- Rate limiting: enabled. On staging use a short window and low limits (for example, 10 seconds and 3 requests per principal/tool) so behavior is easy to observe. Restore production values before the production smoke test.
- Request-size limit: leave enabled; temporarily use a small staging value only when testing the 413 path.
- Idempotency cache: enabled with a short staging TTL (for example, 60 seconds).

After saving, reload the page and confirm that existing settings were preserved and that the migration schema is `2`. A previous `read` value for `bp_activity_create` must not silently remain permissive; the migration should make it `publish_posts`.

## 4. Test method and evidence

Use browser DevTools, Postman, or `curl` with separate cookie jars for each test account. For every case record:

- timestamp, environment (`staging` or `production`), account/session, tool, and request URL;
- HTTP status, WebMCP error code, response headers (especially `Retry-After` and `X-WebMCP-Idempotent-Replay`), and a sanitized response body;
- before/after fixture state (cart item count, activity count, order count);
- relevant log lines and a screenshot for settings/discovery failures.

Do not paste authentication cookies, nonces, authorization headers, or customer data into tickets or chat.

## 5. Staging test matrix

### A. Install, upgrade, and discovery

| ID | Action | Expected result |
| --- | --- | --- |
| A1 | Activate the plugin with integrations inactive | No fatal error; only WordPress/WebMCP routes load. |
| A2 | Activate with WooCommerce, PMPro, and BuddyPress/BuddyBoss active | No PHP warnings/fatals; integration tools appear only when the integration exists. |
| A3 | Visit `/wp-json/webmcp/v1/manifest` and `/wp-json/webmcp/v1/discovery` | Valid JSON; disabled tools and tools the current visitor cannot execute are not advertised. |
| A4 | Toggle a tool off, save, and repeat discovery and direct request | Tool disappears from discovery and direct use returns `webmcp_tool_disabled` (403). |
| A5 | Deactivate/reactivate and upgrade from a pre-remedy settings row | Existing choices remain; schema version becomes 2; secure mutation defaults are applied. |

### B. Public content and privacy

Use a browser/session that matches the configured capability. If the capability is Public, test anonymously; if it is `read`, test with the subscriber.

| ID | Request | Expected result |
| --- | --- | --- |
| B1 | `GET /wp-json/webmcp/v1/post?id=<public-id>` | 200 with title/content limited to the documented shape. |
| B2 | Same request for password-protected, private, draft, or non-public post | 404 and no content/title leakage. |
| B3 | Same request for PMPro-restricted post as non-member | 200 paywall marker only; no protected content. |
| B4 | Same request as an entitled member | 200 with content. |
| B5 | `GET /wp-json/webmcp/v1/search?q=<fixture>` | Only published results; PMPro results are marked `paywalled` and never include protected content. When searching products, hidden or non-visible WooCommerce products are excluded. |
| B6 | Invalid, missing, negative, or very large IDs/queries | 400/404 validation response; no PHP warning or database error. |

For menu discovery, request an assigned location and confirm that it returns
only that menu. An unknown or known-but-unassigned location must return 404
without falling back to another registered location. A direct unassigned
`menu_id` should require `edit_theme_options` and return 403 for an anonymous
or ordinary subscriber session.

### C. Authentication, nonce, and authorization

For Woo cart routes, obtain a same-origin WordPress REST nonce from the site’s normal logged-in page and send it as `X-WP-Nonce`.

| ID | Action | Expected result |
| --- | --- | --- |
| C1 | Call a `read`-gated tool anonymously | 401 `webmcp_auth_required`. |
| C2 | Call the same tool as subscriber/author/admin | Only accounts with the configured capability succeed. |
| C3 | POST Woo cart add/remove/coupon with missing or invalid nonce | 403 `webmcp_bad_nonce`; cart unchanged. |
| C4 | POST with valid nonce but insufficient capability | 403 `webmcp_forbidden`; cart unchanged. |
| C5 | Send a request larger than the configured size limit | 413 `webmcp_request_too_large`; request is not counted as a successful tool call. |
| C6 | Disable the master switch | All direct routes and native abilities fail closed with 403. |

Verify that failed authentication/capability/nonce checks do not consume the caller’s normal rate-limit quota: after several invalid attempts, a valid request should still be allowed when under the configured limit.

### D. Rate limiting and cache backends

Run this only on staging. Set the staging per-principal/tool maximum to 3 and the pooled maximum to 5.

1. Make three valid requests from one anonymous browser, then a fourth: expect 429 with `Retry-After`.
2. Repeat with a different anonymous browser/IP/session and verify the per-principal behavior.
3. Exhaust the anonymous pool and make a valid request as a logged-in test user: the authenticated pool must still work.
4. Exhaust the authenticated pool and make an anonymous request: the anonymous pool must still work.
5. Repeat with the site’s persistent object cache enabled and disabled. Counts must remain bounded and must not reset on every request.
6. Wait for the window to expire and confirm the request succeeds again.

### E. Idempotency and replay protection

Use a disposable cart or activity fixture and a unique key such as `test-2026-08-20-001`.

| ID | Action | Expected result |
| --- | --- | --- |
| E1 | Submit the same Woo add-to-cart request twice with the same authenticated user/key | One cart side effect; second response is a replay with `X-WebMCP-Idempotent-Replay: 1`. |
| E2 | Reuse the key with a different product/quantity | 409 `webmcp_idempotency_conflict`; no second side effect. |
| E3 | Submit the same key concurrently from two staging workers | At most one callback executes; the other receives 409 `webmcp_idempotency_in_progress` or the cached replay. |
| E4 | Repeat E1 for BuddyPress/BuddyBoss activity creation | One activity item only; domain REST controller applies its own validation/privacy rules. |
| E5 | Use two anonymous browser sessions with the same key | They must not share a replay unless both present the same stable browser cookie. |
| E6 | Omit the idempotency key | Requests are not deduplicated; test only with disposable data. |

### F. WooCommerce

Test with a disposable cart and sandbox payment settings; do not place a real order.

- Product search/get/categories/checkout fields expose only public catalog/schema data.
- Add-to-cart accepts a purchasable product and rejects an out-of-stock/non-purchasable product.
- Cart view never shows another browser’s cart. Use two cookie jars and compare item IDs.
- Remove and coupon application require the configured capability and nonce by default. An intentional Public setting must be documented as a product decision and retested for cross-session isolation.
- Invalid coupon, invalid cart item key, quantity above the schema maximum, and missing product ID fail with a bounded 4xx response.

### G. Paid Memberships Pro

- `/wp-json/webmcp/v1/pmpro/memberships` returns only the current logged-in user’s memberships.
- Anonymous and non-member calls do not reveal another user’s level data.
- Public search/post retrieval marks restricted content as paywalled; it never returns the protected body.
- An entitled member can retrieve the allowed content and sees only their own membership information.

### H. BuddyPress/BuddyBoss

- Members/groups/activity reads respect the integration’s privacy rules for public and private groups.
- Activity creation remains disabled unless explicitly enabled.
- When enabled, subscriber/non-posting users are denied; the `publish_posts` test user is passed to the BuddyPress/BuddyBoss REST controller rather than writing directly to activity tables.
- Test blocked/suspended users and malformed content; no activity should be created on a denied or failed request.

### I. Native WordPress Abilities (WordPress 6.9+ only)

The plugin registers read-only WebMCP abilities when the native Abilities API is available. Mutating abilities remain excluded unless the site owner explicitly opts into the plugin filter.

1. Use the site’s native Abilities REST index/client to list the `webmcp/*` abilities and record the exact endpoint exposed by that WordPress version.
2. Confirm that a read-only ability (for example `webmcp/site-search` or `webmcp/woo-product-get`) is discoverable only when enabled and executable for the current user.
3. Call it with valid input, unknown fields, wrong types, deeply nested input, and an oversized input object. Expect the same bounded validation and `webmcp_request_too_large` behavior as the REST route.
4. Verify that capability failures and rate limits apply to native abilities too, even though they do not carry a REST nonce.
5. Confirm that no Woo/community mutation ability appears unless the site has explicitly enabled the native-mutation filter and accepted the absence of a browser confirmation prompt.

## 6. Production smoke test

Run during a low-traffic window with a rollback contact available. Use a disposable logged-in account and do not create orders or activity.

1. Confirm backup timestamp and plugin version.
2. Load the manifest and discovery URLs; verify expected tools and no PHP/WAF errors.
3. Run one public search/post check and one denied protected-post check.
4. Run one read-only Woo catalog check if WooCommerce is active.
5. As the disposable logged-in user, verify one allowed read tool and one denied capability path.
6. Inspect logs, response times, cache health, and error rates for 15–30 minutes.
7. Stop immediately and roll back if there is a fatal error, unexpected content exposure, a cart/order change, a cross-user response, repeated 5xx responses, or abnormal rate-limit behavior.

Do not perform rate-limit exhaustion, concurrency, malformed-input fuzzing, coupon application, cart removal, checkout, or community posting in this production phase.

## 7. Pass/fail and rollback criteria

The release passes only when:

- every enabled route returns the documented status and shape;
- no private, password-protected, paywalled, or cross-user data is exposed;
- every mutation requires its configured capability and nonce, and is confirmation/idempotency protected where applicable;
- rate limits are bounded, scoped by principal/tool, and keep anonymous and authenticated pools separate;
- duplicate/concurrent mutation tests create at most one side effect;
- no fatal, warning, SQL error, secret, or customer data appears in logs;
- the production smoke test remains clean for the agreed observation window.

If a criterion fails, disable the affected WebMCP tool first. If the site is unstable, deactivate the plugin, restore the verified backup, clear only the plugin’s cache entries through the normal cache tooling, and preserve logs and request IDs for diagnosis. Do not delete arbitrary transients/options on production without a backup and an explicit cleanup plan.

## 8. Test report template

Attach this information to the release record:

```text
Environment: staging / production
Site: <domain; omit from public reports if sensitive>
Test date/time (timezone):
Plugin commit/version:
WordPress/PHP:
WooCommerce / PMPro / BuddyPress or BuddyBoss:
Object cache / CDN / WAF:
Fixture IDs:
Settings screenshot or sanitized export:
Cases passed:
Cases failed:
Unexpected logs/errors:
Rollback performed: yes/no
Approver:
```
