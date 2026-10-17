<?php
namespace WebMCP_Layer;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

if (!defined('ABSPATH')) exit;

/** WooCommerce catalog and customer-session adapters. */
final class WooCommerce {

    public static function init(): void {
        add_action('webmcp_layer_register_tools', [__CLASS__, 'register_tools'], 30);
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_tools(Tools $registry): void {
        $registry->register([
            'key' => 'woo_product_search', 'name' => 'woo_product_search',
            'title' => __('Search WooCommerce products', 'webmcp-layer-for-wordpress'),
            'description' => __('Searches purchasable public WooCommerce products by text, category, and price range.', 'webmcp-layer-for-wordpress'),
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'q' => ['type' => 'string', 'maxLength' => 100],
                    'category' => ['type' => 'string', 'maxLength' => 64],
                    'min_price' => ['type' => 'string', 'maxLength' => 20],
                    'max_price' => ['type' => 'string', 'maxLength' => 20],
                    'page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                    'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
                ],
                'additionalProperties' => false,
            ],
            'outputSchema' => ['type' => 'object'],
            'annotations' => ['readOnlyHint' => true, 'untrustedContentHint' => true],
            'option' => 'tool_woo_product_search', 'default_enabled' => true,
            'requires_woocommerce' => true, 'method' => 'GET', 'path' => '/products/search',
            'ability_name' => 'webmcp/woo-product-search',
            'execute_callback' => static function (array $input) { return self::dispatch('GET', '/webmcp/v1/products/search', $input)->get_data(); },
        ]);

        $registry->register([
            'key' => 'woo_product_get', 'name' => 'woo_product_get',
            'title' => __('Get a WooCommerce product', 'webmcp-layer-for-wordpress'),
            'description' => __('Returns public WooCommerce product details, pricing, stock status, attributes, and categories.', 'webmcp-layer-for-wordpress'),
            'inputSchema' => ['type' => 'object', 'properties' => ['product_id' => ['type' => 'integer', 'minimum' => 1]], 'required' => ['product_id'], 'additionalProperties' => false],
            'outputSchema' => ['type' => 'object'],
            'annotations' => ['readOnlyHint' => true, 'untrustedContentHint' => true],
            'option' => 'tool_woo_product_get', 'default_enabled' => true,
            'requires_woocommerce' => true, 'method' => 'GET', 'path' => '/products/get',
            'ability_name' => 'webmcp/woo-product-get',
            'execute_callback' => static function (array $input) { return self::dispatch('GET', '/webmcp/v1/products/get', $input)->get_data(); },
        ]);

