# Test harness

The plugin does not bundle WordPress or a database. For integration tests, install
PHPUnit and the WordPress PHPUnit test suite, set `WP_TESTS_DIR` to its checkout,
and set `WP_TESTS_CONFIG_FILE_PATH` to a `wp-tests-config.php` that points at an
isolated test database:

```bash
composer require --dev phpunit/phpunit
WP_TESTS_DIR=/path/to/wordpress-develop/tests/phpunit \
WP_TESTS_CONFIG_FILE_PATH=/path/to/wp-tests-config.php \
WP_WOO_PLUGIN_PATH=/path/to/woocommerce \
vendor/bin/phpunit -c phpunit.xml.dist
```

`WP_WOO_PLUGIN_PATH` is optional. Set it to run the WooCommerce-specific tests
against a disposable WooCommerce checkout; leave it unset for the core-only
suite.

On Windows PowerShell, the equivalent command is:

```powershell
$env:WP_TESTS_DIR = "$PWD\.test-deps\wordpress-develop\tests\phpunit"
$env:WP_TESTS_CONFIG_FILE_PATH = "$PWD\wp-tests-config.php"
$env:WP_WOO_PLUGIN_PATH = "$PWD\.test-deps\wordpress-develop\src\wp-content\plugins\woocommerce"
vendor\bin\phpunit -c phpunit.xml.dist --testdox
```

The WordPress test suite drops tables with its test prefix. Never point the
configuration at a development, staging, or production database.

The included tests cover bounded input-schema validation, lifecycle behavior,
menu authorization, generic search query bounds, WooCommerce visibility, and
WooCommerce cart validation/session behavior when WooCommerce is active. A full
site test should additionally exercise nonce failures, capability gates, rate
limits, PMPro redaction, and BuddyPress privacy rules on a disposable
WordPress install.
