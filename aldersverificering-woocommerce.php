<?php
/**
 * Plugin Name: Aldersverificering for WooCommerce
 * Plugin URI:  https://unqverify.com
 * Description: Age verification for WooCommerce checkout using MitID via the UNQVerify SDK.
 * Version:     0.2.0
 * Author:      UNQTech
 * Author URI:  https://unqtech.dk
 * Text Domain: unq-age-verification
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'UNQ_AGEV_VERSION',     '0.2.0' );
define( 'UNQ_AGEV_PATH',        plugin_dir_path( __FILE__ ) );
define( 'UNQ_AGEV_URL',         plugin_dir_url( __FILE__ ) );
define( 'UNQ_AGEV_SDK_URL',     'https://unpkg.com/@unqtech/age-verification-mitid@0.4.3/dist/index.umd.js' );
define( 'UNQ_AGEV_COOKIE_NAME', 'unqverify_token' );

require_once UNQ_AGEV_PATH . 'includes/class-unq-jwt-validator.php';

// ---------------------------------------------------------------------------
// Settings helpers — reads merchant config stored via WooCommerce settings API.
// ---------------------------------------------------------------------------

/**
 * Get a plugin setting by key.
 *
 * Keys:
 *   enabled      → 'yes' | 'no'
 *   public_key   → string  (pk_test_* or pk_live_*)
 *   required_age → int     (minimum verified age, 1–120)
 *   mode         → 'popup' | 'redirect'
 *
 * @param  string $key
 * @return mixed
 */
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

// ---------------------------------------------------------------------------
// WooCommerce Settings tab — WooCommerce > Settings > UNQVerify.
// ---------------------------------------------------------------------------

add_filter( 'woocommerce_settings_tabs_array', function ( $tabs ) {
    $tabs['unq_agev'] = __( 'UNQVerify', 'unq-age-verification' );
    return $tabs;
}, 50 );

/**
 * Returns the settings fields definition for WC_Admin_Settings.
 *
 * @return array
 */
function unq_agev_settings_fields() {
    return array(
        array(
            'id'    => 'unq_agev_section_start',
            'type'  => 'title',
            'title' => __( 'UNQVerify Age Verification', 'unq-age-verification' ),
            'desc'  => sprintf(
                wp_kses(
                    /* translators: %s: URL to UNQVerify dashboard */
                    __( 'MitID-based age verification for WooCommerce checkout. Get your API key from the <a href="%s" target="_blank" rel="noopener">UNQVerify dashboard</a>.', 'unq-age-verification' ),
                    array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) )
                ),
                'https://app.aldersverificering.dk/account/api-keys'
            ),
        ),
        array(
            'id'      => 'unq_agev_enabled',
            'type'    => 'checkbox',
            'title'   => __( 'Enable', 'unq-age-verification' ),
            'label'   => __( 'Enable age verification on checkout', 'unq-age-verification' ),
            'default' => 'yes',
        ),
        array(
            'id'          => 'unq_agev_public_key',
            'type'        => 'text',
            'title'       => __( 'Public Key', 'unq-age-verification' ),
            'desc'        => wp_kses(
                __( 'Your UNQVerify public key. Use <code>pk_test_*</code> for the test environment or <code>pk_live_*</code> for production — the key prefix determines the environment automatically.', 'unq-age-verification' ),
                array( 'code' => array() )
            ),
            'desc_tip'    => false,
            'placeholder' => 'pk_test_...',
            'css'         => 'min-width:380px;font-family:monospace;',
        ),
        array(
            'id'                => 'unq_agev_required_age',
            'type'              => 'number',
            'title'             => __( 'Required Age', 'unq-age-verification' ),
            'desc'              => __( 'Minimum age (in years) a customer must have verified to complete a purchase.', 'unq-age-verification' ),
            'default'           => '18',
            'css'               => 'width:80px;',
            'custom_attributes' => array( 'min' => '1', 'max' => '120', 'step' => '1' ),
        ),
        array(
            'id'       => 'unq_agev_verification_mode',
            'type'     => 'select',
            'title'    => __( 'Verification Mode', 'unq-age-verification' ),
            'desc'     => wp_kses(
                __( '<strong>Popup</strong> — MitID opens in a small popup; the customer stays on your page (recommended).<br><strong>Full-page redirect</strong> — The browser navigates to MitID and returns after verification. Use this if your customers frequently have popups blocked.', 'unq-age-verification' ),
                array( 'strong' => array(), 'br' => array() )
            ),
            'desc_tip' => false,
            'default'  => 'popup',
            'options'  => array(
                'popup'    => __( 'Popup (recommended)', 'unq-age-verification' ),
                'redirect' => __( 'Full-page redirect', 'unq-age-verification' ),
            ),
        ),
        array(
            'id'   => 'unq_agev_section_end',
            'type' => 'sectionend',
        ),
    );
}

