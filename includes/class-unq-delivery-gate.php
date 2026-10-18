<?php
/**
 * Optional foreign-delivery rule. Uses WooCommerce server hooks only: no
 * checkout field selectors, checkout templates, submit handlers or bypass flag.
 *
 * @package UNQ_Age_Verification
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class UNQ_Delivery_Gate {
    private static $store_payment_order = null;

    public static function active() {
        return 'yes' === unq_agev_get( 'outside_denmark' )
            && 'yes' === unq_agev_get( 'enabled' )
            && '' !== unq_agev_active_key();
    }

    public static function register() {
        add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_checkout' ), 100, 2 );
        // Check the actual order as well: custom integrations may change its
        // destination after field validation, or empty the cart before payment.
        add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'validate_classic_order' ), 100, 3 );
        add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'track_store_request' ), 100, 2 );
        add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'validate_store_order' ), 100 );
        add_action( 'woocommerce_before_pay_action', array( __CLASS__, 'validate_payment' ), 100 );
    }

    /**
     * $items use the existing cart-line shape, including the WC_Product in data.
     * No country result is cached: the submitted order must win over the session.
     * An exemption requires physical gated goods, a known foreign destination
     * and a shipping method. Unknown data, virtual goods and pickup fail closed.
     */
    public static function decision( $country, $items, $shipping_methods ) {
        $gated = false;
        $physical = true;
        $age = 0;
        foreach ( $items as $item ) {
            $rules = unq_agev_cart_item_rules( $item );
            if ( ! $rules['gated'] ) {
                continue;
            }
            $gated = true;
            $age = max( $age, $rules['age'] );
            $product = $item['data'] ?? null;
            if ( ! $product || ! $product->needs_shipping() ) {
                $physical = false;
            }
        }

        $country = is_string( $country ) ? strtoupper( trim( $country ) ) : '';
        $countries = WC()->countries->get_shipping_countries();
        $foreign = '' !== $country && 'DK' !== $country && isset( $countries[ $country ] );
        $shipped = is_array( $shipping_methods ) && count( $shipping_methods ) > 0;
        $pickup_methods = apply_filters( 'woocommerce_local_pickup_methods', array( 'local_pickup', 'pickup_location' ) );
        foreach ( (array) $shipping_methods as $method ) {
            $method_id = is_string( $method ) ? explode( ':', $method )[0] : '';
            if ( '' === $method_id || in_array( $method_id, $pickup_methods, true ) ) {
                $shipped = false;
            }
        }

        return array(
            'required' => $gated && ! ( $physical && $foreign && $shipped ),
            'age'      => $age ?: unq_agev_get( 'required_age' ),
        );
    }

    public static function order_decision( $order ) {
        $items = array();
        foreach ( $order->get_items() as $item ) {
            $items[] = array(
                'product_id'   => $item->get_product_id(),
                'variation_id' => $item->get_variation_id(),
                'data'         => $item->get_product(),
            );
        }
        $methods = array();
        foreach ( $order->get_shipping_methods() as $method ) {
            $methods[] = $method->get_method_id();
        }
        return self::decision( $order->get_shipping_country(), $items, $methods );
    }

    private static function verification_error( $decision ) {
        if ( ! $decision['required'] ) {
            return null;
        }
        $cookie = $_COOKIE[ UNQ_AGEV_COOKIE_NAME ] ?? '';
        $token = is_string( $cookie ) ? sanitize_text_field( wp_unslash( $cookie ) ) : '';
        $result = UNQ_JWT_Validator::validate( $token, $decision['age'] );
        return is_wp_error( $result ) ? $result : null;
    }

    public static function error_message( $html = true ) {
        $strings = unq_agev_strings( unq_agev_resolve_locale() );
        $message = esc_html( $strings['deliveryRequired'] );
        $url = wc_get_cart_url();
        return $html
            ? $message . ' <a href="' . esc_url( $url ) . '">' . esc_html( $strings['returnToCart'] ) . '</a>'
            : $message . ' ' . esc_url_raw( $url );
    }

    public static function validate_checkout( $data, $errors ) {
        if ( ! self::active() ) {
            return;
        }
        // get_posted_data() has already copied billing to shipping when the
        // customer is not shipping to a separate address. Do not use a stale
        // WC_Customer country or fall back to billing for a missing destination.
        $decision = self::decision(
            $data['shipping_country'] ?? '',
            WC()->cart->get_cart(),
            WC()->session->get( 'chosen_shipping_methods', array() )
        );
        if ( self::verification_error( $decision ) ) {
            $errors->add( 'unqverify_failed', self::error_message() );
        }
    }

    public static function validate_classic_order( $order_id, $data, $order ) {
        if ( self::active() && self::check_order( $order ) ) {
            // WC_Checkout catches this and returns its normal checkout error.
            throw new Exception( self::error_message() );
        }
    }

    public static function track_store_request( $order, $request ) {
        // Checkout and payment of an existing order share the processed hook.
        self::$store_payment_order = preg_match( '#^/wc/store(?:/v\d+)?/checkout/\d+/?$#', $request->get_route() )
            ? $order : null;
    }

    public static function validate_store_order( $order ) {
        $retry = self::$store_payment_order === $order;
        self::$store_payment_order = null;
        if ( ! self::active() || ( $retry && 'yes' !== $order->get_meta( '_unq_agev_delivery_checked' ) ) ) {
            return;
        }
        if ( self::check_order( $order, $retry ) ) {
            throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
                'unqverify_failed', self::error_message( false ), 400
            );
        }
    }

    public static function validate_payment( $order ) {
        // Do not change payment of historical or manually created orders that
        // never went through this optional checkout policy.
        if ( self::active() && 'yes' === $order->get_meta( '_unq_agev_delivery_checked' ) && self::check_order( $order, true ) ) {
            // before_pay_action runs outside WooCommerce's try/catch. A native
            // error notice stops payment; throwing here would break the page.
            wc_add_notice( self::error_message(), 'error' );
        }
    }

    private static function check_order( $order, $retry = false ) {
        $decision = self::order_decision( $order );
        $key_hash = hash( 'sha256', unq_agev_active_key() );
        // An already verified order can retry payment after its browser token
        // expires. Foreign-delivery exemptions never count as verification.
        if ( $retry && $decision['required']
            && (int) $order->get_meta( '_unq_agev_verified_age' ) >= $decision['age']
            && $key_hash === $order->get_meta( '_unq_agev_verified_key_hash' ) ) {
            return null;
        }
        $error = self::verification_error( $decision );
        $order->update_meta_data( '_unq_agev_delivery_checked', 'yes' );
        $order->update_meta_data( '_unq_agev_verified_age', ! $error && $decision['required'] ? $decision['age'] : 0 );
        $order->update_meta_data( '_unq_agev_verified_key_hash', ! $error && $decision['required'] ? $key_hash : '' );
        $order->save_meta_data();
        return $error;
    }
}
