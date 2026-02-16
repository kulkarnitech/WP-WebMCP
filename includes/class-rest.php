<?php
namespace WP_WebMCP_Layer;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

if (!defined('ABSPATH')) exit;

final class REST {

    private const REST_NS = 'webmcp/v1';

    public static function init(): void {
        add_action('rest_api_init', function () {

            // Content endpoints
            register_rest_route(self::REST_NS, '/post', [
                'methods'  => 'GET',
                'callback' => [__CLASS__, 'get_post'],
                'permission_callback' => function (WP_REST_Request $req) {
                    return self::permission_for_tool($req, 'wp_get_post', false);
                },
                'args' => [
                    'id' => [
                        'required' => true,
                        'validate_callback' => static function ($param) {
                            return is_numeric($param) && (int)$param > 0;
                        }
                    ],
                ],
            ]);

            register_rest_route(self::REST_NS, '/search', [
                'methods'  => 'GET',
                'callback' => [__CLASS__, 'search'],
                'permission_callback' => function (WP_REST_Request $req) {
                    return self::permission_for_tool($req, 'wp_search', false);
                },
                'args' => [
                    'q' => ['required' => true],
                    'type' => ['required' => false],
                ],
            ]);

            // Woo endpoints (register only if Woo exists)
            if (class_exists('WooCommerce')) {

                register_rest_route(self::REST_NS, '/cart/view', [
                    'methods'  => 'GET',
                    'callback' => [__CLASS__, 'cart_view'],
                    'permission_callback' => function (WP_REST_Request $req) {
                        // Nonce required (same-origin), plus tool + cap gate
                        return self::permission_for_tool($req, 'woo_cart_view', true);
                    },
                ]);

                register_rest_route(self::REST_NS, '/cart/add', [
                    'methods'  => 'POST',
                    'callback' => [__CLASS__, 'cart_add'],
                    'permission_callback' => function (WP_REST_Request $req) {
                        // Nonce required (same-origin), plus tool + cap gate
                        return self::permission_for_tool($req, 'woo_cart_add', true);
                    },
                ]);
            }
        });
    }

    /**
     * Central permission enforcement:
     * - Master enabled
     * - Tool toggle
     * - Capability gate
     * - Optional nonce requirement
     * - Rate limiting (returns WP_Error on limit)
     */
    private static function permission_for_tool(WP_REST_Request $req, string $tool, bool $require_nonce) {
        // Master switch
        if (!Plugin::opt('enabled', 1)) {
            return new WP_Error('webmcp_disabled', 'WebMCP layer is disabled.', ['status' => 403]);
        }

        // Tool toggle mapping (admin options)
        $toolEnabled = self::is_tool_enabled($tool);
        if (!$toolEnabled) {
            return new WP_Error('webmcp_tool_disabled', 'Tool is disabled.', ['status' => 403]);
        }

        // Rate limiting (applies to all endpoints if enabled)
        $rl = self::rate_limit_check();
        if ($rl instanceof WP_Error) {
            return $rl;
        }

        // Capability gate
        $cap = self::required_cap_for_tool($tool);
        if ($cap !== '') {
            if (!is_user_logged_in()) {
                return new WP_Error('webmcp_auth_required', 'Login required.', ['status' => 401]);
            }
            if (!current_user_can($cap)) {
                return new WP_Error('webmcp_forbidden', 'Insufficient permissions.', ['status' => 403]);
            }
        }

        // Optional nonce
        if ($require_nonce) {
            $nonce = $req->get_header('X-WP-Nonce');
            if (!$nonce || !wp_verify_nonce($nonce, 'wp_rest')) {
                return new WP_Error('webmcp_bad_nonce', 'Invalid or missing nonce.', ['status' => 403]);
            }
        }

        return true;
    }

    private static function is_tool_enabled(string $tool): bool {
        switch ($tool) {
            case 'wp_search':
                return (bool) Plugin::opt('tool_wp_search', 1);

            case 'wp_get_post':
                return (bool) Plugin::opt('tool_wp_get_post', 1);

            case 'woo_cart_view':
                // only meaningful if Woo active
                return class_exists('WooCommerce') && (bool) Plugin::opt('tool_woo_cart_view', 1);

            case 'woo_cart_add':
                return class_exists('WooCommerce') && (bool) Plugin::opt('tool_woo_cart_add', 1);

            default:
                return false;
        }
    }

    private static function required_cap_for_tool(string $tool): string {
        switch ($tool) {
            case 'wp_search':
                return (string) Plugin::opt('cap_wp_search', '');

            case 'wp_get_post':
                return (string) Plugin::opt('cap_wp_get_post', '');

            case 'woo_cart_view':
                return (string) Plugin::opt('cap_woo_cart_view', 'read');

            case 'woo_cart_add':
                return (string) Plugin::opt('cap_woo_cart_add', 'read');

            default:
                return '';
        }
    }

