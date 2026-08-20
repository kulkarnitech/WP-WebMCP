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
     * Apply the shared server-side policy to a native WordPress Ability.
     * Native Abilities do not carry a REST nonce, but they must still receive
     * the same size, toggle, capability, and abuse-control enforcement.
     *
     * @param array<string,mixed> $input
     */
    public static function authorize_native_tool(string $tool, array $input = []) {
        if (!Plugin::opt('enabled', 1)) {
            return new WP_Error('webmcp_disabled', 'WebMCP layer is disabled.', ['status' => 403]);
        }

        if (!Tools::is_enabled($tool)) {
            return new WP_Error('webmcp_tool_disabled', 'Tool is disabled.', ['status' => 403]);
        }

        $size_check = self::request_size_check_input($input);
        if ($size_check instanceof WP_Error) {
            return $size_check;
        }

        $cap = Tools::required_capability($tool);
        if ($cap !== '') {
            if (!is_user_logged_in()) {
                return new WP_Error('webmcp_auth_required', 'Login required.', ['status' => 401]);
            }
            if (!current_user_can($cap)) {
                return new WP_Error('webmcp_forbidden', 'Insufficient permissions.', ['status' => 403]);
            }
        }

        return self::rate_limit_check($tool);
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
        $lock_key = '';
        $fingerprint = '';
        $lock_acquired = false;
        if ($key !== '') {
            $identity = self::idempotency_identity($req);
            // Do not share anonymous replay records between callers that do
            // not present a stable browser/session cookie.
            if ($identity !== '') {
                $fingerprint = self::request_fingerprint($req, $tool);
                $cache_key = 'webmcp_idem_' . md5($tool . '|' . $identity . '|' . $key);
                $cached = get_transient($cache_key);
                if (is_array($cached) && isset($cached['data'], $cached['status'])) {
                    if (!empty($cached['fingerprint']) && !hash_equals((string) $cached['fingerprint'], $fingerprint)) {
                        return new WP_Error('webmcp_idempotency_conflict', 'The idempotency key was reused with different input.', ['status' => 409]);
                    }
                    $response = new WP_REST_Response($cached['data'], (int) $cached['status']);
                    $response->header('X-WebMCP-Idempotent-Replay', '1');
                    self::audit($tool, 'replay', 0);
                    return $response;
                }

                $lock_key = 'webmcp_idem_lock_' . md5($cache_key);
                $lock_acquired = self::claim_idempotency($lock_key, $fingerprint, max(60, (int) Plugin::opt('idempotency_ttl', 300)));
                if (!$lock_acquired) {
                    return new WP_Error('webmcp_idempotency_in_progress', 'An identical request is already being processed.', ['status' => 409]);
                }
            }
        }

        $started = microtime(true);
        try {
            $response = call_user_func($callback);
        } catch (\Throwable $error) {
            self::audit($tool, 'exception', (microtime(true) - $started) * 1000);
            if ($lock_acquired) self::release_idempotency($lock_key, $fingerprint);
            return new WP_Error('webmcp_tool_exception', 'The tool could not complete safely.', ['status' => 500]);
        }

        if ($cache_key !== '' && $response instanceof WP_REST_Response) {
            set_transient($cache_key, [
                'data'        => $response->get_data(),
                'status'      => $response->get_status(),
                'fingerprint' => $fingerprint,
            ], max(60, (int) Plugin::opt('idempotency_ttl', 300)));
        }

        if ($lock_acquired) self::release_idempotency($lock_key, $fingerprint);

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

        // Charge only requests that have passed authentication/nonce policy.
        return self::rate_limit_check($tool);
    }

    /**
     * Per-principal/tool rate limit with an optional scoped global ceiling.
     * Returns true or WP_Error(429).
     */
    private static function rate_limit_check(string $tool = '') {
        if (!Plugin::opt('rate_limit_enabled', 1)) {
            return true;
        }

        $window = max(10, (int) Plugin::opt('rate_limit_window', 60));  // seconds
        $maxReq = max(1, (int) Plugin::opt('rate_limit_max', 60));      // requests/window

        $principal = self::rate_limit_principal();
        $bucket = (int) floor(time() / $window);

        $key = 'webmcp_rl_' . md5($principal . '|' . sanitize_key($tool) . '|' . $bucket);
        $count = self::increment_counter($key, $window + 5);

        if ($count > $maxReq) {
            return new WP_Error(
                'webmcp_rate_limited',
                'Rate limit exceeded. Try again later.',
                ['status' => 429, 'retry_after' => $window]
            );
        }

        if (Plugin::opt('rate_limit_global_enabled', 1)) {
            $global_max = max(1, (int) Plugin::opt('rate_limit_global_max', 120));
            // Keep anonymous traffic from consuming the authenticated pool.
            $scope = is_user_logged_in() ? 'authenticated' : 'anonymous';
            $global_key = 'webmcp_rl_global_' . $scope . '_' . $bucket;
            $global_count = self::increment_counter($global_key, $window + 5);
            if ($global_count > $global_max) {
                return new WP_Error(
                    'webmcp_global_rate_limited',
                    'The WebMCP limit for this caller class has been reached. Try again later.',
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

    /**
     * Apply the configured request-size limit to native Ability input.
     *
     * @param array<string,mixed> $input
     */
    private static function request_size_check_input(array $input) {
        $limit = max(1024, (int) Plugin::opt('request_size_limit', 102400));
        $encoded = wp_json_encode($input);
        if ($encoded === false || strlen((string) $encoded) > $limit) {
            return new WP_Error(
                'webmcp_request_too_large',
                'Tool input exceeds the configured size limit.',
                ['status' => 413]
            );
        }
        return true;
    }

    private static function rate_limit_principal(): string {
        return is_user_logged_in() ? 'user:' . get_current_user_id() : 'ip:' . self::client_ip();
    }

    private static function increment_counter(string $key, int $expiration): int {
        $group = 'wp-webmcp';

        // External object caches generally provide atomic increment semantics.
        if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) {
            if (wp_cache_add($key, 1, $group, $expiration)) return 1;
            if (function_exists('wp_cache_incr')) {
                $count = wp_cache_incr($key, 1, $group);
                if ($count !== false) return (int) $count;
            }
        }

        // The default WordPress object cache is request-local, so serialize
        // transient fallback updates with an atomic option-row lock. A short
        // expiry lets another request recover if the worker terminates.
        $lock_key = 'webmcp_rl_lock_' . md5($key);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            if (add_option($lock_key, ['expires' => time() + 5], '', 'no')) {
                try {
                    $count = (int) get_transient($key) + 1;
                    set_transient($key, $count, $expiration);
                    return $count;
                } finally {
                    delete_option($lock_key);
                }
            }

            $lock = get_option($lock_key, []);
            if (is_array($lock) && !empty($lock['expires']) && (int) $lock['expires'] < time()) {
                delete_option($lock_key);
            }
            usleep(1000);
        }

        // Preserve availability if an object-cache/DB lock backend is
        // misbehaving; the normal path above remains serialized.
        $count = (int) get_transient($key) + 1;
        set_transient($key, $count, $expiration);
        return $count;
    }

    private static function idempotency_identity(WP_REST_Request $req): string {
        if (is_user_logged_in()) return 'user:' . get_current_user_id();

        $cookie = (string) $req->get_header('Cookie');
        if ($cookie === '') return '';

        return 'cookie:' . hash_hmac('sha256', $cookie, wp_salt('auth'));
    }

    private static function request_fingerprint(WP_REST_Request $req, string $tool): string {
        $params = $req->get_params();
        if (is_array($params)) ksort($params);
        return hash('sha256', $tool . '|' . (string) wp_json_encode($params));
    }

    private static function claim_idempotency(string $lock_key, string $fingerprint, int $ttl): bool {
        $lock = ['fingerprint' => $fingerprint, 'expires' => time() + $ttl];
        if (add_option($lock_key, $lock, '', 'no')) return true;

        $existing = get_option($lock_key, []);
        if (is_array($existing) && !empty($existing['expires']) && (int) $existing['expires'] < time()) {
            delete_option($lock_key);
            return add_option($lock_key, $lock, '', 'no');
        }

        return false;
    }

    private static function release_idempotency(string $lock_key, string $fingerprint): void {
        $existing = get_option($lock_key, []);
        if (is_array($existing) && isset($existing['fingerprint']) && hash_equals((string) $existing['fingerprint'], $fingerprint)) {
            delete_option($lock_key);
        }
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

        if (!$post || $post->post_status !== 'publish' || !is_post_publicly_viewable($post) || post_password_required($post)) {
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
