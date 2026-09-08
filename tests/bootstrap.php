<?php
/**
 * PHPUnit bootstrap for the WordPress test suite.
 *
 * Set WP_TESTS_DIR to the checkout's wordpress-tests-lib directory before
 * running PHPUnit. A missing test checkout is reported immediately so the
 * suite cannot appear to pass with zero loaded tests.
 */
$tests_dir = getenv('WP_TESTS_DIR');
if (!$tests_dir || !file_exists($tests_dir . '/includes/functions.php')) {
    throw new RuntimeException(
        'WordPress test dependencies are missing. Set WP_TESTS_DIR to a wordpress-tests-lib checkout before running PHPUnit.'
    );
}

require_once $tests_dir . '/includes/functions.php';

tests_add_filter('muplugins_loaded', static function () {
    require dirname(__DIR__) . '/webmcp-layer-for-wordpress.php';
});

require_once $tests_dir . '/includes/bootstrap.php';
