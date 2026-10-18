<?php
/** Disposable fixtures for the foreign-delivery HTTP checkout tests. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() ) {
    throw new RuntimeException( 'Delivery fixtures are restricted to local WP-CLI.' );
}

$action = $args[0] ?? '';
$fixture_key = $args[1] ?? 'unq_agev_delivery_test_fixtures';
if ( ! preg_match( '/^unq_agev_delivery_test_(?:fixtures|[a-f0-9]{16})$/', $fixture_key ) ) {
    WP_CLI::error( 'Invalid delivery fixture key.' );
}
$fixture = get_option( $fixture_key );

if ( 'restore' === $action ) {
    if ( ! $fixture ) {
        WP_CLI::success( 'No delivery fixtures to restore.' );
        return;
    }
    foreach ( $fixture['options'] as $key => $value ) {
        null === $value ? delete_option( $key ) : update_option( $key, $value );
    }
    foreach ( wc_get_orders( array( 'limit' => -1, 'billing_email' => $fixture['email'] ?? 'delivery-test@example.invalid' ) ) as $order ) {
        $order->delete( true );
    }
    foreach ( $fixture['posts'] as $id ) {
        wp_delete_post( $id, true );
    }
    if ( $fixture['zone'] ) {
        ( new WC_Shipping_Zone( $fixture['zone'] ) )->delete();
    }
    delete_option( $fixture_key );
    WP_CLI::success( 'Delivery fixtures removed; previous settings restored.' );
    return;
}

if ( 'setup' === $action ) {
    if ( $fixture ) {
        WP_CLI::error( 'Restore previous delivery fixtures first.' );
    }
    $options = array(
        'unq_agev_enabled' => 'yes',
        'unq_agev_outside_denmark' => 'yes',
        'unq_agev_use_production' => 'no',
        'unq_agev_test_public_key' => 'pk_test_local_delivery_fixture_only',
        'unq_agev_locale' => 'en',
        'unq_agev_targeting' => 'all',
        'woocommerce_ship_to_countries' => 'specific',
        'woocommerce_specific_ship_to_countries' => array( 'DE', 'DK', 'SE' ),
        'woocommerce_allowed_countries' => 'all',
        'woocommerce_ship_to_destination' => 'shipping',
        'woocommerce_enable_guest_checkout' => 'yes',
        'woocommerce_checkout_page_id' => 0,
        'woocommerce_cart_page_id' => 0,
        'woocommerce_bacs_settings' => array( 'enabled' => 'yes', 'title' => 'Local test bank transfer' ),
    );
    $fixture = array(
        'options' => array(), 'posts' => array(), 'zone' => 0,
        'email' => 'unq_agev_delivery_test_fixtures' === $fixture_key
            ? 'delivery-test@example.invalid' : $fixture_key . '@example.invalid',
    );
    foreach ( $options as $key => $value ) {
        $fixture['options'][ $key ] = get_option( $key, null );
    }
    update_option( $fixture_key, $fixture );
    foreach ( $options as $key => $value ) {
        update_option( $key, $value );
    }

    $product = new WC_Product_Simple();
    $product->set_name( 'Disposable delivery test product' );
    $product->set_status( 'publish' );
    $product->set_regular_price( '10' );
    $product->set_tax_status( 'none' );
    $fixture['product'] = $product->save();
    $fixture['posts'][] = $fixture['product'];
    update_option( $fixture_key, $fixture );

    // Use WooCommerce's real default block tree, rather than an empty block.
    $block_content = new ReflectionMethod( 'WC_Install', 'get_checkout_block_content' );
    $block_content->setAccessible( true );
    foreach ( array( 'classic' => '[woocommerce_checkout]', 'blocks' => $block_content->invoke( null ), 'cart' => '[woocommerce_cart]' ) as $name => $content ) {
        $id = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Delivery test ' . $name, 'post_status' => 'publish', 'post_content' => $content ) );
        $fixture[ $name ] = $id;
        $fixture['posts'][] = $id;
        update_option( $fixture_key, $fixture );
    }
    update_option( 'woocommerce_checkout_page_id', $fixture['classic'] );
    update_option( 'woocommerce_cart_page_id', $fixture['cart'] );
    $zone = new WC_Shipping_Zone();
    $zone->set_zone_name( 'Disposable delivery test zone' );
    $zone_order = -1;
    foreach ( WC_Shipping_Zones::get_zones() as $existing_zone ) {
        $zone_order = min( $zone_order, (int) $existing_zone['zone_order'] - 1 );
    }
    $zone->set_zone_order( $zone_order );
    foreach ( array( 'DE', 'DK', 'SE' ) as $country ) {
        $zone->add_location( $country, 'country' );
    }
    $fixture['zone'] = $zone->save();
    $fixture['shipping'] = 'flat_rate:' . $zone->add_shipping_method( 'flat_rate' );
    update_option( $fixture_key, $fixture );
}

if ( in_array( $action, array( 'classic', 'blocks' ), true ) && $fixture ) {
    update_option( 'woocommerce_checkout_page_id', $fixture[ $action ] );
}
if ( ! $fixture ) {
    WP_CLI::error( 'Run setup first.' );
}
if ( 'audit-denial' === $action ) {
    wc_load_cart();
    $found = false;
    foreach ( wc_get_orders( array( 'limit' => -1, 'billing_email' => $fixture['email'] ?? 'delivery-test@example.invalid' ) ) as $order ) {
        if ( 'DK' !== $order->get_shipping_country() || 'yes' !== $order->get_meta( '_unq_agev_delivery_checked' ) ) {
            continue;
        }
        $found = true;
        if ( 'pending' !== $order->get_status() || 0 !== (int) $order->get_meta( '_unq_agev_verified_age' ) ) {
            WP_CLI::error( 'Rejected Danish order was processed or marked verified.' );
        }
        wc_clear_notices();
        do_action( 'woocommerce_before_pay_action', $order );
        if ( 1 !== wc_notice_count( 'error' ) ) {
            WP_CLI::error( 'Rejected Danish order could retry payment without verification.' );
        }
        wc_clear_notices();
    }
    if ( ! $found ) {
        WP_CLI::error( 'No persisted rejected Danish order found.' );
    }
}
echo 'DELIVERY_FIXTURE=' . wp_json_encode( $fixture ) . PHP_EOL;
