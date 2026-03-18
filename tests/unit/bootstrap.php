<?php
/**
 * Bootstrap for unit tests.
 *
 * Loads Brain\Monkey (so WP functions can be mocked/stubbed without a real
 * WordPress install) and directly requires the two plugin source files.
 * No database, no HTTP — these tests run in milliseconds.
 */

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

// ---------------------------------------------------------------------------
// WordPress constant stubs — the plugin files need these at parse time.
// ---------------------------------------------------------------------------
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', '/tmp/wp/' );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
    define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
    define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'OPENSSL_ALGO_SHA256' ) ) {
    // Already defined by PHP's openssl extension in all normal environments.
    define( 'OPENSSL_ALGO_SHA256', 7 );
}

// ---------------------------------------------------------------------------
// Load ONLY the includes file directly — the main plugin file registers hooks
// that call WP/WC functions we haven't stubbed yet. Unit tests work against
// the class in isolation.
// ---------------------------------------------------------------------------
require_once dirname( __DIR__, 2 ) . '/includes/class-unq-jwt-validator.php';

// A minimal unq_agev_get() stub mirroring the main plugin's real logic.
// It calls get_option() so individual tests can stub that via Brain\Monkey.
if ( ! function_exists( 'unq_agev_get' ) ) {
    function unq_agev_get( $key ) {
        switch ( $key ) {
            case 'enabled':
                return get_option( 'unq_agev_enabled', 'yes' );
            case 'public_key':
                return (string) get_option( 'unq_agev_public_key', '' );
            case 'required_age':
                return max( 1, (int) get_option( 'unq_agev_required_age', 18 ) );
            case 'mode':
                $mode = get_option( 'unq_agev_verification_mode', 'popup' );
                return in_array( $mode, array( 'popup', 'redirect' ), true ) ? $mode : 'popup';
            default:
                return '';
        }
    }
}
