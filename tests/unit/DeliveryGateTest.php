<?php

namespace UNQVerify\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/class-unq-delivery-gate.php';
if ( ! defined( 'UNQ_AGEV_COOKIE_NAME' ) ) {
    define( 'UNQ_AGEV_COOKIE_NAME', 'unqverify_token' );
}

class DeliveryGateTest extends TestCase {
    private $options;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        $this->options = array( 'unq_agev_outside_denmark' => 'yes', 'unq_agev_test_public_key' => 'pk_test_unit' );
        Functions\when( 'get_option' )->alias( function ( $key, $default = null ) {
            return $this->options[ $key ] ?? $default;
        } );
        Functions\when( 'absint' )->alias( function ( $value ) { return abs( (int) $value ); } );
        Functions\when( 'has_filter' )->justReturn( false );
        Functions\when( 'get_post_meta' )->alias( function ( $id, $key ) {
            return '_unq_agev_required' === $key && 2 === $id ? 'yes' : '';
        } );
        Functions\when( 'get_the_terms' )->justReturn( false );
        Functions\when( 'apply_filters' )->alias( function ( $hook, $value ) { return $value; } );
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'esc_url_raw' )->returnArg();
        Functions\when( 'get_locale' )->justReturn( 'en_US' );
        Functions\when( 'wc_get_cart_url' )->justReturn( 'https://shop.test/cart/' );
        Functions\when( 'is_wp_error' )->alias( function ( $value ) { return $value instanceof \WP_Error; } );
        unset( $_COOKIE[ UNQ_AGEV_COOKIE_NAME ] );
        WC()->countries = new class {
            public function get_shipping_countries() { return array( 'DK' => 'Denmark', 'DE' => 'Germany', 'SE' => 'Sweden' ); }
        };
        WC()->session = new class {
            public function get( $key, $default = null ) { return array( 'flat_rate:1' ); }
        };
    }

    protected function tearDown(): void {
        WC()->countries = null;
        WC()->session = null;
        unset( $_COOKIE[ UNQ_AGEV_COOKIE_NAME ] );
        Monkey\tearDown();
        parent::tearDown();
    }

    private function item( $id = 1, $physical = true ) {
        return array(
            'product_id' => $id,
            'data' => new class( $physical ) {
                private $physical;
                public function __construct( $physical ) { $this->physical = $physical; }
                public function needs_shipping() { return $this->physical; }
            },
        );
    }

    public function test_feature_is_opt_in_and_requires_active_plugin(): void {
        unset( $this->options['unq_agev_outside_denmark'] );
        $this->assertFalse( \UNQ_Delivery_Gate::active() );
        $this->options['unq_agev_outside_denmark'] = 'unexpected';
        $this->assertFalse( \UNQ_Delivery_Gate::active() );
        $this->options['unq_agev_outside_denmark'] = 'yes';
        $this->assertTrue( \UNQ_Delivery_Gate::active() );
        $this->options['unq_agev_enabled'] = 'no';
        $this->assertFalse( \UNQ_Delivery_Gate::active() );
        $this->options['unq_agev_enabled'] = 'yes';
        $this->options['unq_agev_test_public_key'] = '';
        $this->assertFalse( \UNQ_Delivery_Gate::active() );
    }

    /** @dataProvider destinations */
    public function test_delivery_policy( $country, $methods, $physical, $required ): void {
        $decision = \UNQ_Delivery_Gate::decision( $country, array( $this->item( 1, $physical ) ), $methods );
        $this->assertSame( $required, $decision['required'] );
    }

    public static function destinations(): array {
        return array(
            'Denmark' => array( 'DK', array( 'flat_rate:1' ), true, true ),
            'Germany' => array( 'DE', array( 'flat_rate:1' ), true, false ),
            'Sweden' => array( 'SE', array( 'free_shipping:2' ), true, false ),
            'empty country' => array( '', array( 'flat_rate:1' ), true, true ),
            'unknown country' => array( 'ZZ', array( 'flat_rate:1' ), true, true ),
            'unsupported country' => array( 'US', array( 'flat_rate:1' ), true, true ),
            'malformed country' => array( array( 'DE' ), array( 'flat_rate:1' ), true, true ),
            'missing method' => array( 'DE', array(), true, true ),
            'malformed method' => array( 'DE', array( array( 'flat_rate' ) ), true, true ),
            'classic pickup' => array( 'DE', array( 'local_pickup:3' ), true, true ),
            'block pickup' => array( 'DE', array( 'pickup_location:0' ), true, true ),
            'mixed pickup' => array( 'DE', array( 'flat_rate:1', 'local_pickup:3' ), true, true ),
            'virtual goods' => array( 'DE', array( 'flat_rate:1' ), false, true ),
        );
    }

    public function test_country_changes_do_not_reuse_an_exemption(): void {
        foreach ( array( 'DE' => false, 'DK' => true, 'SE' => false ) as $country => $required ) {
            $decision = \UNQ_Delivery_Gate::decision( $country, array( $this->item() ), array( 'flat_rate:1' ) );
            $this->assertSame( $required, $decision['required'] );
        }
    }

    public function test_ungated_products_and_gated_virtual_products_in_mixed_carts(): void {
        $this->options['unq_agev_targeting'] = 'selected_only';
        $this->assertFalse( \UNQ_Delivery_Gate::decision( 'DK', array( $this->item() ), array( 'flat_rate:1' ) )['required'] );
        $this->assertTrue( \UNQ_Delivery_Gate::decision( 'DE', array( $this->item(), $this->item( 2, false ) ), array( 'flat_rate:1' ) )['required'] );
        $this->assertFalse( \UNQ_Delivery_Gate::decision( 'DE', array( $this->item( 1, false ), $this->item( 2 ) ), array( 'flat_rate:1' ) )['required'] );
    }

    public function test_custom_pickup_methods_can_use_woocommerce_filter(): void {
        Functions\when( 'apply_filters' )->alias( function ( $hook, $value ) {
            return 'woocommerce_local_pickup_methods' === $hook ? array_merge( $value, array( 'custom_pickup' ) ) : $value;
        } );
        $decision = \UNQ_Delivery_Gate::decision( 'DE', array( $this->item() ), array( 'custom_pickup:4' ) );
        $this->assertTrue( $decision['required'] );
    }

    private function errors() {
        return new class {
            public $messages = array();
            public function add( $code, $message ) { $this->messages[ $code ] = $message; }
        };
    }

    public function test_classic_validation_uses_final_shipping_not_foreign_billing_or_cookie(): void {
        $GLOBALS['unq_agev_test_cart_items'] = array( $this->item() );
        $_COOKIE[ UNQ_AGEV_COOKIE_NAME ] = array( 'malformed' );
        $errors = $this->errors();
        \UNQ_Delivery_Gate::validate_checkout( array( 'billing_country' => 'DE', 'shipping_country' => 'DK' ), $errors );
        $this->assertArrayHasKey( 'unqverify_failed', $errors->messages );
        $this->assertStringContainsString( 'href="https://shop.test/cart/"', $errors->messages['unqverify_failed'] );
        $errors = $this->errors();
        \UNQ_Delivery_Gate::validate_checkout( array( 'billing_country' => 'DK', 'shipping_country' => 'DE' ), $errors );
        $this->assertSame( array(), $errors->messages );
    }

    private function token( $overrides = array() ) {
        $factory = new JwtTestFactory();
        Functions\when( 'get_transient' )->alias( function ( $key ) use ( $factory ) {
            return 'unqverify_pubkey_test' === $key ? $factory->public_pem() : false;
        } );
        $_COOKIE[ UNQ_AGEV_COOKIE_NAME ] = $factory->make_jwt( $overrides );
    }

    public function test_danish_delivery_needs_a_valid_sufficient_age_token(): void {
        $GLOBALS['unq_agev_test_cart_items'] = array( $this->item() );
        foreach ( array( array( 'age' => 18 ), array( 'age' => 17 ), array( 'exp_offset' => -1000 ) ) as $i => $claims ) {
            $this->token( $claims );
            $errors = $this->errors();
            \UNQ_Delivery_Gate::validate_checkout( array( 'shipping_country' => 'DK' ), $errors );
            $this->assertSame( 0 === $i, empty( $errors->messages ) );
        }
    }

    private function order( $country ) {
        return new class( $country, $this->item()['data'] ) {
            public $country;
            private $product;
            public $meta = array();
            public $saves = 0;
            public function __construct( $country, $product ) { $this->country = $country; $this->product = $product; }
            public function get_shipping_country() { return $this->country; }
            public function get_items() {
                return array( new class( $this->product ) {
                    private $product;
                    public function __construct( $product ) { $this->product = $product; }
                    public function get_product_id() { return 1; }
                    public function get_variation_id() { return 0; }
                    public function get_product() { return $this->product; }
                } );
            }
            public function get_shipping_methods() {
                return array( new class { public function get_method_id() { return 'flat_rate'; } } );
            }
            public function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
            public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
            public function save_meta_data() { ++$this->saves; }
        };
    }

    public function test_final_order_is_checked_even_when_cart_has_been_emptied(): void {
        $GLOBALS['unq_agev_test_cart_items'] = array();
        $this->expectException( \Exception::class );
        $this->expectExceptionMessage( 'Age verification is required' );
        \UNQ_Delivery_Gate::validate_classic_order( 1, array( 'shipping_country' => 'DE' ), $this->order( 'DK' ) );
    }

    public function test_foreign_exemption_does_not_authorize_a_danish_payment_retry(): void {
        $order = $this->order( 'DE' );
        \UNQ_Delivery_Gate::validate_classic_order( 1, array(), $order );
        $this->assertSame( 0, $order->meta['_unq_agev_verified_age'] );
        $order->country = 'DK';
        Functions\expect( 'wc_add_notice' )->once()->with( \Mockery::type( 'string' ), 'error' );
        \UNQ_Delivery_Gate::validate_payment( $order );
    }

    public function test_verified_order_can_retry_after_cookie_expires_but_not_after_environment_changes(): void {
        $order = $this->order( 'DK' );
        $this->token();
        \UNQ_Delivery_Gate::validate_classic_order( 1, array(), $order );
        $this->assertSame( 18, $order->meta['_unq_agev_verified_age'] );
        unset( $_COOKIE[ UNQ_AGEV_COOKIE_NAME ] );
        Functions\expect( 'wc_add_notice' )->once()->with( \Mockery::type( 'string' ), 'error' );
        \UNQ_Delivery_Gate::validate_payment( $order );
        $this->options['unq_agev_test_public_key'] = 'pk_test_different';
        \UNQ_Delivery_Gate::validate_payment( $order );
    }

    public function test_old_manual_orders_and_disabled_feature_do_not_change_payment_behavior(): void {
        Functions\expect( 'wc_add_notice' )->never();
        $order = $this->order( 'DK' );
        \UNQ_Delivery_Gate::validate_payment( $order );
        $this->options['unq_agev_outside_denmark'] = 'no';
        \UNQ_Delivery_Gate::validate_classic_order( 1, array(), $order );
        $this->assertSame( 0, $order->saves );
    }

    private function store_request( $order, $route = '/wc/store/v1/checkout/123' ) {
        \UNQ_Delivery_Gate::track_store_request( $order, new class( $route ) {
            private $route;
            public function __construct( $route ) { $this->route = $route; }
            public function get_route() { return $this->route; }
        } );
    }

    public function test_store_api_payment_retry_preserves_verified_order_without_cookie(): void {
        $order = $this->order( 'DK' );
        $this->token();
        \UNQ_Delivery_Gate::validate_store_order( $order );
        unset( $_COOKIE[ UNQ_AGEV_COOKIE_NAME ] );
        $proof = $order->meta;
        foreach ( array( '/wc/store/v1/checkout/123', '/wc/store/checkout/123' ) as $route ) {
            $this->store_request( $order, $route );
            \UNQ_Delivery_Gate::validate_store_order( $order );
            $this->assertSame( $proof, $order->meta );
        }
    }

    public function test_store_api_retry_still_checks_a_changed_foreign_destination(): void {
        $order = $this->order( 'DE' );
        \UNQ_Delivery_Gate::validate_store_order( $order );
        $order->country = 'DK';
        $this->store_request( $order );
        $this->expectException( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException::class );
        \UNQ_Delivery_Gate::validate_store_order( $order );
    }

    public function test_store_api_retry_rejects_proof_after_key_change(): void {
        $order = $this->order( 'DK' );
        $this->token();
        \UNQ_Delivery_Gate::validate_store_order( $order );
        unset( $_COOKIE[ UNQ_AGEV_COOKIE_NAME ] );
        $this->options['unq_agev_test_public_key'] = 'pk_test_different';
        $this->store_request( $order );
        $this->expectException( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException::class );
        \UNQ_Delivery_Gate::validate_store_order( $order );
    }

    public function test_store_api_historical_payment_is_untouched_but_new_checkout_is_checked(): void {
        $order = $this->order( 'DK' );
        $this->store_request( $order );
        \UNQ_Delivery_Gate::validate_store_order( $order );
        $this->assertSame( 0, $order->saves );
        $this->store_request( $order, '/wc/store/v1/checkout' );
        $this->expectException( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException::class );
        \UNQ_Delivery_Gate::validate_store_order( $order );
    }
}
