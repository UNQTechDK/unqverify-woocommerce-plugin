<?php
/**
 * UNQVerify Age Verification for WooCommerce — helper functions.
 *
 * This file contains all stateless or memoized helper functions used by both
 * the admin and frontend layers. It is loaded early in the bootstrap and is
 * the only file required by the unit-test suite, keeping tests fast and
 * independent of admin/frontend hook registrations.
 *
 * @package UNQ_Age_Verification
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

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
 *   targeting        → 'all' | 'selected_only'
 *
 * @param  string $key
 * @return mixed
 */
function unq_agev_get( $key ) {
    static $cache = array();
    if ( array_key_exists( $key, $cache ) ) {
        return $cache[ $key ];
    }
    switch ( $key ) {
        case 'enabled':
            $value = get_option( 'unq_agev_enabled', 'yes' );
            break;
        case 'public_key':
            $value = (string) get_option( 'unq_agev_public_key', '' );
            break;
        case 'test_public_key':
            $value = (string) get_option( 'unq_agev_test_public_key', '' );
            break;
        case 'use_production':
            $val   = get_option( 'unq_agev_use_production', 'no' );
            $value = in_array( $val, array( 'yes', 'no' ), true ) ? $val : 'no';
            break;
        case 'required_age':
            $value = max( 1, (int) get_option( 'unq_agev_required_age', 18 ) );
            break;
        case 'mode':
            $mode  = get_option( 'unq_agev_verification_mode', 'popup' );
            $value = in_array( $mode, array( 'popup', 'redirect' ), true ) ? $mode : 'popup';
            break;
        case 'locale':
            $locale = get_option( 'unq_agev_locale', 'auto' );
            $value  = in_array( $locale, array( 'auto', 'en', 'da' ), true ) ? $locale : 'auto';
            break;
        case 'targeting':
            $targeting = get_option( 'unq_agev_targeting', 'all' );
            $value     = in_array( $targeting, array( 'all', 'selected_only' ), true ) ? $targeting : 'all';
            break;
        default:
            return '';
    }
    $cache[ $key ] = $value;
    return $cache[ $key ];
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
 * Resolves a possibly-translated product ID to the canonical (source-language)
 * product ID.
 *
 * When WPML or Polylang is active, translated products are separate posts whose
 * IDs differ from the original. Per-product age gate meta is stored only on the
 * source post, so we must resolve to the canonical ID before reading those keys.
 *
 * Falls back to the original $product_id when neither plugin is active.
 *
 * @param  int $product_id
 * @return int
 */
function unq_agev_canonical_product_id( $product_id ) {
    $product_id = (int) $product_id;

    // WPML: wpml_object_id filter returns the matching post ID in any language.
    // Passing the default language always returns the source-language post.
    if ( has_filter( 'wpml_object_id' ) ) {
        $default_lang = apply_filters( 'wpml_default_language', null );
        if ( null !== $default_lang ) {
            $canonical = (int) apply_filters( 'wpml_object_id', $product_id, 'product', true, $default_lang );
            if ( $canonical > 0 ) {
                return $canonical;
            }
        }
    }

    // Polylang: pll_get_post() returns the same post in the requested language.
    if ( function_exists( 'pll_get_post' ) && function_exists( 'pll_default_language' ) ) {
        $canonical = (int) pll_get_post( $product_id, pll_default_language() );
        if ( $canonical > 0 ) {
            return $canonical;
        }
    }

    return $product_id;
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
 * @param  bool $reset Pass true to clear the static cache (e.g. after cart mutation).
 * @return bool
 */
function unq_agev_cart_is_gated( $reset = false ) {
    static $cache = null;
    if ( $reset ) {
        $cache = null;
        return false; // Return value is ignored by cache-reset callers.
    }
    if ( null !== $cache ) {
        return $cache;
    }

    if ( 'yes' !== unq_agev_get( 'enabled' ) || empty( unq_agev_active_key() ) ) {
        $cache = false;
        return $cache;
    }

    if ( 'all' === unq_agev_get( 'targeting' ) ) {
        $cache = true;
        return $cache;
    }

    // 'selected_only' — scan the cart.
    if ( ! function_exists( 'WC' ) || is_null( WC()->cart ) ) {
        $cache = false;
        return $cache;
    }

    foreach ( WC()->cart->get_cart() as $item ) {
        $product_id = (int) ( $item['product_id'] ?? 0 );
        if ( ! $product_id ) {
            continue;
        }
        $canonical_id = unq_agev_canonical_product_id( $product_id );
        if ( 'yes' === get_post_meta( $canonical_id, '_unq_agev_required', true ) ) {
            $cache = true;
            return $cache;
        }
        $terms = get_the_terms( $canonical_id, 'product_cat' );
        if ( is_array( $terms ) ) {
            foreach ( $terms as $term ) {
                if ( 'yes' === get_term_meta( $term->term_id, 'unq_agev_category_required', true ) ) {
                    $cache = true;
                    return $cache;
                }
            }
        }
    }

    $cache = false;
    return $cache;
}

/**
 * Returns the effective minimum required age for the current cart.
 *
 * - 'all'           → the maximum effective age across all cart items, using
 *                     product/category overrides where set, falling back to
 *                     the store-wide default per item.
 * - 'selected_only' → same logic, but only gated items are considered. Returns
 *                     the store-wide default when no gated items are in the cart.
 *
 * Per-item age is resolved by unq_agev_effective_product_age().
 *
 * @param  bool $reset Pass true to clear the static cache (e.g. after cart mutation).
 * @return int
 */
function unq_agev_cart_required_age( $reset = false ) {
    static $cache = null;
    if ( $reset ) {
        $cache = null;
        return 0; // Return value is ignored by cache-reset callers.
    }
    if ( null !== $cache ) {
        return $cache;
    }

    $global    = unq_agev_get( 'required_age' );
    $targeting = unq_agev_get( 'targeting' );

    if ( ! function_exists( 'WC' ) || is_null( WC()->cart ) ) {
        $cache = $global;
        return $cache;
    }

    $ages = array();
    foreach ( WC()->cart->get_cart() as $item ) {
        $product_id = (int) ( $item['product_id'] ?? 0 );
        if ( ! $product_id ) {
            continue;
        }

        // For 'selected_only' mode, skip products that are not explicitly gated.
        if ( 'selected_only' === $targeting ) {
            $canonical_id = unq_agev_canonical_product_id( $product_id );
            $is_gated = false;
            if ( 'yes' === get_post_meta( $canonical_id, '_unq_agev_required', true ) ) {
                $is_gated = true;
            } else {
                $terms = get_the_terms( $canonical_id, 'product_cat' );
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
        }

        $ages[] = unq_agev_effective_product_age( $product_id );
    }

    $cache = empty( $ages ) ? $global : max( $ages );
    return $cache;
}

/**
 * Returns the effective minimum required age for a single product.
 *
 * Resolution order (most specific wins):
 *   1. Product-level override — `_unq_agev_required_age` post meta, if > 0.
 *   2. Category-level age    — `unq_agev_category_required_age` on each *gated*
 *                              `product_cat` term the product belongs to;
 *                              the maximum across all gated categories is used.
 *   3. Store-wide default    — `unq_agev_get( 'required_age' )`.
 *
 * Results are memoized per product for the lifetime of the request.
 *
 * @param int $product_id
 * @return int
 */
function unq_agev_effective_product_age( $product_id ) {
    static $cache = array();
    // Resolve translated products (WPML/Polylang) to the source-language variant
    // so per-product meta stored on the source post is always found correctly.
    $product_id = unq_agev_canonical_product_id( (int) $product_id );
    if ( array_key_exists( $product_id, $cache ) ) {
        return $cache[ $product_id ];
    }

    // 1. Product-level override.
    $product_override = (int) get_post_meta( $product_id, '_unq_agev_required_age', true );
    if ( $product_override > 0 ) {
        $cache[ $product_id ] = $product_override;
        return $cache[ $product_id ];
    }

    // 2. Max age across gated categories this product belongs to.
    $cat_ages = array();
    $terms    = get_the_terms( $product_id, 'product_cat' );
    if ( is_array( $terms ) ) {
        foreach ( $terms as $term ) {
            if ( 'yes' !== get_term_meta( $term->term_id, 'unq_agev_category_required', true ) ) {
                continue;
            }
            $cat_age = (int) get_term_meta( $term->term_id, 'unq_agev_category_required_age', true );
            if ( $cat_age > 0 ) {
                $cat_ages[] = $cat_age;
            }
        }
    }
    if ( ! empty( $cat_ages ) ) {
        $cache[ $product_id ] = max( $cat_ages );
        return $cache[ $product_id ];
    }

    // 3. Store-wide default.
    $cache[ $product_id ] = unq_agev_get( 'required_age' );
    return $cache[ $product_id ];
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
// Cart cache invalidation helpers.
// ---------------------------------------------------------------------------

/**
 * Clears the per-request static caches for unq_agev_cart_is_gated() and
 * unq_agev_cart_required_age() by calling each with $reset = true.
 *
 * Hooked to cart mutation actions so mid-request cache is always fresh
 * even when other plugins (free-gift, auto-coupon, etc.) add/remove items.
 */
function unq_agev_reset_cart_cache() {
    unq_agev_cart_is_gated( true );
    unq_agev_cart_required_age( true );
}