    /**
     * Basic per-IP rate limit using transients.
     * Returns true or WP_Error(429).
     */
    private static function rate_limit_check() {
        if (!Plugin::opt('rate_limit_enabled', 1)) {
            return true;
        }

        $window = max(10, (int) Plugin::opt('rate_limit_window', 60));  // seconds
        $maxReq = max(1, (int) Plugin::opt('rate_limit_max', 60));      // requests/window

        $ip = self::client_ip();
        $bucket = (int) floor(time() / $window);

        $key = 'webmcp_rl_' . md5($ip . '|' . $bucket);
        $count = (int) get_transient($key);

        $count++;
        set_transient($key, $count, $window + 5);

        if ($count > $maxReq) {
            return new WP_Error(
                'webmcp_rate_limited',
                'Rate limit exceeded. Try again later.',
                ['status' => 429]
            );
        }

        return true;
    }

    private static function client_ip(): string {
        // Keep it conservative; do not trust X-Forwarded-For unless you control proxy.
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $ip = is_string($ip) ? $ip : '0.0.0.0';
        $ip = trim(wp_unslash($ip));

        // Normalize
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return '0.0.0.0';
        }
        return $ip;
    }

    /*
     * =====================================================
     * GET POST (PMPro safe; never leaks paywalled content)
     * =====================================================
     */
    public static function get_post(WP_REST_Request $req): WP_REST_Response {
        $post_id = absint($req->get_param('id'));
        $post = get_post($post_id);

        if (!$post || $post->post_status !== 'publish') {
            return new WP_REST_Response(['error' => 'Not found'], 404);
        }

        // Default: public
        $access = true;

        // Enforce PMPro only if active
        if (function_exists('pmpro_has_membership_access')) {
            $access = (bool) pmpro_has_membership_access($post_id, get_current_user_id(), true);
        }

        if (!$access) {
            return new WP_REST_Response([
                'id'        => $post_id,
                'title'     => get_the_title($post),
                'paywalled' => true,
                'message'   => 'Content is behind a membership paywall.',
                'url'       => get_permalink($post),
            ], 200);
        }

        return new WP_REST_Response([
            'id'        => $post_id,
            'title'     => get_the_title($post),
            'paywalled' => false,
            'content'   => wp_strip_all_tags(apply_filters('the_content', $post->post_content)),
            'url'       => get_permalink($post),
        ], 200);
    }

    /*
     * =====================================================
     * SEARCH (marks paywalled results if PMPro active)
     * =====================================================
     */
    public static function search(WP_REST_Request $req): WP_REST_Response {
        $q    = sanitize_text_field((string) $req->get_param('q'));
        $type = sanitize_key((string) ($req->get_param('type') ?: 'post'));

        $args = [
            's'              => $q,
            'post_status'    => 'publish',
            'posts_per_page' => 10,
        ];

        if ($type === 'product' && class_exists('WooCommerce')) {
            $args['post_type'] = 'product';
        } else {
            $args['post_type'] = ['post', 'page'];
        }

        $query = new \WP_Query($args);
        $results = [];

        foreach ($query->posts as $p) {
            $access = true;

            if (function_exists('pmpro_has_membership_access')) {
                $access = (bool) pmpro_has_membership_access($p->ID, get_current_user_id(), true);
            }

            $results[] = [
                'id'        => $p->ID,
                'type'      => $p->post_type,
                'title'     => get_the_title($p),
                'url'       => get_permalink($p),
                'paywalled' => !$access,
            ];
        }

        return new WP_REST_Response(['results' => $results], 200);
    }

    /*
     * =====================================================
     * Woo: View Cart
     * =====================================================
     */
    public static function cart_view(WP_REST_Request $req): WP_REST_Response {
        if (!class_exists('WooCommerce') || !function_exists('WC')) {
            return new WP_REST_Response(['error' => 'WooCommerce not active'], 400);
        }

        // Ensure cart exists
        if (!WC()->cart) {
            wc_load_cart();
        }

        $items = [];
        foreach (WC()->cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            $items[] = [
                'product_id' => $product->get_id(),
                'name'       => $product->get_name(),
                'qty'        => (int) $cart_item['quantity'],
            ];
        }

        return new WP_REST_Response(['items' => $items], 200);
    }

    /*
     * =====================================================
     * Woo: Add to Cart
     * =====================================================
     */
    public static function cart_add(WP_REST_Request $req): WP_REST_Response {
        if (!class_exists('WooCommerce') || !function_exists('WC')) {
            return new WP_REST_Response(['error' => 'WooCommerce not active'], 400);
        }

        // Ensure cart exists
        if (!WC()->cart) {
            wc_load_cart();
        }

        $product_id = absint($req->get_param('product_id'));
        $qty        = max(1, absint($req->get_param('qty') ?: 1));

        if (!$product_id) {
            return new WP_REST_Response(['error' => 'Missing product_id'], 400);
        }

        $added = WC()->cart->add_to_cart($product_id, $qty);
        if (!$added) {
            return new WP_REST_Response(['error' => 'Could not add to cart'], 400);
        }

        return new WP_REST_Response([
            'ok'      => true,
            'message' => 'Added to cart'
        ], 200);
    }
}
