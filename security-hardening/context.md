# Hardening Analysis Context

This derived analysis is based on the completed Codex Security standard scan `be39a2e7-9b79-47be-977d-35f1d117cad0` for revision `371ebac5b9b59247df76ef56e7799f4b1f9f9b99`. The source tree was clean at that revision. The scan reviewed all 23 repository files. The analysis is a design product only; it does not prove that any finding has been fixed.

## Evidence registry

| ID | Finding | What it establishes |
| --- | --- | --- |
| `csf_91129cebbfbb83383193d9e0` | `wp_get_post` bypasses WordPress visibility and password protections | `includes/class-rest.php:318-346` checks `post_status` and returns content without public-visibility or password checks. |
| `csf_efc629ac65c7d03cc8d6a47f` | Native WordPress Abilities bypass WebMCP rate and request-size controls | `includes/class-tools.php:164-215` registers native abilities whose execution path does not enter the REST abuse-control path. |
| `csf_973adf337076f4752f904952` | Global REST rate limit is remotely exhaustible before authentication | `includes/class-rest.php:201-215,258-269` charges a shared bucket before capability and nonce checks. |
| `csf_014652bc75c27a165ce450a8` | BuddyPress activity creation bypasses BuddyPress posting authorization | `includes/class-community.php:185-197,278-285` uses the generic WebMCP gate and calls `bp_activity_add()` directly. |
| `csf_8a400003aa21945f7d5ff023` | Idempotency protection is non-atomic and allows duplicate mutations | `includes/class-rest.php:128-156` reads and writes the transient around the callback, allowing concurrent duplicate execution. |

## Scope and limitations

The findings are source-backed and were manually traced from entry points to controls and sinks. No live WordPress database, WooCommerce, BuddyPress/BuddyBoss runtime, or WordPress 6.9 Abilities endpoint was available. PHPUnit was not installed. Guest WooCommerce cart mutations were treated as a policy question because they are scoped to the caller's session and may be intentional.

