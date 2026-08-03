<?php
/**
 * Creates repeatable local WooCommerce fixtures for manual plugin testing.
 *
 * Run with: pnpm seed:products
 */

if ( ! function_exists( 'wc_get_product_id_by_sku' ) ) {
    WP_CLI::error( 'WooCommerce must be active before seeding products.' );
}

/**
 * Get or create a product category by slug.
 *
 * @param string $name Category name.
 * @param string $slug Category slug.
 * @return int
 */
function unq_agev_seed_category( $name, $slug ) {
    $term = get_term_by( 'slug', $slug, 'product_cat' );
    if ( $term ) {
        return (int) $term->term_id;
    }

    $created = wp_insert_term( $name, 'product_cat', array( 'slug' => $slug ) );
    if ( is_wp_error( $created ) ) {
        WP_CLI::error( $created->get_error_message() );
    }

    return (int) $created['term_id'];
}

/**
 * Get or create a simple product by SKU.
 *
 * @param string $name Product name.
 * @param string $sku Product SKU.
 * @param string $price Regular price.
 * @param int    $category_id Category term ID.
 * @return int
 */
function unq_agev_seed_simple_product( $name, $sku, $price, $category_id ) {
    $product_id = wc_get_product_id_by_sku( $sku );
    $product    = $product_id ? wc_get_product( $product_id ) : new WC_Product_Simple();

    $product->set_name( $name );
    $product->set_sku( $sku );
    $product->set_regular_price( $price );
    $product->set_status( 'publish' );
    $product->set_catalog_visibility( 'visible' );
    $product->set_category_ids( array( $category_id ) );

    return $product->save();
}

/**
 * Find a fixture by its current SKU or a legacy SKU that should be migrated.
 *
 * @param string $sku Current fixture SKU.
 * @param string $legacy_sku Legacy fixture SKU.
 * @return int
 */
function unq_agev_seed_fixture_id( $sku, $legacy_sku = '' ) {
    $product_id = wc_get_product_id_by_sku( $sku );
    if ( ! $product_id && $legacy_sku ) {
        $product_id = wc_get_product_id_by_sku( $legacy_sku );
    }

    return $product_id;
}

/**
 * Get or create the configurable test item and its size/eligibility variations.
 *
 * @param int $category_id Category term ID.
 * @return int
 */
