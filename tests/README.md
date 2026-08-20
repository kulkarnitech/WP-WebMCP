# Test harness

The plugin does not bundle WordPress or a database. For integration tests, install
the WordPress PHPUnit test suite, set `WP_TESTS_DIR` to its checkout, and run:

```bash
composer require --dev phpunit/phpunit
WP_TESTS_DIR=/path/to/wordpress-tests-lib vendor/bin/phpunit -c phpunit.xml.dist
```

The included tests cover the registry's bounded input-schema validation. A full
site test should additionally exercise nonce failures, capability gates, rate
limits, WooCommerce session/cart behavior, PMPro redaction, and BuddyPress privacy
rules on a disposable WordPress install.
