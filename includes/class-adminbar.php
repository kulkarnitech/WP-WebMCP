<?php
namespace WP_WebMCP_Layer;

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
        $class = $active ? 'wp-webmcp-dot wp-webmcp-dot--green' : 'wp-webmcp-dot wp-webmcp-dot--red';

        $bar->add_node([
            'id'    => 'wp-webmcp-layer',
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
                'id'     => 'wp-webmcp-layer-' . $i,
                'parent' => 'wp-webmcp-layer',
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

        $lines[] = 'wp_search: ' . (Plugin::opt('tool_wp_search', 1) ? 'On' : 'Off');
        $lines[] = 'wp_get_post: ' . (Plugin::opt('tool_wp_get_post', 1) ? 'On' : 'Off');

        if ($hasWoo) {
            $lines[] = 'Woo cart tools: ' . (Plugin::opt('tool_woo_cart', 1) ? 'On' : 'Off');
        } else {
            $lines[] = 'Woo: Not active';
        }

        if ($hasPMPro) {
            $lines[] = 'PMPro: Paywall redaction On';
        } else {
            $lines[] = 'PMPro: Not active';
        }

        return $lines;
    }

    public static function admin_styles(): void {
        if (!is_user_logged_in() || !current_user_can('manage_options')) return;

        $css = '
        #wpadminbar .wp-webmcp-dot{
            display:inline-block;
            width:10px;height:10px;
            border-radius:50%;
            margin-right:6px;
            vertical-align:middle;
            box-shadow:0 0 0 1px rgba(255,255,255,0.35) inset;
        }
        #wpadminbar .wp-webmcp-dot--green{ background:#22c55e; }
        #wpadminbar .wp-webmcp-dot--red{ background:#ef4444; }
        ';

        wp_register_style('wp-webmcp-adminbar', false, [], WP_WEBMCP_LAYER_VERSION);
        wp_enqueue_style('wp-webmcp-adminbar');
        wp_add_inline_style('wp-webmcp-adminbar', $css);
    }
}
