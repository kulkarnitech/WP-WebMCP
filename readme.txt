=== WebMCP Layer for WordPress ===
Contributors: kulkarnitech
Tags: webmcp, ai, woo, pmpro, rest
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.4.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds a progressive WebMCP tool layer to WordPress with PMPro-safe content
exposure and WooCommerce support. It uses the current
document.modelContext.registerTool() draft API when available and keeps the
REST routes as a compatibility/server integration surface.

== Description ==

WebMCP Layer for WordPress enables structured tool exposure via WebMCP (Web
Model Context Protocol). It allows AI agents to interact with WordPress safely
through defined REST endpoints.

Features:

* Content search tool
* Post retrieval tool (PMPro-safe)
* WooCommerce catalog, checkout metadata, and cart tools
* Core menu, taxonomy, and site metadata tools
* PMPro membership status (current visitor only)
* BuddyPress/BuddyBoss member, group, and activity tools
* Discovery manifest, nonce endpoint, and well-known metadata
* Confirmation-gated mutations with idempotency replay defense
* Role-based tool exposure
* Rate limiting
* Browser WebMCP detection
* Debug export panel
* Extensible tool registry for integrations such as BuddyPress/BuddyBoss
* Generic product search follows WooCommerce public catalog visibility rules
* Menu discovery is limited to assigned public locations unless the caller has
  the edit_theme_options capability, with a 100-item query limit

== Installation ==

1. Upload the plugin folder to /wp-content/plugins/
2. Activate the plugin
3. Go to Settings → WebMCP Layer for WordPress

== Frequently Asked Questions ==

= Does this replace XML sitemaps? =
No. This is unrelated to SEO sitemaps.

= Is WooCommerce required? =
No. WooCommerce tools activate only if WooCommerce is installed.

= Is Paid Memberships Pro required? =
No. If active, paywalled content is automatically protected.

== Changelog ==

= 0.4.0 =
* Rename the plugin to WebMCP Layer for WordPress.
* Align generic product search with WooCommerce public visibility rules.
* Bound menu retrieval and restrict direct unassigned menu IDs.
* Scope discovery headers and head links to enabled frontend requests.
* Replace legacy plugin-specific namespaces, options, hooks, handles, and tool IDs.
* Normalize the readme metadata and align the plugin license headers.

= 0.3.0 =
* Add visitor-filtered manifest, discovery, nonce, Link, and well-known metadata.
* Add core WordPress, WooCommerce, PMPro, and community adapters.
* Add confirmed cart/community mutations with idempotency protection.
* Add global rate limiting, request-size/schema bounds, audit hooks, admin examples, and tests.
* Defer the optional webmcp.dev blue connector.

= 0.2.0 =
* Align browser registration with the current WebMCP draft API.
* Add a central tool registry and optional WordPress Abilities integration.
* Harden REST argument validation and WooCommerce product checks.
* Keep admin previews and frontend schemas in sync.
* Add a read-only BuddyPress/BuddyBoss member search adapter.

= 0.1.0 =
* Initial release with WebMCP tools, WooCommerce integration, PMPro-safe exposure, role-based gating, rate limiting, and a debug export panel.

Updated on 2026-09-03
