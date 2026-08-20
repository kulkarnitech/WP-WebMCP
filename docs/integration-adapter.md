# Building a custom adapter

Third-party plugins can add a read-only tool without changing the bridge:

```php
add_action('wp_webmcp_register_tools', function ($registry) {
    $registry->register([
        'key' => 'acme_public_items',
        'name' => 'acme_public_items',
        'title' => 'List public Acme items',
        'description' => 'Returns bounded public items.',
        'inputSchema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
        'outputSchema' => ['type' => 'object'],
        'annotations' => ['readOnlyHint' => true, 'untrustedContentHint' => true],
        'option' => 'tool_acme_public_items',
        'default_enabled' => true,
        'method' => 'GET',
        'path' => '/acme/items',
        'ability_name' => 'webmcp/acme-public-items',
    ]);
});
```

Register a matching `webmcp/v1/acme/items` route with a permission callback that
calls `REST::authorize_tool($request, 'acme_public_items', false)`. Validate and
sanitize every argument, cap collection sizes, and never expose private records.
For mutations, use `REST::with_idempotency()`, set `confirmation => true`, keep
native WordPress Abilities disabled unless the integration has an explicit
authorization and confirmation design, and require a nonce for the REST route.
