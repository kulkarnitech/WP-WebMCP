<?php
/**
 * Plugin Name: WebMCP Layer for WordPress
 * Plugin URI: https://kulkarnitech.com/
 * Description: Adds a WebMCP layer to WordPress with PMPro-safe content, WooCommerce tools, and optional BuddyPress/BuddyBoss adapters.
 * Version: 0.4.0
 * Author: Kulkarni Technologies
 * Author URI: https://kulkarnitech.com/
 * Text Domain: webmcp-layer-for-wordpress
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WEBMCP_LAYER_VERSION', '0.4.0');
define('WEBMCP_LAYER_PATH', plugin_dir_path(__FILE__));
define('WEBMCP_LAYER_URL', plugin_dir_url(__FILE__));

require_once WEBMCP_LAYER_PATH . 'includes/class-plugin.php';

register_activation_hook(__FILE__, ['WebMCP_Layer\\Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['WebMCP_Layer\\Plugin', 'deactivate']);

add_action('plugins_loaded', function () {
    \WebMCP_Layer\Plugin::instance();
});