add_action( 'woocommerce_settings_tabs_unq_agev', function () {
    WC_Admin_Settings::output_fields( unq_agev_settings_fields() );
} );

add_action( 'woocommerce_update_options_unq_agev', function () {
    WC_Admin_Settings::save_fields( unq_agev_settings_fields() );
} );

// Clear the cached JWKS public key whenever the merchant updates settings
// (e.g. switching between test and live keys).
add_action( 'woocommerce_update_options_unq_agev', function () {
    delete_transient( 'unqverify_pubkey_test' );
    delete_transient( 'unqverify_pubkey_live' );
} );

// Admin notice when the plugin is active but has no public key configured.
add_action( 'admin_notices', function () {
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        return;
    }
    if ( 'yes' !== unq_agev_get( 'enabled' ) || ! empty( unq_agev_get( 'public_key' ) ) ) {
        return;
    }
    $settings_url = admin_url( 'admin.php?page=wc-settings&tab=unq_agev' );
    printf(
        '<div class="notice notice-warning"><p>%s</p></div>',
        wp_kses(
            sprintf(
                /* translators: %s: link to plugin settings page */
                __( '<strong>UNQVerify Age Verification:</strong> No public key is configured. <a href="%s">Add your key in WooCommerce &rarr; Settings &rarr; UNQVerify</a>.', 'unq-age-verification' ),
                esc_url( $settings_url )
            ),
            array(
                'strong' => array(),
                'a'      => array( 'href' => array() ),
            )
        )
    );
} );

// ---------------------------------------------------------------------------
// 1. Popup callback page — served at /unqverify/callback/?jwt=TOKEN
//
//    The redirectUri MUST be a clean path with no '?' so UNQVerify can append
//    ?jwt=TOKEN as a proper query string. Using /?unqverify=callback would
//    produce /?unqverify=callback?jwt=TOKEN (two '?' chars), which PHP parses
//    as $_GET['unqverify'] = 'callback?jwt=TOKEN' — never matching 'callback'
//    — and URLSearchParams in the popup can't parse 'jwt' either.
// ---------------------------------------------------------------------------

// Register the rewrite rule on every request so it is always present in the
// in-memory table (survives cache flushes, permalink changes, etc.).
add_action( 'init', function () {
    add_rewrite_rule( '^unqverify/callback/?$', 'index.php?unqverify=callback', 'top' );
} );

// Flush .htaccess/rewrite cache on activation so the rule takes effect
// immediately — no manual Settings → Permalinks save required.
register_activation_hook( __FILE__, function () {
    add_rewrite_rule( '^unqverify/callback/?$', 'index.php?unqverify=callback', 'top' );
    flush_rewrite_rules();
} );

// Remove the rule from .htaccess when the plugin is deactivated.
register_deactivation_hook( __FILE__, function () {
    flush_rewrite_rules();
} );

add_filter( 'query_vars', function ( $vars ) {
    $vars[] = 'unqverify';
    return $vars;
} );

