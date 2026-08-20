<?php
namespace WP_WebMCP_Layer;

if (!defined('ABSPATH')) exit;

/**
 * Central registry for WebMCP tools and their WordPress-side capabilities.
 *
 * WebMCP names use dots/underscores/dashes. WordPress Abilities use one
 * namespaced slash, so the registry keeps an explicit mapping between the
 * browser-facing name and the server-side ability name.
 */
final class Tools {

    private static $tools = [];
    private static $extensions_loaded = false;
    private static $native_registered = false;

    public static function init(): void {
        self::register_defaults();

        // Allow integration plugins to add tools after all plugins load.
        add_action('init', [__CLASS__, 'load_extensions'], 5);

        // WordPress 6.9+ only. Older WordPress versions continue to use the
        // compatibility registry and the webmcp/v1 routes.
        add_action('wp_abilities_api_categories_init', [__CLASS__, 'register_native_category'], 20);
        add_action('wp_abilities_api_init', [__CLASS__, 'register_native_abilities'], 20);
    }

    public static function load_extensions(): void {
        if (self::$extensions_loaded) return;

        self::$extensions_loaded = true;
        do_action('wp_webmcp_register_tools', new self());
    }

    /**
     * Register a tool from an integration plugin.
     *
     * @param array<string,mixed> $tool
     */
    public function register(array $tool): bool {
        $tool = self::normalize($tool);
        if (!$tool) return false;

        self::$tools[$tool['key']] = $tool;
        return true;
    }

    /**
     * Return browser-facing definitions, optionally limited to enabled tools.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function frontend_definitions(bool $enabled_only = true): array {
        self::register_defaults();
        self::load_extensions();

        $definitions = [];
        foreach (self::$tools as $key => $tool) {
            // Do not advertise a tool to a visitor who cannot execute it.
            // Server-side routes still repeat this check, so hiding a tool is
            // only an exposure optimization, never an authorization boundary.
            if ($enabled_only && (!self::is_enabled($key) || !self::can_execute($key))) continue;

            $definition = $tool;
            unset($definition['execute_callback'], $definition['permission_callback']);
            $definitions[$key] = $definition;
        }

        return apply_filters('wp_webmcp_frontend_definitions', $definitions);
    }

    public static function get(string $key): ?array {
        self::register_defaults();
        self::load_extensions();
        return self::$tools[$key] ?? null;
    }

    public static function is_enabled(string $key): bool {
        $tool = self::get($key);
        if (!$tool || !Plugin::opt('enabled', 1)) return false;

        $option = (string) ($tool['option'] ?? '');
        $default_enabled = !empty($tool['default_enabled']) ? 1 : 0;
        if ($option !== '' && !Plugin::opt($option, $default_enabled)) return false;

        if (!empty($tool['requires_woocommerce']) && !class_exists('WooCommerce')) return false;
        if (!empty($tool['requires_pmpro']) && !function_exists('pmpro_has_membership_access')) return false;

        return true;
    }

    public static function required_capability(string $key): string {
        $tool = self::get($key);
        if (!$tool) return '';

        $option = (string) ($tool['capability_option'] ?? '');
        $default = (string) ($tool['capability'] ?? '');
        return $option !== '' ? (string) Plugin::opt($option, $default) : $default;
    }

    /**
     * Shared server-side permission check. REST routes add nonce validation
     * separately because native Abilities already have authenticated access.
     */
    public static function can_execute(string $key): bool {
        $tool = self::get($key);
        if (!$tool || !self::is_enabled($key)) return false;

        $capability = self::required_capability($key);
        if ($capability !== '' && (!is_user_logged_in() || !current_user_can($capability))) {
            return false;
        }

        if (!empty($tool['permission_callback']) && is_callable($tool['permission_callback'])) {
            return (bool) call_user_func($tool['permission_callback']);
        }

        return true;
    }

    public static function ability_name(string $key): string {
        $tool = self::get($key);
        if ($tool && !empty($tool['ability_name'])) return (string) $tool['ability_name'];

        return 'webmcp/' . preg_replace('/[^a-z0-9-]+/', '-', strtolower($key));
    }

