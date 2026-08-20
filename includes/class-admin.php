<?php
namespace WP_WebMCP_Layer;

if (!defined('ABSPATH')) exit;

final class Admin {

    public const OPTION_KEY = 'wp_webmcp_layer_options';
    public const PAGE_SLUG  = 'wp-webmcp-layer';

    /**
     * Capabilities list for dropdowns (practical subset).
     * Add more if you need.
     */
    private static function capability_choices(): array {
        return [
            ''                   => 'Public (no login required)',
            'read'               => 'read (any logged-in user)',
            'edit_posts'         => 'edit_posts (Authors+)',
            'publish_posts'      => 'publish_posts (Authors+)',
            'edit_pages'         => 'edit_pages (Editors+)',
            'manage_options'     => 'manage_options (Admins)',
            'manage_woocommerce' => 'manage_woocommerce (Woo Managers/Admins)',
        ];
    }

    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_init', [__CLASS__, 'settings']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_assets']);
    }

    public static function menu(): void {
        add_options_page(
            'WP WebMCP Layer',
            'WP WebMCP Layer',
            'manage_options',
            self::PAGE_SLUG,
            [__CLASS__, 'render']
        );
    }

    public static function settings(): void {

        register_setting(self::PAGE_SLUG, self::OPTION_KEY, [
            'type'              => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitize'],
            'default'           => [
                // Master
                'enabled'              => 1,

                // Tool toggles
                'tool_wp_search'       => 1,
                'tool_wp_get_post'     => 1,
                'tool_woo_cart_view'   => 1,
                'tool_woo_cart_add'    => 1,
                'tool_bp_members'      => 1,
                'tool_wp_get_menu'     => 1,
                'tool_wp_get_categories' => 1,
                'tool_wp_get_site_info' => 1,
                'tool_woo_product_search' => 1,
                'tool_woo_product_get' => 1,
                'tool_woo_product_categories' => 1,
                'tool_woo_checkout_fields' => 1,
                'tool_woo_cart_remove' => 1,
                'tool_woo_coupon_apply' => 1,
                'tool_pmpro_memberships' => 1,
                'tool_bp_groups' => 1,
                'tool_bp_activity' => 1,
                'tool_bp_activity_create' => 0,

                // Role/cap gates for tool *exposure* (frontend) + intended REST enforcement
                'cap_wp_search'        => '',
                'cap_wp_get_post'      => '',
                'cap_woo_cart_view'    => 'read',
                'cap_woo_cart_add'     => 'read',
                'cap_bp_members'      => 'read',
                'cap_pmpro_memberships' => 'read',
                'cap_bp_groups' => 'read',
                'cap_bp_activity' => 'read',
                'cap_bp_activity_create' => 'read',

                // Rate limiting (intended for REST; UI + option storage here)
                'rate_limit_enabled'   => 1,
                'rate_limit_window'    => 60,  // seconds
                'rate_limit_max'       => 60,  // requests per window per IP
                'rate_limit_global_enabled' => 1,
                'rate_limit_global_max' => 120,
                'request_size_limit' => 102400,
                'schema_max_depth' => 8,
                'idempotency_ttl' => 300,
            ],
        ]);

        // =======================
        // Main settings
        // =======================
        add_settings_section(
            'wp_webmcp_main',
            'Main Settings',
            function () {
                echo '<p>Controls what your site exposes via WebMCP. WooCommerce and PMPro features only activate if those plugins are active.</p>';
            },
            self::PAGE_SLUG
        );

        self::add_checkbox('enabled', 'Enable WebMCP layer', 'Master switch to load WebMCP tools on the frontend.');

        // =======================
        // Tool toggles
        // =======================
        add_settings_section(
            'wp_webmcp_tools',
            'Tools',
            function () {
                echo '<p>Enable/disable individual tools.</p>';
            },
            self::PAGE_SLUG
        );

        self::add_checkbox('tool_wp_search', 'Tool: wp_search', 'Expose search tool.', 'wp_webmcp_tools');
        self::add_checkbox('tool_wp_get_post', 'Tool: wp_get_post', 'Expose post retrieval tool (PMPro paywall redaction applies if active).', 'wp_webmcp_tools');
        self::add_checkbox('tool_woo_cart_view', 'Tool: woo_cart_view', 'Expose Woo cart view tool (requires WooCommerce).', 'wp_webmcp_tools');
        self::add_checkbox('tool_woo_cart_add', 'Tool: woo_cart_add', 'Expose Woo add-to-cart tool (requires WooCommerce; should be user-confirmed).', 'wp_webmcp_tools');
        self::add_checkbox('tool_bp_members', 'Tool: bp_members', 'Expose BuddyPress/BuddyBoss member search (requires the community plugin).', 'wp_webmcp_tools');
        foreach (['wp_get_menu', 'wp_get_categories', 'wp_get_site_info', 'woo_product_search', 'woo_product_get', 'woo_product_categories', 'woo_checkout_fields', 'woo_cart_remove', 'woo_coupon_apply', 'pmpro_memberships', 'bp_groups', 'bp_activity', 'bp_activity_create'] as $tool) {
            self::add_checkbox('tool_' . $tool, 'Tool: ' . $tool, 'Expose this tool when its integration is available.', 'wp_webmcp_tools');
        }

        // =======================
        // Role / capability gates
        // =======================
        add_settings_section(
            'wp_webmcp_access',
            'Role-based Exposure',
            function () {
                echo '<p>These gates control whether tools are exposed to the current visitor. Use this to restrict tools to logged-in users or admins only.</p>';
                echo '<p><strong>Note:</strong> You should also enforce the same gates server-side in REST permission callbacks (recommended).</p>';
            },
            self::PAGE_SLUG
        );

        self::add_capability_select('cap_wp_search', 'Capability required: wp_search', 'Who can see/use wp_search tool.');
        self::add_capability_select('cap_wp_get_post', 'Capability required: wp_get_post', 'Who can see/use wp_get_post tool.');
        self::add_capability_select('cap_woo_cart_view', 'Capability required: woo_cart_view', 'Who can see/use cart viewing tool.');
        self::add_capability_select('cap_woo_cart_add', 'Capability required: woo_cart_add', 'Who can see/use add-to-cart tool.');
        self::add_capability_select('cap_bp_members', 'Capability required: bp_members', 'Who can see/use community member search.');
        foreach (['pmpro_memberships', 'bp_groups', 'bp_activity', 'bp_activity_create'] as $tool) {
            self::add_capability_select('cap_' . $tool, 'Capability required: ' . $tool, 'Who can see/use this tool.');
        }

        // =======================
        // Rate limiting settings
        // =======================
        add_settings_section(
            'wp_webmcp_ratelimit',
            'REST Rate Limiting',
            function () {
                echo '<p>Basic rate limiting for WebMCP REST endpoints (per IP). Helps prevent abuse.</p>';
                echo '<p><strong>Note:</strong> This requires server-side checks in the REST handlers. These options store the configuration.</p>';
            },
            self::PAGE_SLUG
        );

        self::add_checkbox('rate_limit_enabled', 'Enable rate limiting', 'Apply rate limiting to WebMCP REST endpoints.');
        self::add_number('rate_limit_window', 'Window (seconds)', 'Time window for counting requests (e.g., 60).', 10, 86400);
        self::add_number('rate_limit_max', 'Max requests per window', 'Max requests per IP per window (e.g., 60).', 1, 100000);
        self::add_checkbox('rate_limit_global_enabled', 'Enable global rate limiting', 'Apply a site-wide request ceiling in addition to the per-IP limit.', 'wp_webmcp_ratelimit');
        self::add_number('rate_limit_global_max', 'Global max requests per window', 'Maximum WebMCP requests for the whole site in one window.', 1, 100000);
        self::add_number('request_size_limit', 'Maximum request size (bytes)', 'Reject oversized WebMCP inputs before tool execution.', 1024, 1048576);
        self::add_number('schema_max_depth', 'Maximum input nesting depth', 'Bounds nested native-ability input validation.', 2, 16);
        self::add_number('idempotency_ttl', 'Idempotency cache (seconds)', 'How long mutation replay responses are retained.', 60, 86400);
    }

    private static function add_checkbox(string $key, string $label, string $help, string $section = ''): void {
        $section = $section ?: 'wp_webmcp_main';

        add_settings_field(
            $key,
            $label,
            function () use ($key, $help) {
                $opts = get_option(self::OPTION_KEY, []);
                $val  = !empty($opts[$key]) ? 1 : 0;

                echo '<label style="display:flex; gap:10px; align-items:center;">';
                echo '<input type="checkbox" name="' . esc_attr(self::OPTION_KEY) . '[' . esc_attr($key) . ']" value="1" ' . checked(1, $val, false) . ' />';
                echo '<span>' . esc_html($help) . '</span>';
                echo '</label>';
            },
            self::PAGE_SLUG,
            $section
        );
    }

    private static function add_number(string $key, string $label, string $help, int $min, int $max): void {
        add_settings_field(
            $key,
            $label,
            function () use ($key, $help, $min, $max) {
                $opts = get_option(self::OPTION_KEY, []);
                $val  = isset($opts[$key]) ? (int) $opts[$key] : 0;

                echo '<div style="display:flex; flex-direction:column; gap:6px;">';
                echo '<input type="number" min="' . esc_attr((string)$min) . '" max="' . esc_attr((string)$max) . '" name="' . esc_attr(self::OPTION_KEY) . '[' . esc_attr($key) . ']" value="' . esc_attr((string)$val) . '" style="width:120px;" />';
                echo '<span style="color:#646970;">' . esc_html($help) . '</span>';
                echo '</div>';
            },
            self::PAGE_SLUG,
            'wp_webmcp_ratelimit'
        );
    }

    private static function add_capability_select(string $key, string $label, string $help): void {
        add_settings_field(
            $key,
            $label,
            function () use ($key, $help) {
                $opts = get_option(self::OPTION_KEY, []);
                $val  = isset($opts[$key]) ? (string) $opts[$key] : '';
                $choices = self::capability_choices();

                echo '<div style="display:flex; flex-direction:column; gap:6px;">';
                echo '<select name="' . esc_attr(self::OPTION_KEY) . '[' . esc_attr($key) . ']">';
                foreach ($choices as $cap => $capLabel) {
                    echo '<option value="' . esc_attr($cap) . '" ' . selected($val, $cap, false) . '>' . esc_html($capLabel) . '</option>';
                }
                echo '</select>';
                echo '<span style="color:#646970;">' . esc_html($help) . '</span>';
                echo '</div>';
            },
            self::PAGE_SLUG,
            'wp_webmcp_access'
        );
    }

    public static function sanitize($input): array {
        $out = [];

        // Master + toggles
        $out['enabled']            = !empty($input['enabled']) ? 1 : 0;

        $out['tool_wp_search']     = !empty($input['tool_wp_search']) ? 1 : 0;
        $out['tool_wp_get_post']   = !empty($input['tool_wp_get_post']) ? 1 : 0;
        $out['tool_woo_cart_view'] = !empty($input['tool_woo_cart_view']) ? 1 : 0;
        $out['tool_woo_cart_add']  = !empty($input['tool_woo_cart_add']) ? 1 : 0;
        $out['tool_bp_members']    = !empty($input['tool_bp_members']) ? 1 : 0;
        foreach (['wp_get_menu', 'wp_get_categories', 'wp_get_site_info', 'woo_product_search', 'woo_product_get', 'woo_product_categories', 'woo_checkout_fields', 'woo_cart_remove', 'woo_coupon_apply', 'pmpro_memberships', 'bp_groups', 'bp_activity', 'bp_activity_create'] as $tool) {
            $out['tool_' . $tool] = !empty($input['tool_' . $tool]) ? 1 : 0;
        }

        // Capability gates (must be from whitelist)
        $choices = self::capability_choices();
        $capKeys = ['cap_wp_search','cap_wp_get_post','cap_woo_cart_view','cap_woo_cart_add','cap_bp_members','cap_pmpro_memberships','cap_bp_groups','cap_bp_activity','cap_bp_activity_create'];
        foreach ($capKeys as $k) {
            $v = isset($input[$k]) ? sanitize_text_field((string)$input[$k]) : '';
            $out[$k] = array_key_exists($v, $choices) ? $v : '';
        }

        // Rate limiting
        $out['rate_limit_enabled'] = !empty($input['rate_limit_enabled']) ? 1 : 0;
        $out['rate_limit_window']  = isset($input['rate_limit_window']) ? max(10, min(86400, (int)$input['rate_limit_window'])) : 60;
        $out['rate_limit_max']     = isset($input['rate_limit_max']) ? max(1, min(100000, (int)$input['rate_limit_max'])) : 60;
        $out['rate_limit_global_enabled'] = !empty($input['rate_limit_global_enabled']) ? 1 : 0;
        $out['rate_limit_global_max'] = isset($input['rate_limit_global_max']) ? max(1, min(100000, (int) $input['rate_limit_global_max'])) : 120;
        $out['request_size_limit'] = isset($input['request_size_limit']) ? max(1024, min(1048576, (int) $input['request_size_limit'])) : 102400;
        $out['schema_max_depth'] = isset($input['schema_max_depth']) ? max(2, min(16, (int) $input['schema_max_depth'])) : 8;
        $out['idempotency_ttl'] = isset($input['idempotency_ttl']) ? max(60, min(86400, (int) $input['idempotency_ttl'])) : 300;

        return $out;
    }

    public static function enqueue_admin_assets(string $hook): void {
        if ($hook !== 'settings_page_' . self::PAGE_SLUG) return;

        wp_enqueue_script(
            'wp-webmcp-admin',
            WP_WEBMCP_LAYER_URL . 'assets/admin.js',
            [],
            WP_WEBMCP_LAYER_VERSION,
            true
        );
        wp_localize_script('wp-webmcp-admin', 'WP_WEBMCP_ADMIN', [
            'restBase' => esc_url_raw(rest_url('webmcp/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
            'manifestUrl' => esc_url_raw(rest_url('webmcp/v1/manifest')),
            'discoveryUrl' => esc_url_raw(rest_url('webmcp/v1/discovery')),
            'definitions' => Tools::frontend_definitions(false),
        ]);
    }

    /**
     * Tool definitions shown in the admin for documentation/preview.
     * The central registry is also the source for frontend registration.
     */
    private static function tool_schemas(): array {
        return Tools::frontend_definitions(false);
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) return;

        $opts = get_option(self::OPTION_KEY, []);
        $enabled = !empty($opts['enabled']);

        $hasWoo   = class_exists('WooCommerce');
        $hasPMPro = function_exists('pmpro_has_membership_access');
        $hasCommunity = function_exists('buddypress') || defined('BP_VERSION') || class_exists('BuddyPress') || class_exists('BuddyBoss_Platform') || class_exists('BuddyBossPlatform');

        // Derived tool enablement (layer + toggle + integration)
        $toolStatus = [];
        foreach (Tools::frontend_definitions(false) as $toolKey => $definition) {
            $toolStatus[$toolKey] = $enabled && Tools::is_enabled($toolKey);
        }

        // Debug info payload (server-side)
        $debug = [
            'plugin' => [
                'version' => defined('WP_WEBMCP_LAYER_VERSION') ? WP_WEBMCP_LAYER_VERSION : null,
                'option_key' => self::OPTION_KEY,
            ],
            'integrations' => [
                'woocommerce_active' => $hasWoo,
                'pmpro_active' => $hasPMPro,
                'community_active' => $hasCommunity,
            ],
            'settings' => $opts,
            'tool_status' => $toolStatus,
            'rest_base' => rest_url('webmcp/v1'),
            'schemas' => self::tool_schemas(),
        ];

        echo '<div class="wrap">';
        echo '<h1>WP WebMCP Layer</h1>';

        // =======================
        // Integration status
        // =======================
        echo '<h2>Integration Status</h2>';
        echo '<ul style="list-style:disc; padding-left:20px;">';
        echo '<li>WooCommerce: <strong>' . ($hasWoo ? 'Active' : 'Not active') . '</strong></li>';
        echo '<li>Paid Memberships Pro: <strong>' . ($hasPMPro ? 'Active' : 'Not active') . '</strong></li>';
        echo '<li>BuddyPress/BuddyBoss: <strong>' . ($hasCommunity ? 'Active' : 'Not active') . '</strong></li>';
        echo '</ul>';

        // =======================
        // Browser support tester (client-side)
        // =======================
        echo '<h2>Browser Support</h2>';
        echo '<p>This checks whether <em>your current browser</em> exposes the WebMCP API.</p>';
        echo '<div style="padding:12px 14px; background:#fff; border:1px solid #c3c4c7; border-radius:6px; max-width:980px;">';
        echo '<div style="display:flex; gap:10px; align-items:center;">';
        echo '<span style="min-width:220px;"><strong>WebMCP Browser API</strong></span>';
        echo '<span id="wp-webmcp-browser-support">Checking…</span>';
        echo '</div>';
        echo '<div class="wp-webmcp-support-detail" style="margin-top:6px; color:#646970;"></div>';
        echo '</div>';

        // =======================
        // Tool summary table
        // =======================
        echo '<h2>Tool Summary</h2>';
        echo '<table class="widefat striped" style="max-width:980px;">';
        echo '<thead><tr><th>Tool</th><th>Status</th><th>Notes</th></tr></thead><tbody>';

        $rows = [
            [
                'name' => 'wp_search',
                'status' => $toolStatus['wp_search'],
                'note' => 'Search posts/pages and products (if Woo active).',
            ],
            [
                'name' => 'wp_get_post',
                'status' => $toolStatus['wp_get_post'],
                'note' => $hasPMPro ? 'PMPro active: paywalled content redacted (title + notice only).' : 'PMPro not active: content treated as public.',
            ],
            [
                'name' => 'woo_cart_view',
                'status' => $toolStatus['woo_cart_view'],
                'note' => $hasWoo ? 'Woo active: nonce-protected endpoint.' : 'Woo not active: tool not registered.',
            ],
            [
                'name' => 'woo_cart_add',
                'status' => $toolStatus['woo_cart_add'],
                'note' => $hasWoo ? 'Woo active: requires user confirmation + nonce-protected endpoint.' : 'Woo not active: tool not registered.',
            ],
        ];

        if (isset($toolStatus['bp_members'])) {
            $rows[] = [
                'name' => 'bp_members',
                'status' => $toolStatus['bp_members'],
                'note' => $hasCommunity ? 'BuddyPress/BuddyBoss active: uses its permission-aware REST controller.' : 'Community plugin not active: tool not registered.',
            ];
        }

        $known = array_map(static function ($row) { return $row['name']; }, $rows);
        foreach ($toolStatus as $tool_name => $status) {
            if (in_array($tool_name, $known, true)) continue;
            $rows[] = [
                'name' => $tool_name,
                'status' => $status,
                'note' => 'Registered through the WebMCP adapter registry; verify integration availability before use.',
            ];
        }

        foreach ($rows as $r) {
            $statusText = $r['status'] ? 'Enabled' : 'Disabled';
            echo '<tr>';
            echo '<td><code>' . esc_html($r['name']) . '</code></td>';
            echo '<td><strong>' . esc_html($statusText) . '</strong></td>';
            echo '<td>' . esc_html($r['note']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        // =======================
        // JSON schema preview
        // =======================
        echo '<h2>Tool Schema Preview</h2>';
        echo '<p>These are the definitions used by frontend registration and optional WordPress Abilities.</p>';
        echo '<div style="max-width:980px;">';
        foreach (self::tool_schemas() as $toolKey => $schema) {
            echo '<details style="background:#fff; border:1px solid #c3c4c7; border-radius:6px; padding:10px 12px; margin:10px 0;">';
            echo '<summary style="cursor:pointer;"><strong><code>' . esc_html($toolKey) . '</code></strong></summary>';
            echo '<pre style="white-space:pre-wrap; margin-top:10px;">' . esc_html(wp_json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>';
            echo '</details>';
        }
        echo '</div>';

        echo '<h2>Discovery and API Examples</h2>';
        echo '<p>Machine-readable discovery is available at <a href="' . esc_url(rest_url('webmcp/v1/manifest')) . '" target="_blank" rel="noopener">manifest</a> and <a href="' . esc_url(rest_url('webmcp/v1/discovery')) . '" target="_blank" rel="noopener">discovery</a>. The plugin does not include the deferred blue connector button.</p>';
        echo '<div style="max-width:980px;">';
        foreach (self::tool_schemas() as $toolKey => $schema) {
            $method = strtoupper((string) ($schema['method'] ?? 'GET'));
            $path = (string) ($schema['path'] ?? '');
            if ($path === '') continue;
            $endpoint = rest_url('webmcp/v1' . $path);
            $curl = 'curl -X ' . $method . ' ' . escapeshellarg($endpoint) . ' -H ' . escapeshellarg('X-WP-Nonce: <nonce>');
            if ($method !== 'GET') $curl .= ' -H ' . escapeshellarg('Content-Type: application/json') . ' -d ' . escapeshellarg('{}');
            $js = "fetch(" . wp_json_encode($endpoint) . ", {method: " . wp_json_encode($method) . ", headers: {\"X-WP-Nonce\": nonce}}).then(r => r.json())";
            echo '<details style="background:#fff; border:1px solid #c3c4c7; border-radius:6px; padding:10px 12px; margin:10px 0;">';
            echo '<summary style="cursor:pointer;"><strong><code>' . esc_html($toolKey) . '</code></strong> <code>' . esc_html($method . ' ' . $path) . '</code></summary>';
            echo '<pre style="white-space:pre-wrap;">' . esc_html($curl . "\n\n" . $js) . '</pre>';
            if ($method === 'GET') echo '<button type="button" class="button button-secondary wp-webmcp-run-example" data-endpoint="' . esc_attr($endpoint) . '">Run read-only example</button><pre class="wp-webmcp-example-output" style="white-space:pre-wrap; max-height:220px; overflow:auto;"></pre>';
            echo '</details>';
        }
        echo '</div>';

        // =======================
        // Debug export panel
        // =======================
        echo '<h2>Debug Export</h2>';
        echo '<p>Copy this into a support ticket. Includes settings, integrations, and schema details.</p>';
        echo '<div style="max-width:980px;">';
        echo '<textarea id="wp-webmcp-debug" rows="10" style="width:100%; font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;">' .
            esc_textarea(wp_json_encode($debug, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) .
            '</textarea>';
        echo '<p><button type="button" class="button button-secondary" id="wp-webmcp-copy-debug">Copy debug JSON</button></p>';
        echo '</div>';

        // Inline JS for copy button (admin-only)
        echo '<script>
        (function(){
            var btn = document.getElementById("wp-webmcp-copy-debug");
            var ta  = document.getElementById("wp-webmcp-debug");
            if (!btn || !ta) return;
            btn.addEventListener("click", function(){
                ta.focus();
                ta.select();
                try {
                    var ok = document.execCommand("copy");
                    btn.textContent = ok ? "Copied" : "Copy failed";
                    setTimeout(function(){ btn.textContent = "Copy debug JSON"; }, 1500);
                } catch(e) {
                    btn.textContent = "Copy failed";
                    setTimeout(function(){ btn.textContent = "Copy debug JSON"; }, 1500);
                }
            });
        })();
        </script>';

        // =======================
        // Settings form
        // =======================
        echo '<hr />';
        echo '<form method="post" action="options.php">';
        settings_fields(self::PAGE_SLUG);

        // Render all sections (Main + Tools + Access + Rate limit)
        do_settings_sections(self::PAGE_SLUG);

        submit_button('Save Settings');
        echo '</form>';

        echo '</div>';
    }
}
