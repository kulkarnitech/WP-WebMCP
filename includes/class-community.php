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

        $registry->register([
            'key' => 'bp_groups', 'name' => 'bp_groups',
            'title' => __('Find community groups', 'wp-webmcp-layer'),
            'description' => __('Returns groups visible to the current BuddyPress/BuddyBoss visitor.', 'wp-webmcp-layer'),
            'inputSchema' => ['type' => 'object', 'properties' => [
                'search' => ['type' => 'string', 'maxLength' => 100],
                'page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
            ], 'additionalProperties' => false],
            'outputSchema' => ['type' => 'object'],
            'annotations' => ['readOnlyHint' => true, 'untrustedContentHint' => true],
            'option' => 'tool_bp_groups', 'default_enabled' => true,
            'capability_option' => 'cap_bp_groups', 'capability' => 'read',
            'method' => 'GET', 'path' => '/community/groups',
            'ability_name' => 'webmcp/bp-groups',
            'execute_callback' => static function (array $input) {
                $request = new WP_REST_Request('GET', '/webmcp/v1/community/groups');
                foreach (['search', 'page', 'per_page'] as $param) if (array_key_exists($param, $input)) $request->set_param($param, $input[$param]);
                return self::groups($request)->get_data();
            },
        ]);

        $registry->register([
            'key' => 'bp_activity', 'name' => 'bp_activity',
            'title' => __('Read community activity', 'wp-webmcp-layer'),
            'description' => __('Returns activity entries visible to the current BuddyPress/BuddyBoss visitor.', 'wp-webmcp-layer'),
            'inputSchema' => ['type' => 'object', 'properties' => [
                'page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
                'component' => ['type' => 'string', 'maxLength' => 32],
            ], 'additionalProperties' => false],
            'outputSchema' => ['type' => 'object'],
            'annotations' => ['readOnlyHint' => true, 'untrustedContentHint' => true],
            'option' => 'tool_bp_activity', 'default_enabled' => true,
            'capability_option' => 'cap_bp_activity', 'capability' => 'read',
            'method' => 'GET', 'path' => '/community/activity',
            'ability_name' => 'webmcp/bp-activity',
            'execute_callback' => static function (array $input) {
                $request = new WP_REST_Request('GET', '/webmcp/v1/community/activity');
                foreach (['page', 'per_page', 'component'] as $param) if (array_key_exists($param, $input)) $request->set_param($param, $input[$param]);
                return self::activity($request)->get_data();
            },
        ]);

        $registry->register([
            'key' => 'bp_activity_create', 'name' => 'bp_activity_create',
            'title' => __('Publish community activity', 'wp-webmcp-layer'),
            'description' => __('Publishes a short activity update as the signed-in visitor after confirmation.', 'wp-webmcp-layer'),
            'inputSchema' => ['type' => 'object', 'properties' => [
                'content' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500],
                'idempotency_key' => ['type' => 'string', 'maxLength' => 128],
            ], 'required' => ['content'], 'additionalProperties' => false],
            'outputSchema' => ['type' => 'object'],
            'annotations' => ['readOnlyHint' => false, 'untrustedContentHint' => false],
            'confirmation' => true, 'idempotent' => true,
            'option' => 'tool_bp_activity_create', 'default_enabled' => false,
            'capability_option' => 'cap_bp_activity_create', 'capability' => 'publish_posts',
            'method' => 'POST', 'path' => '/community/activity',
            'ability_name' => 'webmcp/bp-activity-create',
            'execute_callback' => static function (array $input) {
                $request = new WP_REST_Request('POST', '/webmcp/v1/community/activity');
                $request->set_param('content', $input['content'] ?? '');
                if (isset($input['idempotency_key'])) $request->set_param('idempotency_key', $input['idempotency_key']);
                $response = self::activity_create($request);
                return $response instanceof WP_REST_Response ? $response->get_data() : $response;
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

        register_rest_route('webmcp/v1', '/community/groups', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'groups'],
            'permission_callback' => static function (WP_REST_Request $request) { return REST::authorize_tool($request, 'bp_groups', false); },
            'args' => self::collection_args(true),
        ]);

        register_rest_route('webmcp/v1', '/community/activity', [
            'methods' => ['GET', 'POST'],
            'callback' => static function (WP_REST_Request $request) {
                return $request->get_method() === 'POST' ? self::activity_create($request) : self::activity($request);
            },
            'permission_callback' => static function (WP_REST_Request $request) {
                return REST::authorize_tool($request, $request->get_method() === 'POST' ? 'bp_activity_create' : 'bp_activity', $request->get_method() === 'POST');
            },
            'args' => self::collection_args(false) + [
                'component' => ['required' => false, 'sanitize_callback' => 'sanitize_key', 'validate_callback' => static function ($v) { return is_scalar($v) && mb_strlen((string) $v) <= 32; }],
                'content' => ['required' => false, 'sanitize_callback' => static function ($v) { return mb_substr(sanitize_textarea_field((string) $v), 0, 500); }, 'validate_callback' => static function ($v) { return is_scalar($v) && trim((string) $v) !== '' && mb_strlen((string) $v) <= 500; }],
                'idempotency_key' => ['required' => false, 'sanitize_callback' => static function ($v) { return mb_substr((string) preg_replace('/[^A-Za-z0-9._:-]/', '', (string) $v), 0, 128); }],
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

    public static function groups(WP_REST_Request $request): WP_REST_Response {
        $params = ['page' => max(1, min(100, absint($request->get_param('page') ?: 1))), 'per_page' => max(1, min(50, absint($request->get_param('per_page') ?: 20)))];
        if ($request->get_param('search')) $params['search'] = mb_substr(sanitize_text_field((string) $request->get_param('search')), 0, 100);
        $response = self::bp_request('/buddypress/v1/groups', $params);
        if ($response instanceof WP_Error || !($response instanceof WP_REST_Response) || $response->get_status() >= 400) return new WP_REST_Response(['error' => 'Community groups are unavailable'], $response instanceof WP_REST_Response ? $response->get_status() : 500);
        $groups = [];
        foreach ((array) $response->get_data() as $group) {
            if (!is_array($group)) continue;
            $groups[] = ['id' => absint($group['id'] ?? 0), 'name' => sanitize_text_field((string) ($group['name'] ?? '')), 'description' => mb_substr(wp_strip_all_tags((string) ($group['description']['rendered'] ?? $group['description'] ?? '')), 0, 500), 'link' => esc_url_raw((string) ($group['link'] ?? '')), 'status' => sanitize_key((string) ($group['status'] ?? '')), 'members_count' => absint($group['total_member_count'] ?? $group['members_count'] ?? 0)];
        }
        return new WP_REST_Response(['groups' => $groups], 200);
    }

    public static function activity(WP_REST_Request $request): WP_REST_Response {
        $params = ['page' => max(1, min(100, absint($request->get_param('page') ?: 1))), 'per_page' => max(1, min(50, absint($request->get_param('per_page') ?: 20)))];
        if ($request->get_param('component')) $params['component'] = sanitize_key((string) $request->get_param('component'));
        $response = self::bp_request('/buddypress/v1/activity', $params);
        if ($response instanceof WP_Error || !($response instanceof WP_REST_Response) || $response->get_status() >= 400) return new WP_REST_Response(['error' => 'Community activity is unavailable'], $response instanceof WP_REST_Response ? $response->get_status() : 500);
        $items = [];
        foreach ((array) $response->get_data() as $item) {
            if (!is_array($item)) continue;
            $content = $item['content']['rendered'] ?? $item['content'] ?? '';
            $items[] = ['id' => absint($item['id'] ?? 0), 'content' => mb_substr(wp_strip_all_tags((string) $content), 0, 500), 'link' => esc_url_raw((string) ($item['link'] ?? '')), 'date' => sanitize_text_field((string) ($item['date'] ?? '')), 'component' => sanitize_key((string) ($item['component'] ?? '')), 'type' => sanitize_key((string) ($item['type'] ?? ''))];
        }
        return new WP_REST_Response(['activity' => $items], 200);
    }

    public static function activity_create(WP_REST_Request $request) {
        return REST::with_idempotency($request, 'bp_activity_create', static function () use ($request) {
            if (!function_exists('rest_do_request') || !is_user_logged_in()) return new WP_REST_Response(['error' => 'Activity publishing is unavailable'], 400);
            $content = mb_substr(sanitize_textarea_field((string) $request->get_param('content')), 0, 500);
            if ($content === '') return new WP_REST_Response(['error' => 'Content is required'], 400);

            // Delegate to BuddyPress/BuddyBoss's permission-aware REST
            // controller instead of inserting directly with bp_activity_add().
            $bp_request = new WP_REST_Request('POST', '/buddypress/v1/activity');
            $bp_request->set_param('content', $content);
            $bp_request->set_param('user_id', get_current_user_id());
            $response = rest_do_request($bp_request);
            if ($response instanceof WP_Error) return new WP_REST_Response(['error' => 'Activity could not be published'], 403);
            if (!($response instanceof WP_REST_Response) || $response->get_status() >= 400) {
                $status = $response instanceof WP_REST_Response ? max(400, min(599, (int) $response->get_status())) : 502;
                return new WP_REST_Response(['error' => 'Activity could not be published'], $status);
            }

            $data = $response->get_data();
            $activity_id = is_array($data) ? absint($data['id'] ?? ($data[0]['id'] ?? 0)) : 0;
            if (!$activity_id) return new WP_REST_Response(['error' => 'Activity could not be published'], 502);
            return new WP_REST_Response(['ok' => true, 'activity_id' => absint($activity_id), 'content' => $content], 201);
        });
    }

    private static function bp_request(string $route, array $params = []) {
        if (!self::is_active() || !function_exists('rest_do_request')) return new WP_Error('webmcp_community_inactive');
        $request = new WP_REST_Request('GET', $route);
        foreach ($params as $key => $value) $request->set_param($key, $value);
        return rest_do_request($request);
    }

    private static function collection_args(bool $include_search): array {
        $args = [
            'page' => ['required' => false, 'default' => 1, 'sanitize_callback' => 'absint', 'validate_callback' => static function ($v) { return is_scalar($v) && (int) $v >= 1 && (int) $v <= 100; }],
            'per_page' => ['required' => false, 'default' => 20, 'sanitize_callback' => 'absint', 'validate_callback' => static function ($v) { return is_scalar($v) && (int) $v >= 1 && (int) $v <= 50; }],
        ];
        if ($include_search) $args['search'] = ['required' => false, 'sanitize_callback' => static function ($v) { return mb_substr(sanitize_text_field((string) $v), 0, 100); }, 'validate_callback' => static function ($v) { return is_scalar($v) && mb_strlen((string) $v) <= 100; }];
        return $args;
    }
}
