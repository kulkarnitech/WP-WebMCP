🚀 Check WebMCP support online:
https://webmcpchecker.com

# WP WebMCP Layer

Adds a WebMCP tool layer to WordPress with:

-   Content search tools
-   PMPro-safe post retrieval
-   WooCommerce cart tools
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

**Version 0.2.0 — Phase 1 foundation complete.**

-   Current WebMCP browser registration with a legacy preview fallback
-   Central registry shared by browser tools, admin previews, REST checks, and
    read-only WordPress Abilities
-   Hardened WordPress and WooCommerce REST validation, capability gates,
    nonces, rate limiting, and purchasability checks
-   PMPro-safe content redaction
-   First BuddyPress/BuddyBoss `bp_members` read-only adapter
-   Roadmap for WooCommerce products, PMPro membership status, community
    extensions, mutation confirmations, and integration testing

The blue token connector used by [webmcp.dev](https://webmcp.dev/) is
intentionally deferred to the final optional phase because it requires an
external/local WebSocket bridge. It is not part of the current WebMCP browser
draft.

## Reference alignment

Phase 1 follows the current `document.modelContext.registerTool()` contract
used by the [WebMCP draft](https://webmachinelearning.github.io/webmcp/) and
the [WebMCP-org/MCP-B packages](https://github.com/WebMCP-org/npm-packages).
The [WordPress.org WebMCP Bridge](https://wordpress.org/plugins/webmcp-bridge/)
is a useful feature reference, but its broader manifest/discovery surface and
WooCommerce catalog, coupon, and checkout tools are still planned here. See
[`WEBMCP-WORDPRESS-PLAN.md`](WEBMCP-WORDPRESS-PLAN.md) for the alignment audit
and prioritized Phase 2 gaps.

------------------------------------------------------------------------

## Architecture

    wp-webmcp-layer/
    │
    ├── wp-webmcp-layer.php
    ├── includes/
    │   ├── class-plugin.php
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

### WooCommerce (optional)

-   woo_cart_view
-   woo_cart_add

### BuddyPress/BuddyBoss (optional)

-   bp_members (read-only, privacy-aware proxy to the plugin REST controller)

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

------------------------------------------------------------------------

## Security

-   Master enable switch
-   Per-tool toggles
-   Capability gating
-   Nonce validation
-   Rate limiting
-   No paywall leakage
-   Strict route argument schemas and WooCommerce purchasability checks

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
