<?php
namespace WP_WebMCP_Layer;

use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) exit;

/**
 * Local discovery surfaces for WebMCP-aware agents.
 *
 * Discovery is intentionally descriptive: it advertises the same visitor-
 * filtered registry used by the browser bridge and never creates an OAuth or
 * external relay dependency.
 */
final class Discovery {

    public static function init(): void {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_action('template_redirect', [__CLASS__, 'well_known'], 0);
        add_filter('wp_headers', [__CLASS__, 'headers']);
        add_action('wp_head', [__CLASS__, 'head_links'], 1);
    }

    public static function register_routes(): void {
        register_rest_route('webmcp/v1', '/manifest', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'manifest'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('webmcp/v1', '/discovery', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'discovery'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('webmcp/v1', '/nonce', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'nonce'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function manifest(WP_REST_Request $request): WP_REST_Response {
        $enabled = (bool) Plugin::opt('enabled', 1);
        $tools = $enabled ? Tools::frontend_definitions(true) : [];
        $base = rest_url('webmcp/v1');

        $public_tools = [];
        foreach ($tools as $key => $tool) {
            $public_tools[$key] = [
                'name'        => $tool['name'],
                'title'       => $tool['title'],
                'description' => $tool['description'],
                'inputSchema' => $tool['inputSchema'],
                'outputSchema' => $tool['outputSchema'],
                'annotations' => $tool['annotations'],
                'method'      => $tool['method'] ?? 'GET',
                'path'        => $tool['path'] ?? '',
            ];
        }

        $response = new WP_REST_Response([
            'schema_version' => '1.0',
            'plugin_version' => defined('WP_WEBMCP_LAYER_VERSION') ? WP_WEBMCP_LAYER_VERSION : '',
            'site_url'       => esc_url_raw(home_url('/')),
            'enabled'        => $enabled,
            'browser_api'    => 'document.modelContext.registerTool',
            'rest_base'      => esc_url_raw($base),
            'nonce_url'      => esc_url_raw($base . '/nonce'),
            'discovery_url'  => esc_url_raw($base . '/discovery'),
            'tools'          => $public_tools,
            'authentication' => [
                'transport' => 'same-origin',
                'nonce_header' => 'X-WP-Nonce',
                'state_changing_tools' => 'wp_rest nonce + capability checks',
            ],
        ], 200);
        $response->header('Cache-Control', 'private, no-store');
        $response->header('Vary', 'Cookie');
        return $response;
    }

    public static function discovery(WP_REST_Request $request): WP_REST_Response {
        $base = rest_url('webmcp/v1');
        $urls = [
            'manifest'       => $base . '/manifest',
            'nonce'          => $base . '/nonce',
            'server_card'    => home_url('/.well-known/mcp/server-card.json'),
            'api_catalog'    => home_url('/.well-known/api-catalog'),
            'agent_skills'   => home_url('/.well-known/agent-skills/index.json'),
            'webmcp_manifest' => home_url('/.well-known/webmcp-manifest'),
        ];

        $response = new WP_REST_Response([
            'schema_version' => '1.0',
            'urls'           => array_map('esc_url_raw', $urls),
            'link_relations' => ['service-desc', 'service-doc', 'webmcp-manifest', 'api-catalog', 'mcp-server-card', 'agent-skills'],
        ], 200);
        $response->header('Cache-Control', 'private, no-store');
        $response->header('Vary', 'Cookie');
        return $response;
    }

    public static function nonce(WP_REST_Request $request): WP_REST_Response {
        $response = new WP_REST_Response([
            'nonce'        => wp_create_nonce('wp_rest'),
            'header'       => 'X-WP-Nonce',
            'rest_base'    => esc_url_raw(rest_url('webmcp/v1')),
            'authenticated' => is_user_logged_in(),
        ], 200);
        $response->header('Cache-Control', 'private, no-store');
        $response->header('Vary', 'Cookie');
        return $response;
    }

    public static function headers(array $headers): array {
        if (is_admin()) return $headers;

        $base = rest_url('webmcp/v1');
        $links = [
            '<' . esc_url_raw($base . '/manifest') . '>; rel="service-desc"; type="application/json"',
            '<' . esc_url_raw($base . '/manifest') . '>; rel="webmcp-manifest"; type="application/json"',
            '<' . esc_url_raw($base . '/discovery') . '>; rel="service-doc"; type="application/json"',
            '<' . esc_url_raw(home_url('/.well-known/api-catalog')) . '>; rel="api-catalog"; type="application/json"',
            '<' . esc_url_raw(home_url('/.well-known/mcp/server-card.json')) . '>; rel="mcp-server-card"; type="application/json"',
        ];

        $headers['Link'] = isset($headers['Link']) ? $headers['Link'] . ', ' . implode(', ', $links) : implode(', ', $links);
        $headers['Vary'] = isset($headers['Vary']) ? $headers['Vary'] . ', Cookie' : 'Cookie';
        if (empty($headers['Permissions-Policy'])) $headers['Permissions-Policy'] = 'tools=(self)';
        return $headers;
    }

    public static function head_links(): void {
        if (is_admin()) return;

        $base = rest_url('webmcp/v1');
        $links = [
            [$base . '/manifest', 'service-desc'],
            [$base . '/manifest', 'webmcp-manifest'],
            [$base . '/discovery', 'service-doc'],
            [home_url('/.well-known/api-catalog'), 'api-catalog'],
            [home_url('/.well-known/mcp/server-card.json'), 'mcp-server-card'],
        ];
        foreach ($links as $link) {
            echo '<link rel="' . esc_attr($link[1]) . '" href="' . esc_url($link[0]) . '" type="application/json" />' . "\n";
        }
    }

    public static function well_known(): void {
        $request_path = wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $path = untrailingslashit((string) $request_path);
        $well_known = static function (string $suffix): string {
            return untrailingslashit((string) wp_parse_url(home_url('/.well-known/' . ltrim($suffix, '/')), PHP_URL_PATH));
        };
        $payload = null;

        if ($path === $well_known('mcp/server-card.json')) {
            $manifest = self::manifest(new WP_REST_Request('GET', '/webmcp/v1/manifest'))->get_data();
            $payload = [
                'name'           => sanitize_text_field((string) get_bloginfo('name')),
                'description'    => wp_strip_all_tags((string) get_bloginfo('description')),
                'url'            => esc_url_raw(home_url('/')),
                'protocol'       => 'webmcp',
                'browser_api'    => 'document.modelContext.registerTool',
                'manifest_url'   => esc_url_raw(rest_url('webmcp/v1/manifest')),
                'authentication' => ['same-origin-cookie', 'wp-rest-nonce'],
                'tools'          => array_keys((array) ($manifest['tools'] ?? [])),
            ];
        } elseif ($path === $well_known('api-catalog')) {
            $payload = [
                'version' => '1',
                'apis'    => [[
                    'name'        => 'WordPress WebMCP',
                    'type'        => 'webmcp',
                    'description' => 'Visitor-filtered browser tools backed by WordPress REST routes.',
                    'manifest'    => esc_url_raw(rest_url('webmcp/v1/manifest')),
                    'discovery'   => esc_url_raw(rest_url('webmcp/v1/discovery')),
                ]],
            ];
        } elseif ($path === $well_known('agent-skills/index.json')) {
            $manifest = self::manifest(new WP_REST_Request('GET', '/webmcp/v1/manifest'))->get_data();
            $encoded = wp_json_encode($manifest, JSON_UNESCAPED_SLASHES);
            $payload = [
                'version' => '0.2.0',
                'skills'  => [[
                    'id'          => 'wordpress-webmcp',
                    'name'        => 'WordPress WebMCP tools',
                    'description' => 'Structured tools exposed by this WordPress site.',
                    'url'         => esc_url_raw(rest_url('webmcp/v1/manifest')),
                    'sha256'      => hash('sha256', (string) $encoded),
                ]],
            ];
        } elseif ($path === $well_known('webmcp-manifest')) {
            $payload = self::manifest(new WP_REST_Request('GET', '/webmcp/v1/manifest'))->get_data();
        }

        if ($payload === null) return;

        status_header(200);
        header('Content-Type: application/json; charset=' . get_bloginfo('charset'));
        header('Cache-Control: private, no-store');
        header('Vary: Cookie');
        echo wp_json_encode($payload, JSON_UNESCAPED_SLASHES);
        exit;
    }
}
