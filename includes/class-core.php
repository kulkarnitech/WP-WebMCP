<?php
namespace WP_WebMCP_Layer;

use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) exit;

/**
 * Read-only WordPress core adapters.
 *
 * These methods intentionally use public WordPress APIs rather than direct
 * SQL so custom post types, taxonomies, menu filters, and visibility rules
 * remain in control of the data boundary.
 */
final class Core {

    public static function init(): void {
        add_action('wp_webmcp_register_tools', [__CLASS__, 'register_tools'], 10);
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_tools(Tools $registry): void {
        $registry->register([
            'key'              => 'wp_get_menu',
            'name'             => 'wp_get_menu',
            'title'            => __('Get a navigation menu', 'wp-webmcp-layer'),
            'description'      => __('Returns visible navigation menu items for a menu location or menu ID.', 'wp-webmcp-layer'),
            'inputSchema'      => [
                'type'                 => 'object',
                'properties'           => [
                    'location' => ['type' => 'string', 'maxLength' => 64, 'description' => __('Registered menu location, such as primary.', 'wp-webmcp-layer')],
                    'menu_id'  => ['type' => 'integer', 'minimum' => 1, 'description' => __('Optional navigation menu ID.', 'wp-webmcp-layer')],
                ],
                'additionalProperties' => false,
            ],
            'outputSchema'    => ['type' => 'object'],
            'annotations'     => ['readOnlyHint' => true, 'untrustedContentHint' => true],
            'option'          => 'tool_wp_get_menu',
            'default_enabled' => true,
            'method'          => 'GET',
            'path'            => '/menu',
            'ability_name'    => 'webmcp/wp-get-menu',
            'execute_callback' => static function (array $input) {
                $request = new WP_REST_Request('GET', '/webmcp/v1/menu');
                foreach (['location', 'menu_id'] as $param) {
                    if (array_key_exists($param, $input)) $request->set_param($param, $input[$param]);
                }
                return self::get_menu($request)->get_data();
            },
        ]);

        $registry->register([
            'key'              => 'wp_get_categories',
            'name'             => 'wp_get_categories',
            'title'            => __('List taxonomy terms', 'wp-webmcp-layer'),
            'description'      => __('Lists public WordPress taxonomy terms with counts and links.', 'wp-webmcp-layer'),
            'inputSchema'      => [
                'type'                 => 'object',
                'properties'           => [
                    'taxonomy'  => ['type' => 'string', 'maxLength' => 32, 'default' => 'category'],
                    'hide_empty' => ['type' => 'boolean', 'default' => true],
                    'page'      => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                    'per_page'  => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                ],
                'additionalProperties' => false,
            ],
            'outputSchema'    => ['type' => 'object'],
            'annotations'     => ['readOnlyHint' => true, 'untrustedContentHint' => true],
            'option'          => 'tool_wp_get_categories',
            'default_enabled' => true,
            'method'          => 'GET',
            'path'            => '/categories',
            'ability_name'    => 'webmcp/wp-get-categories',
            'execute_callback' => static function (array $input) {
                $request = new WP_REST_Request('GET', '/webmcp/v1/categories');
                foreach (['taxonomy', 'hide_empty', 'page', 'per_page'] as $param) {
                    if (array_key_exists($param, $input)) $request->set_param($param, $input[$param]);
                }
                return self::get_categories($request)->get_data();
            },
        ]);

        $registry->register([
            'key'              => 'wp_get_site_info',
            'name'             => 'wp_get_site_info',
            'title'            => __('Get site information', 'wp-webmcp-layer'),
            'description'      => __('Returns public site identity and locale metadata without administrative contact data.', 'wp-webmcp-layer'),
            'inputSchema'      => ['type' => 'object', 'properties' => (object) [], 'required' => [], 'additionalProperties' => false],
            'outputSchema'    => ['type' => 'object'],
            'annotations'     => ['readOnlyHint' => true, 'untrustedContentHint' => false],
            'option'          => 'tool_wp_get_site_info',
            'default_enabled' => true,
            'method'          => 'GET',
            'path'            => '/site-info',
            'ability_name'    => 'webmcp/wp-get-site-info',
            'execute_callback' => static function (array $input) {
                return self::site_info(new WP_REST_Request('GET', '/webmcp/v1/site-info'))->get_data();
            },
        ]);
    }

    public static function register_routes(): void {
        register_rest_route('webmcp/v1', '/menu', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_menu'],
            'permission_callback' => static function (WP_REST_Request $request) {
                return REST::authorize_tool($request, 'wp_get_menu', false);
            },
            'args' => [
                'location' => [
                    'required' => false,
                    'sanitize_callback' => static function ($value) {
                        return mb_substr(sanitize_key((string) $value), 0, 64);
                    },
                    'validate_callback' => static function ($value) {
                        return is_scalar($value) && mb_strlen((string) $value) <= 64;
                    },
                ],
                'menu_id' => [
                    'required' => false,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => static function ($value) {
                        return is_scalar($value) && ((int) $value === 0 || (int) $value > 0);
                    },
                ],
            ],
        ]);

        register_rest_route('webmcp/v1', '/categories', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_categories'],
            'permission_callback' => static function (WP_REST_Request $request) {
                return REST::authorize_tool($request, 'wp_get_categories', false);
            },
            'args' => [
                'taxonomy' => [
                    'required' => false,
                    'default' => 'category',
                    'sanitize_callback' => 'sanitize_key',
                    'validate_callback' => static function ($value) {
                        return is_scalar($value) && preg_match('/^[a-z0-9_\-]{1,32}$/', (string) $value);
                    },
                ],
                'hide_empty' => [
                    'required' => false,
                    'default' => true,
                    'sanitize_callback' => static function ($value) {
                        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false;
                    },
                ],
                'page' => [
                    'required' => false,
                    'default' => 1,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => static function ($value) {
                        return is_scalar($value) && (int) $value >= 1 && (int) $value <= 100;
                    },
                ],
                'per_page' => [
                    'required' => false,
                    'default' => 20,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => static function ($value) {
                        return is_scalar($value) && (int) $value >= 1 && (int) $value <= 100;
                    },
                ],
            ],
        ]);

