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
                        'idempotency_key' => [
                            'required' => false,
                            'sanitize_callback' => static function ($value) {
                                return mb_substr((string) preg_replace('/[^A-Za-z0-9._:-]/', '', (string) $value), 0, 128);
                            },
                            'validate_callback' => static function ($value) {
                                return is_scalar($value) && mb_strlen((string) $value) <= 128;
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
     * Execute a state-changing callback with replay protection when the caller
     * supplies X-WebMCP-Idempotency-Key (or idempotency_key in JSON input).
     *
     * The cached value contains only the response payload/status; credentials
     * and request bodies are never persisted.
     */
    public static function with_idempotency(WP_REST_Request $req, string $tool, callable $callback) {
        $key = self::idempotency_key($req);
        $cache_key = '';
        if ($key !== '') {
            $identity = is_user_logged_in() ? 'user:' . get_current_user_id() : 'ip:' . self::client_ip();
            $cache_key = 'webmcp_idem_' . md5($tool . '|' . $identity . '|' . $key);
            $cached = get_transient($cache_key);
            if (is_array($cached) && isset($cached['data'], $cached['status'])) {
                $response = new WP_REST_Response($cached['data'], (int) $cached['status']);
                $response->header('X-WebMCP-Idempotent-Replay', '1');
                self::audit($tool, 'replay', 0);
                return $response;
            }
        }

        $started = microtime(true);
        try {
            $response = call_user_func($callback);
        } catch (\Throwable $error) {
            self::audit($tool, 'exception', (microtime(true) - $started) * 1000);
            return new WP_Error('webmcp_tool_exception', 'The tool could not complete safely.', ['status' => 500]);
        }

        if ($cache_key !== '' && $response instanceof WP_REST_Response) {
            set_transient($cache_key, [
                'data'   => $response->get_data(),
                'status' => $response->get_status(),
            ], max(60, (int) Plugin::opt('idempotency_ttl', 300)));
        }

        $status = $response instanceof WP_REST_Response ? $response->get_status() : 200;
        self::audit($tool, $status >= 400 ? 'failure' : 'success', (microtime(true) - $started) * 1000);
        return $response;
    }

    /**
     * Lightweight audit hook. Consumers can log tool name, result class and
     * duration without receiving content, credentials, or request bodies.
     */
    public static function audit(string $tool, string $result, $duration_ms): void {
        do_action('wp_webmcp_tool_event', [
            'tool'        => sanitize_key($tool),
            'result'      => sanitize_key($result),
            'duration_ms' => round((float) $duration_ms, 2),
            'user_id'     => is_user_logged_in() ? get_current_user_id() : 0,
            'timestamp'   => time(),
        ]);
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

        $size_check = self::request_size_check($req);
        if ($size_check instanceof WP_Error) {
            return $size_check;
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
                ['status' => 429, 'retry_after' => $window]
            );
        }

        if (Plugin::opt('rate_limit_global_enabled', 1)) {
            $global_max = max(1, (int) Plugin::opt('rate_limit_global_max', 120));
            $global_key = 'webmcp_rl_global_' . $bucket;
            $global_count = (int) get_transient($global_key) + 1;
            set_transient($global_key, $global_count, $window + 5);
            if ($global_count > $global_max) {
                return new WP_Error(
                    'webmcp_global_rate_limited',
                    'The site-wide WebMCP limit has been reached. Try again later.',
                    ['status' => 429, 'retry_after' => $window]
                );
            }
        }

        return true;
    }

    private static function request_size_check(WP_REST_Request $req) {
        $limit = max(1024, (int) Plugin::opt('request_size_limit', 102400));
        $body_size = strlen((string) $req->get_body());
        $query_size = strlen((string) wp_json_encode($req->get_params()));
        if ($body_size > $limit || $query_size > $limit) {
            return new WP_Error(
                'webmcp_request_too_large',
                'Tool input exceeds the configured size limit.',
                ['status' => 413]
            );
        }
        return true;
    }

    private static function idempotency_key(WP_REST_Request $req): string {
        $key = (string) $req->get_header('X-WebMCP-Idempotency-Key');
        if ($key === '') $key = (string) $req->get_param('idempotency_key');
        $key = preg_replace('/[^A-Za-z0-9._:-]/', '', $key);
        return mb_substr((string) $key, 0, 128);
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
                'title'     => mb_substr(sanitize_text_field((string) get_the_title($post)), 0, 300),
                'paywalled' => true,
                'message'   => 'Content is behind a membership paywall.',
                'url'       => get_permalink($post),
            ], 200);
        }

        return new WP_REST_Response([
            'id'        => $post_id,
            'title'     => mb_substr(sanitize_text_field((string) get_the_title($post)), 0, 300),
            'paywalled' => false,
            'content'   => mb_substr(wp_strip_all_tags(apply_filters('the_content', $post->post_content)), 0, 5000),
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
                'title'     => mb_substr(sanitize_text_field((string) get_the_title($p)), 0, 300),
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
        if (!WC()->cart && function_exists('wc_load_cart')) {
            wc_load_cart();
        }

        if (!WC()->cart) return new WP_REST_Response(['items' => [], 'totals' => []], 200);

        $items = [];
        foreach (WC()->cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            if (!is_object($product) || !method_exists($product, 'get_id')) continue;
            $items[] = [
                'cart_item_key' => sanitize_text_field((string) ($cart_item['key'] ?? '')),
                'product_id' => $product->get_id(),
                'name'       => sanitize_text_field((string) $product->get_name()),
                'qty'        => (int) $cart_item['quantity'],
                'price'      => method_exists($product, 'get_price') ? (string) $product->get_price() : '',
                'line_subtotal' => isset($cart_item['line_subtotal']) ? (string) $cart_item['line_subtotal'] : '',
            ];
        }

        $totals = [];
        foreach (['get_cart_contents_total' => 'contents', 'get_shipping_total' => 'shipping', 'get_total_tax' => 'tax', 'get_total' => 'total'] as $method => $key) {
            if (method_exists(WC()->cart, $method)) $totals[$key] = (string) WC()->cart->{$method}();
        }
        if (function_exists('get_woocommerce_currency')) $totals['currency'] = sanitize_text_field((string) get_woocommerce_currency());

        return new WP_REST_Response(['items' => $items, 'item_count' => count($items), 'totals' => $totals], 200);
    }

    /*
     * =====================================================
     * Woo: Add to Cart
     * =====================================================
     */
    public static function cart_add(WP_REST_Request $req) {
        return REST::with_idempotency($req, 'woo_cart_add', static function () use ($req) {
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
            'message' => 'Added to cart',
            'cart'    => REST::cart_view($req)->get_data(),
        ], 200);
        });
    }
}
