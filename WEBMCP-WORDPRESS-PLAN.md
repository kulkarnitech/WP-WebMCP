# WebMCP for WordPress: implementation plan

This document turns the WebMCP research and the `WP-WebMCP` prototype into a
deployable roadmap. WebMCP is still a draft browser API, so WordPress support
must remain progressive enhancement: the site and REST API continue to work
when no browser exposes `document.modelContext`.

## Architecture now in the plugin

1. `includes/class-tools.php` is the single registry for tool names, JSON
   Schemas, annotations, feature toggles, capability gates, and optional
   WordPress Abilities mappings.
2. `assets/webmcp.js` registers the enabled, visitor-authorized definitions
   through `document.modelContext.registerTool()` and keeps a legacy preview
   fallback for older experiments.
3. `includes/class-rest.php` remains the compatibility/server boundary. Every
   route validates input, repeats the toggle/capability checks, applies nonce
   checks to cart operations, and rate-limits requests.
4. Read-only tools may be exposed as WordPress Abilities on WordPress 6.9+.
   Mutations stay browser-only unless a site explicitly opts into the
   `wp_webmcp_register_native_mutation` filter.

## Reference alignment audit (2026-08-20)

**Conclusion: Phase 1 is aligned with the current WebMCP browser direction,
but it is not yet feature-complete compared with the WordPress.org WebMCP
Bridge plugin.**

### What is aligned

- The browser bridge uses `document.modelContext.registerTool()` and accepts
  the early `navigator.modelContext` aliases only as compatibility fallbacks.
  This matches the current WebMCP draft and the WebMCP-org/MCP-B packages.
- Tool definitions are schema-driven and centralized, so browser registration,
  REST authorization, admin previews, and optional WordPress Abilities share a
  contract.
- The server stays local to WordPress. No external relay, telemetry, or token
  service is required for the core browser path.
- State-changing WooCommerce actions are separated from read-only tools and
  use capability, nonce, purchasability, confirmation, and rate-limit gates.

### Deliberate differences

- The plugin remains compatible with WordPress 6.0/PHP 7.4 and conditionally
  uses WordPress Abilities on newer WordPress versions. A WordPress 6.9+/PHP 8
  requirement would unnecessarily narrow the install base at this stage.
- The blue `webmcp.dev` connector and MCP-B/local-relay transport remain an
  optional final phase. They must not become an implicit external dependency.
- The WordPress.org plugin currently documents `navigator.modelContext` APIs,
  while the current W3C draft and MCP-B documentation use
  `document.modelContext.registerTool()`. We follow the latter and retain a
  legacy `navigator` fallback for preview compatibility.

### Prioritized gaps to close

1. **Discovery contract (Phase 2A):** add a filtered `/manifest` endpoint and a
   small `/discovery` response, then evaluate RFC 8288 Link headers/HTML tags,
   API Catalog, MCP Server Card, Agent Skills, and service-doc/service-desc
   metadata. These should advertise only enabled, visitor-authorized tools.
2. **Core and WooCommerce coverage (Phase 2B):** add menu, taxonomy, and site
   metadata tools; then Woo product search/detail, cart removal, coupons,
   checkout-field schema, and product categories using supported Woo APIs.
3. **Auth and abuse hardening (Phase 2C):** add a fresh-nonce endpoint for
   long-lived browser sessions, an optional global execution ceiling alongside
   the current per-IP limiter, input-size/schema depth limits, and explicit
   output sanitization/redaction tests.
4. **Operations (Phase 4):** add WordPress Playground/plugin-fixture tests,
   live admin API examples, and cache-aware discovery tests before claiming
   WordPress.org production readiness.

