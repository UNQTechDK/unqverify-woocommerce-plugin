<?php
/**
 * Plugin Name: Aldersverificering for WooCommerce
 * Plugin URI:  https://www.aldersverificering.dk
 * Description: Aldersverificering med MitID fra Aldersverificering.dk
 * Version:     1.1.2
 * Author:      UNQTech
 * Author URI:  https://www.aldersverificering.dk
 * Text Domain: unq-age-verification
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * Network: false
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'UNQ_AGEV_VERSION',     '1.1.2' );
define( 'UNQ_AGEV_PATH',        plugin_dir_path( __FILE__ ) );
define( 'UNQ_AGEV_URL',         plugin_dir_url( __FILE__ ) );
define( 'UNQ_AGEV_SDK_URL',     'https://unpkg.com/@unqtech/age-verification-mitid@0.4.3/dist/index.umd.js' );
// SRI hash for the SDK — update this every time you publish a new SDK version.
// Compute: curl -sL "<UNQ_AGEV_SDK_URL>" | openssl dgst -sha384 -binary | openssl base64 -A
define( 'UNQ_AGEV_SDK_SRI',     'sha384-tW93lDADwO+RhSpCCOTre3DUwppRX5zOFKVMVBYsxdEuFfFPQK8/pAU4v+GtfERK' );
define( 'UNQ_AGEV_COOKIE_NAME', 'unqverify_token' );
// Stable plugin basename for plugin_row_meta — must be computed from __FILE__
// in the main plugin file, not from any include.
define( 'UNQ_AGEV_BASENAME',    plugin_basename( __FILE__ ) );

require_once UNQ_AGEV_PATH . 'includes/class-unq-jwt-validator.php';
require_once UNQ_AGEV_PATH . 'includes/functions.php';
require_once UNQ_AGEV_PATH . 'includes/class-unq-admin.php';
require_once UNQ_AGEV_PATH . 'includes/class-unq-frontend.php';

// ---------------------------------------------------------------------------
// Declare compatibility with WooCommerce HPOS and Cart/Checkout Blocks.
// Must reference __FILE__ from the main plugin file — not from any include.
// ---------------------------------------------------------------------------
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
    }
} );

// ---------------------------------------------------------------------------
// Load plugin textdomain — enables da_DK.po/.mo for admin UI strings.
// ---------------------------------------------------------------------------
add_action( 'plugins_loaded', function () {
    load_plugin_textdomain(
        'unq-age-verification',
        false,
        dirname( plugin_basename( __FILE__ ) ) . '/languages/'
    );
} );

// ---------------------------------------------------------------------------
// Lifecycle hooks — must be registered at top-level in the main plugin file.
// ---------------------------------------------------------------------------
register_activation_hook( __FILE__, function () {
    add_rewrite_rule( '^unqverify/callback/?$', 'index.php?unqverify=callback', 'top' );
    flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, function () {
    flush_rewrite_rules();
} );

// ---------------------------------------------------------------------------
// Boot admin and frontend layers.
// ---------------------------------------------------------------------------
if ( is_admin() ) {
    UNQ_Admin::register();
}
UNQ_Frontend::register();
