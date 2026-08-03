<?php
/**
 * Verifies variation age-verification controls in WooCommerce's real admin
 * template. Run with: pnpm test:integration
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    exit( 1 );
}

if ( ! defined( 'WC_ABSPATH' ) ) {
    WP_CLI::error( 'WooCommerce must be active before running this check.' );
}

require_once WC_ABSPATH . 'includes/admin/wc-meta-box-functions.php';

UNQ_Admin::register();

/**
 * Fail the check with a clear assertion message.
 *
 * @param bool   $condition Assertion condition.
 * @param string $message Assertion failure message.
 */
function unq_agev_smoke_assert( $condition, $message ) {
    if ( ! $condition ) {
        WP_CLI::error( $message );
    }
}

/**
 * Render a real WooCommerce variation admin row.
 *
 * @param WC_Product_Variable  $product Parent variable product.
 * @param WC_Product_Variation $variation Variation to render.
 * @return string
 */
function unq_agev_render_variation_admin_template( $product, $variation ) {
    $product_object   = $product;
    $variation_object = $variation;
    $variation_id     = $variation->get_id();
    $variation        = get_post( $variation_id );
    $variation_data   = array();
    $loop             = 0;
    $base_cost        = null;

    ob_start();
    include WC_ABSPATH . 'includes/admin/meta-boxes/views/html-variation-admin.php';
    return ob_get_clean();
}

$parent_id = wc_get_product_id_by_sku( 'UNQ-AGEV-CONFIGURABLE-ITEM' );
$parent    = $parent_id ? wc_get_product( $parent_id ) : false;

unq_agev_smoke_assert( $parent && $parent->is_type( 'variable' ), 'Seeded configurable product is missing. Run pnpm seed:products first.' );

$fixtures = array(
    'UNQ-AGEV-ITEM-L-UNRESTRICTED'    => false,
    'UNQ-AGEV-ITEM-L-AGE-RESTRICTED'  => true,
);

foreach ( array( false, true ) as $manage_stock ) {
    foreach ( $fixtures as $sku => $expected_checked ) {
        $variation_id = wc_get_product_id_by_sku( $sku );
        $variation    = $variation_id ? wc_get_product( $variation_id ) : false;

        unq_agev_smoke_assert( $variation && $variation->is_type( 'variation' ), sprintf( 'Seeded variation %s is missing.', $sku ) );

        $variation->set_manage_stock( $manage_stock );
        $variation->save();

        $html = unq_agev_render_variation_admin_template( $parent, $variation );
        unq_agev_smoke_assert(
            1 === substr_count( $html, 'unq-agev-variation-fields' ),
            sprintf( 'Expected exactly one age-verification control for %s with manage stock %s.', $sku, $manage_stock ? 'enabled' : 'disabled' )
        );

        preg_match( '/<input[^>]*class="[^"]*unq-agev-variation-required[^"]*"[^>]*>/', $html, $checkbox_match );
        unq_agev_smoke_assert( ! empty( $checkbox_match[0] ), sprintf( 'Age-verification checkbox is missing for %s.', $sku ) );
        $is_checked = false !== strpos( $checkbox_match[0], "checked='checked'" );
        unq_agev_smoke_assert(
            $expected_checked === $is_checked,
            sprintf( 'Unexpected checked state for %s with manage stock %s.', $sku, $manage_stock ? 'enabled' : 'disabled' )
        );
    }
}

foreach ( array_keys( $fixtures ) as $sku ) {
    $variation = wc_get_product( wc_get_product_id_by_sku( $sku ) );
    $variation->set_manage_stock( false );
    $variation->save();
}

WP_CLI::success( 'Variation admin template smoke test passed.' );