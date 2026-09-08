<?php
namespace WebMCP_Layer;

if (!defined('ABSPATH')) exit;

/**
 * Core plugin bootstrapper (loaded by webmcp-layer-for-wordpress.php)
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

    /**
     * Seed a complete, sanitized option set when the plugin is activated.
     * Runtime migration remains in Admin::init() for updates that do not
     * execute activation hooks (for example, a file replacement deployment).
     */
    public static function activate(): void {
        require_once WEBMCP_LAYER_PATH . 'includes/class-admin.php';

        Admin::activate();
    }

    /**
     * Preserve settings on deactivation. The plugin has no scheduled jobs,
     * rewrite rules, or other temporary resources that require teardown.
     */
    public static function deactivate(): void {
        // Intentionally empty: rate-limit and idempotency records expire.
    }

    private function __construct() {
        $this->has_woo   = class_exists('WooCommerce');
        $this->has_pmpro = function_exists('pmpro_has_membership_access');

        // Core
        require_once WEBMCP_LAYER_PATH . 'includes/class-admin.php';
        require_once WEBMCP_LAYER_PATH . 'includes/class-adminbar.php';
        require_once WEBMCP_LAYER_PATH . 'includes/class-core.php';
        require_once WEBMCP_LAYER_PATH . 'includes/class-discovery.php';
        require_once WEBMCP_LAYER_PATH . 'includes/class-rest.php';
        require_once WEBMCP_LAYER_PATH . 'includes/class-tools.php';

        Admin::init();
        AdminBar::init();
        Core::init();
        Discovery::init();
        REST::init();
        Tools::init();

        // Integrations (load only when active)
        if ($this->has_pmpro) {
            require_once WEBMCP_LAYER_PATH . 'includes/class-pmpro.php';
            PMPro::init();
        }

        if ($this->has_woo) {
            require_once WEBMCP_LAYER_PATH . 'includes/class-woocommerce.php';
            WooCommerce::init();
        }

        if (function_exists('buddypress') || defined('BP_VERSION') || class_exists('BuddyPress') || class_exists('BuddyBoss_Platform') || class_exists('BuddyBossPlatform')) {
            require_once WEBMCP_LAYER_PATH . 'includes/class-community.php';
            Community::init();
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
        $definitions = Tools::frontend_definitions();

        // If no tools are enabled for this visitor, skip enqueue to keep frontend clean
        if (!$definitions) {
            return;
        }

        $tool_flags = ['enabled' => 1];
        foreach (array_keys($definitions) as $key) {
            $tool_flags[$key] = 1;
        }

        $script = defined('SCRIPT_DEBUG') && SCRIPT_DEBUG
            ? 'assets/webmcp.js'
            : 'assets/webmcp.min.js';

        wp_enqueue_script(
            'webmcp-layer-for-wordpress',
            WEBMCP_LAYER_URL . $script,
            [],
            WEBMCP_LAYER_VERSION,
            true
        );

        wp_localize_script('webmcp-layer-for-wordpress', 'WEBMCP_LAYER', [
            'restUrl'  => esc_url_raw(rest_url('webmcp/v1')),
            'nonce'    => wp_create_nonce('wp_rest'),
            'siteName' => get_bloginfo('name'),

            'hasWoo'   => $this->has_woo,
            'hasPMPro' => $this->has_pmpro,

            // The current WebMCP draft exposes document.modelContext. The
            // legacy provider is retained only as a compatibility fallback.
            'api'      => 'document.modelContext',

            // Tool exposure (already toggle/integration/capability-gated here).
            // Keep this dynamic so third-party registry adapters are exposed.
            'tools'       => $tool_flags,
            'definitions' => $definitions,
        ]);
    }
}
