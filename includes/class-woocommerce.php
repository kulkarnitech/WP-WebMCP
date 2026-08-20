<?php
namespace WP_WebMCP_Layer;

use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) exit;

/** WooCommerce catalog and customer-session adapters. */
final class WooCommerce {

    public static function init(): void {
        add_action('wp_webmcp_register_tools', [__CLASS__, 'register_tools'], 30);
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_tools(Tools $registry): void {
        $registry->register([
            'key' => 'woo_product_search', 'name' => 'woo_product_search',
            'title' => __('Search WooCommerce products', 'wp-webmcp-layer'),
            'description' => __('Searches purchasable public WooCommerce products by text, category, and price range.', 'wp-webmcp-layer'),
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
            'title' => __('Get a WooCommerce product', 'wp-webmcp-layer'),
            'description' => __('Returns public WooCommerce product details, pricing, stock status, attributes, and categories.', 'wp-webmcp-layer'),
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
            'title' => __('List WooCommerce product categories', 'wp-webmcp-layer'),
            'description' => __('Lists public WooCommerce product categories.', 'wp-webmcp-layer'),
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
            'title' => __('Get checkout field schema', 'wp-webmcp-layer'),
            'description' => __('Returns the public WooCommerce checkout field schema without customer values.', 'wp-webmcp-layer'),
            'inputSchema' => ['type' => 'object', 'properties' => (object) [], 'required' => [], 'additionalProperties' => false],
            'outputSchema' => ['type' => 'object'],
            'annotations' => ['readOnlyHint' => true, 'untrustedContentHint' => false],
            'option' => 'tool_woo_checkout_fields', 'default_enabled' => true,
            'requires_woocommerce' => true, 'method' => 'GET', 'path' => '/checkout/fields',
            'ability_name' => 'webmcp/woo-checkout-fields',
            'execute_callback' => static function (array $input) { return self::checkout_fields(new WP_REST_Request('GET', '/webmcp/v1/checkout/fields'))->get_data(); },
        ]);

        $registry->register([
            'key' => 'woo_cart_remove', 'name' => 'woo_cart_remove',
            'title' => __('Remove an item from the cart', 'wp-webmcp-layer'),
            'description' => __('Removes one identified item from the current WooCommerce cart after confirmation.', 'wp-webmcp-layer'),
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
            'title' => __('Apply a WooCommerce coupon', 'wp-webmcp-layer'),
            'description' => __('Applies a customer-supplied WooCommerce coupon after confirmation.', 'wp-webmcp-layer'),
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
        if ($callback === 'cart/remove') return self::cart_remove($request);
        return self::coupon_apply($request);
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
