<?php
namespace WebMCP_Layer;

if (!defined('ABSPATH')) exit;

final class AdminBar {

    public static function init(): void {
        add_action('admin_bar_menu', [__CLASS__, 'add_node'], 100);
        add_action('admin_enqueue_scripts', [__CLASS__, 'admin_styles']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'admin_styles']);
    }

    public static function add_node(\WP_Admin_Bar $bar): void {
        if (!is_user_logged_in() || !current_user_can('manage_options')) return;
        if (is_admin() && defined('DOING_AJAX') && DOING_AJAX) return;

        $enabled  = (bool) Plugin::opt('enabled', 1);
        $supported = true; // WebMCP API support is browser-side; we assume "layer enabled" == active.

        // Determine "status"
        $active = $enabled;

        $title = $active ? 'WebMCP: Active' : 'WebMCP: Disabled';
        $class = $active ? 'webmcp-layer-dot webmcp-layer-dot--green' : 'webmcp-layer-dot webmcp-layer-dot--red';

        $bar->add_node([
            'id'    => 'webmcp-layer-for-wordpress',
            'title' => '<span class="' . esc_attr($class) . '"></span>' . esc_html($title),
            'href'  => admin_url('options-general.php?page=' . Admin::PAGE_SLUG),
            'meta'  => [
                'title' => 'Open WebMCP settings',
            ],
        ]);

        // Child: Quick summary
        $summary = self::summary_lines();
        foreach ($summary as $i => $line) {
            $bar->add_node([
                'id'     => 'webmcp-layer-for-wordpress-' . $i,
                'parent' => 'webmcp-layer-for-wordpress',
                'title'  => esc_html($line),
                'href'   => admin_url('options-general.php?page=' . Admin::PAGE_SLUG),
            ]);
        }
    }

    private static function summary_lines(): array {
        $enabled = (bool) Plugin::opt('enabled', 1);

        $hasWoo   = class_exists('WooCommerce');
        $hasPMPro = function_exists('pmpro_has_membership_access');

        $lines = [];

        $lines[] = $enabled ? 'Layer: Enabled' : 'Layer: Disabled';

        $lines[] = 'site_search: ' . (Tools::is_enabled('site_search') ? 'On' : 'Off');
        $lines[] = 'site_get_post: ' . (Tools::is_enabled('site_get_post') ? 'On' : 'Off');

        if ($hasWoo) {
            $lines[] = 'Woo cart view: ' . (Tools::is_enabled('woo_cart_view') ? 'On' : 'Off');
            $lines[] = 'Woo cart add: ' . (Tools::is_enabled('woo_cart_add') ? 'On' : 'Off');
        } else {
            $lines[] = 'Woo: Not active';
        }

        if ($hasPMPro) {
            $lines[] = 'PMPro: Paywall redaction On';
        } else {
            $lines[] = 'PMPro: Not active';
        }

        if (function_exists('buddypress') || defined('BP_VERSION') || class_exists('BuddyPress') || class_exists('BuddyBoss_Platform') || class_exists('BuddyBossPlatform')) {
            $lines[] = 'bp_members: ' . (Tools::is_enabled('bp_members') ? 'On' : 'Off');
        } else {
            $lines[] = 'BuddyPress/BuddyBoss: Not active';
        }

        return $lines;
    }

    public static function admin_styles(): void {
        if (!is_user_logged_in() || !current_user_can('manage_options')) return;

        $css = '
        #wpadminbar .webmcp-layer-dot{
            display:inline-block;
            width:10px;height:10px;
            border-radius:50%;
            margin-right:6px;
            vertical-align:middle;
            box-shadow:0 0 0 1px rgba(255,255,255,0.35) inset;
        }
        #wpadminbar .webmcp-layer-dot--green{ background:#22c55e; }
        #wpadminbar .webmcp-layer-dot--red{ background:#ef4444; }
        ';

        wp_register_style('webmcp-layer-adminbar', false, [], WEBMCP_LAYER_VERSION);
        wp_enqueue_style('webmcp-layer-adminbar');
        wp_add_inline_style('webmcp-layer-adminbar', $css);
    }
}
