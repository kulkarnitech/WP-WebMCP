# WP WebMCP Layer

Adds a WebMCP tool layer to WordPress with:

-   Content search tools
-   PMPro-safe post retrieval
-   WooCommerce cart tools
-   Role-based exposure control
-   REST rate limiting
-   Browser WebMCP capability detection
-   Debug export panel

------------------------------------------------------------------------

## What This Plugin Does

This plugin allows your WordPress site to expose structured tools via
WebMCP (Web Model Context Protocol) so AI agents can interact with your
site safely.

It does NOT replace SEO sitemaps.

------------------------------------------------------------------------

## Architecture

    wp-webmcp-layer/
    │
    ├── wp-webmcp-layer.php
    ├── includes/
    │   ├── class-plugin.php
    │   ├── class-rest.php
    │   ├── class-admin.php
    │   ├── class-adminbar.php
    │   ├── class-pmpro.php
    │   ├── class-woocommerce.php
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

------------------------------------------------------------------------

## REST Endpoints

Base namespace:

    /wp-json/webmcp/v1/

Examples:

GET /post?id=123\
GET /search?q=keyword\
GET /cart/view\
POST /cart/add

------------------------------------------------------------------------

## Security

-   Master enable switch
-   Per-tool toggles
-   Capability gating
-   Nonce validation
-   Rate limiting
-   No paywall leakage

------------------------------------------------------------------------

## Requirements

-   WordPress 6.0+
-   PHP 7.4+
-   WooCommerce (optional)
-   Paid Memberships Pro (optional)

------------------------------------------------------------------------

## Author

Kulkarni Technologies\
https://kulkarnitech.com

Generated on 2026-02-16
