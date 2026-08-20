<?php
namespace WP_WebMCP_Layer;

use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) exit;

/** Paid Memberships Pro read-only adapter. */
final class PMPro {

    public static function init(): void {
        add_action('wp_webmcp_register_tools', [__CLASS__, 'register_tools'], 40);
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_tools(Tools $registry): void {
        $registry->register([
            'key' => 'pmpro_memberships',
            'name' => 'pmpro_memberships',
            'title' => __('Get my memberships', 'wp-webmcp-layer'),
            'description' => __('Returns the signed-in visitor’s active and historical Paid Memberships Pro levels without exposing another user’s profile.', 'wp-webmcp-layer'),
            'inputSchema' => ['type' => 'object', 'properties' => (object) [], 'required' => [], 'additionalProperties' => false],
            'outputSchema' => ['type' => 'object'],
            'annotations' => ['readOnlyHint' => true, 'untrustedContentHint' => false],
            'option' => 'tool_pmpro_memberships',
            'default_enabled' => true,
            'capability' => 'read',
            'capability_option' => 'cap_pmpro_memberships',
            'requires_pmpro' => true,
            'method' => 'GET',
            'path' => '/pmpro/memberships',
            'ability_name' => 'webmcp/pmpro-memberships',
            'execute_callback' => static function (array $input) {
                return self::memberships(new WP_REST_Request('GET', '/webmcp/v1/pmpro/memberships'))->get_data();
            },
        ]);
    }

    public static function register_routes(): void {
        if (!function_exists('pmpro_has_membership_access')) return;

        register_rest_route('webmcp/v1', '/pmpro/memberships', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'memberships'],
            'permission_callback' => static function (WP_REST_Request $request) {
                return REST::authorize_tool($request, 'pmpro_memberships', false);
            },
        ]);
    }

    public static function memberships(WP_REST_Request $request): WP_REST_Response {
        if (!is_user_logged_in()) return new WP_REST_Response(['error' => 'Login required'], 401);
        $user_id = get_current_user_id();
        $levels = [];

        if (function_exists('pmpro_getMembershipLevelsForUser')) {
            $raw_levels = pmpro_getMembershipLevelsForUser($user_id);
            foreach (is_array($raw_levels) ? $raw_levels : [] as $level) {
                if (is_object($level)) $levels[] = self::level($level);
            }
        }

        $current = null;
        if (function_exists('pmpro_getMembershipLevelForUser')) {
            $level = pmpro_getMembershipLevelForUser($user_id);
            if (is_object($level)) $current = self::level($level);
        }

        if ($current && !$levels) $levels[] = $current;
        return new WP_REST_Response([
            'has_membership' => !empty($levels),
            'current' => $current,
            'memberships' => $levels,
        ], 200);
    }

    public static function user_has_access(int $post_id, int $user_id): bool {
        if (!function_exists('pmpro_has_membership_access')) return true;
        $post = get_post($post_id);
        if (!$post) return false;
        return (bool) pmpro_has_membership_access($post_id, $user_id, true);
    }

    private static function level($level): array {
        $get = static function ($name, $default = '') use ($level) {
            return isset($level->{$name}) ? $level->{$name} : $default;
        };

        return [
            'id' => absint($get('id', $get('ID', 0))),
            'name' => sanitize_text_field((string) $get('name')),
            'description' => mb_substr(wp_strip_all_tags((string) $get('description')), 0, 500),
            'status' => sanitize_key((string) $get('status', 'active')),
            'startdate' => sanitize_text_field((string) $get('startdate')),
            'enddate' => sanitize_text_field((string) $get('enddate')),
        ];
    }
}
