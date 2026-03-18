<?php
/**
 * Plugin Name: Aldersverificering for WooCommerce
 * Plugin URI:  https://unqverify.com
 * Description: Age verification for WooCommerce checkout using MitID via the UNQVerify SDK.
 * Version:     0.2.0
 * Author:      UNQTech
 * Author URI:  https://unqtech.dk
 * Text Domain: unq-age-verification
 * Domain Path: /languages
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
 *   enabled          → 'yes' | 'no'
 *   public_key       → string  (pk_live_*  — production API key)
 *   test_public_key  → string  (pk_test_*  — test / sandbox API key)
 *   use_production   → 'yes' | 'no'  (which environment is active)
 *   required_age     → int     (minimum verified age, 1–120)
 *   mode             → 'popup' | 'redirect'
 *   locale           → 'auto' | 'en' | 'da'
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
        case 'test_public_key':
            return (string) get_option( 'unq_agev_test_public_key', '' );
        case 'use_production':
            $val = get_option( 'unq_agev_use_production', 'no' );
            return in_array( $val, array( 'yes', 'no' ), true ) ? $val : 'no';
        case 'required_age':
            return max( 1, (int) get_option( 'unq_agev_required_age', 18 ) );
        case 'mode':
            $mode = get_option( 'unq_agev_verification_mode', 'popup' );
            return in_array( $mode, array( 'popup', 'redirect' ), true ) ? $mode : 'popup';
        case 'locale':
            $locale = get_option( 'unq_agev_locale', 'auto' );
            return in_array( $locale, array( 'auto', 'en', 'da' ), true ) ? $locale : 'auto';
        case 'targeting':
            $targeting = get_option( 'unq_agev_targeting', 'all' );
            return in_array( $targeting, array( 'all', 'selected_only' ), true ) ? $targeting : 'all';
        default:
            return '';
    }
}

/**
 * Returns the API key that should be used for the currently-active environment.
 *
 * When the merchant has toggled to Production AND a pk_live_* key is saved,
 * the production key is returned. In all other cases (test mode or missing
 * production key) the test key is returned.
 *
 * Use this helper everywhere a runtime API key is needed instead of reading
 * 'public_key' directly.
 *
 * @return string
 */
function unq_agev_active_key() {
    if ( 'yes' === unq_agev_get( 'use_production' ) && ! empty( unq_agev_get( 'public_key' ) ) ) {
        return unq_agev_get( 'public_key' );
    }
    return unq_agev_get( 'test_public_key' );
}

/**
 * Returns true when the current cart contains at least one product that requires
 * age verification, based on the configured targeting scope.
 *
 * - 'all'           → always true (when the plugin is enabled and a key is set).
 * - 'selected_only' → true only if the cart has an item whose product has the
 *                     `_unq_agev_required` meta flag, or whose product belongs
 *                     to a category with `unq_agev_category_required` term meta.
 *
 * @return bool
 */
function unq_agev_cart_is_gated() {
    if ( 'yes' !== unq_agev_get( 'enabled' ) || empty( unq_agev_active_key() ) ) {
        return false;
    }

    if ( 'all' === unq_agev_get( 'targeting' ) ) {
        return true;
    }

    // 'selected_only' — scan the cart.
    if ( ! function_exists( 'WC' ) || is_null( WC()->cart ) ) {
        return false;
    }

    foreach ( WC()->cart->get_cart() as $item ) {
        $product_id = (int) ( $item['product_id'] ?? 0 );
        if ( ! $product_id ) {
            continue;
        }
        if ( 'yes' === get_post_meta( $product_id, '_unq_agev_required', true ) ) {
            return true;
        }
        $terms = get_the_terms( $product_id, 'product_cat' );
        if ( is_array( $terms ) ) {
            foreach ( $terms as $term ) {
                if ( 'yes' === get_term_meta( $term->term_id, 'unq_agev_category_required', true ) ) {
                    return true;
                }
            }
        }
    }

    return false;
}

/**
 * Returns the effective minimum required age for the current cart.
 *
 * - 'all'           → the store-wide required_age setting.
 * - 'selected_only' → the maximum of each gated cart item's `_unq_agev_required_age`
 *                     meta (falling back to the store-wide setting per item).
 *                     Returns the store-wide default when the cart is empty or
 *                     contains no gated items.
 *
 * @return int
 */
function unq_agev_cart_required_age() {
    if ( 'all' === unq_agev_get( 'targeting' ) ) {
        return unq_agev_get( 'required_age' );
    }

    $global = unq_agev_get( 'required_age' );

    if ( ! function_exists( 'WC' ) || is_null( WC()->cart ) ) {
        return $global;
    }

    $ages = array();
    foreach ( WC()->cart->get_cart() as $item ) {
        $product_id = (int) ( $item['product_id'] ?? 0 );
        if ( ! $product_id ) {
            continue;
        }
        $is_gated = false;
        if ( 'yes' === get_post_meta( $product_id, '_unq_agev_required', true ) ) {
            $is_gated = true;
        } else {
            $terms = get_the_terms( $product_id, 'product_cat' );
            if ( is_array( $terms ) ) {
                foreach ( $terms as $term ) {
                    if ( 'yes' === get_term_meta( $term->term_id, 'unq_agev_category_required', true ) ) {
                        $is_gated = true;
                        break;
                    }
                }
            }
        }
        if ( ! $is_gated ) {
            continue;
        }
        $override = (int) get_post_meta( $product_id, '_unq_agev_required_age', true );
        $ages[]   = ( $override > 0 ) ? $override : $global;
    }

    return empty( $ages ) ? $global : max( $ages );
}

/**
 * Resolve the effective customer-facing locale ('en' or 'da').
 *
 * Priority:
 *   1. Merchant setting — 'en' or 'da' selected explicitly.
 *   2. Auto-detect — inspects the WordPress site language (get_locale()).
 *      Any locale starting with 'da' maps to Danish; everything else is English.
 *
 * This mirrors the Shopify app's `storefrontLocale` / `effective_locale` logic.
 *
 * @return string 'en' | 'da'
 */
function unq_agev_resolve_locale() {
    $setting = unq_agev_get( 'locale' );
    if ( 'en' === $setting ) {
        return 'en';
    }
    if ( 'da' === $setting ) {
        return 'da';
    }
    // Auto-detect from WP site language.
    $wp_locale = function_exists( 'get_locale' ) ? get_locale() : 'en_US';
    return ( strpos( strtolower( $wp_locale ), 'da' ) === 0 ) ? 'da' : 'en';
}

/**
 * Returns an array of all customer-facing strings for the given locale.
 *
 * These strings are merchant-locale-controlled (not visitor-locale), matching
 * the Shopify app pattern where storefrontLocale is a store setting.
 * They are intentionally NOT run through __() — WP i18n applies to admin strings.
 *
 * @param  string $locale 'en' | 'da'
 * @param  int    $age    Required age for modal body placeholder.
 * @return array<string,string>
 */
