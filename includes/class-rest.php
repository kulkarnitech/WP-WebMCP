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
                        'sanitize_callback' => 'absint',
                        'validate_callback' => static function ($param) {
                            return is_scalar($param) && (string) absint($param) === (string) $param && (int) $param > 0;
                        },
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
                    'q' => [
                        'required' => true,
                        'sanitize_callback' => static function ($param) {
                            return mb_substr(sanitize_text_field((string) $param), 0, 200);
                        },
                        'validate_callback' => static function ($param) {
                            return is_scalar($param) && trim((string) $param) !== '' && mb_strlen((string) $param) <= 200;
                        },
                    ],
                    'type' => [
                        'required' => false,
                        'sanitize_callback' => 'sanitize_key',
                        'validate_callback' => static function ($param) {
                            return in_array((string) $param, ['', 'post', 'page', 'product'], true);
                        },
                    ],
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
                    'args' => [
                        'product_id' => [
                            'required' => true,
                            'sanitize_callback' => 'absint',
                            'validate_callback' => static function ($param) {
                                return is_scalar($param) && (int) $param > 0;
                            },
                        ],
                        'qty' => [
                            'required' => false,
                            'default' => 1,
                            'sanitize_callback' => 'absint',
                            'validate_callback' => static function ($param) {
                                return is_scalar($param) && (int) $param >= 1 && (int) $param <= 100;
                            },
                        ],
                    ],
                ]);
            }
        });
    }

    /**
     * Shared authorization entry point for integration adapters.
     *
     * Adapters should use this instead of duplicating toggle, capability, and
     * rate-limit checks. The nonce flag remains explicit per route.
     */
    public static function authorize_tool(WP_REST_Request $req, string $tool, bool $require_nonce = false) {
        return self::permission_for_tool($req, $tool, $require_nonce);
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
        if (!Tools::is_enabled($tool)) {
            return new WP_Error('webmcp_tool_disabled', 'Tool is disabled.', ['status' => 403]);
        }

        // Rate limiting (applies to all endpoints if enabled)
        $rl = self::rate_limit_check();
        if ($rl instanceof WP_Error) {
            return $rl;
        }

        // Capability gate
        $cap = Tools::required_capability($tool);
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
        $q    = mb_substr(sanitize_text_field((string) $req->get_param('q')), 0, 200);
        $type = sanitize_key((string) ($req->get_param('type') ?: 'post'));

        $args = [
            's'              => $q,
            'post_status'    => 'publish',
            'posts_per_page' => 10,
        ];

        if ($type === 'product' && class_exists('WooCommerce')) {
            $args['post_type'] = 'product';
        } elseif ($type === 'page') {
            $args['post_type'] = 'page';
        } elseif ($type === 'post') {
            $args['post_type'] = 'post';
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
        $qty        = min(100, max(1, absint($req->get_param('qty') ?: 1)));

        if (!$product_id) {
            return new WP_REST_Response(['error' => 'Missing product_id'], 400);
        }

        $product = wc_get_product($product_id);
        if (!$product || !$product->is_purchasable()) {
            return new WP_REST_Response(['error' => 'Product is not available for purchase'], 400);
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
