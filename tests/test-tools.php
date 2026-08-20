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
    }
}
