<?php
/**
 * Bootstrap for integration tests.
 *
 * Requires a running WordPress test library — use wp-env (tests-cli service)
 * which provides /tmp/wordpress-tests-lib out of the box, or provision one
 * manually via bin/install-wp-tests.sh.
 *
 * Run via:
 *   wp-env run tests-cli php vendor/bin/phpunit --testsuite integration
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
    $_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
    echo 'Could not find ' . $_tests_dir . '/includes/functions.php' . PHP_EOL;
    echo 'Ensure WP_TESTS_DIR points to a valid WP test library, or run bin/install-wp-tests.sh.' . PHP_EOL;
    exit( 1 );
}

require_once $_tests_dir . '/includes/functions.php';

/**
 * Loads WooCommerce and the plugin under test before WP itself boots.
 * Tests use WP_UnitTestCase which resets DB state between cases.
 */
tests_add_filter(
    'muplugins_loaded',
    function () {
        // WooCommerce — installed by wp-env or manually. Skip gracefully if absent.
        $wc_plugin = WP_CONTENT_DIR . '/plugins/woocommerce/woocommerce.php';
        if ( file_exists( $wc_plugin ) ) {
            require_once $wc_plugin;
        }

        // Plugin under test.
        require_once dirname( __DIR__, 2 ) . '/aldersverificering-woocommerce.php';
    }
);

require $_tests_dir . '/includes/bootstrap.php';