    public static function register_native_category(): void {
        if (!function_exists('wp_register_ability_category')) return;

        if (function_exists('wp_has_ability_category') && wp_has_ability_category('webmcp')) {
            return;
        }

        wp_register_ability_category(
            'webmcp',
            [
                'label'       => __('WebMCP', 'wp-webmcp-layer'),
                'description' => __('Capabilities exposed to browser-based WebMCP agents.', 'wp-webmcp-layer'),
            ]
        );
    }

    public static function register_native_abilities(): void {
        if (self::$native_registered || !function_exists('wp_register_ability')) return;

        self::load_extensions();
        self::$native_registered = true;

        foreach (self::$tools as $key => $tool) {
            if (!self::is_enabled($key)) continue;

            // Mutations remain browser-only until an integration explicitly
            // opts into native WordPress Abilities. The browser bridge can
            // require human confirmation; a server-side ability has no
            // equivalent prompt by default.
            if (empty($tool['annotations']['readOnlyHint']) && !apply_filters('wp_webmcp_register_native_mutation', false, $key, $tool)) {
                continue;
            }

            $ability_name = self::ability_name($key);
            if (function_exists('wp_has_ability') && wp_has_ability($ability_name)) continue;

            $execute = $tool['execute_callback'] ?? null;
            if (!is_callable($execute)) continue;

            wp_register_ability(
                $ability_name,
                [
                    'label'               => $tool['title'],
                    'description'         => $tool['description'],
                    'category'            => 'webmcp',
                    'input_schema'        => $tool['inputSchema'],
                    'output_schema'       => $tool['outputSchema'],
                    'execute_callback'    => static function ($input = null) use ($execute) {
                        return call_user_func($execute, is_array($input) ? $input : []);
                    },
                    'permission_callback' => static function ($input = null) use ($key) {
                        return Tools::can_execute($key);
                    },
                    'meta'                => [
                        'show_in_rest' => true,
                        'annotations'  => [
                            'readonly'    => !empty($tool['annotations']['readOnlyHint']),
                            'destructive' => !empty($tool['destructive']),
                            'idempotent'  => !empty($tool['idempotent']),
                        ],
                    ],
                ]
            );
        }
    }