        $registry->register([
            'key' => 'woo_product_categories', 'name' => 'woo_product_categories',
            'title' => __('List WooCommerce product categories', 'webmcp-layer-for-wordpress'),
            'description' => __('Lists public WooCommerce product categories.', 'webmcp-layer-for-wordpress'),
            'inputSchema' => ['type' => 'object', 'properties' => ['page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100], 'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]], 'additionalProperties' => false],
            'outputSchema' => ['type' => 'object'],
            'annotations' => ['readOnlyHint' => true, 'untrustedContentHint' => true],
            'option' => 'tool_woo_product_categories', 'default_enabled' => true,
            'requires_woocommerce' => true, 'method' => 'GET', 'path' => '/products/categories',
            'ability_name' => 'webmcp/woo-product-categories',
            'execute_callback' => static function (array $input) { return self::dispatch('GET', '/webmcp/v1/products/categories', $input)->get_data(); },
        ]);

        $registry->register([
            'key' => 'woo_checkout_fields', 'name' => 'woo_checkout_fields',
            'title' => __('Get checkout field schema', 'webmcp-layer-for-wordpress'),
            'description' => __('Returns the public WooCommerce checkout field schema without customer values.', 'webmcp-layer-for-wordpress'),
            'inputSchema' => ['type' => 'object', 'properties' => (object) [], 'required' => [], 'additionalProperties' => false],
            'outputSchema' => ['type' => 'object'],
            'annotations' => ['readOnlyHint' => true, 'untrustedContentHint' => false],
            'option' => 'tool_woo_checkout_fields', 'default_enabled' => true,
            'requires_woocommerce' => true, 'method' => 'GET', 'path' => '/checkout/fields',
            'ability_name' => 'webmcp/woo-checkout-fields',
            'execute_callback' => static function (array $input) { return self::checkout_fields(new WP_REST_Request('GET', '/webmcp/v1/checkout/fields'))->get_data(); },
        ]);

        $registry->register([
            'key' => 'woo_checkout_preview', 'name' => 'woo_checkout_preview',
            'title' => __('Preview WooCommerce checkout', 'webmcp-layer-for-wordpress'),
            'description' => __('Validates the current cart checkout data, updates the customer session, and returns totals and available payment methods without creating an order.', 'webmcp-layer-for-wordpress'),
            'inputSchema' => self::checkout_schema(false),
            'outputSchema' => ['type' => 'object'],
            'annotations' => ['readOnlyHint' => false, 'untrustedContentHint' => false],
            'option' => 'tool_woo_checkout_preview', 'default_enabled' => true,
            'capability_option' => 'cap_woo_checkout_preview',
            'requires_woocommerce' => true, 'method' => 'POST', 'path' => '/checkout/preview',
            'ability_name' => 'webmcp/woo-checkout-preview',
            'execute_callback' => static function (array $input) { return self::response_data(self::dispatch('POST', '/webmcp/v1/checkout/preview', $input)); },
        ]);

        $registry->register([
            'key' => 'woo_checkout_create', 'name' => 'woo_checkout_create',
            'title' => __('Create a WooCommerce checkout order', 'webmcp-layer-for-wordpress'),
            'description' => __('Creates a pending WooCommerce order from the current cart and returns the native payment URL. It never accepts raw card data or processes payment inside WebMCP.', 'webmcp-layer-for-wordpress'),
            'inputSchema' => self::checkout_schema(true),
            'outputSchema' => ['type' => 'object'],
            'annotations' => ['readOnlyHint' => false, 'untrustedContentHint' => false],
            'option' => 'tool_woo_checkout_create', 'default_enabled' => false,
            'capability_option' => 'cap_woo_checkout_create',
            'requires_woocommerce' => true, 'method' => 'POST', 'path' => '/checkout/create',
            'ability_name' => 'webmcp/woo-checkout-create', 'confirmation' => true, 'idempotent' => true,
            'execute_callback' => static function (array $input) { return self::response_data(self::dispatch('POST', '/webmcp/v1/checkout/create', $input)); },
        ]);

        $registry->register([
            'key' => 'woo_cart_remove', 'name' => 'woo_cart_remove',
            'title' => __('Remove an item from the cart', 'webmcp-layer-for-wordpress'),
            'description' => __('Removes one identified item from the current WooCommerce cart after confirmation.', 'webmcp-layer-for-wordpress'),
            'inputSchema' => ['type' => 'object', 'properties' => ['cart_item_key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64], 'idempotency_key' => ['type' => 'string', 'maxLength' => 128]], 'required' => ['cart_item_key'], 'additionalProperties' => false],
            'outputSchema' => ['type' => 'object'],
            'annotations' => ['readOnlyHint' => false, 'untrustedContentHint' => false],
            'option' => 'tool_woo_cart_remove', 'default_enabled' => true,
            'capability_option' => 'cap_woo_cart_remove', 'capability' => 'read',
            'requires_woocommerce' => true, 'method' => 'POST', 'path' => '/cart/remove',
            'ability_name' => 'webmcp/woo-cart-remove', 'confirmation' => true,
            'execute_callback' => static function (array $input) { return self::response_data(self::dispatch('POST', '/webmcp/v1/cart/remove', $input)); },
        ]);

        $registry->register([
            'key' => 'woo_coupon_apply', 'name' => 'woo_coupon_apply',
            'title' => __('Apply a WooCommerce coupon', 'webmcp-layer-for-wordpress'),
            'description' => __('Applies a customer-supplied WooCommerce coupon after confirmation.', 'webmcp-layer-for-wordpress'),
            'inputSchema' => ['type' => 'object', 'properties' => ['coupon' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100], 'idempotency_key' => ['type' => 'string', 'maxLength' => 128]], 'required' => ['coupon'], 'additionalProperties' => false],
            'outputSchema' => ['type' => 'object'],
            'annotations' => ['readOnlyHint' => false, 'untrustedContentHint' => false],
            'option' => 'tool_woo_coupon_apply', 'default_enabled' => true,
            'capability_option' => 'cap_woo_coupon_apply', 'capability' => 'read',
            'requires_woocommerce' => true, 'method' => 'POST', 'path' => '/cart/coupon',
            'ability_name' => 'webmcp/woo-coupon-apply', 'confirmation' => true,
            'execute_callback' => static function (array $input) { return self::response_data(self::dispatch('POST', '/webmcp/v1/cart/coupon', $input)); },
        ]);
    }

    public static function register_routes(): void {
        if (!self::active()) return;

        register_rest_route('webmcp/v1', '/products/search', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'product_search'],
            'permission_callback' => static function (WP_REST_Request $request) { return REST::authorize_tool($request, 'woo_product_search', false); },
            'args' => self::search_args(),
        ]);
        register_rest_route('webmcp/v1', '/products/get', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'product_get'],
            'permission_callback' => static function (WP_REST_Request $request) { return REST::authorize_tool($request, 'woo_product_get', false); },
            'args' => ['product_id' => ['required' => true, 'sanitize_callback' => 'absint', 'validate_callback' => static function ($v) { return is_scalar($v) && (int) $v > 0; }]],
        ]);
        register_rest_route('webmcp/v1', '/products/categories', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'product_categories'],
            'permission_callback' => static function (WP_REST_Request $request) { return REST::authorize_tool($request, 'woo_product_categories', false); },
            'args' => self::page_args(),
        ]);
        register_rest_route('webmcp/v1', '/checkout/fields', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'checkout_fields'],
            'permission_callback' => static function (WP_REST_Request $request) { return REST::authorize_tool($request, 'woo_checkout_fields', false); },
        ]);
        register_rest_route('webmcp/v1', '/checkout/preview', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'checkout_preview'],
            'permission_callback' => static function (WP_REST_Request $request) { return REST::authorize_tool($request, 'woo_checkout_preview', true); },
        ]);
        register_rest_route('webmcp/v1', '/checkout/create', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'checkout_create'],
            'permission_callback' => static function (WP_REST_Request $request) { return REST::authorize_tool($request, 'woo_checkout_create', true); },
        ]);
        register_rest_route('webmcp/v1', '/cart/remove', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'cart_remove'],
            'permission_callback' => static function (WP_REST_Request $request) { return REST::authorize_tool($request, 'woo_cart_remove', true); },
            'args' => ['cart_item_key' => ['required' => true, 'sanitize_callback' => static function ($v) { return mb_substr(preg_replace('/[^A-Za-z0-9:_\-]/', '', (string) $v), 0, 64); }, 'validate_callback' => static function ($v) { return is_scalar($v) && preg_match('/^[A-Za-z0-9:_\-]{1,64}$/', (string) $v); }]],
        ]);
        register_rest_route('webmcp/v1', '/cart/coupon', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'coupon_apply'],
            'permission_callback' => static function (WP_REST_Request $request) { return REST::authorize_tool($request, 'woo_coupon_apply', true); },
            'args' => ['coupon' => ['required' => true, 'sanitize_callback' => static function ($v) { return mb_substr(sanitize_text_field((string) $v), 0, 100); }, 'validate_callback' => static function ($v) { return is_scalar($v) && trim((string) $v) !== '' && mb_strlen((string) $v) <= 100; }]],
        ]);
    }

    public static function product_search(WP_REST_Request $request): WP_REST_Response {
        if (!self::active() || !function_exists('wc_get_products')) return new WP_REST_Response(['error' => 'WooCommerce is not active'], 400);
        $page = max(1, min(100, absint($request->get_param('page') ?: 1)));
        $per_page = max(1, min(50, absint($request->get_param('per_page') ?: 20)));
        $args = [
            'status' => 'publish', 'limit' => $per_page, 'page' => $page, 'paginate' => true,
            'search' => mb_substr(sanitize_text_field((string) $request->get_param('q')), 0, 100),
            'category' => sanitize_title((string) $request->get_param('category')),
        ];
        $min = self::price((string) $request->get_param('min_price'));
        $max = self::price((string) $request->get_param('max_price'));
        if ($min !== '') $args['min_price'] = $min;
        if ($max !== '') $args['max_price'] = $max;

        $result = wc_get_products($args);
        $products = is_object($result) && isset($result->products) ? $result->products : (is_array($result) ? $result : []);
        $total = is_object($result) && isset($result->total) ? absint($result->total) : count($products);
        $output = [];
        foreach ($products as $product) {
            if (!$product instanceof \WC_Product || !$product->is_visible()) continue;
            $output[] = self::summary($product);
        }
        return new WP_REST_Response(['page' => $page, 'per_page' => $per_page, 'total' => $total, 'products' => $output], 200);
    }

    public static function product_get(WP_REST_Request $request): WP_REST_Response {
        $product = function_exists('wc_get_product') ? wc_get_product(absint($request->get_param('product_id'))) : false;
        if (!$product instanceof \WC_Product || !$product->is_visible()) return new WP_REST_Response(['error' => 'Product not found'], 404);
        return new WP_REST_Response(self::details($product), 200);
    }

    public static function product_categories(WP_REST_Request $request): WP_REST_Response {
        $page = max(1, min(100, absint($request->get_param('page') ?: 1)));
        $per_page = max(1, min(100, absint($request->get_param('per_page') ?: 20)));
        $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => true, 'number' => $per_page, 'offset' => ($page - 1) * $per_page]);
        if (is_wp_error($terms)) return new WP_REST_Response(['error' => 'Product categories are unavailable'], 500);
        $output = [];
        foreach (is_array($terms) ? $terms : [] as $term) {
            $term_link = get_term_link($term);
            $output[] = ['id' => absint($term->term_id), 'name' => sanitize_text_field((string) $term->name), 'slug' => sanitize_title((string) $term->slug), 'count' => absint($term->count), 'link' => is_wp_error($term_link) ? '' : esc_url_raw((string) $term_link)];
        }
        return new WP_REST_Response(['page' => $page, 'per_page' => $per_page, 'categories' => $output, 'has_more' => count($output) === $per_page], 200);
    }

    public static function checkout_fields(WP_REST_Request $request): WP_REST_Response {
        if (!function_exists('WC') || !WC()) return new WP_REST_Response(['error' => 'WooCommerce is not active'], 400);
        if (!WC()->checkout() || !method_exists(WC()->checkout(), 'get_checkout_fields')) return new WP_REST_Response(['fields' => []], 200);
        $fields = WC()->checkout()->get_checkout_fields();
        $output = [];
        foreach (is_array($fields) ? $fields : [] as $group => $group_fields) {
            $output[$group] = [];
            foreach (is_array($group_fields) ? $group_fields : [] as $key => $field) {
                $options = isset($field['options']) && is_array($field['options']) ? array_map('sanitize_text_field', $field['options']) : [];
                $output[$group][sanitize_key((string) $key)] = [
                    'type' => sanitize_key((string) ($field['type'] ?? 'text')),
                    'label' => sanitize_text_field((string) ($field['label'] ?? '')),
                    'required' => !empty($field['required']),
                    'placeholder' => sanitize_text_field((string) ($field['placeholder'] ?? '')),
                    'options' => $options,
                ];
            }
        }
        return new WP_REST_Response(['fields' => $output], 200);
    }

    /**
     * Validate and apply checkout data to the current WooCommerce customer
     * session without creating an order.
     */
    public static function checkout_preview(WP_REST_Request $request) {
        return REST::with_idempotency($request, 'woo_checkout_preview', static function () use ($request) {
            $input = self::request_input($request);
            $validation = self::prepare_checkout($input, 'woo_checkout_preview', false);
            if ($validation instanceof WP_Error) return self::error_response($validation);

            return new WP_REST_Response(self::checkout_snapshot(), 200);
        });
    }

    /**
     * Create a pending order using WooCommerce CRUD, then hand payment back
     * to the native WooCommerce order-pay page. Raw card/payment credentials
     * are intentionally not accepted or persisted by this adapter.
     */
    public static function checkout_create(WP_REST_Request $request) {
        return REST::with_idempotency($request, 'woo_checkout_create', static function () use ($request) {
            $input = self::request_input($request);
            $idempotency_key = (string) $request->get_header('X-WebMCP-Idempotency-Key');
            if ($idempotency_key === '') $idempotency_key = isset($input['idempotency_key']) ? (string) $input['idempotency_key'] : '';
            if (trim($idempotency_key) === '') {
                return self::error_response(new WP_Error('webmcp_checkout_idempotency_required', 'An idempotency key is required to create an order.', ['status' => 400]));
            }
            $validation = self::prepare_checkout($input, 'woo_checkout_create', true);
            if ($validation instanceof WP_Error) return self::error_response($validation);

            $posted = self::checkout_data($input, true);
            $payment_method = (string) ($posted['payment_method'] ?? '');
            $gateways = WC()->payment_gateways && method_exists(WC()->payment_gateways, 'get_available_payment_gateways')
                ? WC()->payment_gateways->get_available_payment_gateways()
                : [];
            if ($payment_method === '' || !isset($gateways[$payment_method])) {
                return self::error_response(new WP_Error('webmcp_checkout_payment_method', 'The selected payment method is unavailable.', ['status' => 400]));
            }

            try {
                $order_id = WC()->checkout()->create_order($posted);
            } catch (\Throwable $error) {
                return self::error_response(new WP_Error('webmcp_checkout_create_failed', 'WooCommerce could not create the order.', ['status' => 500]));
            }

            if (is_wp_error($order_id)) {
                return self::error_response(new WP_Error('webmcp_checkout_create_failed', 'WooCommerce could not create the order.', ['status' => 400]));
            }

            $order = wc_get_order($order_id);
            if (!$order) {
                return self::error_response(new WP_Error('webmcp_checkout_order_missing', 'The checkout order could not be loaded.', ['status' => 500]));
            }

            if (WC()->session) WC()->session->set('order_awaiting_payment', absint($order_id));

            $needs_payment = method_exists($order, 'needs_payment') ? (bool) $order->needs_payment() : true;
            $payment_url = method_exists($order, 'get_checkout_payment_url') ? $order->get_checkout_payment_url(true) : '';
            $received_url = method_exists($order, 'get_checkout_order_received_url') ? $order->get_checkout_order_received_url() : '';

            return new WP_REST_Response([
                'ok' => true,
                'order_id' => absint($order_id),
                'status' => sanitize_key((string) $order->get_status()),
                'needs_payment' => $needs_payment,
                'payment_url' => $needs_payment ? esc_url_raw($payment_url) : '',
                'order_received_url' => !$needs_payment ? esc_url_raw($received_url) : '',
                'continue_url' => esc_url_raw($needs_payment ? $payment_url : $received_url),
                'cart' => self::checkout_snapshot(),
            ], 201);
        });
    }

    public static function cart_remove(WP_REST_Request $request) {
        return REST::with_idempotency($request, 'woo_cart_remove', static function () use ($request) {
            self::load_cart();
            $key = (string) $request->get_param('cart_item_key');
            $cart = WC()->cart ? WC()->cart->get_cart() : [];
            if (!WC()->cart || !isset($cart[$key])) return new WP_REST_Response(['error' => 'Cart item not found'], 404);
            WC()->cart->remove_cart_item($key);
            WC()->cart->calculate_totals();
            return new WP_REST_Response(['ok' => true, 'cart' => REST::cart_view($request)->get_data()], 200);
        });
    }

    public static function coupon_apply(WP_REST_Request $request) {
        return REST::with_idempotency($request, 'woo_coupon_apply', static function () use ($request) {
            self::load_cart();
            if (!WC()->cart || !method_exists(WC()->cart, 'apply_coupon')) return new WP_REST_Response(['error' => 'Cart is unavailable'], 400);
            $coupon = function_exists('wc_format_coupon_code') ? wc_format_coupon_code((string) $request->get_param('coupon')) : sanitize_text_field((string) $request->get_param('coupon'));
            $applied = WC()->cart->apply_coupon($coupon);
            WC()->cart->calculate_totals();
            return new WP_REST_Response(['ok' => (bool) $applied, 'coupon' => sanitize_text_field($coupon), 'cart' => REST::cart_view($request)->get_data()], $applied ? 200 : 400);
        });
    }

    private static function dispatch(string $method, string $path, array $input) {
        $request = new WP_REST_Request($method, $path);
        foreach ($input as $key => $value) $request->set_param($key, $value);
        $callback = str_replace('/webmcp/v1/', '', $path);
        if ($callback === 'products/search') return self::product_search($request);
        if ($callback === 'products/get') return self::product_get($request);
        if ($callback === 'products/categories') return self::product_categories($request);
        if ($callback === 'checkout/fields') return self::checkout_fields($request);
        if ($callback === 'checkout/preview') return self::checkout_preview($request);
        if ($callback === 'checkout/create') return self::checkout_create($request);
        if ($callback === 'cart/remove') return self::cart_remove($request);
        return self::coupon_apply($request);
    }

    /**
     * Read JSON input while retaining support for form/query requests used by
     * integration tests and older WebMCP clients.
     *
     * @return array<string,mixed>
     */
    private static function request_input(WP_REST_Request $request): array {
        $input = $request->get_json_params();
        if (!is_array($input)) $input = $request->get_params();
        return is_array($input) ? $input : [];
    }

    /**
     * Shared checkout schema. Payment method is required only for order
     * creation; preview remains useful with the current session alone.
     */
    private static function checkout_schema(bool $include_payment): array {
        $properties = [
            'billing' => self::address_schema(),
            'shipping' => self::address_schema(),
            'ship_to_different_address' => ['type' => 'boolean'],
            'shipping_method' => ['type' => 'string', 'maxLength' => 100],
            'order_comments' => ['type' => 'string', 'maxLength' => 500],
            'accept_terms' => ['type' => 'boolean'],
            'idempotency_key' => ['type' => 'string', 'maxLength' => 128],
        ];
        $schema = [
            'type' => 'object',
            'properties' => $properties,
            'required' => [],
            'additionalProperties' => false,
        ];
        if ($include_payment) {
            $schema['properties']['payment_method'] = ['type' => 'string', 'minLength' => 1, 'maxLength' => 100];
            $schema['required'] = ['payment_method'];
        }
        return $schema;
    }

    private static function address_schema(): array {
        $fields = [];
        foreach (self::address_field_keys() as $key) {
            $fields[$key] = ['type' => 'string', 'maxLength' => $key === 'email' ? 254 : 100];
        }
        return ['type' => 'object', 'properties' => $fields, 'additionalProperties' => false];
    }

    /** @return array<int,string> */
    private static function address_field_keys(): array {
        return ['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone'];
    }

    /**
     * Prepare the Woo customer/session and validate the bounded checkout
     * payload. This deliberately avoids WC_Checkout::process_checkout(),
     * which expects browser POST globals and may redirect or process payment.
     */
    private static function prepare_checkout(array $input, string $tool, bool $require_payment) {
        $error = '';
        if (!Tools::validate_input($tool, $input, $error)) {
            return new WP_Error('webmcp_invalid_input', $error ?: 'Invalid checkout input.', ['status' => 400]);
        }

        self::load_cart();
        if (!self::active() || !WC()->cart) {
            return new WP_Error('webmcp_woocommerce_unavailable', 'WooCommerce is not available.', ['status' => 400]);
        }
        if (WC()->cart->is_empty()) {
            return new WP_Error('webmcp_checkout_empty_cart', 'The cart is empty.', ['status' => 400]);
        }

        $checkout = WC()->checkout();
        if ($require_payment && !is_user_logged_in() && $checkout && method_exists($checkout, 'is_registration_required') && $checkout->is_registration_required()) {
            return new WP_Error('webmcp_checkout_login_required', 'Login is required to complete checkout.', ['status' => 401]);
        }

        $data = self::checkout_data($input, $require_payment);
        self::hydrate_checkout_data($data);
        $validation = self::validate_checkout_data($data, $require_payment);
        if ($validation instanceof WP_Error) return $validation;

        try {
            self::update_customer_session($data);
        } catch (\Throwable $error) {
            return new WP_Error('webmcp_checkout_customer', 'The checkout address could not be applied.', ['status' => 400]);
        }

        return true;
    }

    /**
     * Flatten the bounded nested input into WooCommerce checkout field names.
     * No payment_data or arbitrary order metadata is accepted.
     *
     * @return array<string,mixed>
     */
    private static function checkout_data(array $input, bool $include_payment): array {
        $data = [];
        $keys = self::address_field_keys();
        foreach (['billing', 'shipping'] as $section) {
            $values = isset($input[$section]) && is_array($input[$section]) ? $input[$section] : [];
            foreach ($keys as $key) {
                if (!array_key_exists($key, $values)) continue;
                $value = (string) $values[$key];
                if ($key === 'email') {
                    $value = sanitize_email($value);
                } elseif ($key === 'country') {
                    $value = strtoupper(sanitize_text_field($value));
                } elseif ($key === 'phone' && function_exists('wc_sanitize_phone_number')) {
                    $value = wc_sanitize_phone_number($value);
                } else {
                    $value = sanitize_text_field($value);
                }
                $data[$section . '_' . $key] = $value;
            }
        }

        $data['ship_to_different_address'] = !empty($input['ship_to_different_address']);
        if (!empty($input['shipping_method'])) {
            $method = self::sanitize_method((string) $input['shipping_method']);
            if ($method !== '') $data['shipping_method'] = [$method];
        }
        if (array_key_exists('order_comments', $input)) {
            $data['order_comments'] = function_exists('sanitize_textarea_field')
                ? sanitize_textarea_field((string) $input['order_comments'])
                : sanitize_text_field((string) $input['order_comments']);
        }
        if (array_key_exists('accept_terms', $input)) $data['terms'] = !empty($input['accept_terms']) ? 1 : 0;
        if ($include_payment && isset($input['payment_method'])) {
            $data['payment_method'] = self::sanitize_method((string) $input['payment_method']);
        }
        return $data;
    }

    private static function sanitize_method(string $value): string {
        return mb_substr((string) preg_replace('/[^A-Za-z0-9:_\-]/', '', trim($value)), 0, 100);
    }

    private static function validate_checkout_data(array &$data, bool $require_payment) {
        if ($require_payment && empty($data['payment_method'])) {
            return new WP_Error('webmcp_checkout_payment_method', 'A payment method is required.', ['status' => 400]);
        }

        if ($require_payment && get_option('woocommerce_terms_page_id') && empty($data['terms'])) {
            return new WP_Error('webmcp_checkout_terms_required', 'You must accept the store terms before checkout.', ['status' => 400]);
        }

        $checkout = WC()->checkout();
        $fields = $checkout && method_exists($checkout, 'get_checkout_fields') ? $checkout->get_checkout_fields() : [];
        $shipping_required = WC()->cart->needs_shipping_address() && !empty($data['ship_to_different_address']);
        $errors = [];

        foreach (['billing', 'shipping'] as $fieldset) {
            if ($fieldset === 'shipping' && !$shipping_required) continue;
            $fieldset_fields = isset($fields[$fieldset]) && is_array($fields[$fieldset]) ? $fields[$fieldset] : [];
            foreach ($fieldset_fields as $key => $field) {
                $value = isset($data[$key]) ? (string) $data[$key] : '';
                if (!empty($field['required']) && trim($value) === '') {
                    $errors[$key] = sprintf('%s is required.', sanitize_text_field((string) ($field['label'] ?? $key)));
                    continue;
                }
                $formats = isset($field['validate']) ? (array) $field['validate'] : [];
                if ($value !== '' && in_array('email', $formats, true) && !is_email($value)) {
                    $errors[$key] = 'Enter a valid email address.';
                } elseif ($value !== '' && in_array('phone', $formats, true) && class_exists('WC_Validation') && !\WC_Validation::is_phone($value, isset($data[$fieldset . '_country']) ? (string) $data[$fieldset . '_country'] : null)) {
                    $errors[$key] = 'Enter a valid phone number.';
                } elseif ($value !== '' && in_array('postcode', $formats, true) && class_exists('WC_Validation') && !empty($data[$fieldset . '_country']) && !\WC_Validation::is_postcode($value, (string) $data[$fieldset . '_country'])) {
                    $errors[$key] = 'Enter a valid postcode or ZIP code.';
                }
            }
        }

        foreach (['billing_country' => false, 'shipping_country' => true] as $key => $shipping) {
            if ($shipping && !$shipping_required) continue;
            if (empty($data[$key])) continue;
            $countries = $shipping ? WC()->countries->get_shipping_countries() : WC()->countries->get_allowed_countries();
            if (is_array($countries) && !isset($countries[(string) $data[$key]])) {
                $errors[$key] = 'The selected country is not available.';
            }
        }

        if ($errors) {
            return new WP_Error('webmcp_checkout_validation', 'Checkout validation failed.', ['status' => 400, 'fields' => $errors]);
        }
        return true;
    }

    private static function hydrate_checkout_data(array &$data): void {
        if (!WC()->customer) return;

        foreach (['billing', 'shipping'] as $fieldset) {
            foreach (self::address_field_keys() as $field) {
                $key = $fieldset . '_' . $field;
                if (array_key_exists($key, $data)) continue;
                $getter = 'get_' . $key;
                if (is_callable([WC()->customer, $getter])) {
                    $value = call_user_func([WC()->customer, $getter]);
                    if ($value !== null && $value !== '') $data[$key] = (string) $value;
                }
            }
        }

        if (empty($data['ship_to_different_address'])) {
            foreach (self::address_field_keys() as $field) {
                $billing_key = 'billing_' . $field;
                $shipping_key = 'shipping_' . $field;
                if (!array_key_exists($shipping_key, $data) && array_key_exists($billing_key, $data)) {
                    $data[$shipping_key] = $data[$billing_key];
                }
            }
        }
    }

    private static function update_customer_session(array $data): void {
        $customer = WC()->customer;
        if (!$customer) throw new \RuntimeException('WooCommerce customer session unavailable.');

        foreach (['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'postcode', 'state', 'country', 'email', 'phone'] as $field) {
            $billing_key = 'billing_' . $field;
            $shipping_key = 'shipping_' . $field;
            if (array_key_exists($billing_key, $data) && is_callable([$customer, 'set_billing_' . $field])) {
                call_user_func([$customer, 'set_billing_' . $field], $data[$billing_key]);
            }
            if (array_key_exists($shipping_key, $data) && is_callable([$customer, 'set_shipping_' . $field])) {
                call_user_func([$customer, 'set_shipping_' . $field], $data[$shipping_key]);
            } elseif (empty($data['ship_to_different_address']) && array_key_exists($billing_key, $data) && is_callable([$customer, 'set_shipping_' . $field])) {
                call_user_func([$customer, 'set_shipping_' . $field], $data[$billing_key]);
            }
        }

        if (method_exists($customer, 'save')) $customer->save();
        if (WC()->session) {
            if (!empty($data['shipping_method']) && is_array($data['shipping_method'])) {
                WC()->session->set('chosen_shipping_methods', array_values($data['shipping_method']));
            }
            if (!empty($data['payment_method'])) WC()->session->set('chosen_payment_method', $data['payment_method']);
        }
        WC()->cart->calculate_totals();
    }

    private static function checkout_snapshot(): array {
        $gateways = [];
        if (WC()->payment_gateways && method_exists(WC()->payment_gateways, 'get_available_payment_gateways')) {
            foreach ((array) WC()->payment_gateways->get_available_payment_gateways() as $id => $gateway) {
                $title = method_exists($gateway, 'get_title')
                    ? $gateway->get_title()
                    : ($gateway->title ?? $id);
                $gateways[] = ['id' => self::sanitize_method((string) $id), 'title' => sanitize_text_field((string) $title)];
            }
        }
        return [
            'ok' => true,
            'checkout_url' => function_exists('wc_get_checkout_url') ? esc_url_raw(wc_get_checkout_url()) : '',
            'cart' => REST::cart_view(new WP_REST_Request('GET', '/webmcp/v1/cart/view'))->get_data(),
            'payment_methods' => $gateways,
        ];
    }

    private static function error_response(WP_Error $error): WP_REST_Response {
        $data = $error->get_error_data();
        $status = is_array($data) && !empty($data['status']) ? (int) $data['status'] : 400;
        $payload = ['error' => sanitize_text_field((string) $error->get_error_message()), 'code' => sanitize_key((string) $error->get_error_code())];
        if (is_array($data) && isset($data['fields']) && is_array($data['fields'])) {
            $payload['fields'] = array_map('sanitize_text_field', $data['fields']);
        }
        return new WP_REST_Response($payload, $status);
    }

    private static function response_data($response) {
        return $response instanceof WP_REST_Response ? $response->get_data() : $response;
    }

    private static function active(): bool { return class_exists('WooCommerce') && function_exists('WC'); }

    private static function load_cart(): void {
        if (self::active() && !WC()->cart && function_exists('wc_load_cart')) wc_load_cart();
    }

    public static function price(string $value): string {
        $value = trim($value);
        return $value !== '' && preg_match('/^\d{1,10}(?:\.\d{1,4})?$/', $value) ? $value : '';
    }

    private static function summary(\WC_Product $product): array {
        return [
            'id' => $product->get_id(), 'name' => sanitize_text_field($product->get_name()),
            'slug' => sanitize_title($product->get_slug()), 'url' => esc_url_raw($product->get_permalink()),
            'price' => (string) $product->get_price(), 'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '',
            'stock_status' => sanitize_key($product->get_stock_status()), 'purchasable' => $product->is_purchasable(),
        ];
    }

    private static function details(\WC_Product $product): array {
        $data = self::summary($product);
        $data['type'] = sanitize_key($product->get_type());
        $data['description'] = mb_substr(wp_strip_all_tags($product->get_description()), 0, 1000);
        $data['short_description'] = mb_substr(wp_strip_all_tags($product->get_short_description()), 0, 500);
        $data['regular_price'] = (string) $product->get_regular_price();
        $data['sale_price'] = (string) $product->get_sale_price();
        $data['stock_quantity'] = $product->managing_stock() ? (int) $product->get_stock_quantity() : null;
        $data['categories'] = [];
        foreach ($product->get_category_ids() as $term_id) {
            $term = get_term($term_id, 'product_cat');
            if ($term && !is_wp_error($term)) $data['categories'][] = ['id' => absint($term->term_id), 'name' => sanitize_text_field((string) $term->name), 'slug' => sanitize_title((string) $term->slug)];
        }
        $image_id = $product->get_image_id();
        $data['image_url'] = $image_id ? esc_url_raw((string) wp_get_attachment_image_url($image_id, 'thumbnail')) : '';
        return $data;
    }

    private static function page_args(): array {
        return [
            'page' => ['required' => false, 'default' => 1, 'sanitize_callback' => 'absint', 'validate_callback' => static function ($v) { return is_scalar($v) && (int) $v >= 1 && (int) $v <= 100; }],
            'per_page' => ['required' => false, 'default' => 20, 'sanitize_callback' => 'absint', 'validate_callback' => static function ($v) { return is_scalar($v) && (int) $v >= 1 && (int) $v <= 100; }],
        ];
    }

    private static function search_args(): array {
        return self::page_args() + [
            'q' => ['required' => false, 'sanitize_callback' => static function ($v) { return mb_substr(sanitize_text_field((string) $v), 0, 100); }, 'validate_callback' => static function ($v) { return is_scalar($v) && mb_strlen((string) $v) <= 100; }],
            'category' => ['required' => false, 'sanitize_callback' => 'sanitize_title', 'validate_callback' => static function ($v) { return is_scalar($v) && mb_strlen((string) $v) <= 64; }],
            'min_price' => ['required' => false, 'sanitize_callback' => [__CLASS__, 'price'], 'validate_callback' => static function ($v) { return is_scalar($v) && ((string) $v === '' || preg_match('/^\d{1,10}(?:\.\d{1,4})?$/', (string) $v)); }],
            'max_price' => ['required' => false, 'sanitize_callback' => [__CLASS__, 'price'], 'validate_callback' => static function ($v) { return is_scalar($v) && ((string) $v === '' || preg_match('/^\d{1,10}(?:\.\d{1,4})?$/', (string) $v)); }],
        ];
    }
}