function unq_agev_strings( $locale, $age = 18 ) {
    $strings = array(
        'en' => array(
            'cart_notice'    => 'You must complete age verification before proceeding to checkout.',
            'expired'        => 'Your age verification has expired. Please verify again to complete your purchase.',
            'general'        => 'You must complete age verification to complete your purchase.',
            'verifyPrompt'   => 'Verify age to continue',
            'verified'       => 'Age verified',
            'denied'         => 'You do not meet the age requirement for these products.',
            'cancelled'      => 'Age verification cancelled.',
            'popupBlocked'   => 'Allow popups on this site to verify your age.',
            'error'          => 'An error occurred. Please try again.',
            'modalTitle'     => 'Age verification required',
            /* translators: %d: minimum required age */
            'modalBody'      => sprintf( 'This store sells age-restricted products. You must confirm that you are %d years or older to proceed to checkout. This is done securely via MitID and only takes a moment.', $age ),
            'modalVerifyBtn' => 'Verify age with MitID',
            'modalCancelBtn' => 'Cancel',
        ),
        'da' => array(
            'cart_notice'    => 'Du skal gennemføre aldersverificering, inden du kan gå til kassen.',
            'expired'        => 'Din aldersverificering er udløbet. Verificér venligst igen for at gennemføre dit køb.',
            'general'        => 'Du skal gennemføre aldersverificering for at gennemføre dit køb.',
            'verifyPrompt'   => 'Bekræft alder for at fortsætte',
            'verified'       => 'Alder bekræftet',
            'denied'         => 'Du opfylder ikke alderskravet for disse varer.',
            'cancelled'      => 'Aldersverificering annulleret.',
            'popupBlocked'   => 'Tillad pop-up vinduer på dette site for at bekræfte din alder.',
            'error'          => 'Der opstod en fejl. Prøv igen.',
            'modalTitle'     => 'Aldersverificering påkrævet',
            /* translators: %d: minimum required age */
            'modalBody'      => sprintf( 'Denne butik sælger aldersbegrænsede varer. Du skal bekræfte, at du er %d år eller ældre, for at gå til kassen. Det sker sikkert via MitID og tager kun et øjeblik.', $age ),
            'modalVerifyBtn' => 'Bekræft alder med MitID',
            'modalCancelBtn' => 'Annuller',
        ),
    );
    return isset( $strings[ $locale ] ) ? $strings[ $locale ] : $strings['en'];
}

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
// WooCommerce Settings tab — WooCommerce > Settings > UNQVerify.
// ---------------------------------------------------------------------------

add_filter( 'woocommerce_settings_tabs_array', function ( $tabs ) {
    $tabs['unq_agev'] = __( 'UNQVerify', 'unq-age-verification' );
    return $tabs;
}, 50 );

// ---------------------------------------------------------------------------
// Settings page CSS — only injected on the plugin's own tab.
// ---------------------------------------------------------------------------

