<?php
/**
 * UNQVerify Age Verification — frontend layer.
 *
 * Registers all frontend hooks: rewrite rules, template redirects, cart/checkout
 * validation, script enqueueing, and cart-cache invalidation.
 *
 * @package UNQ_Age_Verification
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class UNQ_Frontend {

    public static function register() {

        // ── Rewrite rule (registered every request so it is always live) ─
        add_action( 'init', function () {
            add_rewrite_rule( '^unqverify/callback/?$', 'index.php?unqverify=callback', 'top' );
        } );

        add_filter( 'query_vars', function ( $vars ) {
            $vars[] = 'unqverify';
            return $vars;
        } );

        // ── Popup callback page — /unqverify/callback/ ────────────────────
        add_action( 'template_redirect', function () {
            if ( 'callback' !== get_query_var( 'unqverify' ) ) {
                return;
            }

            if ( empty( unq_agev_active_key() ) ) {
                wp_die( esc_html__( 'Age verification is not configured.', 'unq-age-verification' ), '', array( 'response' => 500 ) );
            }

            $parsed          = wp_parse_url( home_url() );
            $redirect_origin = $parsed['scheme'] . '://' . $parsed['host'] . ( ! empty( $parsed['port'] ) ? ':' . $parsed['port'] : '' );
            $sdk_url         = esc_url( UNQ_AGEV_SDK_URL );

            status_header( 200 );
            nocache_headers();
            header( 'Content-Type: text/html; charset=utf-8' );

            // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
            echo '<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Age Verification Callback</title>
</head>
<body>
  <script src="' . $sdk_url . '"' . ( UNQ_AGEV_SDK_SRI ? ' integrity="' . esc_attr( UNQ_AGEV_SDK_SRI ) . '" crossorigin="anonymous"' : '' ) . '></script>
  <script>
    (function () {
      var mode        = ' . wp_json_encode( unq_agev_get( 'mode' ) ) . ';
      var checkoutUrl = ' . wp_json_encode( function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/checkout/' ) ) . ';
      var cartUrl     = ' . wp_json_encode( function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/cart/' ) ) . ';

      function bc_post(msg) {
        if (window.BroadcastChannel) {
          try { new BroadcastChannel("unqverify").postMessage(msg); } catch (ignore) {}
        }
      }

      if (window.UnqVerify) {
        window.UnqVerify.handleRedirectResult({
          onVerified: function () {
            bc_post({ type: "UNQVERIFY_VERIFIED" });
            if (mode === "redirect") {
              window.location.replace(checkoutUrl);
            } else {
              window.close();
            }
          },
          onDenied: function (outcome) {
            bc_post({ type: "UNQVERIFY_DENIED", code: outcome && outcome.code });
            if (mode === "redirect") {
              var sep = cartUrl.indexOf("?") === -1 ? "?" : "&";
              window.location.replace(cartUrl + sep + "unqverify_denied=1");
            } else {
              window.close();
            }
          },
          onError: function (outcome) {
            bc_post({ type: "UNQVERIFY_ERROR", code: outcome && outcome.code });
            if (mode === "redirect") {
              var sep = cartUrl.indexOf("?") === -1 ? "?" : "&";
              window.location.replace(cartUrl + sep + "unqverify_error=1");
            } else {
              window.close();
            }
          },
          targetOrigin: ' . wp_json_encode( $redirect_origin ) . ',
        });
      }
    })();
  </script>
</body>
</html>';
            // phpcs:enable
            exit;
        } );

        // ── Checkout guard — redirect to cart when JWT is absent/invalid ─
        add_action( 'template_redirect', function () {
            if ( ! unq_agev_cart_is_gated() ) {
                return;
            }
            if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
                return;
            }
            if ( is_wc_endpoint_url( 'order-received' ) ) {
                return;
            }
            if ( is_wc_endpoint_url( 'order-pay' ) ) {
                return;
            }

            $token = isset( $_COOKIE[ UNQ_AGEV_COOKIE_NAME ] )
                ? sanitize_text_field( wp_unslash( $_COOKIE[ UNQ_AGEV_COOKIE_NAME ] ) )
                : '';

            if ( empty( $token ) ) {
                $cart_url = add_query_arg( 'unqverify_required', '1', wc_get_cart_url() );
                wp_safe_redirect( $cart_url );
                exit;
            }

            $result = UNQ_JWT_Validator::validate( $token, unq_agev_cart_required_age() );
            if ( is_wp_error( $result ) ) {
                $cart_url = add_query_arg( 'unqverify_required', '1', wc_get_cart_url() );
                wp_safe_redirect( $cart_url );
                exit;
            }
        }, 20 );

        // ── Cart notice when redirected from checkout ─────────────────────
        add_action( 'woocommerce_before_cart', function () {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if ( ! empty( $_GET['unqverify_required'] ) ) {
                $s = unq_agev_strings( unq_agev_resolve_locale() );
                wc_add_notice( esc_html( $s['cart_notice'] ), 'notice' );
            }
        } );

        // ── Server-side validation: WooCommerce Blocks (Store API) ────────
        add_action( 'woocommerce_store_api_checkout_order_processed', function ( $order ) {
            if ( ! unq_agev_cart_is_gated() ) {
                return;
            }

            $token = isset( $_COOKIE[ UNQ_AGEV_COOKIE_NAME ] )
                ? sanitize_text_field( wp_unslash( $_COOKIE[ UNQ_AGEV_COOKIE_NAME ] ) )
                : '';

            $result = UNQ_JWT_Validator::validate( $token, unq_agev_cart_required_age() );

            if ( is_wp_error( $result ) ) {
                $s       = unq_agev_strings( unq_agev_resolve_locale() );
                $message = $result->get_error_code() === 'unqverify_expired' ? $s['expired'] : $s['general'];

                if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
                    throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
                        'unqverify_failed',
                        esc_html( $message ),
                        400
                    );
                }
            }
        } );

        // ── Server-side validation: classic checkout ──────────────────────
        add_action( 'woocommerce_checkout_process', function () {
            if ( ! unq_agev_cart_is_gated() ) {
                return;
            }

            $token = isset( $_COOKIE[ UNQ_AGEV_COOKIE_NAME ] )
                ? sanitize_text_field( wp_unslash( $_COOKIE[ UNQ_AGEV_COOKIE_NAME ] ) )
                : '';

            $result = UNQ_JWT_Validator::validate( $token, unq_agev_cart_required_age() );

            if ( is_wp_error( $result ) ) {
                $s       = unq_agev_strings( unq_agev_resolve_locale() );
                $message = $result->get_error_code() === 'unqverify_expired' ? $s['expired'] : $s['general'];
                wc_add_notice( esc_html( $message ), 'error' );
            }
        } );

        // ── Enqueue cart and checkout scripts ─────────────────────────────
        add_action( 'wp_enqueue_scripts', function () {
            if ( 'yes' !== unq_agev_get( 'enabled' ) || empty( unq_agev_active_key() ) ) {
                return;
            }
            if ( ! function_exists( 'is_cart' ) || ! function_exists( 'is_checkout' ) ) {
                return;
            }

            $shared_data = array(
                'sdkUrl'       => UNQ_AGEV_SDK_URL,
                'sdkIntegrity' => UNQ_AGEV_SDK_SRI,
                'publicKey'    => unq_agev_active_key(),
                'ageToVerify'  => unq_agev_cart_required_age(),
                'redirectUri'  => home_url( '/unqverify/callback/' ),
                'mitIdLogoUrl' => UNQ_AGEV_URL . 'assets/mitid-logo-white.png',
                'mode'         => unq_agev_get( 'mode' ),
                'testMode'     => 'yes' !== unq_agev_get( 'use_production' ),
            );

            $is_gated_cart     = is_cart() && unq_agev_cart_is_gated();
            $is_gated_checkout = is_checkout()
                && unq_agev_cart_is_gated()
                && ! is_wc_endpoint_url( 'order-received' )
                && ! is_wc_endpoint_url( 'order-pay' );

            if ( ! $is_gated_cart && ! $is_gated_checkout ) {
                return;
            }

            wp_enqueue_style(
                'unq-agev-frontend',
                UNQ_AGEV_URL . 'assets/frontend.css',
                array(),
                UNQ_AGEV_VERSION
            );
            wp_enqueue_script(
                'unq-agev-ui',
                UNQ_AGEV_URL . 'assets/verification-ui.js',
                array(),
                UNQ_AGEV_VERSION,
                true
            );

            if ( $is_gated_cart ) {
                wp_enqueue_script(
                    'unq-age-cart',
                    UNQ_AGEV_URL . 'assets/cart.js',
                    array( 'jquery', 'unq-agev-ui' ),
                    UNQ_AGEV_VERSION,
                    true
                );
                wp_localize_script(
                    'unq-age-cart',
                    'UNQCart',
                    array_merge( $shared_data, array(
                        'checkoutUrl' => wc_get_checkout_url(),
                        'nonce'       => wp_create_nonce( 'unq_age_cart' ),
                        'i18n'        => unq_agev_strings( unq_agev_resolve_locale(), unq_agev_cart_required_age() ),
                    ) )
                );
            }

            if ( $is_gated_checkout ) {
                wp_enqueue_script(
                    'unq-age-checkout',
                    UNQ_AGEV_URL . 'assets/checkout.js',
                    array( 'jquery', 'unq-agev-ui' ),
                    UNQ_AGEV_VERSION,
                    true
                );
                wp_localize_script(
                    'unq-age-checkout',
                    'UNQCheckout',
                    array_merge( $shared_data, array(
                        'nonce' => wp_create_nonce( 'unq_age_checkout' ),
                        'i18n'  => unq_agev_strings( unq_agev_resolve_locale(), unq_agev_cart_required_age() ),
                    ) )
                );
            }
        } );

        // ── Cart cache invalidation ───────────────────────────────────────
        add_action( 'woocommerce_cart_updated',       'unq_agev_reset_cart_cache' );
        add_action( 'woocommerce_cart_item_removed',  'unq_agev_reset_cart_cache' );
        add_action( 'woocommerce_cart_item_restored', 'unq_agev_reset_cart_cache' );
        add_action( 'woocommerce_add_to_cart',        'unq_agev_reset_cart_cache' );
    }
}
