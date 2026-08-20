<?php
/** @noinspection PhpUndefinedClassInspection */

if (class_exists('WP_UnitTestCase')) {
    final class WP_WebMCP_Tools_Test extends WP_UnitTestCase {
        public function test_known_tool_input_is_accepted(): void {
            $error = '';
            $this->assertTrue(\WP_WebMCP_Layer\Tools::validate_input('wp_search', ['q' => 'webmcp'], $error), $error);
        }

        public function test_unknown_fields_are_rejected(): void {
            $error = '';
            $this->assertFalse(\WP_WebMCP_Layer\Tools::validate_input('wp_search', ['q' => 'webmcp', 'secret' => 'x'], $error));
        }

        public function test_mutation_input_is_bounded(): void {
            $error = '';
            $this->assertFalse(\WP_WebMCP_Layer\Tools::validate_input('woo_cart_add', ['product_id' => 1, 'qty' => 101], $error));
        }

        public function test_password_protected_published_post_is_not_exposed(): void {
            $post_id = self::factory()->post->create([
                'post_status'   => 'publish',
                'post_password' => 'secret',
                'post_content'  => 'private content',
            ]);
            $request = new WP_REST_Request('GET', '/webmcp/v1/post');
            $request->set_param('id', $post_id);

            $response = \WP_WebMCP_Layer\REST::get_post($request);

            $this->assertSame(404, $response->get_status());
        }

        public function test_native_input_size_limit_is_enforced(): void {
            $previous = get_option(\WP_WebMCP_Layer\Admin::OPTION_KEY, []);
            update_option(\WP_WebMCP_Layer\Admin::OPTION_KEY, array_merge((array) $previous, [
                'enabled'           => 1,
                'tool_wp_search'    => 1,
                'request_size_limit' => 1024,
            ]));

            $result = \WP_WebMCP_Layer\REST::authorize_native_tool('wp_search', ['q' => str_repeat('x', 2048)]);

            update_option(\WP_WebMCP_Layer\Admin::OPTION_KEY, $previous);
            $this->assertWPError($result);
            $this->assertSame('webmcp_request_too_large', $result->get_error_code());
        }

        public function test_idempotency_replays_for_a_stable_anonymous_cookie(): void {
            $key = 'test-' . wp_generate_uuid4();
            $request = new WP_REST_Request('POST', '/webmcp/v1/test');
            $request->set_header('Cookie', 'wp_test_session=stable');
            $request->set_header('X-WebMCP-Idempotency-Key', $key);
            $calls = 0;
            $callback = static function () use (&$calls) {
                $calls++;
                return new WP_REST_Response(['ok' => true], 200);
            };

            \WP_WebMCP_Layer\REST::with_idempotency($request, 'test_tool', $callback);
            $replay = \WP_WebMCP_Layer\REST::with_idempotency($request, 'test_tool', $callback);

            $this->assertSame(1, $calls);
            $this->assertSame('1', $replay->get_headers()['X-WebMCP-Idempotent-Replay']);
        }

        public function test_admin_sanitize_preserves_legacy_options_and_adds_safe_defaults(): void {
            $previous = get_option(\WP_WebMCP_Layer\Admin::OPTION_KEY, []);
            update_option(\WP_WebMCP_Layer\Admin::OPTION_KEY, [
                'enabled'          => 1,
                'tool_wp_search'   => 1,
                'rate_limit_enabled' => 1,
            ]);

            $sanitized = \WP_WebMCP_Layer\Admin::sanitize(['enabled' => 1]);

            update_option(\WP_WebMCP_Layer\Admin::OPTION_KEY, $previous);
            $this->assertSame(1, $sanitized['tool_wp_search']);
            $this->assertSame('read', $sanitized['cap_woo_cart_remove']);
            $this->assertSame('publish_posts', $sanitized['cap_bp_activity_create']);
        }
    }
}
