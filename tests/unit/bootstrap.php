<?php
/**
 * Bootstrap for unit tests.
 *
 * Loads Brain\Monkey (so WP functions can be mocked/stubbed without a real
 * WordPress install) and directly requires the two plugin source files.
 * No database, no HTTP — these tests run in milliseconds.
 */

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

// ---------------------------------------------------------------------------
// WordPress class stubs — Brain\Monkey mocks functions but not classes.
// ---------------------------------------------------------------------------

if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        private string $code;
        private string $message;
        private mixed  $data;

        public function __construct( string $code = '', string $message = '', mixed $data = '' ) {
            $this->code    = $code;
            $this->message = $message;
            $this->data    = $data;
        }

        public function get_error_code(): string {
            return $this->code;
        }

        public function get_error_message( string $code = '' ): string {
            return $this->message;
        }

        public function get_error_data( string $code = '' ): mixed {
            return $this->data;
        }
    }
}

// ---------------------------------------------------------------------------
// WordPress function stubs that must exist before bootstrap (Brain\Monkey
// does not auto-stub pure escaping helpers — define them early).
// ---------------------------------------------------------------------------

if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( $text ) {
        return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
    }
}

// ---------------------------------------------------------------------------
// WordPress constant stubs — the plugin files need these at parse time.
// ---------------------------------------------------------------------------
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', '/tmp/wp/' );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
    define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
    define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'OPENSSL_ALGO_SHA256' ) ) {
    // Already defined by PHP's openssl extension in all normal environments.
    define( 'OPENSSL_ALGO_SHA256', 7 );
}

// ---------------------------------------------------------------------------
// Load ONLY the includes file directly — the main plugin file registers hooks
// that call WP/WC functions we haven't stubbed yet. Unit tests work against
// the class in isolation.
// ---------------------------------------------------------------------------
require_once dirname( __DIR__, 2 ) . '/includes/class-unq-jwt-validator.php';

// A minimal unq_agev_get() stub mirroring the main plugin's real logic.
// It calls get_option() so individual tests can stub that via Brain\Monkey.
if ( ! function_exists( 'unq_agev_get' ) ) {
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
}

// Stub for unq_agev_active_key() — mirrors real plugin logic.
if ( ! function_exists( 'unq_agev_active_key' ) ) {
    function unq_agev_active_key() {
        if ( 'yes' === unq_agev_get( 'use_production' ) && ! empty( unq_agev_get( 'public_key' ) ) ) {
            return unq_agev_get( 'public_key' );
        }
        return unq_agev_get( 'test_public_key' );
    }
}

// Stub for unq_agev_cart_is_gated() — cart helpers need a WC cart stub.
// Unit tests exercise the logic directly via helper functions defined below.
if ( ! function_exists( 'unq_agev_cart_is_gated' ) ) {
    /**
     * @param array|null $cart_items   Array of cart item arrays for testing.
     *                                  Each item: ['product_id' => int].
     *                                  Pass null to simulate no WC cart available.
     */
    function unq_agev_cart_is_gated( $cart_items = null ) {
        if ( 'yes' !== unq_agev_get( 'enabled' ) || empty( unq_agev_active_key() ) ) {
            return false;
        }
        if ( 'all' === unq_agev_get( 'targeting' ) ) {
            return true;
        }
        if ( null === $cart_items ) {
            return false;
        }
        foreach ( $cart_items as $item ) {
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
}

// Stub for unq_agev_cart_required_age() — mirrors real plugin logic.
if ( ! function_exists( 'unq_agev_effective_product_age' ) ) {
    /**
     * @param int $product_id
     */
    function unq_agev_effective_product_age( $product_id ) {
        $product_id = (int) $product_id;

        // 1. Product override.
        $product_override = (int) get_post_meta( $product_id, '_unq_agev_required_age', true );
        if ( $product_override > 0 ) {
            return $product_override;
        }

        // 2. Max age across gated categories.
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
            return max( $cat_ages );
        }

        // 3. Store-wide default.
        return unq_agev_get( 'required_age' );
    }
}

if ( ! function_exists( 'unq_agev_cart_required_age' ) ) {
    /**
     * @param array|null $cart_items   Array of cart item arrays for testing.
     *                                  Each item: ['product_id' => int].
     *                                  Pass null to simulate no WC cart available.
     */
    function unq_agev_cart_required_age( $cart_items = null ) {
        $global    = unq_agev_get( 'required_age' );
        $targeting = unq_agev_get( 'targeting' );

        if ( null === $cart_items ) {
            return $global;
        }

        $ages = array();
        foreach ( $cart_items as $item ) {
            $product_id = (int) ( $item['product_id'] ?? 0 );
            if ( ! $product_id ) {
                continue;
            }

            if ( 'selected_only' === $targeting ) {
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
            }

            $ages[] = unq_agev_effective_product_age( $product_id );
        }

        return empty( $ages ) ? $global : max( $ages );
    }
}

// Mirror the real unq_agev_resolve_locale() and unq_agev_strings() from the
// main plugin file so they are available to SettingsTest without loading
// the full plugin (which registers WP/WC hooks we haven't stubbed).
if ( ! function_exists( 'unq_agev_resolve_locale' ) ) {
    function unq_agev_resolve_locale() {
        $setting = unq_agev_get( 'locale' );
        if ( 'en' === $setting ) {
            return 'en';
        }
        if ( 'da' === $setting ) {
            return 'da';
        }
        $wp_locale = function_exists( 'get_locale' ) ? get_locale() : 'en_US';
        return ( strpos( strtolower( $wp_locale ), 'da' ) === 0 ) ? 'da' : 'en';
    }
}

if ( ! function_exists( 'unq_agev_strings' ) ) {
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
                'modalBody'      => sprintf( 'Denne butik sælger aldersbegrænsede varer. Du skal bekræfte, at du er %d år eller ældre, for at gå til kassen. Det sker sikkert via MitID og tager kun et øjeblik.', $age ),
                'modalVerifyBtn' => 'Bekræft alder med MitID',
                'modalCancelBtn' => 'Annuller',
            ),
        );
        return isset( $strings[ $locale ] ) ? $strings[ $locale ] : $strings['en'];
    }
}