add_action( 'template_redirect', function () {
    if ( 'callback' !== get_query_var( 'unqverify' ) ) {
        return;
    }

    // Guard: nothing useful to serve if no key has been configured.
    if ( empty( unq_agev_get( 'public_key' ) ) ) {
        wp_die( esc_html__( 'Age verification is not configured.', 'unq-age-verification' ), '', array( 'response' => 500 ) );
    }

    // Origin only (scheme + host + port, no trailing slash) — used as postMessage targetOrigin.
    $parsed          = wp_parse_url( home_url() );
    $redirect_origin = $parsed['scheme'] . '://' . $parsed['host'] . ( ! empty( $parsed['port'] ) ? ':' . $parsed['port'] : '' );
    $sdk_url         = esc_url( UNQ_AGEV_SDK_URL );

    // Serve a self-contained page — no WP theme, no header/footer.
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
  <script src="' . $sdk_url . '"></script>
  <script>
    (function () {
      // mode is injected by PHP — "popup" (default) or "redirect".
      //
      // Popup mode: BroadcastChannel("unqverify") posts the result to the
      //   opener cart/checkout page, then window.close() shuts the popup.
      //   This is COOP-safe — works even when MitID sets
      //   Cross-Origin-Opener-Policy: same-origin, which nulls window.opener.
      //
      // Redirect mode: the browser navigated here from MitID; after the SDK
      //   stores the JWT cookie we navigate the main window to checkout/cart.
      var mode        = ' . wp_json_encode( unq_agev_get( 'mode' ) ) . ';
      var checkoutUrl = ' . wp_json_encode( function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/checkout/' ) ) . ';
      var cartUrl     = ' . wp_json_encode( function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/cart/' ) ) . ';

      function bc_post(msg) {
        if (window.BroadcastChannel) {
          try { new BroadcastChannel("unqverify").postMessage(msg); } catch (ignore) {}
        }
      }

      if (window.UnqVerify) {
        // Note: init() is NOT required before handleRedirectResult().
        // handleRedirectResult() reads the ?jwt= query param, stores the
        // cookie, then fires the relevant callback. It is fully self-contained.
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

// ---------------------------------------------------------------------------
// 2. Block direct /checkout access when the JWT cookie is absent or invalid.
//    Redirect to cart with a notice instead of letting the user see a broken
//    checkout that they cannot complete anyway.
// ---------------------------------------------------------------------------

add_action( 'template_redirect', function () {
    if ( 'yes' !== unq_agev_get( 'enabled' ) || empty( unq_agev_get( 'public_key' ) ) ) {
        return;
    }

    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
        return;
    }

    // Order-received / thank-you page shares is_checkout() — allow it through.
    if ( is_wc_endpoint_url( 'order-received' ) ) {
        return;
    }

    // Order-pay page — customer already placed the order and is returning to pay
    // (e.g. BACS, cheque, failed card retry). Age was verified at order creation;
    // blocking them here would strand their open order.
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

    $result = UNQ_JWT_Validator::validate( $token, unq_agev_get( 'required_age' ) );
    if ( is_wp_error( $result ) ) {
        $cart_url = add_query_arg( 'unqverify_required', '1', wc_get_cart_url() );
        wp_safe_redirect( $cart_url );
        exit;
    }
}, 20 ); // Priority 20 — runs after WooCommerce's own template_redirect hooks.

// ---------------------------------------------------------------------------
// 3. Show a notice on the cart page when redirected from checkout.
// ---------------------------------------------------------------------------

add_action( 'woocommerce_before_cart', function () {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if ( ! empty( $_GET['unqverify_required'] ) ) {
        wc_add_notice(
            esc_html__( 'Du skal gennemføre aldersverificering, inden du kan gå til kassen.', 'unq-age-verification' ),
            'notice'
        );
    }
} );

// ---------------------------------------------------------------------------
// 4a. Server-side validation for WooCommerce Blocks (Store API) checkout.
//     The Blocks checkout submits to /wp-json/wc/store/v1/checkout, which
//     never fires woocommerce_checkout_process. We hook into the Store API's
//     own order-processed action and throw a RouteException to abort.
// ---------------------------------------------------------------------------

add_action( 'woocommerce_store_api_checkout_order_processed', function ( $order ) {
    if ( 'yes' !== unq_agev_get( 'enabled' ) || empty( unq_agev_get( 'public_key' ) ) ) {
        return;
    }

    $token = isset( $_COOKIE[ UNQ_AGEV_COOKIE_NAME ] )
        ? sanitize_text_field( wp_unslash( $_COOKIE[ UNQ_AGEV_COOKIE_NAME ] ) )
        : '';

    $result = UNQ_JWT_Validator::validate( $token, unq_agev_get( 'required_age' ) );

    if ( is_wp_error( $result ) ) {
        $message = $result->get_error_code() === 'unqverify_expired'
            ? __( 'Din aldersverificering er udløbet. Verificér venligst igen for at gennemføre dit køb.', 'unq-age-verification' )
            : __( 'Du skal gennemføre aldersverificering for at gennemføre dit køb.', 'unq-age-verification' );

        // RouteException is the correct way to abort a Store API request with
        // a user-visible error. Class is always available when WC Blocks is
        // active; guard with class_exists to be safe on older WC versions.
        if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
            throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
                'unqverify_failed',
                esc_html( $message ),
                400
            );
        }
    }
} );

