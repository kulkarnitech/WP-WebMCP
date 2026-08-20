<?php
/**
 * PHPUnit bootstrap for the WordPress test suite.
 *
 * Set WP_TESTS_DIR to the checkout's wordpress-tests-lib directory before
 * running PHPUnit. The plugin remains loadable without this optional harness.
 */
$tests_dir = getenv('WP_TESTS_DIR');
if (!$tests_dir || !file_exists($tests_dir . '/includes/functions.php')) {
    return;
}

require_once $tests_dir . '/includes/functions.php';
require_once $tests_dir . '/includes/bootstrap.php';

tests_add_filter('muplugins_loaded', static function () {
    require dirname(__DIR__) . '/wp-webmcp-layer.php';
});