function unq_agev_seed_configurable_item( $category_id ) {
    $sku        = 'UNQ-AGEV-CONFIGURABLE-ITEM';
    $product_id = unq_agev_seed_fixture_id( $sku, 'UNQ-AGEV-GIFT-BASKET' );
    $product    = $product_id ? wc_get_product( $product_id ) : new WC_Product_Variable();

    $size_attribute = new WC_Product_Attribute();
    $size_attribute->set_name( 'Size' );
    $size_attribute->set_options( array( 'S', 'L' ) );
    $size_attribute->set_visible( true );
    $size_attribute->set_variation( true );

    $eligibility_attribute = new WC_Product_Attribute();
    $eligibility_attribute->set_name( 'Eligibility' );
    $eligibility_attribute->set_options( array( 'Unrestricted', 'Age-restricted' ) );
    $eligibility_attribute->set_visible( true );
    $eligibility_attribute->set_variation( true );

    $product->set_name( 'UNQ Test Configurable Item' );
    $product->set_sku( $sku );
    $product->set_status( 'publish' );
    $product->set_catalog_visibility( 'visible' );
    $product->set_category_ids( array( $category_id ) );
    $product->set_attributes( array( $size_attribute, $eligibility_attribute ) );
    $product_id = $product->save();

    $variations = array(
        array(
            'sku'         => 'UNQ-AGEV-ITEM-S-UNRESTRICTED',
            'legacy_sku'  => 'UNQ-AGEV-ITEM-UNRESTRICTED',
            'size'        => 'S',
            'eligibility' => 'Unrestricted',
            'required'    => false,
            'price'       => '299',
            'description' => 'Small unrestricted variation for sibling-isolation testing.',
        ),
        array(
            'sku'         => 'UNQ-AGEV-ITEM-S-AGE-RESTRICTED',
            'legacy_sku'  => 'UNQ-AGEV-ITEM-AGE-RESTRICTED',
            'size'        => 'S',
            'eligibility' => 'Age-restricted',
            'required'    => true,
            'price'       => '399',
            'description' => 'Small age-restricted variation for variation-level age-gate testing.',
        ),
        array(
            'sku'         => 'UNQ-AGEV-ITEM-L-UNRESTRICTED',
            'legacy_sku'  => '',
            'size'        => 'L',
            'eligibility' => 'Unrestricted',
            'required'    => false,
            'price'       => '349',
            'description' => 'Large unrestricted variation for sibling-isolation testing.',
        ),
        array(
            'sku'         => 'UNQ-AGEV-ITEM-L-AGE-RESTRICTED',
            'legacy_sku'  => '',
            'size'        => 'L',
            'eligibility' => 'Age-restricted',
            'required'    => true,
            'price'       => '449',
            'description' => 'Large age-restricted variation for variation-level age-gate testing.',
        ),
    );

    foreach ( $variations as $fixture ) {
        $variation_id = unq_agev_seed_fixture_id( $fixture['sku'], $fixture['legacy_sku'] );
        $variation    = $variation_id ? wc_get_product( $variation_id ) : new WC_Product_Variation();

        $variation->set_parent_id( $product_id );
        $variation->set_sku( $fixture['sku'] );
        $variation->set_status( 'publish' );
        $variation->set_regular_price( $fixture['price'] );
        $variation->set_description( $fixture['description'] );
        $variation->set_attributes(
            array(
                'size'        => $fixture['size'],
                'eligibility' => $fixture['eligibility'],
            )
        );

        if ( $fixture['required'] ) {
            $variation->update_meta_data( '_unq_agev_required', 'yes' );
        } else {
            $variation->delete_meta_data( '_unq_agev_required' );
        }
        $variation->delete_meta_data( '_unq_agev_required_age' );
        $variation->save();
    }

    WC_Product_Variable::sync( $product_id );
    wc_delete_product_transients( $product_id );

    return $product_id;
}

$restricted_goods_id = unq_agev_seed_category( 'UNQ Test Restricted Goods', 'unq-test-restricted-goods' );
$general_goods_id = unq_agev_seed_category( 'UNQ Test General Goods', 'unq-test-general-goods' );

$ordinary_id = unq_agev_seed_simple_product( 'UNQ Test Ordinary Widget', 'UNQ-AGEV-ORDINARY', '100', $general_goods_id );
$restricted_id = unq_agev_seed_simple_product( 'UNQ Test Age-Restricted Item', 'UNQ-AGEV-RESTRICTED', '500', $general_goods_id );
$premium_id = unq_agev_seed_simple_product( 'UNQ Test Premium Item', 'UNQ-AGEV-PREMIUM', '800', $restricted_goods_id );
$configurable_item_id = unq_agev_seed_configurable_item( $general_goods_id );

// Deterministic plugin settings for browser tests. This script is only used
// with the disposable wp-env development site.
update_option( 'unq_agev_enabled', 'yes' );
update_option( 'unq_agev_test_public_key', 'pk_test_browser_fixture' );
update_option( 'unq_agev_use_production', 'no' );
update_option( 'unq_agev_required_age', 18 );
update_option( 'unq_agev_verification_mode', 'popup' );
update_option( 'unq_agev_locale', 'en' );
update_option( 'unq_agev_targeting', 'all' );
update_option( 'woocommerce_coming_soon', 'no' );

WP_CLI::success(
    sprintf(
        'Seeded products: Ordinary Widget #%d, Age-Restricted Item #%d, Premium Item #%d, Configurable Item #%d.',
        $ordinary_id,
        $restricted_id,
        $premium_id,
        $configurable_item_id
    )
);
