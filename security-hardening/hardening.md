# Security Hardening Review: WP WebMCP

## Evidence Basis

I inspected the completed Codex Security scan and the affected source paths at revision `371ebac5b9b59247df76ef56e7799f4b1f9f9b99`. Five findings survived validation: four medium and one low. The common design issue is that security policy is split between REST routes, native WordPress Abilities, and integration adapters, so a new entry point or adapter can silently omit a control.

## Constraints

We assume an incremental WordPress plugin release, backwards-compatible REST tool names, no measured latency or memory budget, and no requirement to make anonymous WooCommerce cart operations private. We should preserve the existing opt-in behavior for native mutation Abilities and keep the plugin deployable without a new service.

## Opportunity Portfolio

| Opportunity | Evidence | Options | Recommendation | Proposal |
| --- | --- | --- | --- | --- |
| One policy boundary for REST and native execution | Native Abilities control bypass, pre-auth global limiter, non-atomic idempotency | Local guards; unified execution policy | Unified policy boundary, delivered incrementally | [Execution policy proposal](proposals/execution-policy-gateway.md) |
| Domain-owned authorization before content and community sinks | `wp_get_post` visibility bypass; BuddyPress activity authorization bypass | Adapter-local checks; domain policy services | Domain policy services after tactical guards | [Domain authorization proposal](proposals/domain-aware-authorization.md) |

## Recommendation Summary

I recommend we make the execution policy a single plugin-owned boundary and then move content/community decisions behind domain-aware policy services. The first change gives us the largest recurrence reduction: REST and native Abilities will share request-size, identity, rate, authorization, idempotency, and audit behavior. The second change keeps generic WordPress capabilities from standing in for WordPress visibility or BuddyPress/BuddyBoss posting policy.

The attractive part of the plan is that we can ship the tactical guards first and introduce the stronger boundaries without changing public tool names. What gives me pause is compatibility with host-specific BuddyPress/BuddyBoss permission behavior; that portion needs integration fixtures before we claim parity.

## Next Decisions

1. Confirm whether the production site exposes WordPress 6.9+ native Abilities and whether anonymous WooCommerce cart mutations are intentional.
2. Approve the unified execution policy as the target design, with local guards as the first release gate.
3. Provide a configured WordPress test site with WooCommerce, PMPro, and BuddyPress/BuddyBoss for the runtime validation plan.