The comparison reference for WordPress Abilities bridging is
[Code Atlantic's WebMCP Abilities](https://github.com/code-atlantic/webmcp-abilities).
It reinforces the same useful boundaries: re-check permission callbacks at
execution time, keep an admin allowlist, expose a nonce endpoint, cap input,
and test REST authorization/rate limiting.

## Integration roadmap

| Integration | First tools | Data boundary | Write tools |
| --- | --- | --- | --- |
| Core WordPress | `wp_search`, `wp_get_post` | Published content; PMPro redaction | None in phase 1 |
| WooCommerce | `woo_product_search`, `woo_product_get`, `woo_cart_view` | Store API/customer session semantics; no private orders | `woo_cart_add`, then checkout only with confirmation |
| Paid Memberships Pro | Access-aware variants of core tools; `pmpro_memberships` | Use PMPro access functions and never return protected content | Membership changes require explicit admin/member policy |
| BuddyPress | `bp_members`, `bp_groups`, `bp_activity` | Public/profile visibility and per-endpoint BP permissions | No writes in phase 1 |
| BuddyBoss | BuddyBoss-compatible member/group/activity reads | Detect BuddyBoss first, then use its privacy/private-network rules | No writes in phase 1 |
| Events/calendar plugins | `events_search`, `event_get` | Public event fields only | Registration/reservation only after confirmation |
| Forms/CRM plugins | `form_list`, `form_get` (admin-gated) | Never expose submissions to public visitors | Submission/create tools require capability + confirmation |

Each integration should ship as a small adapter that registers tools through
`wp_webmcp_register_tools`. It should use the plugin's documented REST/API or
service layer, not direct SQL, and should return bounded, schema-valid output.

## Phases

### Phase 1 — foundation (implemented here)

- Align browser registration with the current WebMCP draft API.
- Centralize definitions and prevent admin/frontend schema drift.
- Harden route arguments, Woo product checks, capability checks, and rate
  limiting.
- Keep PMPro paywall redaction and legacy REST routes backwards compatible.
- Add read-only WordPress Abilities mappings where the host supports them.
- Add the first BuddyPress/BuddyBoss member-search adapter through the
  permission-aware BuddyPress REST controller.

### Phase 2 — read-only adapters

- Add Woo product tools using WooCommerce CRUD/Store API semantics and HPOS-safe
  access patterns.
- Add PMPro membership-status tools that disclose only the current user's
  permitted fields.
- Extend the community adapter with group and activity tools and additional
  private-network checks and pagination limits.
- Add adapter-level toggles and capability choices in the admin UI.

### Phase 3 — confirmed mutations

- Add cart/checkout and community actions only as separate, single-purpose
  tools.
- Require browser-side confirmation for each state-changing operation.
- Add idempotency keys, replay protection, audit events, and explicit
  capability/nonce checks.
- Keep native mutation abilities disabled by default.

### Phase 4 — ecosystem and operations

- Publish an adapter SDK/example package and versioned schemas.
- Add integration tests against WordPress Playground and plugin fixtures.
- Add telemetry hooks that record tool name, result class, duration, and error
  code without recording content or credentials.
- Document Permissions Policy (`tools`) and origin-isolation deployment
  requirements for WebMCP-enabled sites.

### Phase 5 — optional token connector (last)

- Add a separately enabled blue connector widget for compatibility with the
  early `webmcp.js` bridge used by `webmcp.dev`.
- Support a configurable `wss://` bridge URL, with localhost as the safe
  development default; do not make remote relays implicit.
- Implement one-time registration-token exchange and short-lived session
  storage without persisting tokens in WordPress options or post meta.
- Reuse the central registry and existing WordPress cookies, nonces,
  capability checks, rate limits, and WooCommerce confirmations.
- Treat this as a compatibility layer, not a replacement for the native
  `document.modelContext.registerTool()` path or a new WebMCP standard.

## Definition of done for each tool

- Name is stable and within WebMCP limits; description is single-purpose.
- Input and output JSON Schemas reject unknown fields and bound strings,
  arrays, pagination, and quantities.
- `readOnlyHint` / `untrustedContentHint` accurately describe behavior.
- REST permission callback repeats all authorization checks; nonce is required
  for state-changing same-origin routes.
- Output is access-filtered, escaped/serialized safely, and capped in size.
- Unit/integration tests cover unauthorized, invalid, empty, rate-limited, and
  plugin-inactive cases; an end-to-end test covers browser registration.
- Admin preview, REST route, native ability (if enabled), and browser handler
  all consume the same registry definition.

## References

- [WebMCP draft](https://webmachinelearning.github.io/webmcp/)
- [webmcp.dev compatibility widget](https://webmcp.dev/)
- [Early WebMCP bridge architecture](https://github.com/jasonjmcghee/WebMCP#more-info-about-how-it-works)
- [Chrome WebMCP guide](https://developer.chrome.com/docs/ai/webmcp)
- [WordPress REST routes](https://developer.wordpress.org/rest-api/extending-the-rest-api/routes-and-endpoints/)
- [WordPress Abilities API](https://developer.wordpress.org/apis/abilities-api/)
- [WooCommerce Store API](https://developer.woocommerce.com/docs/apis/store-api/)
- [PMPro REST API](https://www.paidmembershipspro.com/documentation/advanced/api/rest-api/)
- [BuddyPress REST API](https://codex.buddypress.org/releases/version-5-0-0/)
