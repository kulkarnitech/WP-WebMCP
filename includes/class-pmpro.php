<?php
namespace WP_WebMCP_Layer;

if (!defined('ABSPATH')) exit;

final class PMPro {
    public static function init(): void {
        // nothing needed for now
    }

    public static function user_has_access(int $post_id, int $user_id): bool {
        // If PMPro not installed, treat as public.
        if (!function_exists('pmpro_has_membership_access')) return true;

        $user = $user_id ? get_user_by('id', $user_id) : wp_get_current_user();
        $post = get_post($post_id);
        if (!$post) return false;

        // PMPro expects WP_User/WP_Post context in many places; simplest call:
        $has = pmpro_has_membership_access($post_id, $user_id, true);
        return (bool) $has;
    }
}