        register_rest_route('webmcp/v1', '/site-info', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'site_info'],
            'permission_callback' => static function (WP_REST_Request $request) {
                return REST::authorize_tool($request, 'wp_get_site_info', false);
            },
        ]);
    }

    public static function get_menu(WP_REST_Request $request): WP_REST_Response {
        $locations = function_exists('get_nav_menu_locations') ? get_nav_menu_locations() : [];
        $location = sanitize_key((string) $request->get_param('location'));
        $menu_id = absint($request->get_param('menu_id'));

        if (!$menu_id && $location !== '' && !empty($locations[$location])) {
            $menu_id = absint($locations[$location]);
        }
        if (!$menu_id && !empty($locations)) {
            $menu_id = absint(reset($locations));
            $location = $location ?: (string) array_key_first($locations);
        }

        if (!$menu_id || !function_exists('wp_get_nav_menu_items')) {
            return new WP_REST_Response(['error' => 'No public navigation menu is configured'], 404);
        }

        $menu = wp_get_nav_menu_object($menu_id);
        if (!$menu) return new WP_REST_Response(['error' => 'Navigation menu not found'], 404);

        $items = wp_get_nav_menu_items($menu_id, ['update_post_term_cache' => false]);
        $output = [];
        foreach (is_array($items) ? array_slice($items, 0, 100) : [] as $item) {
            if (!is_object($item)) continue;
            $output[] = [
                'id'       => absint($item->ID),
                'parent_id' => absint($item->menu_item_parent),
                'title'    => sanitize_text_field((string) $item->title),
                'url'      => esc_url_raw((string) $item->url),
                'target'   => sanitize_key((string) $item->target),
                'order'    => absint($item->menu_order),
            ];
        }

        return new WP_REST_Response([
            'menu_id'  => $menu_id,
            'location' => $location,
            'name'     => sanitize_text_field((string) $menu->name),
            'items'    => $output,
        ], 200);
    }

    public static function get_categories(WP_REST_Request $request): WP_REST_Response {
        $taxonomy = sanitize_key((string) ($request->get_param('taxonomy') ?: 'category'));
        $tax = get_taxonomy($taxonomy);
        if (!$tax || empty($tax->public)) {
            return new WP_REST_Response(['error' => 'Taxonomy is not public'], 404);
        }

        $page = max(1, min(100, absint($request->get_param('page') ?: 1)));
        $per_page = max(1, min(100, absint($request->get_param('per_page') ?: 20)));
        $terms = get_terms([
            'taxonomy'   => $taxonomy,
            'hide_empty' => (bool) $request->get_param('hide_empty'),
            'number'     => $per_page,
            'offset'     => ($page - 1) * $per_page,
        ]);
        if (is_wp_error($terms)) {
            return new WP_REST_Response(['error' => 'Terms are unavailable'], 500);
        }

        $output = [];
        foreach (is_array($terms) ? $terms : [] as $term) {
            $term_link = get_term_link($term);
            $output[] = [
                'id'          => absint($term->term_id),
                'name'        => sanitize_text_field((string) $term->name),
                'slug'        => sanitize_title((string) $term->slug),
                'description' => mb_substr(wp_strip_all_tags((string) $term->description), 0, 500),
                'count'       => absint($term->count),
                'link'        => is_wp_error($term_link) ? '' : esc_url_raw((string) $term_link),
            ];
        }

        return new WP_REST_Response([
            'taxonomy' => $taxonomy,
            'page'     => $page,
            'per_page' => $per_page,
            'terms'    => $output,
            'has_more' => count($output) === $per_page,
        ], 200);
    }

    public static function site_info(WP_REST_Request $request): WP_REST_Response {
        $timezone = wp_timezone_string();
        if ($timezone === '') $timezone = 'UTC';

        return new WP_REST_Response([
            'name'        => sanitize_text_field((string) get_bloginfo('name')),
            'description' => mb_substr(wp_strip_all_tags((string) get_bloginfo('description')), 0, 500),
            'url'         => esc_url_raw(home_url('/')),
            'site_url'    => esc_url_raw(site_url('/')),
            'language'    => sanitize_text_field((string) get_bloginfo('language')),
            'locale'      => sanitize_text_field((string) get_locale()),
            'timezone'    => sanitize_text_field($timezone),
            'is_ssl'      => is_ssl(),
        ], 200);
    }
}