    private static function register_defaults(): void {
        if (self::$tools) return;

        self::$tools = [
            'wp_search' => [
                'key'         => 'wp_search',
                'name'        => 'wp_search',
                'title'       => __('Search site content', 'wp-webmcp-layer'),
                'description' => __('Searches published WordPress content and returns concise, access-aware results.', 'wp-webmcp-layer'),
                'inputSchema' => [
                    'type'                 => 'object',
                    'properties'           => [
                        'q'    => ['type' => 'string', 'description' => __('Search query text.', 'wp-webmcp-layer'), 'maxLength' => 200],
                        'type' => ['type' => 'string', 'enum' => ['post', 'page', 'product'], 'description' => __('Optional content type.', 'wp-webmcp-layer')],
                    ],
                    'required'             => ['q'],
                    'additionalProperties' => false,
                ],
                'outputSchema' => [
                    'type'       => 'object',
                    'properties' => ['results' => ['type' => 'array']],
                    'required'   => ['results'],
                ],
                'annotations' => ['readOnlyHint' => true, 'untrustedContentHint' => true],
                'option'      => 'tool_wp_search',
                'capability_option' => 'cap_wp_search',
                'method'      => 'GET',
                'path'        => '/search',
                'ability_name' => 'webmcp/wp-search',
                'execute_callback' => static function (array $input) {
                    $request = new \WP_REST_Request('GET', '/webmcp/v1/search');
                    $request->set_param('q', $input['q'] ?? '');
                    $request->set_param('type', $input['type'] ?? '');
                    return REST::search($request)->get_data();
                },
            ],
            'wp_get_post' => [
                'key'         => 'wp_get_post',
                'name'        => 'wp_get_post',
                'title'       => __('Get a WordPress post', 'wp-webmcp-layer'),
                'description' => __('Fetches a published post or page by ID while respecting membership access.', 'wp-webmcp-layer'),
                'inputSchema' => [
                    'type'                 => 'object',
                    'properties'           => ['id' => ['type' => 'integer', 'minimum' => 1, 'description' => __('WordPress post ID.', 'wp-webmcp-layer')]],
                    'required'             => ['id'],
                    'additionalProperties' => false,
                ],
                'outputSchema' => ['type' => 'object'],
                'annotations' => ['readOnlyHint' => true, 'untrustedContentHint' => true],
                'option'      => 'tool_wp_get_post',
                'capability_option' => 'cap_wp_get_post',
                'method'      => 'GET',
                'path'        => '/post',
                'ability_name' => 'webmcp/wp-get-post',
                'execute_callback' => static function (array $input) {
                    $request = new \WP_REST_Request('GET', '/webmcp/v1/post');
                    $request->set_param('id', $input['id'] ?? 0);
                    return REST::get_post($request)->get_data();
                },
            ],
            'woo_cart_view' => [
                'key'         => 'woo_cart_view',
                'name'        => 'woo_cart_view',
                'title'       => __('View the shopping cart', 'wp-webmcp-layer'),
                'description' => __('Returns the current customer’s WooCommerce cart contents and quantities.', 'wp-webmcp-layer'),
                'inputSchema' => ['type' => 'object', 'properties' => (object) [], 'required' => [], 'additionalProperties' => false],
                'outputSchema' => ['type' => 'object'],
                'annotations' => ['readOnlyHint' => true, 'untrustedContentHint' => true],
                'option'      => 'tool_woo_cart_view',
                'capability_option' => 'cap_woo_cart_view',
                'requires_woocommerce' => true,
                'method'      => 'GET',
                'path'        => '/cart/view',
                'ability_name' => 'webmcp/woo-cart-view',
                'execute_callback' => static function (array $input) {
                    return REST::cart_view(new \WP_REST_Request('GET', '/webmcp/v1/cart/view'))->get_data();
                },
            ],
            'woo_cart_add' => [
                'key'         => 'woo_cart_add',
                'name'        => 'woo_cart_add',
                'title'       => __('Add a product to the cart', 'wp-webmcp-layer'),
                'description' => __('Adds a purchasable WooCommerce product and quantity to the current cart after user confirmation.', 'wp-webmcp-layer'),
                'inputSchema' => [
                    'type'                 => 'object',
                    'properties'           => [
                        'product_id' => ['type' => 'integer', 'minimum' => 1, 'description' => __('WooCommerce product ID.', 'wp-webmcp-layer')],
                        'qty'        => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => __('Quantity to add.', 'wp-webmcp-layer')],
                    ],
                    'required'             => ['product_id'],
                    'additionalProperties' => false,
                ],
                'outputSchema' => ['type' => 'object'],
                'annotations' => ['readOnlyHint' => false, 'untrustedContentHint' => false],
                'option'      => 'tool_woo_cart_add',
                'capability_option' => 'cap_woo_cart_add',
                'requires_woocommerce' => true,
                'method'      => 'POST',
                'path'        => '/cart/add',
                'ability_name' => 'webmcp/woo-cart-add',
                'execute_callback' => static function (array $input) {
                    $request = new \WP_REST_Request('POST', '/webmcp/v1/cart/add');
                    $request->set_param('product_id', $input['product_id'] ?? 0);
                    $request->set_param('qty', $input['qty'] ?? 1);
                    return REST::cart_add($request)->get_data();
                },
            ],
        ];
    }

    private static function normalize(array $tool): ?array {
        $key = isset($tool['key']) ? sanitize_key((string) $tool['key']) : '';
        $name = isset($tool['name']) ? (string) $tool['name'] : $key;

        if ($key === '' || !preg_match('/^[A-Za-z0-9_.-]{1,128}$/', $name)) return null;
        if (empty($tool['title']) || empty($tool['description']) || empty($tool['inputSchema'])) return null;

        $tool['key'] = $key;
        $tool['name'] = $name;
        $tool['outputSchema'] = $tool['outputSchema'] ?? ['type' => 'object'];
        $tool['annotations'] = $tool['annotations'] ?? ['readOnlyHint' => false, 'untrustedContentHint' => false];
        return $tool;
    }
}
