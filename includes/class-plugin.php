<?php
namespace WP_WebMCP_Layer;

if (!defined('ABSPATH')) exit;

/**
 * Core plugin bootstrapper (loaded by wp-webmcp-layer.php)
 * - Conditionally loads Woo/PMPro integration classes only if those plugins are active
 * - Boots Admin settings, Admin bar indicator, and REST API
 * - Enqueues frontend WebMCP registration JS based on admin toggles + capability gates
 */
final class Plugin {

    private static $instance = null;

    /** @var bool */
    public $has_woo = false;

    /** @var bool */
    public $has_pmpro = false;

    public static function instance(): self {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        $this->has_woo   = class_exists('WooCommerce');
        $this->has_pmpro = function_exists('pmpro_has_membership_access');

        // Core
        require_once WP_WEBMCP_LAYER_PATH . 'includes/class-admin.php';
        require_once WP_WEBMCP_LAYER_PATH . 'includes/class-adminbar.php';
        require_once WP_WEBMCP_LAYER_PATH . 'includes/class-rest.php';

        Admin::init();
        AdminBar::init();
        REST::init();

        // Integrations (load only when active)
        if ($this->has_pmpro) {
            require_once WP_WEBMCP_LAYER_PATH . 'includes/class-pmpro.php';
            PMPro::init();
        }

        if ($this->has_woo) {
            require_once WP_WEBMCP_LAYER_PATH . 'includes/class-woocommerce.php';
            WooCommerce::init();
        }

        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /**
     * Read plugin options stored by Admin::OPTION_KEY
     */
    public static function opt(string $key, $default = null) {
        $opts = get_option(Admin::OPTION_KEY, []);
        return $opts[$key] ?? $default;
    }

    /**
     * Capability gate check for a given tool. Empty capability = public.
     */
    public static function visitor_meets_cap_gate(string $cap): bool {
        $cap = (string) $cap;
        if ($cap === '') return true; // public

        if (!is_user_logged_in()) return false;
        return current_user_can($cap);
    }

    /**
     * Enqueue frontend WebMCP tool registration JS if enabled.
     * Also passes tool toggles and integration flags to webmcp.js.
     */
    public function enqueue_assets(): void {
        if (is_admin()) return;

        // Master toggle
        if (!self::opt('enabled', 1)) return;

        // Determine tool toggles AND cap gates (so we don't advertise tools the user can't use)
        $wp_search_on     = (bool) self::opt('tool_wp_search', 1)     && self::visitor_meets_cap_gate((string) self::opt('cap_wp_search', ''));
        $wp_get_post_on   = (bool) self::opt('tool_wp_get_post', 1)   && self::visitor_meets_cap_gate((string) self::opt('cap_wp_get_post', ''));

        $woo_cart_view_on = $this->has_woo
            && (bool) self::opt('tool_woo_cart_view', 1)
            && self::visitor_meets_cap_gate((string) self::opt('cap_woo_cart_view', 'read'));

        $woo_cart_add_on  = $this->has_woo
            && (bool) self::opt('tool_woo_cart_add', 1)
            && self::visitor_meets_cap_gate((string) self::opt('cap_woo_cart_add', 'read'));

        // If no tools are enabled for this visitor, skip enqueue to keep frontend clean
        if (!$wp_search_on && !$wp_get_post_on && !$woo_cart_view_on && !$woo_cart_add_on) {
            return;
        }

        wp_enqueue_script(
            'wp-webmcp-layer',
            WP_WEBMCP_LAYER_URL . 'assets/webmcp.js',
            [],
            WP_WEBMCP_LAYER_VERSION,
            true
        );

        wp_localize_script('wp-webmcp-layer', 'WP_WEBMCP', [
            'restUrl'  => esc_url_raw(rest_url('webmcp/v1')),
            'nonce'    => wp_create_nonce('wp_rest'),
            'siteName' => get_bloginfo('name'),

            'hasWoo'   => $this->has_woo,
            'hasPMPro' => $this->has_pmpro,

            // tool exposure (already cap-gated here)
            'tools' => [
                'enabled'       => 1,
                'wp_search'     => $wp_search_on ? 1 : 0,
                'wp_get_post'   => $wp_get_post_on ? 1 : 0,
                'woo_cart_view' => $woo_cart_view_on ? 1 : 0,
                'woo_cart_add'  => $woo_cart_add_on ? 1 : 0,
            ],
        ]);
    }
}
