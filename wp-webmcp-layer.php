<?php
/**
 * Plugin Name: WP WebMCP Layer
 * Plugin URI: https://kulkarnitech.com/
 * Description: Adds a WebMCP layer to WordPress with PMPro-safe content exposure and WooCommerce tools.
 * Version: 0.1.0
 * Author: Kulkarni Technologies
 * Author URI: https://kulkarnitech.com/
 * Text Domain: wp-webmcp-layer
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WP_WEBMCP_LAYER_VERSION', '0.1.0');
define('WP_WEBMCP_LAYER_PATH', plugin_dir_path(__FILE__));
define('WP_WEBMCP_LAYER_URL', plugin_dir_url(__FILE__));

require_once WP_WEBMCP_LAYER_PATH . 'includes/class-plugin.php';

add_action('plugins_loaded', function () {
    \WP_WebMCP_Layer\Plugin::instance();
});
