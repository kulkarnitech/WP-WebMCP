<?php
/** @noinspection PhpUndefinedClassInspection */

if (class_exists('WP_UnitTestCase')) {
    final class WebMCP_Tools_Test extends WP_UnitTestCase {
        public function test_known_tool_input_is_accepted(): void {
            $error = '';
            $this->assertTrue(\WebMCP_Layer\Tools::validate_input('site_search', ['q' => 'webmcp'], $error), $error);
        }

        public function test_unknown_fields_are_rejected(): void {
            $error = '';
            $this->assertFalse(\WebMCP_Layer\Tools::validate_input('site_search', ['q' => 'webmcp', 'secret' => 'x'], $error));
        }

        public function test_mutation_input_is_bounded(): void {
            $error = '';
            $this->assertFalse(\WebMCP_Layer\Tools::validate_input('woo_cart_add', ['product_id' => 1, 'qty' => 101], $error));
        }

        public function test_checkout_input_rejects_raw_payment_data(): void {
            if (!class_exists('WooCommerce')) $this->markTestSkipped('WooCommerce is not active in the integration test environment.');
            $error = '';
            $this->assertFalse(\WebMCP_Layer\Tools::validate_input('woo_checkout_create', [
                'payment_method' => 'cod',
                'payment_data' => ['card_number' => '4111111111111111'],
            ], $error));
        }

        public function test_checkout_input_accepts_bounded_address(): void {
            if (!class_exists('WooCommerce')) $this->markTestSkipped('WooCommerce is not active in the integration test environment.');
            $error = '';
            $this->assertTrue(\WebMCP_Layer\Tools::validate_input('woo_checkout_create', [
                'payment_method' => 'cod',
                'billing' => [
                    'first_name' => 'Test',
                    'last_name' => 'Customer',
                    'country' => 'US',
                    'email' => 'test@example.com',
                ],
            ], $error), $error);
        }

        public function test_password_protected_published_post_is_not_exposed(): void {
            $post_id = self::factory()->post->create([
                'post_status'   => 'publish',
                'post_password' => 'secret',
                'post_content'  => 'private content',
            ]);
            $request = new WP_REST_Request('GET', '/webmcp/v1/post');
            $request->set_param('id', $post_id);

            $response = \WebMCP_Layer\REST::get_post($request);

            $this->assertSame(404, $response->get_status());
        }

        public function test_native_input_size_limit_is_enforced(): void {
            $previous = get_option(\WebMCP_Layer\Admin::OPTION_KEY, []);
            update_option(\WebMCP_Layer\Admin::OPTION_KEY, array_merge((array) $previous, [
                'enabled'           => 1,
                'tool_site_search'    => 1,
                'request_size_limit' => 1024,
            ]));

            $result = \WebMCP_Layer\REST::authorize_native_tool('site_search', ['q' => str_repeat('x', 2048)]);

            update_option(\WebMCP_Layer\Admin::OPTION_KEY, $previous);
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

            \WebMCP_Layer\REST::with_idempotency($request, 'test_tool', $callback);
            $replay = \WebMCP_Layer\REST::with_idempotency($request, 'test_tool', $callback);

            $this->assertSame(1, $calls);
            $this->assertSame('1', $replay->get_headers()['X-WebMCP-Idempotent-Replay']);
        }

        public function test_admin_sanitize_preserves_existing_options_and_adds_safe_defaults(): void {
            $previous = get_option(\WebMCP_Layer\Admin::OPTION_KEY, []);
            update_option(\WebMCP_Layer\Admin::OPTION_KEY, [
                'enabled'          => 1,
                'tool_site_search'   => 1,
                'rate_limit_enabled' => 1,
            ]);

            $sanitized = \WebMCP_Layer\Admin::sanitize(['enabled' => 1]);

            update_option(\WebMCP_Layer\Admin::OPTION_KEY, $previous);
            $this->assertSame(1, $sanitized['tool_site_search']);
            $this->assertSame('read', $sanitized['cap_woo_cart_remove']);
            $this->assertSame('publish_posts', $sanitized['cap_bp_activity_create']);
        }

        public function test_activation_seeds_sanitized_defaults(): void {
            $previous = get_option(\WebMCP_Layer\Admin::OPTION_KEY, null);
            delete_option(\WebMCP_Layer\Admin::OPTION_KEY);

            try {
                \WebMCP_Layer\Plugin::activate();
                $options = get_option(\WebMCP_Layer\Admin::OPTION_KEY, []);

                $this->assertSame(1, $options['enabled']);
                $this->assertSame(3, $options['_schema_version']);
                $this->assertSame('publish_posts', $options['cap_bp_activity_create']);
            } finally {
                if ($previous === null) {
                    delete_option(\WebMCP_Layer\Admin::OPTION_KEY);
                } else {
                    update_option(\WebMCP_Layer\Admin::OPTION_KEY, $previous);
                }
            }
        }

        public function test_deactivation_preserves_settings_for_reactivation(): void {
            $previous = get_option(\WebMCP_Layer\Admin::OPTION_KEY, null);
            update_option(\WebMCP_Layer\Admin::OPTION_KEY, [
                'enabled' => 0,
                '_schema_version' => 2,
            ]);

            try {
                \WebMCP_Layer\Plugin::deactivate();
                $options = get_option(\WebMCP_Layer\Admin::OPTION_KEY, []);
                $this->assertSame(0, $options['enabled']);
            } finally {
                if ($previous === null) {
                    delete_option(\WebMCP_Layer\Admin::OPTION_KEY);
                } else {
                    update_option(\WebMCP_Layer\Admin::OPTION_KEY, $previous);
                }
            }
        }

        public function test_unknown_menu_location_returns_not_found(): void {
            $request = new WP_REST_Request('GET', '/webmcp/v1/menu');
            $request->set_param('location', 'webmcp_missing_location');

            $response = \WebMCP_Layer\Core::get_menu($request);

            $this->assertSame(404, $response->get_status());
        }

        public function test_unassigned_known_menu_location_does_not_fall_back(): void {
            $menu_id = wp_create_nav_menu('WebMCP fallback test menu');
            $this->assertIsInt($menu_id);
            $previous_locations = get_theme_mod('nav_menu_locations', null);
            set_theme_mod('nav_menu_locations', ['primary' => 0, 'footer' => $menu_id]);

            try {
                $request = new WP_REST_Request('GET', '/webmcp/v1/menu');
                $request->set_param('location', 'primary');
                $response = \WebMCP_Layer\Core::get_menu($request);
            } finally {
                if ($previous_locations === null) {
                    remove_theme_mod('nav_menu_locations');
                } else {
                    set_theme_mod('nav_menu_locations', $previous_locations);
                }
                wp_delete_nav_menu($menu_id);
            }

            $this->assertSame(404, $response->get_status());
        }

        public function test_unassigned_menu_requires_theme_options_capability(): void {
            $menu_id = wp_create_nav_menu('WebMCP private test menu');
            $this->assertIsInt($menu_id);
            $previous_user = get_current_user_id();
            wp_set_current_user(0);

            try {
                $request = new WP_REST_Request('GET', '/webmcp/v1/menu');
                $request->set_param('menu_id', $menu_id);

                $response = \WebMCP_Layer\Core::get_menu($request);

                $this->assertSame(403, $response->get_status());
            } finally {
                wp_set_current_user($previous_user);
                wp_delete_nav_menu($menu_id);
            }
        }

        public function test_assigned_menu_query_is_bounded(): void {
            $menu_id = wp_create_nav_menu('WebMCP public test menu');
            $this->assertIsInt($menu_id);
            wp_update_nav_menu_item($menu_id, 0, [
                'menu-item-title'  => 'Home',
                'menu-item-url'    => home_url('/'),
                'menu-item-status' => 'publish',
            ]);

            $previous_locations = get_theme_mod('nav_menu_locations', null);
            set_theme_mod('nav_menu_locations', ['primary' => $menu_id]);
            $posts_per_page = null;
            $capture = static function ($query) use (&$posts_per_page) {
                if ($query->get('post_type') === 'nav_menu_item') {
                    $posts_per_page = $query->get('posts_per_page');
                }
            };
            add_action('pre_get_posts', $capture);

            try {
                $request = new WP_REST_Request('GET', '/webmcp/v1/menu');
                $request->set_param('location', 'primary');
                $response = \WebMCP_Layer\Core::get_menu($request);
            } finally {
                remove_action('pre_get_posts', $capture);
                if ($previous_locations === null) {
                    remove_theme_mod('nav_menu_locations');
                } else {
                    set_theme_mod('nav_menu_locations', $previous_locations);
                }
                wp_delete_nav_menu($menu_id);
            }

            $this->assertSame(200, $response->get_status());
            $this->assertSame(100, (int) $posts_per_page);
        }

        public function test_site_search_disables_found_rows(): void {
            $previous = null;
            $capture = static function ($query) use (&$previous) {
                if ($query->get('post_type') === 'post' && $query->get('s') === 'webmcp-query-test') {
                    $previous = $query->get('no_found_rows');
                }
            };
            add_action('pre_get_posts', $capture);

            $post_id = self::factory()->post->create([
                'post_status' => 'publish',
                'post_title'  => 'webmcp-query-test',
            ]);
            $request = new WP_REST_Request('GET', '/webmcp/v1/search');
            $request->set_param('q', 'webmcp-query-test');
            $request->set_param('type', 'post');
            \WebMCP_Layer\REST::search($request);

            remove_action('pre_get_posts', $capture);
            wp_delete_post($post_id, true);

            $this->assertTrue($previous);
        }

        public function test_generic_product_search_uses_visible_woocommerce_products(): void {
            if (!class_exists('WooCommerce') || !function_exists('wc_get_products') || !class_exists('WC_Product_Simple')) {
                $this->markTestSkipped('WooCommerce is not active in the integration test environment.');
            }

            $visible = new WC_Product_Simple();
            $visible->set_name('WebMCP visible product');
            $visible->set_status('publish');
            $visible->set_catalog_visibility('visible');
            $visible->save();

            $hidden = new WC_Product_Simple();
            $hidden->set_name('WebMCP hidden product');
            $hidden->set_status('publish');
            $hidden->set_catalog_visibility('hidden');
            $hidden->save();

            try {
                $request = new WP_REST_Request('GET', '/webmcp/v1/search');
                $request->set_param('q', 'WebMCP');
                $request->set_param('type', 'product');
                $results = \WebMCP_Layer\REST::search($request)->get_data()['results'];
                $ids = array_map('absint', array_column($results, 'id'));

                $this->assertContains($visible->get_id(), $ids);
                $this->assertNotContains($hidden->get_id(), $ids);
            } finally {
                wp_delete_post($visible->get_id(), true);
                wp_delete_post($hidden->get_id(), true);
            }
        }

        public function test_cart_add_rejects_quantity_outside_supported_range(): void {
            if (!class_exists('WooCommerce') || !class_exists('WC_Product_Simple')) {
                $this->markTestSkipped('WooCommerce is not active in the integration test environment.');
            }

            $product = new WC_Product_Simple();
            $product->set_name('WebMCP quantity bounds product');
            $product->set_status('publish');
            $product->set_catalog_visibility('visible');
            $product->set_regular_price('10');
            $product->save();

            try {
                wc_load_cart();
                WC()->cart->empty_cart();

                $request = new WP_REST_Request('POST', '/webmcp/v1/cart/add');
                $request->set_param('product_id', $product->get_id());
                $request->set_param('qty', 101);

                $response = \WebMCP_Layer\REST::cart_add($request);

                $this->assertSame(400, $response->get_status());
                $this->assertSame('webmcp_invalid_quantity', $response->get_data()['code']);
            } finally {
                if (function_exists('wc_clear_notices')) {
                    wc_clear_notices();
                }
                WC()->cart->empty_cart();
                wp_delete_post($product->get_id(), true);
            }
        }

        public function test_cart_add_honors_woocommerce_validation_filters(): void {
            if (!class_exists('WooCommerce') || !class_exists('WC_Product_Simple')) {
                $this->markTestSkipped('WooCommerce is not active in the integration test environment.');
            }

            $product = new WC_Product_Simple();
            $product->set_name('WebMCP validation product');
            $product->set_status('publish');
            $product->set_catalog_visibility('visible');
            $product->set_regular_price('10');
            $product->save();

            $reject = static function () {
                return false;
            };
            add_filter('woocommerce_add_to_cart_validation', $reject, 10, 5);

            try {
                wc_load_cart();
                WC()->cart->empty_cart();

                $request = new WP_REST_Request('POST', '/webmcp/v1/cart/add');
                $request->set_param('product_id', $product->get_id());
                $request->set_param('qty', 1);

                $response = \WebMCP_Layer\REST::cart_add($request);

                $this->assertSame(400, $response->get_status());
                $this->assertSame('webmcp_cart_add_rejected', $response->get_data()['code']);
                $this->assertSame([], WC()->cart->get_cart());
            } finally {
                remove_filter('woocommerce_add_to_cart_validation', $reject, 10);
                if (function_exists('wc_clear_notices')) {
                    wc_clear_notices();
                }
                WC()->cart->empty_cart();
                wp_delete_post($product->get_id(), true);
            }
        }

        public function test_cart_add_increments_existing_quantity_after_cart_rehydration(): void {
            if (!class_exists('WooCommerce') || !class_exists('WC_Product_Simple')) {
                $this->markTestSkipped('WooCommerce is not active in the integration test environment.');
            }

            $product = new WC_Product_Simple();
            $product->set_name('WebMCP cart increment product');
            $product->set_status('publish');
            $product->set_catalog_visibility('visible');
            $product->set_regular_price('10');
            $product->save();

            global $wp_actions;
            $previous_cart_loads = $wp_actions['woocommerce_load_cart_from_session'] ?? 0;

            try {
                wc_load_cart();
                WC()->cart->empty_cart();

                $first = new WP_REST_Request('POST', '/webmcp/v1/cart/add');
                $first->set_param('product_id', $product->get_id());
                $first->set_param('qty', 1);
                $this->assertSame(200, \WebMCP_Layer\REST::cart_add($first)->get_status());

                WC()->cart->calculate_totals();
                $session_cart = [];
                foreach (WC()->cart->get_cart() as $key => $values) {
                    unset($values['data']);
                    $session_cart[$key] = $values;
                }
                WC()->session->set('cart', $session_cart);
                $wp_actions['woocommerce_load_cart_from_session'] = 0;
                WC()->cart = new WC_Cart();

                $second = new WP_REST_Request('POST', '/webmcp/v1/cart/add');
                $second->set_param('product_id', $product->get_id());
                $second->set_param('qty', 2);
                $response = \WebMCP_Layer\REST::cart_add($second);

                $this->assertSame(200, $response->get_status());
                $this->assertSame(2, $response->get_data()['added_qty']);
                $this->assertSame(3, (int) $response->get_data()['cart']['items'][0]['qty']);
            } finally {
                $wp_actions['woocommerce_load_cart_from_session'] = $previous_cart_loads;
                if (function_exists('wc_clear_notices')) {
                    wc_clear_notices();
                }
                WC()->cart->empty_cart();
                wp_delete_post($product->get_id(), true);
            }
        }

        public function test_cart_add_rejects_sold_individually_duplicate_after_cart_rehydration(): void {
            if (!class_exists('WooCommerce') || !class_exists('WC_Product_Simple')) {
                $this->markTestSkipped('WooCommerce is not active in the integration test environment.');
            }

            $product = new WC_Product_Simple();
            $product->set_name('WebMCP sold individually product');
            $product->set_status('publish');
            $product->set_catalog_visibility('visible');
            $product->set_regular_price('10');
            $product->set_sold_individually(true);
            $product->save();

            global $wp_actions;
            $previous_cart_loads = $wp_actions['woocommerce_load_cart_from_session'] ?? 0;

            try {
                wc_load_cart();
                WC()->cart->empty_cart();

                $first = new WP_REST_Request('POST', '/webmcp/v1/cart/add');
                $first->set_param('product_id', $product->get_id());
                $this->assertSame(200, \WebMCP_Layer\REST::cart_add($first)->get_status());

                WC()->cart->calculate_totals();
                $session_cart = [];
                foreach (WC()->cart->get_cart() as $key => $values) {
                    unset($values['data']);
                    $session_cart[$key] = $values;
                }
                WC()->session->set('cart', $session_cart);
                $wp_actions['woocommerce_load_cart_from_session'] = 0;
                WC()->cart = new WC_Cart();

                $second = new WP_REST_Request('POST', '/webmcp/v1/cart/add');
                $second->set_param('product_id', $product->get_id());
                $response = \WebMCP_Layer\REST::cart_add($second);

                $this->assertSame(400, $response->get_status());
                $this->assertSame('webmcp_cart_add_failed', $response->get_data()['code']);
            } finally {
                $wp_actions['woocommerce_load_cart_from_session'] = $previous_cart_loads;
                if (function_exists('wc_clear_notices')) {
                    wc_clear_notices();
                }
                WC()->cart->empty_cart();
                wp_delete_post($product->get_id(), true);
            }
        }

        public function test_discovery_headers_are_omitted_when_disabled(): void {
            $previous = get_option(\WebMCP_Layer\Admin::OPTION_KEY, []);
            update_option(\WebMCP_Layer\Admin::OPTION_KEY, array_merge((array) $previous, ['enabled' => 0]));

            try {
                $headers = ['X-Test' => 'keep'];
                $filtered = \WebMCP_Layer\Discovery::headers($headers);
            } finally {
                update_option(\WebMCP_Layer\Admin::OPTION_KEY, $previous);
            }

            $this->assertSame($headers, $filtered);
        }
    }
}
