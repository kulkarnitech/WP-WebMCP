=== WP WebMCP Layer === Contributors: kulkarnitech Tags: webmcp, ai,
woo, pmpro, rest, api Requires at least: 6.0 Tested up to: 6.5 Requires
PHP: 7.4 Stable tag: 0.3.0 License: GPLv2 or later License URI:
https://www.gnu.org/licenses/gpl-2.0.html

Adds a progressive WebMCP tool layer to WordPress with PMPro-safe content
exposure and WooCommerce support. It uses the current
document.modelContext.registerTool() draft API when available and keeps the
REST routes as a compatibility/server integration surface.

== Description ==

WP WebMCP Layer enables structured tool exposure via WebMCP (Web Model
Context Protocol). It allows AI agents to interact with WordPress safely
through defined REST endpoints.

Features:

-   Content search tool
-   Post retrieval tool (PMPro-safe)
-   WooCommerce catalog, checkout metadata, and cart tools
-   Core menu, taxonomy, and site metadata tools
-   PMPro membership status (current visitor only)
-   BuddyPress/BuddyBoss member, group, and activity tools
-   Discovery manifest, nonce endpoint, and well-known metadata
-   Confirmation-gated mutations with idempotency replay defense
-   Role-based tool exposure
-   Rate limiting
-   Browser WebMCP detection
-   Debug export panel
-   Extensible tool registry for integrations such as BuddyPress/BuddyBoss

== Installation ==

1.  Upload the plugin folder to /wp-content/plugins/
2.  Activate the plugin
3.  Go to Settings → WP WebMCP Layer

== Frequently Asked Questions ==

= Does this replace XML sitemaps? = No. This is unrelated to SEO
sitemaps.

= Is WooCommerce required? = No. Woo tools activate only if WooCommerce
is installed.

= Is Paid Memberships Pro required? = No. If active, paywalled content
is automatically protected.

== Changelog ==

= 0.3.0 =
* Add visitor-filtered manifest, discovery, nonce, Link, and well-known metadata.
* Add core WordPress, WooCommerce catalog, PMPro, and community adapters.
* Add confirmed cart/community mutations with idempotency protection.
* Add global rate limiting, request-size/schema bounds, audit hooks, admin examples, and tests.
* Defer the optional webmcp.dev blue connector.

= 0.2.0 =
* Align browser registration with the current WebMCP draft API
* Add a central tool registry and optional WordPress Abilities integration
* Harden REST argument validation and WooCommerce product checks
* Keep admin previews and frontend schemas in sync
* Add a read-only BuddyPress/BuddyBoss member search adapter

= 0.1.0 = * Initial release * WebMCP tools * WooCommerce integration *
PMPro-safe exposure * Role-based gating * Rate limiting * Debug export
panel

Updated on 2026-08-20
