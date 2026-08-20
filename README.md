🚀 Check WebMCP support online:
https://webmcpchecker.com

# WP WebMCP Layer

Adds a WebMCP tool layer to WordPress with:

-   Content search tools
-   PMPro-safe post retrieval
-   WooCommerce catalog, checkout metadata, and cart tools
-   Core menu, taxonomy, and site metadata tools
-   PMPro membership-status tools
-   BuddyPress/BuddyBoss members, groups, and activity tools
-   Role-based exposure control
-   REST rate limiting
-   Browser WebMCP capability detection
-   Debug export panel
-   Extensible tool registry for integrations such as BuddyPress/BuddyBoss

------------------------------------------------------------------------

## What This Plugin Does

This plugin allows your WordPress site to expose structured tools via
WebMCP (Web Model Context Protocol) so AI agents can interact with your
site safely.

It does NOT replace SEO sitemaps.

The browser bridge uses `document.modelContext.registerTool()` when the
current WebMCP draft API is available. On WordPress 6.9 and newer, read-only
tools can also be registered as WordPress Abilities. REST routes remain the
compatibility and server-side enforcement layer for older WordPress versions.

## Current implementation status

**Version 0.3.0 — Phases 1–4 implementation complete.**

-   Current WebMCP browser registration with a legacy preview fallback
-   Central registry shared by browser tools, admin previews, REST checks, and
    read-only WordPress Abilities
-   Hardened WordPress and WooCommerce REST validation, capability gates,
    nonces, rate limiting, and purchasability checks
-   PMPro-safe content redaction
-   Discovery manifest, nonce endpoint, well-known metadata, Link headers, and
    visitor-filtered API catalogs
-   Core WordPress, WooCommerce, PMPro, and BuddyPress/BuddyBoss adapters
-   Confirmation-gated cart/community mutations with idempotency replay defense
-   Global/per-IP rate limits, request-size and schema-depth bounds, and audit hooks
-   Admin API examples, deployment guidance, and a WordPress PHPUnit harness

The blue token connector used by [webmcp.dev](https://webmcp.dev/) is
intentionally deferred to the final optional phase. It is not enabled or
required by this release.

## Reference alignment

The plugin follows the current `document.modelContext.registerTool()` contract
used by the [WebMCP draft](https://webmachinelearning.github.io/webmcp/) and
the [WebMCP-org/MCP-B packages](https://github.com/WebMCP-org/npm-packages).
The [WordPress.org WebMCP Bridge](https://wordpress.org/plugins/webmcp-bridge/)
and [WebMCP organization packages](https://github.com/webmcp/) were used as
compatibility references. See [`WEBMCP-WORDPRESS-PLAN.md`](WEBMCP-WORDPRESS-PLAN.md)
for the alignment audit and the deferred connector phase.

------------------------------------------------------------------------

## Architecture

    wp-webmcp-layer/
    │
    ├── wp-webmcp-layer.php
    ├── includes/
    │   ├── class-plugin.php
    │   ├── class-core.php
    │   ├── class-discovery.php
    │   ├── class-rest.php
    │   ├── class-tools.php
    │   ├── class-admin.php
    │   ├── class-adminbar.php
    │   ├── class-pmpro.php
    │   ├── class-woocommerce.php
    │   ├── class-community.php
    │
    └── assets/
        ├── webmcp.js
        └── admin.js

------------------------------------------------------------------------

## Tools Exposed

### Content

-   wp_search
-   wp_get_post (PMPro-safe)
-   wp_get_menu
-   wp_get_categories
-   wp_get_site_info

### WooCommerce (optional)

-   woo_cart_view
-   woo_cart_add
-   woo_product_search
-   woo_product_get
-   woo_product_categories
-   woo_checkout_fields
-   woo_cart_remove
-   woo_coupon_apply

### Paid Memberships Pro (optional)

-   pmpro_memberships (current visitor only)

### BuddyPress/BuddyBoss (optional)

-   bp_members (read-only, privacy-aware proxy to the plugin REST controller)
-   bp_groups
-   bp_activity
-   bp_activity_create (disabled by default; confirmation required)

Mutating tools are browser-only by default so a site can require a human
confirmation before changing state. Integrations can opt into native mutation
abilities with the `wp_webmcp_register_native_mutation` filter only after
adding an equivalent authorization/confirmation policy.

------------------------------------------------------------------------

## REST Endpoints

Base namespace:

    /wp-json/webmcp/v1/

Examples:

GET /post?id=123\
GET /search?q=keyword\
GET /cart/view\
POST /cart/add\
GET /community/members?search=...&page=1
GET /manifest
GET /discovery
GET /nonce

------------------------------------------------------------------------

## Security

-   Master enable switch
-   Per-tool toggles
-   Capability gating
-   Nonce validation
-   Rate limiting
-   No paywall leakage
-   Strict route argument schemas and WooCommerce purchasability checks
-   Idempotency keys for state-changing tools
-   Request-size and schema-depth bounds
-   Discovery responses are private/no-store and vary by cookie

See [`docs/deployment.md`](docs/deployment.md) for HTTPS, caching, Permissions
Policy, and proxy guidance.

------------------------------------------------------------------------

## Extending the registry

Integration plugins can register read-only tools without editing this plugin:

```php
add_action('wp_webmcp_register_tools', function ($registry) {
    $registry->register([
        'key' => 'bp_members',
        'name' => 'bp_members',
        'title' => 'Find community members',
        'description' => 'Returns public member profiles visible to the current visitor.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'search' => ['type' => 'string', 'maxLength' => 100],
            ],
            'additionalProperties' => false,
        ],
        'outputSchema' => ['type' => 'object'],
        'annotations' => [
            'readOnlyHint' => true,
            'untrustedContentHint' => true,
        ],
        'option' => 'tool_bp_members',
        'capability' => 'read',
        'method' => 'GET',
        'path' => '/bp/members',
        'execute_callback' => function (array $input) {
            // Native Abilities path: validate again and call the integration's
            // own permission-aware service or REST controller here.
            return ['results' => []];
        },
    ]);
});
```

The callback is server-side only; the browser receives the definition and
invokes the corresponding endpoint/handler supplied by the integration. New
integrations should add their own REST route, capability check, nonce policy,
rate-limit policy, and tests rather than exposing arbitrary database queries.

------------------------------------------------------------------------

## Requirements

-   WordPress 6.0+
-   PHP 7.4+
-   WooCommerce (optional)
-   Paid Memberships Pro (optional)
-   BuddyPress or BuddyBoss (optional)

------------------------------------------------------------------------

## Author

Kulkarni Technologies\
https://kulkarnitech.com

Updated on 2026-08-20
