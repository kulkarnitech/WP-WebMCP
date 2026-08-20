<?php
namespace WP_WebMCP_Layer;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) exit;

/**
 * BuddyPress/BuddyBoss read-only adapter.
 *
 * Both products expose the BuddyPress REST namespace. Calling that controller
 * through rest_do_request() keeps its privacy and private-network rules in
 * force instead of duplicating them with direct database queries.
 */
final class Community {

    public static function init(): void {
        add_action('wp_webmcp_register_tools', [__CLASS__, 'register_tools'], 20);
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function is_active(): bool {
        return function_exists('buddypress')
            || defined('BP_VERSION')
            || class_exists('BuddyPress')
            || class_exists('BuddyBoss_Platform')
            || class_exists('BuddyBossPlatform');
    }

    public static function register_tools(Tools $registry): void {
        if (!self::is_active()) return;

        $registry->register([
            'key'              => 'bp_members',
            'name'             => 'bp_members',
            'title'            => __('Find community members', 'wp-webmcp-layer'),
            'description'      => __('Returns public or visitor-authorized BuddyPress/BuddyBoss member profiles.', 'wp-webmcp-layer'),
            'inputSchema'      => [
                'type'       => 'object',
                'properties' => [
                    'search'   => ['type' => 'string', 'maxLength' => 100, 'description' => __('Optional member search text.', 'wp-webmcp-layer')],
                    'page'     => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => __('Page number.', 'wp-webmcp-layer')],
                    'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'description' => __('Results per page.', 'wp-webmcp-layer')],
                ],
                'additionalProperties' => false,
            ],
            'outputSchema'    => [
                'type'       => 'object',
                'properties' => ['members' => ['type' => 'array']],
                'required'   => ['members'],
            ],
            'annotations'     => ['readOnlyHint' => true, 'untrustedContentHint' => true],
            'option'          => 'tool_bp_members',
            'default_enabled' => true,
            'capability_option' => 'cap_bp_members',
            'capability'      => 'read',
            'method'          => 'GET',
            'path'            => '/community/members',
            'ability_name'    => 'webmcp/bp-members',
            'execute_callback' => static function (array $input) {
                $request = new WP_REST_Request('GET', '/webmcp/v1/community/members');
                foreach (['search', 'page', 'per_page'] as $param) {
                    if (array_key_exists($param, $input)) $request->set_param($param, $input[$param]);
                }

                return self::members($request)->get_data();
            },
        ]);
    }

    public static function register_routes(): void {
        if (!self::is_active()) return;

        register_rest_route('webmcp/v1', '/community/members', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'members'],
            'permission_callback' => static function (WP_REST_Request $request) {
                return REST::authorize_tool($request, 'bp_members', false);
            },
            'args' => [
                'search' => [
                    'required' => false,
                    'sanitize_callback' => static function ($param) {
                        return mb_substr(sanitize_text_field((string) $param), 0, 100);
                    },
                    'validate_callback' => static function ($param) {
                        return is_scalar($param) && mb_strlen((string) $param) <= 100;
                    },
                ],
                'page' => [
                    'required' => false,
                    'default' => 1,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => static function ($param) {
                        return is_scalar($param) && (int) $param >= 1 && (int) $param <= 100;
                    },
                ],
                'per_page' => [
                    'required' => false,
                    'default' => 20,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => static function ($param) {
                        return is_scalar($param) && (int) $param >= 1 && (int) $param <= 50;
                    },
                ],
            ],
        ]);
    }

    public static function members(WP_REST_Request $request): WP_REST_Response {
        if (!self::is_active() || !function_exists('rest_do_request')) {
            return new WP_REST_Response(['error' => 'BuddyPress/BuddyBoss is not active'], 400);
        }

        $search = mb_substr(sanitize_text_field((string) $request->get_param('search')), 0, 100);
        $page = max(1, min(100, absint($request->get_param('page') ?: 1)));
        $per_page = max(1, min(50, absint($request->get_param('per_page') ?: 20)));

        $bp_request = new WP_REST_Request('GET', '/buddypress/v1/members');
        $bp_request->set_param('page', $page);
        $bp_request->set_param('per_page', $per_page);
        if ($search !== '') $bp_request->set_param('search', $search);

        $response = rest_do_request($bp_request);
        if ($response instanceof WP_Error) {
            return new WP_REST_Response(['error' => 'Community members are unavailable'], 500);
        }

        if (!($response instanceof WP_REST_Response)) {
            return new WP_REST_Response(['error' => 'Community endpoint returned an invalid response'], 502);
        }

        $status = $response->get_status();
        if ($status >= 400) {
            return new WP_REST_Response(['error' => 'Community members are unavailable'], $status);
        }

        $data = $response->get_data();
        $members = [];
        foreach (is_array($data) ? $data : [] as $member) {
            if (!is_array($member)) continue;

            $avatar = '';
            if (!empty($member['avatar_urls']) && is_array($member['avatar_urls'])) {
                $avatar = esc_url_raw((string) reset($member['avatar_urls']));
            }

            $members[] = [
                'id'         => isset($member['id']) ? absint($member['id']) : 0,
                'name'       => isset($member['name']) ? sanitize_text_field((string) $member['name']) : '',
                'mention_name' => isset($member['mention_name']) ? sanitize_text_field((string) $member['mention_name']) : '',
                'link'       => isset($member['link']) ? esc_url_raw((string) $member['link']) : '',
                'avatar_url' => $avatar,
            ];
        }

        return new WP_REST_Response(['members' => $members], 200);
    }
}