// ---------------------------------------------------------------------------
// 4. Server-side validation at order submission — the real security gate.
//    Fires before WooCommerce creates the order. A wc_add_notice() error here
//    causes WooCommerce to abort order creation automatically.
// ---------------------------------------------------------------------------

add_action( 'woocommerce_checkout_process', function () {
    if ( 'yes' !== unq_agev_get( 'enabled' ) || empty( unq_agev_get( 'public_key' ) ) ) {
        return;
    }

    $token = isset( $_COOKIE[ UNQ_AGEV_COOKIE_NAME ] )
        ? sanitize_text_field( wp_unslash( $_COOKIE[ UNQ_AGEV_COOKIE_NAME ] ) )
        : '';

    $result = UNQ_JWT_Validator::validate( $token, unq_agev_get( 'required_age' ) );

    if ( is_wp_error( $result ) ) {
        $message = $result->get_error_code() === 'unqverify_expired'
            ? __( 'Din aldersverificering er udløbet. Verificér venligst igen for at gennemføre dit køb.', 'unq-age-verification' )
            : __( 'Du skal gennemføre aldersverificering for at gennemføre dit køb.', 'unq-age-verification' );

        wc_add_notice( esc_html( $message ), 'error' );
    }
} );

// ---------------------------------------------------------------------------
// 5. Enqueue scripts.
// ---------------------------------------------------------------------------

add_action( 'wp_enqueue_scripts', function () {
    if ( 'yes' !== unq_agev_get( 'enabled' ) || empty( unq_agev_get( 'public_key' ) ) ) {
        return;
    }

    if ( ! function_exists( 'is_cart' ) || ! function_exists( 'is_checkout' ) ) {
        return;
    }

    $shared_data = array(
        'sdkUrl'      => UNQ_AGEV_SDK_URL,
        'publicKey'   => unq_agev_get( 'public_key' ),
        'ageToVerify' => unq_agev_get( 'required_age' ),
        'redirectUri' => home_url( '/unqverify/callback/' ),
        'mode'        => unq_agev_get( 'mode' ),
    );

    if ( is_cart() ) {
        wp_enqueue_script(
            'unq-age-cart',
            UNQ_AGEV_URL . 'assets/cart.js',
            array( 'jquery' ),
            UNQ_AGEV_VERSION,
            true
        );
        wp_localize_script(
            'unq-age-cart',
            'UNQCart',
            array_merge( $shared_data, array(
                'checkoutUrl' => wc_get_checkout_url(),
                'nonce'       => wp_create_nonce( 'unq_age_cart' ),
                'i18n'        => array(
                    'verifyPrompt'  => __( 'Bekræft alder for at fortsætte', 'unq-age-verification' ),
                    'verified'      => __( 'Alder bekræftet', 'unq-age-verification' ),
                    'denied'        => __( 'Du opfylder ikke alderskravet for disse varer.', 'unq-age-verification' ),
                    'cancelled'     => __( 'Aldersverificering annulleret.', 'unq-age-verification' ),
                    'popupBlocked'  => __( 'Tillad pop-up vinduer på dette site for at bekræfte din alder.', 'unq-age-verification' ),
                    'error'         => __( 'Der opstod en fejl. Prøv igen.', 'unq-age-verification' ),
                    'modalTitle'    => __( 'Aldersverificering påkrævet', 'unq-age-verification' ),
                    'modalBody'     => sprintf(
                        /* translators: %d: minimum required age */
                        __( 'Denne butik sælger aldersbegrænsede varer. Du skal bekræfte, at du er %d år eller ældre, for at gå til kassen. Det sker sikkert via MitID og tager kun et øjeblik.', 'unq-age-verification' ),
                        unq_agev_get( 'required_age' )
                    ),
                    'modalVerifyBtn' => __( 'Bekræft alder med MitID', 'unq-age-verification' ),
                    'modalCancelBtn' => __( 'Annuller', 'unq-age-verification' ),
                ),
            ) )
        );
    }
} );