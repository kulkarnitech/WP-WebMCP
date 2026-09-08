<?php
/**
 * Remove WebMCP Layer for WordPress settings during an explicit uninstall.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('webmcp_layer_options');