add_action( 'admin_head', function () {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if ( ! isset( $_GET['tab'] ) || 'unq_agev' !== sanitize_key( $_GET['tab'] ) ) {
        return;
    }
    ?>
    <style id="unq-agev-admin-css">
    /* ---- Reset & layout ---------------------------------------------- */
    .unq-agev-page { max-width: 860px; padding-bottom: 40px; }
    .unq-agev-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 24px 28px;
        margin-bottom: 16px;
        box-shadow: 0 1px 2px rgba(0,0,0,.04);
    }
    .unq-agev-card h2 {
        margin: 0 0 4px;
        font-size: 15px;
        font-weight: 600;
        color: #0f172a;
    }
    .unq-card-desc {
        margin: 0 0 18px;
        font-size: 13px;
        color: #64748b;
        line-height: 1.5;
    }

    /* ---- Enable toggle ----------------------------------------------- */
    .unq-enable-row {
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 14px 0;
    }
    .unq-toggle-switch {
        position: relative;
        width: 44px;
        height: 24px;
        flex-shrink: 0;
        cursor: pointer;
    }
    .unq-toggle-switch input { display: none; }
    .unq-toggle-track {
        position: absolute;
        inset: 0;
        background: #cbd5e1;
        border-radius: 12px;
        cursor: pointer;
        transition: background .2s;
    }
    .unq-toggle-track::after {
        content: '';
        position: absolute;
        left: 3px;
        top: 3px;
        width: 18px;
        height: 18px;
        background: #fff;
        border-radius: 50%;
        transition: transform .2s;
        box-shadow: 0 1px 3px rgba(0,0,0,.2);
    }
    .unq-toggle-switch input:checked + .unq-toggle-track { background: #16a34a; }
    .unq-toggle-switch input:checked + .unq-toggle-track::after { transform: translateX(20px); }
    .unq-enable-label { font-size: 14px; font-weight: 500; color: #0f172a; }
    .unq-enable-sublabel { font-size: 12px; color: #64748b; margin-top: 2px; }

    /* ---- Status strip ------------------------------------------------ */
    .unq-status-strip {
        display: flex;
        align-items: center;
        gap: 9px;
        padding: 11px 16px;
        border-radius: 6px;
        font-size: 13px;
        font-weight: 500;
        margin-top: 4px;
    }
    .unq-status-strip.status-live { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }
    .unq-status-strip.status-test { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
    .unq-status-strip.status-off  { background: #f8fafc; color: #64748b; border: 1px solid #e2e8f0; }
    .unq-status-dot {
        width: 8px; height: 8px;
        border-radius: 50%;
        flex-shrink: 0;
    }
    .status-live .unq-status-dot { background: #16a34a; }
    .status-test .unq-status-dot { background: #f59e0b; }
    .status-off  .unq-status-dot { background: #94a3b8; }

    /* ---- Environment radio cards ------------------------------------- */
    .unq-env-cards {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 14px;
        margin-bottom: 24px;
    }
    @media (max-width: 720px) { .unq-env-cards { grid-template-columns: 1fr; } }
    .unq-env-card {
        position: relative;
        border: 2px solid #e2e8f0;
        border-radius: 8px;
        padding: 16px 18px;
        cursor: pointer;
        transition: border-color .15s, background .15s;
        display: block;
    }
    .unq-env-card input[type="radio"] {
        position: absolute;
        opacity: 0;
        pointer-events: none;
    }
    .unq-env-card:hover { border-color: #94a3b8; }
    .unq-env-card.selected.is-test { border-color: #f59e0b; background: #fffbeb; }
    .unq-env-card.selected.is-live { border-color: #16a34a; background: #f0fdf4; }
    .unq-env-card-title {
        font-size: 14px;
        font-weight: 600;
        color: #0f172a;
        margin: 0 0 4px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .unq-radio-dot {
        width: 16px; height: 16px;
        border-radius: 50%;
        border: 2px solid #cbd5e1;
        flex-shrink: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: border-color .15s;
    }
    .selected.is-test .unq-radio-dot { border-color: #f59e0b; }
    .selected.is-test .unq-radio-dot::after { content: ''; width: 8px; height: 8px; background: #f59e0b; border-radius: 50%; }
    .selected.is-live .unq-radio-dot { border-color: #16a34a; }
    .selected.is-live .unq-radio-dot::after { content: ''; width: 8px; height: 8px; background: #16a34a; border-radius: 50%; }
    .unq-env-card-desc { font-size: 12px; color: #64748b; margin: 0; }
    .selected.is-test .unq-env-card-desc { color: #92400e; }
    .selected.is-live .unq-env-card-desc { color: #166534; }

    /* ---- Key fields -------------------------------------------------- */
    .unq-key-group {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
    }
    @media (max-width: 720px) { .unq-key-group { grid-template-columns: 1fr; } }
    .unq-key-label {
        display: block;
        font-size: 12px;
        font-weight: 600;
        letter-spacing: .04em;
        text-transform: uppercase;
        margin-bottom: 6px;
        color: #475569;
    }
    .unq-key-field.unq-test .unq-key-label { color: #b45309; }
    .unq-key-field.unq-live .unq-key-label { color: #15803d; }
    .unq-key-field input[type="text"] {
        width: 100%;
        font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
        font-size: 13px;
        padding: 8px 10px;
        border: 1.5px solid #e2e8f0;
        border-radius: 6px;
        box-sizing: border-box;
        transition: border-color .15s;
    }
    .unq-key-field input[type="text"]:focus {
        border-color: #94a3b8;
        outline: none;
        box-shadow: 0 0 0 3px rgba(148,163,184,.15);
    }
    .unq-key-field input.has-error { border-color: #dc2626 !important; }
    .unq-key-field input.is-valid  { border-color: #16a34a !important; }
    .unq-field-help { margin-top: 5px; font-size: 12px; color: #64748b; }
    .unq-field-link { color: #2563eb; text-decoration: none; }
    .unq-field-link:hover { text-decoration: underline; }
    .unq-field-error { margin-top: 4px; font-size: 12px; color: #dc2626; display: none; }
    .unq-field-error.visible { display: block; }

    /* ---- General settings table ------------------------------------- */
    .unq-agev-page .form-table { margin: 0; }
    .unq-agev-page .form-table th {
        width: 200px; font-size: 13px; color: #374151;
        padding: 12px 10px 12px 0; font-weight: 500;
    }
    .unq-agev-page .form-table td { padding: 10px 0; }
    .unq-agev-page .form-table td p.description { font-size: 12px; color: #64748b; margin-top: 4px; }

    /* ---- Domain card ------------------------------------------------- */
    .unq-domain-warning {
        display: flex;
        gap: 12px;
        align-items: flex-start;
        background: #fffbeb;
        border: 1px solid #fde68a;
        border-radius: 6px;
        padding: 14px 16px;
        margin-bottom: 16px;
        font-size: 13px;
        color: #78350f;
        line-height: 1.5;
    }
    .unq-domain-warning .dashicons { flex-shrink: 0; margin-top: 1px; color: #d97706; }
    .unq-btn-primary {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 9px 18px;
        background: #0f172a;
        color: #fff !important;
        border-radius: 6px;
        font-size: 13px;
        font-weight: 500;
        text-decoration: none !important;
        transition: background .15s;
    }
    .unq-btn-primary:hover { background: #1e293b; }

    /* ---- Save validation errors ------------------------------------- */
    .unq-save-errors {
        margin-bottom: 16px;
        padding: 14px 18px;
        background: #fef2f2;
        border: 1px solid #fecaca;
        border-radius: 8px;
        color: #991b1b;
        font-size: 13px;
    }
    .unq-save-errors ul { margin: 4px 0 0 16px; padding: 0; }
    .unq-save-errors li { margin-bottom: 3px; }

    /* ---- Onboarding guide ------------------------------------------- */
    .unq-guide-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        cursor: pointer;
        user-select: none;
    }
    .unq-guide-toggle-btn {
        flex-shrink: 0;
        font-size: 12px;
        color: #2563eb;
        background: none;
        border: 1px solid #bfdbfe;
        border-radius: 4px;
        cursor: pointer;
        padding: 4px 10px;
        margin-top: 2px;
    }
    .unq-guide-toggle-btn:hover { background: #eff6ff; }
    .unq-guide-body { margin-top: 20px; }
    .unq-guide-body.collapsed { display: none; }
    .unq-steps { list-style: none; margin: 0; padding: 0; counter-reset: steps; }
    .unq-steps li {
        counter-increment: steps;
        display: flex;
        gap: 16px;
        padding: 16px 0;
        border-bottom: 1px solid #f1f5f9;
    }
    .unq-steps li:last-child { border-bottom: none; }
    .unq-step-num {
        flex-shrink: 0;
        width: 26px; height: 26px;
        border-radius: 50%;
        background: #f1f5f9;
        color: #475569;
        border: 1.5px solid #e2e8f0;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 11px;
        font-weight: 700;
    }
    .unq-step-num::before { content: counter(steps); }
    .unq-step-body { flex: 1; }
    .unq-step-body strong { display: block; font-size: 13px; font-weight: 600; color: #0f172a; margin-bottom: 3px; }
    .unq-step-body p { margin: 0 0 8px; font-size: 13px; color: #475569; }
    .unq-step-link {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        font-size: 12px;
        font-weight: 500;
        color: #2563eb;
        text-decoration: none;
        padding: 5px 12px;
        border: 1px solid #bfdbfe;
        border-radius: 5px;
        background: #eff6ff;
    }
    .unq-step-link:hover { background: #dbeafe; border-color: #93c5fd; }
    .unq-going-live {
        margin-top: 20px;
        padding: 18px 20px;
        background: #f0fdf4;
        border: 1px solid #bbf7d0;
        border-radius: 6px;
    }
    .unq-going-live h3 { margin: 0 0 8px; font-size: 14px; font-weight: 600; color: #15803d; }
    .unq-going-live p  { margin: 0 0 8px; font-size: 13px; color: #166534; }
    .unq-going-live ol { margin: 0 0 12px 20px; font-size: 13px; color: #166534; }
    .unq-going-live ol li { margin-bottom: 4px; }
    .unq-btn-success {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 8px 16px;
        background: #16a34a;
        color: #fff !important;
        border-radius: 6px;
        font-size: 13px;
        font-weight: 500;
        text-decoration: none !important;
    }
    .unq-btn-success:hover { background: #15803d; }

    /* ---- Scope cards ------------------------------------------------- */
    .unq-section-sep { border: none; border-top: 1px solid #f1f5f9; margin: 20px 0 16px; }
    .unq-section-title { font-size: 13px; font-weight: 600; color: #0f172a; margin: 0 0 4px; }
    .unq-env-card.selected.is-all          { border-color: #2563eb; background: #eff6ff; }
    .unq-env-card.selected.is-selected-only { border-color: #7c3aed; background: #f5f3ff; }
    .selected.is-all .unq-radio-dot          { border-color: #2563eb; }
    .selected.is-all .unq-radio-dot::after   { content: ''; width: 8px; height: 8px; background: #2563eb; border-radius: 50%; }
    .selected.is-selected-only .unq-radio-dot { border-color: #7c3aed; }
    .selected.is-selected-only .unq-radio-dot::after { content: ''; width: 8px; height: 8px; background: #7c3aed; border-radius: 50%; }
    .selected.is-all .unq-env-card-desc          { color: #1d4ed8; }
    .selected.is-selected-only .unq-env-card-desc { color: #5b21b6; }

    /* ---- Category picker --------------------------------------------- */
    .unq-category-picker {
        margin-top: 16px;
        padding: 16px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
    }
    .unq-cat-picker-help { margin: 0 0 14px; font-size: 13px; color: #475569; }
    .unq-cat-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
        gap: 8px;
    }
    .unq-cat-item {
        display: flex;
        align-items: center;
        gap: 7px;
        font-size: 13px;
        color: #374151;
        cursor: pointer;
    }
    .unq-cat-item input[type="checkbox"] { flex-shrink: 0; margin: 0; }
    .unq-cat-count { font-size: 11px; color: #94a3b8; }
    .unq-cat-empty { margin: 0; font-size: 13px; color: #94a3b8; font-style: italic; }

    /* ---- Products list column badge ---------------------------------- */
    .column-unq_agev { width: 90px; }
    .unq-col-badge {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 10px;
        font-size: 11px;
        font-weight: 600;
        white-space: nowrap;
    }
    .unq-col-badge.is-required   { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }
    .unq-col-badge.is-store-wide { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
    .unq-col-badge.is-none       { color: #94a3b8; font-weight: 400; }
    </style>
    <?php
} );

// ---------------------------------------------------------------------------
// Settings page renderer — fully custom HTML, no WC_Admin_Settings fields API.
// ---------------------------------------------------------------------------

/**
 * Render the UNQVerify settings tab content.
 *
 * NOTE: WooCommerce already wraps all tab content in its own <form id="mainform">.
 * We must NOT output a <form> here — doing so creates invalid nested forms.
 * All <input> / <select> fields we output are automatically part of WC's form
 * and will be submitted when the merchant clicks "Save changes".
 * WooCommerce fires `woocommerce_update_options_unq_agev` after verifying its
 * own nonce, so we don't need our own nonce here.
 */
function unq_agev_render_settings_page() {
    $enabled        = unq_agev_get( 'enabled' );
    $prod_key       = unq_agev_get( 'public_key' );
    $test_key       = unq_agev_get( 'test_public_key' );
    $use_production = unq_agev_get( 'use_production' );
    $required_age   = unq_agev_get( 'required_age' );
    $mode           = unq_agev_get( 'mode' );
    $locale         = unq_agev_get( 'locale' );
    $targeting      = unq_agev_get( 'targeting' );
    $active_key     = unq_agev_active_key();

    // Load all product categories for the scope section category picker.
    $all_cats = function_exists( 'get_terms' )
        ? get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) )
        : array();
    if ( is_wp_error( $all_cats ) || ! is_array( $all_cats ) ) {
        $all_cats = array();
    }

    // Status strip.
    $scope_label = ( 'all' === $targeting )
        ? __( 'all products', 'unq-age-verification' )
        : __( 'selected products only', 'unq-age-verification' );

    if ( 'yes' !== $enabled ) {
        $status_class = 'status-off';
        $status_text  = __( 'Inactive — age verification is disabled', 'unq-age-verification' );
    } elseif ( empty( $active_key ) ) {
        $status_class = 'status-off';
        $status_text  = __( 'Inactive — no API key configured', 'unq-age-verification' );
    } elseif ( 'yes' === $use_production ) {
        $status_class = 'status-live';
        /* translators: %s: scope label, e.g. "all products" or "selected products only" */
        $status_text  = sprintf( __( 'Active — Production mode · %s', 'unq-age-verification' ), $scope_label );
    } else {
        $status_class = 'status-test';
        /* translators: %s: scope label, e.g. "all products" or "selected products only" */
        $status_text  = sprintf( __( 'Active — Test mode · %s', 'unq-age-verification' ), $scope_label );
    }

    // Validation errors from previous save attempt.
    $save_errors = get_transient( 'unq_agev_save_errors' );
    delete_transient( 'unq_agev_save_errors' );
    if ( ! is_array( $save_errors ) ) {
        $save_errors = array();
    }
    ?>
    <!-- Marker so our save hook knows this is our form submission. -->
    <input type="hidden" name="unq_agev_submitted" value="1">

    <div class="unq-agev-page">

        <?php if ( ! empty( $save_errors ) ) : ?>
        <div class="unq-save-errors">
            <strong><?php esc_html_e( 'Please fix the following errors before saving:', 'unq-age-verification' ); ?></strong>
            <ul>
            <?php foreach ( $save_errors as $err ) : ?>
                <li><?php echo esc_html( $err ); ?></li>
            <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- ── Card 1: Age Verification (on/off) ──────────────────────── -->
        <div class="unq-agev-card">
            <h2><?php esc_html_e( 'Age Verification', 'unq-age-verification' ); ?></h2>
            <p class="unq-card-desc"><?php esc_html_e( 'Enable or disable the age gate on your store. Requires an API key to be configured below.', 'unq-age-verification' ); ?></p>

            <div class="unq-enable-row">
                <label class="unq-toggle-switch" for="unq_agev_enabled">
                    <input type="checkbox" id="unq_agev_enabled" name="unq_agev_enabled" value="yes"
                           <?php checked( $enabled, 'yes' ); ?>>
                    <span class="unq-toggle-track"></span>
                </label>
                <div>
                    <div class="unq-enable-label"><?php esc_html_e( 'Enable age verification on checkout', 'unq-age-verification' ); ?></div>
                    <div class="unq-enable-sublabel"><?php esc_html_e( 'When enabled, customers must verify their age before completing a purchase.', 'unq-age-verification' ); ?></div>
                </div>
            </div>

            <div class="unq-status-strip <?php echo esc_attr( $status_class ); ?>">
                <span class="unq-status-dot"></span>
                <?php echo esc_html( $status_text ); ?>
            </div>

            <hr class="unq-section-sep">
            <h3 class="unq-section-title"><?php esc_html_e( 'Scope', 'unq-age-verification' ); ?></h3>
            <p class="unq-card-desc" style="margin-bottom:14px;"><?php esc_html_e( 'Which products require age verification at checkout?', 'unq-age-verification' ); ?></p>

            <div class="unq-env-cards unq-scope-cards" id="unq-scope-cards">
                <label class="unq-env-card is-all <?php echo ( 'all' === $targeting ) ? 'selected' : ''; ?>" for="unq_targeting_all">
                    <input type="radio" name="unq_agev_targeting" id="unq_targeting_all"
                           value="all" <?php checked( $targeting, 'all' ); ?>>
                    <div class="unq-env-card-title">
                        <span class="unq-radio-dot"></span>
                        <?php esc_html_e( 'All products', 'unq-age-verification' ); ?>
                    </div>
                    <p class="unq-env-card-desc"><?php esc_html_e( 'Every product in the store requires age verification at checkout.', 'unq-age-verification' ); ?></p>
                </label>

                <label class="unq-env-card is-selected-only <?php echo ( 'selected_only' === $targeting ) ? 'selected' : ''; ?>" for="unq_targeting_selected">
                    <input type="radio" name="unq_agev_targeting" id="unq_targeting_selected"
                           value="selected_only" <?php checked( $targeting, 'selected_only' ); ?>>
                    <div class="unq-env-card-title">
                        <span class="unq-radio-dot"></span>
                        <?php esc_html_e( 'Selected products &amp; categories', 'unq-age-verification' ); ?>
                    </div>
                    <p class="unq-env-card-desc"><?php esc_html_e( 'Only products you mark individually, or products in ticked categories, require verification.', 'unq-age-verification' ); ?></p>
                </label>
            </div>

            <!-- Category picker — revealed by JS when "Selected only" is chosen -->
            <div class="unq-category-picker" id="unq-category-picker"
                 style="<?php echo ( 'selected_only' !== $targeting ) ? 'display:none;' : ''; ?>">
                <p class="unq-cat-picker-help">
                    <?php esc_html_e( 'Tick the categories that should require age verification. You can also mark individual products from each product\'s edit screen.', 'unq-age-verification' ); ?>
                </p>
                <?php if ( ! empty( $all_cats ) ) : ?>
                    <div class="unq-cat-grid">
                    <?php foreach ( $all_cats as $cat ) : ?>
                        <?php $cat_required = get_term_meta( $cat->term_id, 'unq_agev_category_required', true ); ?>
                        <label class="unq-cat-item">
                            <input type="checkbox"
                                   name="unq_agev_gated_categories[]"
                                   value="<?php echo esc_attr( $cat->term_id ); ?>"
                                   <?php checked( $cat_required, 'yes' ); ?>>
                            <?php echo esc_html( $cat->name ); ?>
                            <span class="unq-cat-count">(<?php echo (int) $cat->count; ?>)</span>
                        </label>
                    <?php endforeach; ?>
                    </div>
                <?php else : ?>
                    <p class="unq-cat-empty"><?php esc_html_e( 'No product categories found. Open Products &rarr; Categories to create some.', 'unq-age-verification' ); ?></p>
                <?php endif; ?>
            </div><!-- .unq-category-picker -->
        </div><!-- .unq-agev-card -->

        <!-- ── Card 2: Environment & API Keys ─────────────────────────── -->
        <div class="unq-agev-card">
            <h2><?php esc_html_e( 'Environment &amp; API Keys', 'unq-age-verification' ); ?></h2>
            <p class="unq-card-desc"><?php esc_html_e( 'Select which environment is active. Always set up and test in Test mode before switching to Production.', 'unq-age-verification' ); ?></p>

            <!-- Environment selector -->
            <div class="unq-env-cards" id="unq-env-cards">
                <label class="unq-env-card is-test <?php echo ( 'no' === $use_production ) ? 'selected' : ''; ?>" for="unq_use_production_no">
                    <input type="radio" name="unq_agev_use_production" id="unq_use_production_no"
                           value="no" <?php checked( $use_production, 'no' ); ?>>
                    <div class="unq-env-card-title">
                        <span class="unq-radio-dot"></span>
                        <?php esc_html_e( 'Test mode', 'unq-age-verification' ); ?>
                    </div>
                    <p class="unq-env-card-desc"><?php esc_html_e( 'Uses pk_test_ key. Simulated verification — safe for development.', 'unq-age-verification' ); ?></p>
                </label>

                <label class="unq-env-card is-live <?php echo ( 'yes' === $use_production ) ? 'selected' : ''; ?>" for="unq_use_production_yes">
                    <input type="radio" name="unq_agev_use_production" id="unq_use_production_yes"
                           value="yes" <?php checked( $use_production, 'yes' ); ?>>
                    <div class="unq-env-card-title">
                        <span class="unq-radio-dot"></span>
                        <?php esc_html_e( 'Production mode', 'unq-age-verification' ); ?>
                    </div>
                    <p class="unq-env-card-desc"><?php esc_html_e( 'Uses pk_live_ key. Real MitID verification — for your live store.', 'unq-age-verification' ); ?></p>
                </label>
            </div>

            <!-- Key fields -->
            <div class="unq-key-group">
                <div class="unq-key-field unq-test">
                    <label class="unq-key-label" for="unq_agev_test_public_key">
                        <?php esc_html_e( 'Test Public Key', 'unq-age-verification' ); ?>
                    </label>
                    <input type="text"
                           id="unq_agev_test_public_key"
                           name="unq_agev_test_public_key"
                           value="<?php echo esc_attr( $test_key ); ?>"
                           placeholder="pk_test_..."
                           autocomplete="off"
                           data-prefix="pk_test_"
                           data-label="<?php esc_attr_e( 'Test key', 'unq-age-verification' ); ?>">
                    <p class="unq-field-error" id="unq-test-key-error"></p>
                    <p class="unq-field-help">
                        <?php esc_html_e( 'Must start with pk_test_', 'unq-age-verification' ); ?> &mdash;
                        <a class="unq-field-link" href="https://www.aldersverificering.dk/account/api-keys" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Get API keys', 'unq-age-verification' ); ?> &rarr;</a>
                    </p>
                </div>

                <div class="unq-key-field unq-live">
                    <label class="unq-key-label" for="unq_agev_public_key">
                        <?php esc_html_e( 'Production Public Key', 'unq-age-verification' ); ?>
                    </label>
                    <input type="text"
                           id="unq_agev_public_key"
                           name="unq_agev_public_key"
                           value="<?php echo esc_attr( $prod_key ); ?>"
                           placeholder="pk_live_..."
                           autocomplete="off"
                           data-prefix="pk_live_"
                           data-label="<?php esc_attr_e( 'Production key', 'unq-age-verification' ); ?>">
                    <p class="unq-field-error" id="unq-live-key-error"></p>
                    <p class="unq-field-help">
                        <?php esc_html_e( 'Must start with pk_live_', 'unq-age-verification' ); ?> &mdash;
                        <a class="unq-field-link" href="https://www.aldersverificering.dk/account/api-keys" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Get API keys', 'unq-age-verification' ); ?> &rarr;</a>
                    </p>
                </div>
            </div><!-- .unq-key-group -->
        </div><!-- .unq-agev-card -->

        <!-- ── Card 3: General Settings ────────────────────────────────── -->
        <div class="unq-agev-card">
            <h2><?php esc_html_e( 'General Settings', 'unq-age-verification' ); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="unq_agev_required_age"><?php esc_html_e( 'Required Age', 'unq-age-verification' ); ?></label></th>
                    <td>
                        <input type="number" id="unq_agev_required_age" name="unq_agev_required_age"
                               value="<?php echo esc_attr( $required_age ); ?>"
                               min="1" max="120" step="1" style="width:80px;">
                        <p class="description"><?php esc_html_e( 'Minimum age (in years) a customer must have verified before checkout.', 'unq-age-verification' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="unq_agev_verification_mode"><?php esc_html_e( 'Verification Mode', 'unq-age-verification' ); ?></label></th>
                    <td>
                        <select id="unq_agev_verification_mode" name="unq_agev_verification_mode">
                            <option value="popup" <?php selected( $mode, 'popup' ); ?>><?php esc_html_e( 'Popup (recommended)', 'unq-age-verification' ); ?></option>
                            <option value="redirect" <?php selected( $mode, 'redirect' ); ?>><?php esc_html_e( 'Full-page redirect', 'unq-age-verification' ); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e( 'Popup keeps the customer on your page. Use redirect if popups are frequently blocked.', 'unq-age-verification' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="unq_agev_locale"><?php esc_html_e( 'Popup Language', 'unq-age-verification' ); ?></label></th>
                    <td>
                        <select id="unq_agev_locale" name="unq_agev_locale">
                            <option value="auto" <?php selected( $locale, 'auto' ); ?>><?php esc_html_e( 'Auto-detect (WordPress site language)', 'unq-age-verification' ); ?></option>
                            <option value="en" <?php selected( $locale, 'en' ); ?>><?php esc_html_e( 'English', 'unq-age-verification' ); ?></option>
                            <option value="da" <?php selected( $locale, 'da' ); ?>><?php esc_html_e( 'Dansk', 'unq-age-verification' ); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e( 'Language for the age verification popup and cart notices shown to customers.', 'unq-age-verification' ); ?></p>
                    </td>
                </tr>
            </table>
        </div><!-- .unq-agev-card -->

        <!-- ── Card 4: Domain Setup ─────────────────────────────────────── -->
        <div class="unq-agev-card">
            <h2><?php esc_html_e( 'Domain Setup', 'unq-age-verification' ); ?></h2>
            <div class="unq-domain-warning">
                <span class="dashicons dashicons-warning"></span>
                <div>
                    <strong><?php esc_html_e( 'Required before age verification will work:', 'unq-age-verification' ); ?></strong>
                    <?php esc_html_e( 'Your domain must be added to the whitelist at aldersverificering.dk. Without this step, all verification attempts will be rejected.', 'unq-age-verification' ); ?>
                </div>
            </div>
            <p style="font-size:13px;color:#475569;margin:0 0 16px;">
                <?php
                printf(
                    /* translators: %s: the site's host name in a <code> tag */
                    esc_html__( 'Domain to whitelist: %s', 'unq-age-verification' ),
                    '<code style="background:#f1f5f9;padding:2px 6px;border-radius:4px;font-size:12px;">' . esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ) . '</code>'
                );
                ?>
            </p>
            <a class="unq-btn-primary" href="https://www.aldersverificering.dk/account/domains" target="_blank" rel="noopener noreferrer">
                <?php esc_html_e( 'Configure Domains', 'unq-age-verification' ); ?> &rarr;
            </a>
        </div><!-- .unq-agev-card -->

        <!-- ── Quick Start Guide ────────────────────────────────────────── -->
        <div class="unq-agev-card" id="unq-guide-card">
            <div class="unq-guide-header">
                <div>
                    <h2><?php esc_html_e( 'Quick Start Guide', 'unq-age-verification' ); ?></h2>
                    <p class="unq-card-desc" style="margin-bottom:0;"><?php esc_html_e( 'New here? Follow these steps to set up and go live.', 'unq-age-verification' ); ?></p>
                </div>
                <button type="button" class="unq-guide-toggle-btn" id="unq-guide-toggle-label">
                    <?php esc_html_e( 'Hide guide', 'unq-age-verification' ); ?>
                </button>
            </div>

            <div class="unq-guide-body" id="unq-guide-body">
                <ul class="unq-steps">
                    <li>
                        <div class="unq-step-num"></div>
                        <div class="unq-step-body">
                            <strong><?php esc_html_e( 'Create your account', 'unq-age-verification' ); ?></strong>
                            <p><?php esc_html_e( 'Sign up at aldersverificering.dk to get access to API keys and domain settings.', 'unq-age-verification' ); ?></p>
                            <a class="unq-step-link" href="https://www.aldersverificering.dk/" target="_blank" rel="noopener noreferrer">
                                <?php esc_html_e( 'Create account', 'unq-age-verification' ); ?> &nearr;
                            </a>
                        </div>
                    </li>
                    <li>
                        <div class="unq-step-num"></div>
                        <div class="unq-step-body">
                            <strong><?php esc_html_e( 'Get your test key', 'unq-age-verification' ); ?></strong>
                            <p><?php esc_html_e( 'Copy your Test Public Key (starts with pk_test_) from your account. Paste it in the Test Public Key field above.', 'unq-age-verification' ); ?></p>
                            <a class="unq-step-link" href="https://www.aldersverificering.dk/account/api-keys" target="_blank" rel="noopener noreferrer">
                                <?php esc_html_e( 'Get API keys', 'unq-age-verification' ); ?> &nearr;
                            </a>
                        </div>
                    </li>
                    <li>
                        <div class="unq-step-num"></div>
                        <div class="unq-step-body">
                            <strong><?php esc_html_e( 'Configure your domain', 'unq-age-verification' ); ?></strong>
                            <p><?php
                                printf(
                                    /* translators: %s: site host in a <code> tag */
                                    esc_html__( 'Add %s to the domain whitelist. Without this, verification will always fail.', 'unq-age-verification' ),
                                    '<code>' . esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ) . '</code>'
                                );
                            ?></p>
                            <a class="unq-step-link" href="https://www.aldersverificering.dk/account/domains" target="_blank" rel="noopener noreferrer">
                                <?php esc_html_e( 'Configure domains', 'unq-age-verification' ); ?> &nearr;
                            </a>
                        </div>
                    </li>
                    <li>
                        <div class="unq-step-num"></div>
                        <div class="unq-step-body">
                            <strong><?php esc_html_e( 'Test the age gate', 'unq-age-verification' ); ?></strong>
                            <p><?php esc_html_e( 'Enable the plugin in Test mode, add a product to your cart and go to checkout. The age verification popup should appear.', 'unq-age-verification' ); ?></p>
                        </div>
                    </li>
                    <li>
                        <div class="unq-step-num"></div>
                        <div class="unq-step-body">
                            <strong><?php esc_html_e( 'Go live', 'unq-age-verification' ); ?></strong>
                            <p><?php esc_html_e( 'Upgrade your account, add your Production Public Key (pk_live_), and switch to Production mode above.', 'unq-age-verification' ); ?></p>
                            <a class="unq-step-link" href="https://www.aldersverificering.dk/account/subscription" target="_blank" rel="noopener noreferrer">
                                <?php esc_html_e( 'Upgrade account', 'unq-age-verification' ); ?> &nearr;
                            </a>
                        </div>
                    </li>
                </ul>

                <div class="unq-going-live">
                    <h3><?php esc_html_e( 'Ready to go live?', 'unq-age-verification' ); ?></h3>
                    <p><?php esc_html_e( 'When you\'re ready for real age verification:', 'unq-age-verification' ); ?></p>
                    <ol>
                        <li><?php esc_html_e( 'Get your Production Public Key (pk_live_) from your account.', 'unq-age-verification' ); ?></li>
                        <li><?php esc_html_e( 'Paste it in the Production Public Key field above and save.', 'unq-age-verification' ); ?></li>
                        <li><?php esc_html_e( 'Select Production mode and save again.', 'unq-age-verification' ); ?></li>
                    </ol>
                    <a class="unq-btn-success" href="https://www.aldersverificering.dk/account/subscription" target="_blank" rel="noopener noreferrer">
                        <?php esc_html_e( 'Manage subscription', 'unq-age-verification' ); ?> &rarr;
                    </a>
                </div>
            </div><!-- .unq-guide-body -->
        </div><!-- .unq-agev-card -->

    </div><!-- .unq-agev-page -->

    <script>
    (function () {
        var LS_KEY = 'unq_agev_guide_collapsed';

        // Restore guide collapse state from localStorage.
        if (localStorage.getItem(LS_KEY) === '1') {
            var body = document.getElementById('unq-guide-body');
            var lbl  = document.getElementById('unq-guide-toggle-label');
            if (body) body.classList.add('collapsed');
            if (lbl)  lbl.textContent = <?php echo wp_json_encode( __( 'Show guide', 'unq-age-verification' ) ); ?>;
        }

        // Guide header click handler.
        document.getElementById('unq-guide-toggle-label').addEventListener('click', function (e) {
            e.stopPropagation();
            var body = document.getElementById('unq-guide-body');
            var lbl  = document.getElementById('unq-guide-toggle-label');
            var collapsed = body.classList.toggle('collapsed');
            lbl.textContent = collapsed
                ? <?php echo wp_json_encode( __( 'Show guide', 'unq-age-verification' ) ); ?>
                : <?php echo wp_json_encode( __( 'Hide guide', 'unq-age-verification' ) ); ?>;
            localStorage.setItem(LS_KEY, collapsed ? '1' : '0');
        });

        // Card selection: sync "selected" class within each radio group independently.
        // Uses ID suffix "-cards" to identify group containers (unq-env-cards, unq-scope-cards).
        document.querySelectorAll('[id$="-cards"]').forEach(function (group) {
            var groupCards = group.querySelectorAll('.unq-env-card');
            groupCards.forEach(function (card) {
                var radio = card.querySelector('input[type="radio"]');
                if (!radio) return;
                radio.addEventListener('change', function () {
                    groupCards.forEach(function (c) { c.classList.remove('selected'); });
                    card.classList.add('selected');
                    // Category picker: show when targeting = selected_only.
                    if (radio.name === 'unq_agev_targeting') {
                        var picker = document.getElementById('unq-category-picker');
                        if (picker) picker.style.display = radio.value === 'selected_only' ? '' : 'none';
                    }
                });
            });
        });

        // Key prefix validation on blur — PHP 7.4 safe, no str_starts_with.
        function validateKeyField(input, errorEl) {
            var val    = input.value.trim();
            var prefix = input.getAttribute('data-prefix');
            var label  = input.getAttribute('data-label');
            errorEl.textContent = '';
            errorEl.classList.remove('visible');
            input.classList.remove('has-error', 'is-valid');
            if (!val) return true;
            if (val.indexOf(prefix) !== 0) {
                errorEl.textContent = label + ' ' + <?php echo wp_json_encode( __( 'must start with', 'unq-age-verification' ) ); ?> + ' \u2018' + prefix + '\u2019';
                errorEl.classList.add('visible');
                input.classList.add('has-error');
                return false;
            }
            if (val.length < 28) {
                errorEl.textContent = label + ' ' + <?php echo wp_json_encode( __( 'appears too short — please check your key', 'unq-age-verification' ) ); ?>;
                errorEl.classList.add('visible');
                input.classList.add('has-error');
                return false;
            }
            input.classList.add('is-valid');
            return true;
        }

        var testInput = document.getElementById('unq_agev_test_public_key');
        var liveInput = document.getElementById('unq_agev_public_key');
        var testErr   = document.getElementById('unq-test-key-error');
        var liveErr   = document.getElementById('unq-live-key-error');

        if (testInput) testInput.addEventListener('blur', function () { validateKeyField(testInput, testErr); });
        if (liveInput) liveInput.addEventListener('blur', function () { validateKeyField(liveInput, liveErr); });

        // Block form submit on invalid keys — hook WC's outer form, not a nested one.
        var form = document.getElementById('mainform');
        if (form) {
            form.addEventListener('submit', function (e) {
                var ok = true;
                if (testInput) ok = validateKeyField(testInput, testErr) && ok;
                if (liveInput) ok = validateKeyField(liveInput, liveErr) && ok;
                if (!ok) {
                    e.preventDefault();
                    var firstErr = document.querySelector('.unq-field-error.visible');
                    if (firstErr) firstErr.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            });
        }
    })();
    </script>
    <?php
}

add_action( 'woocommerce_settings_tabs_unq_agev', 'unq_agev_render_settings_page' );

// ---------------------------------------------------------------------------
// Custom save handler — reads $_POST, validates, persists, redirects.
// ---------------------------------------------------------------------------

add_action( 'woocommerce_update_options_unq_agev', function () {
    // WooCommerce already verified its own admin nonce before firing this hook.
    // Only process when our hidden marker field is present (i.e. our tab was submitted).
    if ( ! isset( $_POST['unq_agev_submitted'] ) ) {
        return;
    }

    $errors = array();

    // ── Read & sanitize ────────────────────────────────────────────────────
    $enabled        = isset( $_POST['unq_agev_enabled'] ) ? 'yes' : 'no';
    $prod_key       = sanitize_text_field( wp_unslash( $_POST['unq_agev_public_key'] ?? '' ) );
    $test_key       = sanitize_text_field( wp_unslash( $_POST['unq_agev_test_public_key'] ?? '' ) );
    $use_production = ( isset( $_POST['unq_agev_use_production'] ) && 'yes' === $_POST['unq_agev_use_production'] ) ? 'yes' : 'no';
    $required_age   = max( 1, min( 120, (int) ( $_POST['unq_agev_required_age'] ?? 18 ) ) );
    $mode_raw       = sanitize_key( $_POST['unq_agev_verification_mode'] ?? 'popup' );
    $mode           = in_array( $mode_raw, array( 'popup', 'redirect' ), true ) ? $mode_raw : 'popup';
    $locale_raw     = sanitize_key( $_POST['unq_agev_locale'] ?? 'auto' );
    $locale         = in_array( $locale_raw, array( 'auto', 'en', 'da' ), true ) ? $locale_raw : 'auto';
    $targeting_raw  = sanitize_key( $_POST['unq_agev_targeting'] ?? 'all' );
    $targeting      = in_array( $targeting_raw, array( 'all', 'selected_only' ), true ) ? $targeting_raw : 'all';

    // ── Validate key prefixes (PHP 7.4-compatible — no str_starts_with) ───
    if ( ! empty( $test_key ) && strpos( $test_key, 'pk_test_' ) !== 0 ) {
        $errors[] = __( 'Test key must start with pk_test_', 'unq-age-verification' );
    }
    if ( ! empty( $prod_key ) && strpos( $prod_key, 'pk_live_' ) !== 0 ) {
        $errors[] = __( 'Production key must start with pk_live_', 'unq-age-verification' );
    }
    if ( 'yes' === $use_production && empty( $prod_key ) ) {
        $errors[] = __( 'You cannot enable Production mode without a Production Public Key. Please add a pk_live_ key or switch back to Test mode.', 'unq-age-verification' );
    }

    // ── Persist or surface errors ──────────────────────────────────────────
    if ( ! empty( $errors ) ) {
        set_transient( 'unq_agev_save_errors', $errors, 60 );
    } else {
        update_option( 'unq_agev_enabled',           $enabled );
        update_option( 'unq_agev_public_key',         $prod_key );
        update_option( 'unq_agev_test_public_key',    $test_key );
        update_option( 'unq_agev_use_production',     $use_production );
        update_option( 'unq_agev_required_age',       $required_age );
        update_option( 'unq_agev_verification_mode',  $mode );
        update_option( 'unq_agev_locale',             $locale );
        update_option( 'unq_agev_targeting',          $targeting );

        // Batch-update category term metas from the settings-page category picker.
        $gated_cats = isset( $_POST['unq_agev_gated_categories'] ) && is_array( $_POST['unq_agev_gated_categories'] )
            ? array_map( 'absint', $_POST['unq_agev_gated_categories'] )
            : array();

        if ( function_exists( 'get_terms' ) ) {
            $all_product_cats = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'fields' => 'ids' ) );
            if ( is_array( $all_product_cats ) ) {
                foreach ( $all_product_cats as $cat_id ) {
                    if ( in_array( (int) $cat_id, $gated_cats, true ) ) {
                        update_term_meta( (int) $cat_id, 'unq_agev_category_required', 'yes' );
                    } else {
                        delete_term_meta( (int) $cat_id, 'unq_agev_category_required' );
                    }
                }
            }
        }

        // Clear JWKS transient caches so a new key takes effect immediately.
        delete_transient( 'unqverify_pubkey_test' );
        delete_transient( 'unqverify_pubkey_live' );
    }
} );

// ---------------------------------------------------------------------------
// Per-product meta box — age gate flag + optional age override.
// Displayed in the "side" context of the WooCommerce product edit screen.
// ---------------------------------------------------------------------------

add_action( 'add_meta_boxes', function () {
    add_meta_box(
        'unq_agev_product',
        __( 'Age Verification', 'unq-age-verification' ),
        'unq_agev_product_meta_box_callback',
        'product',
        'side',
        'default'
    );
} );

/**
 * Render the product meta box.
 *
 * @param WP_Post $post
 */
function unq_agev_product_meta_box_callback( $post ) {
    wp_nonce_field( 'unq_agev_product_meta', 'unq_agev_product_meta_nonce' );

    $is_required  = 'yes' === get_post_meta( $post->ID, '_unq_agev_required', true );
    $age_override = (int) get_post_meta( $post->ID, '_unq_agev_required_age', true );
    $global_age   = unq_agev_get( 'required_age' );
    ?>
    <p style="margin-top:8px;">
        <label>
            <input type="checkbox"
                   name="_unq_agev_required"
                   id="unq_agev_required_chk"
                   value="yes"
                   <?php checked( $is_required ); ?>>
            <?php esc_html_e( 'Require age verification to purchase this product', 'unq-age-verification' ); ?>
        </label>
    </p>

    <div id="unq_agev_age_override_row" style="margin-top:10px;<?php echo $is_required ? '' : 'display:none;'; ?>">
        <label for="unq_agev_required_age_override" style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#374151;">
            <?php esc_html_e( 'Minimum age override', 'unq-age-verification' ); ?>
        </label>
        <input type="number"
               id="unq_agev_required_age_override"
               name="_unq_agev_required_age"
               value="<?php echo esc_attr( $age_override > 0 ? $age_override : '' ); ?>"
               min="1" max="120" step="1"
               placeholder="<?php echo esc_attr( $global_age ); ?>"
               style="width:70px;">
        <p style="margin:4px 0 0;font-size:11px;color:#94a3b8;">
            <?php
            printf(
                /* translators: %d: store-wide required age */
                esc_html__( 'Leave empty to use the store default (%d years).', 'unq-age-verification' ),
                $global_age
            );
            ?>
        </p>
    </div>
    <script>
    (function () {
        var chk = document.getElementById('unq_agev_required_chk');
        var row = document.getElementById('unq_agev_age_override_row');
        if ( chk && row ) {
            chk.addEventListener('change', function () {
                row.style.display = chk.checked ? '' : 'none';
            });
        }
    })();
    </script>
    <?php
}

add_action( 'woocommerce_process_product_meta', function ( $post_id ) {
    if ( ! isset( $_POST['unq_agev_product_meta_nonce'] )
         || ! wp_verify_nonce(
                 sanitize_text_field( wp_unslash( $_POST['unq_agev_product_meta_nonce'] ) ),
                 'unq_agev_product_meta'
             )
    ) {
        return;
    }

    if ( isset( $_POST['_unq_agev_required'] ) && 'yes' === $_POST['_unq_agev_required'] ) {
        update_post_meta( $post_id, '_unq_agev_required', 'yes' );

        $age = isset( $_POST['_unq_agev_required_age'] ) ? (int) $_POST['_unq_agev_required_age'] : 0;
        if ( $age >= 1 && $age <= 120 ) {
            update_post_meta( $post_id, '_unq_agev_required_age', $age );
        } else {
            delete_post_meta( $post_id, '_unq_agev_required_age' );
        }
    } else {
        delete_post_meta( $post_id, '_unq_agev_required' );
        delete_post_meta( $post_id, '_unq_agev_required_age' );
    }
} );

// ---------------------------------------------------------------------------
// Product category meta — gating flag on product_cat taxonomy terms.
// Two paths: settings-page category picker (batch) and per-term edit screen.
// ---------------------------------------------------------------------------

add_action( 'product_cat_add_form_fields', function () {
    ?>
    <div class="form-field">
        <label>
            <input type="checkbox" name="unq_agev_category_required" value="yes">
            <?php esc_html_e( 'Require age verification for all products in this category', 'unq-age-verification' ); ?>
        </label>
        <p><?php esc_html_e( 'When ticked, any cart containing a product from this category will require age verification at checkout.', 'unq-age-verification' ); ?></p>
    </div>
    <?php
} );

add_action( 'product_cat_edit_form_fields', function ( $term ) {
    $required = get_term_meta( $term->term_id, 'unq_agev_category_required', true );
    ?>
    <tr class="form-field">
        <th scope="row">
            <?php esc_html_e( 'Age Verification', 'unq-age-verification' ); ?>
        </th>
        <td>
            <label>
                <input type="checkbox"
                       name="unq_agev_category_required"
                       value="yes"
                       <?php checked( $required, 'yes' ); ?>>
                <?php esc_html_e( 'Require age verification for all products in this category', 'unq-age-verification' ); ?>
            </label>
            <p class="description"><?php esc_html_e( 'When ticked, any cart containing a product from this category will require age verification at checkout.', 'unq-age-verification' ); ?></p>
        </td>
    </tr>
    <?php
} );

/**
 * Save unq_agev_category_required term meta when a product_cat term is created or updated.
 *
 * @param int $term_id
 */
function unq_agev_save_category_meta( $term_id ) {
    if ( isset( $_POST['unq_agev_category_required'] ) && 'yes' === $_POST['unq_agev_category_required'] ) {
        update_term_meta( $term_id, 'unq_agev_category_required', 'yes' );
    } else {
        delete_term_meta( $term_id, 'unq_agev_category_required' );
    }
}
add_action( 'created_product_cat', 'unq_agev_save_category_meta' );
add_action( 'edited_product_cat',  'unq_agev_save_category_meta' );

// ---------------------------------------------------------------------------
// Products list column — shows a "Required" badge or "—" per product.
// ---------------------------------------------------------------------------

add_filter( 'manage_product_posts_columns', function ( $columns ) {
    // Insert after the title column.
    $new = array();
    foreach ( $columns as $key => $label ) {
        $new[ $key ] = $label;
        if ( 'title' === $key ) {
            $new['unq_agev'] = __( 'Age gate', 'unq-age-verification' );
        }
    }
    return $new;
} );

add_action( 'manage_product_posts_custom_column', function ( $column, $post_id ) {
    if ( 'unq_agev' !== $column ) {
        return;
    }
    $is_required = 'yes' === get_post_meta( $post_id, '_unq_agev_required', true );
    $targeting   = unq_agev_get( 'targeting' );

    if ( 'all' === $targeting ) {
        echo '<span class="unq-col-badge is-store-wide">' . esc_html__( 'Store-wide', 'unq-age-verification' ) . '</span>';
    } elseif ( $is_required ) {
        $age = (int) get_post_meta( $post_id, '_unq_agev_required_age', true );
        $label = $age > 0
            ? sprintf(
                /* translators: %d: minimum age */
                __( 'Required (%d+)', 'unq-age-verification' ),
                $age
              )
            : __( 'Required', 'unq-age-verification' );
        echo '<span class="unq-col-badge is-required">' . esc_html( $label ) . '</span>';
    } else {
        echo '<span class="unq-col-badge is-none">&mdash;</span>';
    }
}, 10, 2 );

// Admin notice when the plugin is active but has no API key configured.
add_action( 'admin_notices', function () {
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        return;
    }
    // Suppress the generic notice when we are already on the settings page.
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if ( isset( $_GET['page'], $_GET['tab'] ) && $_GET['page'] === 'wc-settings' && $_GET['tab'] === 'unq_agev' ) {
        return;
    }
    if ( 'yes' !== unq_agev_get( 'enabled' ) || ! empty( unq_agev_active_key() ) ) {
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
    if ( empty( unq_agev_active_key() ) ) {
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
    if ( ! unq_agev_cart_is_gated() ) {
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

    $result = UNQ_JWT_Validator::validate( $token, unq_agev_cart_required_age() );
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
        $s = unq_agev_strings( unq_agev_resolve_locale() );
        wc_add_notice( esc_html( $s['cart_notice'] ), 'notice' );
    }
} );

// ---------------------------------------------------------------------------
// 4a. Server-side validation for WooCommerce Blocks (Store API) checkout.
//     The Blocks checkout submits to /wp-json/wc/store/v1/checkout, which
//     never fires woocommerce_checkout_process. We hook into the Store API's
//     own order-processed action and throw a RouteException to abort.
// ---------------------------------------------------------------------------

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

// ---------------------------------------------------------------------------
// 5. Enqueue scripts.
// ---------------------------------------------------------------------------

add_action( 'wp_enqueue_scripts', function () {
    if ( 'yes' !== unq_agev_get( 'enabled' ) || empty( unq_agev_active_key() ) ) {
        return;
    }

    if ( ! function_exists( 'is_cart' ) || ! function_exists( 'is_checkout' ) ) {
        return;
    }

    $shared_data = array(
        'sdkUrl'      => UNQ_AGEV_SDK_URL,
        'publicKey'   => unq_agev_active_key(),
        'ageToVerify' => unq_agev_cart_required_age(),
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
                'i18n'        => unq_agev_strings( unq_agev_resolve_locale(), unq_agev_cart_required_age() ),
            ) )
        );
    }
} );