<?php

namespace UNQVerify\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the unq_agev_get() settings helper.
 *
 * The bootstrap defines unq_agev_get() in terms of get_option(), so we can
 * stub get_option() via Brain\Monkey and verify that all the normalization
 * logic (max, allowlist, defaults) behaves correctly.
 */
class SettingsTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        $GLOBALS['unq_agev_test_cart_items'] = array();
        unq_agev_reset_cart_cache();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    protected function set_cart_items( array $items ): void {
        $GLOBALS['unq_agev_test_cart_items'] = $items;
        unq_agev_reset_cart_cache();
    }

    // ------------------------------------------------------------------
    // 20. 'enabled' default
    // ------------------------------------------------------------------

    public function test_enabled_returns_yes_when_option_not_set(): void {
        Functions\when( 'get_option' )->alias( fn( $opt, $def = null ) => $def );

        $result = unq_agev_get( 'enabled' );

        $this->assertSame( 'yes', $result );
    }

    // ------------------------------------------------------------------
    // 21. 'required_age' clamp — stored 0 must become 1 (not zero)
    // ------------------------------------------------------------------

    public function test_required_age_stored_as_zero_is_clamped_to_one(): void {
        Functions\when( 'get_option' )->alias( function ( $opt, $def = null ) {
            if ( 'unq_agev_required_age' === $opt ) {
                return '0'; // Stored as string '0', like WP's get_option.
            }
            return $def;
        } );

        $result = unq_agev_get( 'required_age' );

        $this->assertSame( 1, $result );
    }

    // ------------------------------------------------------------------
    // 22. 'mode' rejects unknown value → falls back to 'popup'
    // ------------------------------------------------------------------

    public function test_invalid_mode_falls_back_to_popup(): void {
        Functions\when( 'get_option' )->alias( function ( $opt, $def = null ) {
            if ( 'unq_agev_verification_mode' === $opt ) {
                return 'invalid_mode';
            }
            return $def;
        } );

        $result = unq_agev_get( 'mode' );

        $this->assertSame( 'popup', $result );
    }

    // ------------------------------------------------------------------
    // 23. Unknown key returns empty string
    // ------------------------------------------------------------------

    public function test_unknown_key_returns_empty_string(): void {
        // get_option should never even be called for unknown keys.
        Functions\expect( 'get_option' )->never();

        $result = unq_agev_get( 'this_key_does_not_exist' );

        $this->assertSame( '', $result );
    }

    // ------------------------------------------------------------------
    // 24. 'locale' default is 'auto' when option not set
    // ------------------------------------------------------------------

    public function test_locale_default_returns_auto(): void {
        Functions\when( 'get_option' )->alias( fn( $opt, $def = null ) => $def );

        $this->assertSame( 'auto', unq_agev_get( 'locale' ) );
    }

    // ------------------------------------------------------------------
    // 25. 'locale' rejects unknown value → falls back to 'auto'
    // ------------------------------------------------------------------

    public function test_invalid_locale_falls_back_to_auto(): void {
        Functions\when( 'get_option' )->alias( function ( $opt, $def = null ) {
            if ( 'unq_agev_locale' === $opt ) {
                return 'fr';
            }
            return $def;
        } );

        $this->assertSame( 'auto', unq_agev_get( 'locale' ) );
    }

    // ------------------------------------------------------------------
    // 26. unq_agev_strings() returns EN strings for 'en'
    // ------------------------------------------------------------------

    public function test_strings_returns_english_for_en(): void {
        $s = unq_agev_strings( 'en', 18 );

        $this->assertSame( 'Verify age to continue', $s['verifyPrompt'] );
        $this->assertSame( 'Age verified', $s['verified'] );
        $this->assertSame( 'Confirm with MitID', $s['modalVerifyBtn'] );
        $this->assertSame( 'MitID verification opens in a new window.', $s['popupNotice'] );
        $this->assertSame( 'Test mode', $s['testModeLabel'] );
    }

    // ------------------------------------------------------------------
    // 27. unq_agev_strings() returns DA strings for 'da'
    // ------------------------------------------------------------------

    public function test_strings_returns_danish_for_da(): void {
        $s = unq_agev_strings( 'da', 18 );

        $this->assertSame( 'Bekræft alder for at fortsætte', $s['verifyPrompt'] );
        $this->assertSame( 'Alder bekræftet', $s['verified'] );
        $this->assertSame( 'Bekræft med MitID', $s['modalVerifyBtn'] );
        $this->assertSame( 'MitID-verificeringen åbner i et nyt vindue.', $s['popupNotice'] );
        $this->assertSame( 'Testtilstand', $s['testModeLabel'] );
    }

    // ------------------------------------------------------------------
    // 28. unq_agev_strings() falls back to EN for unknown locale
    // ------------------------------------------------------------------

    public function test_strings_falls_back_to_en_for_unknown_locale(): void {
        $s = unq_agev_strings( 'fr', 18 );

        $this->assertSame( 'Verify age to continue', $s['verifyPrompt'] );
    }

    // ------------------------------------------------------------------
    // 29. unq_agev_strings() interpolates age into modalBody
    // ------------------------------------------------------------------

    public function test_strings_interpolates_age_in_modal_body(): void {
        $s = unq_agev_strings( 'en', 21 );

        $this->assertStringContainsString( '21', $s['modalBody'] );
    }

    // ------------------------------------------------------------------
    // 30. unq_agev_resolve_locale() returns 'en' when setting is 'en'
    // ------------------------------------------------------------------

    public function test_resolve_locale_returns_en_when_set_to_en(): void {
        Functions\when( 'get_option' )->alias( function ( $opt, $def = null ) {
            if ( 'unq_agev_locale' === $opt ) {
                return 'en';
            }
            return $def;
        } );

        $this->assertSame( 'en', unq_agev_resolve_locale() );
    }

    // ------------------------------------------------------------------
    // 31. unq_agev_resolve_locale() returns 'da' when setting is 'da'
    // ------------------------------------------------------------------

    public function test_resolve_locale_returns_da_when_set_to_da(): void {
        Functions\when( 'get_option' )->alias( function ( $opt, $def = null ) {
            if ( 'unq_agev_locale' === $opt ) {
                return 'da';
            }
            return $def;
        } );

        $this->assertSame( 'da', unq_agev_resolve_locale() );
    }

    // ------------------------------------------------------------------
    // 32. 'test_public_key' default is empty string
    // ------------------------------------------------------------------

    public function test_test_public_key_default_returns_empty_string(): void {
        Functions\when( 'get_option' )->alias( fn( $opt, $def = null ) => $def );

        $this->assertSame( '', unq_agev_get( 'test_public_key' ) );
    }

    // ------------------------------------------------------------------
    // 33. 'use_production' default is 'no'
    // ------------------------------------------------------------------

    public function test_use_production_default_returns_no(): void {
        Functions\when( 'get_option' )->alias( fn( $opt, $def = null ) => $def );

        $this->assertSame( 'no', unq_agev_get( 'use_production' ) );
    }

    // ------------------------------------------------------------------
    // 34. 'use_production' rejects invalid value → falls back to 'no'
    // ------------------------------------------------------------------

    public function test_invalid_use_production_falls_back_to_no(): void {
        Functions\when( 'get_option' )->alias( function ( $opt, $def = null ) {
            if ( 'unq_agev_use_production' === $opt ) {
                return 'maybe';
            }
            return $def;
        } );

        $this->assertSame( 'no', unq_agev_get( 'use_production' ) );
    }

    // ------------------------------------------------------------------
    // 35. unq_agev_active_key() returns test key when use_production=no
    // ------------------------------------------------------------------

    public function test_active_key_returns_test_key_in_test_mode(): void {
        Functions\when( 'get_option' )->alias( function ( $opt, $def = null ) {
            if ( 'unq_agev_test_public_key' === $opt )  return 'pk_test_abc123';
            if ( 'unq_agev_public_key' === $opt )       return 'pk_live_xyz789';
            if ( 'unq_agev_use_production' === $opt )   return 'no';
            return $def;
        } );

        $this->assertSame( 'pk_test_abc123', unq_agev_active_key() );
    }

    // ------------------------------------------------------------------
    // 36. unq_agev_active_key() returns production key when use_production=yes and key is set
    // ------------------------------------------------------------------

    public function test_active_key_returns_production_key_in_production_mode(): void {
        Functions\when( 'get_option' )->alias( function ( $opt, $def = null ) {
            if ( 'unq_agev_test_public_key' === $opt )  return 'pk_test_abc123';
            if ( 'unq_agev_public_key' === $opt )       return 'pk_live_xyz789';
            if ( 'unq_agev_use_production' === $opt )   return 'yes';
            return $def;
        } );

        $this->assertSame( 'pk_live_xyz789', unq_agev_active_key() );
    }

    // ------------------------------------------------------------------
    // 37. unq_agev_active_key() falls back to test key when production
    //     mode is on but no production key is saved
    // ------------------------------------------------------------------

    public function test_active_key_falls_back_to_test_key_when_no_production_key(): void {
        Functions\when( 'get_option' )->alias( function ( $opt, $def = null ) {
            if ( 'unq_agev_test_public_key' === $opt )  return 'pk_test_abc123';
            if ( 'unq_agev_public_key' === $opt )       return '';
            if ( 'unq_agev_use_production' === $opt )   return 'yes';
            return $def;
        } );

        // Production toggle is on but the key is empty → fall back to test key.
        $this->assertSame( 'pk_test_abc123', unq_agev_active_key() );
    }

    // ------------------------------------------------------------------
    // 38. 'targeting' defaults to 'all' when option not set
    // ------------------------------------------------------------------

    public function test_targeting_default_returns_all(): void {
        Functions\when( 'get_option' )->alias( fn( $opt, $def = null ) => $def );

        $this->assertSame( 'all', unq_agev_get( 'targeting' ) );
    }

    // ------------------------------------------------------------------
    // 39. 'targeting' returns 'selected_only' when option is set
    // ------------------------------------------------------------------

    public function test_targeting_returns_selected_only_when_set(): void {
        Functions\when( 'get_option' )->alias( function ( $opt, $def = null ) {
            if ( 'unq_agev_targeting' === $opt ) return 'selected_only';
            return $def;
        } );

        $this->assertSame( 'selected_only', unq_agev_get( 'targeting' ) );
    }

    // ------------------------------------------------------------------
    // 40. 'targeting' rejects invalid value → falls back to 'all'
    // ------------------------------------------------------------------

    public function test_targeting_rejects_invalid_value(): void {
        Functions\when( 'get_option' )->alias( function ( $opt, $def = null ) {
            if ( 'unq_agev_targeting' === $opt ) return 'everything';
            return $def;
        } );

        $this->assertSame( 'all', unq_agev_get( 'targeting' ) );
    }

    // ------------------------------------------------------------------
    // 41. cart_is_gated() returns true when targeting=all, enabled, key set
    // ------------------------------------------------------------------

    public function test_cart_is_gated_true_when_all_enabled_key_set(): void {
        Functions\when( 'get_option' )->alias( function ( $opt, $def = null ) {
            if ( 'unq_agev_enabled' === $opt )          return 'yes';
            if ( 'unq_agev_test_public_key' === $opt )  return 'pk_test_key';
            if ( 'unq_agev_use_production' === $opt )   return 'no';
            if ( 'unq_agev_targeting' === $opt )        return 'all';
            return $def;
        } );

        $this->set_cart_items( array() );
        $this->assertTrue( unq_agev_cart_is_gated() );
    }

    // ------------------------------------------------------------------
    // 42. cart_is_gated() returns false when plugin is disabled
    // ------------------------------------------------------------------

    public function test_cart_is_gated_false_when_disabled(): void {
        Functions\when( 'get_option' )->alias( function ( $opt, $def = null ) {
            if ( 'unq_agev_enabled' === $opt ) return 'no';
            return $def;
        } );

        $this->set_cart_items( array() );
        $this->assertFalse( unq_agev_cart_is_gated() );
    }

    // ------------------------------------------------------------------
    // 43. cart_is_gated() returns false when targeting=selected_only and
    //     cart contains no gated products
    // ------------------------------------------------------------------

    public function test_cart_is_gated_false_when_selected_only_no_gated_items(): void {
        Functions\when( 'get_option' )->alias( function ( $opt, $def = null ) {
            if ( 'unq_agev_enabled' === $opt )          return 'yes';
            if ( 'unq_agev_test_public_key' === $opt )  return 'pk_test_key';
            if ( 'unq_agev_use_production' === $opt )   return 'no';
            if ( 'unq_agev_targeting' === $opt )        return 'selected_only';
            return $def;
        } );
        // Product 99 has no _unq_agev_required meta.
        Functions\when( 'get_post_meta' )->alias( fn() => '' );
        Functions\when( 'get_the_terms' )->alias( fn() => array() );

        $this->set_cart_items( array( array( 'product_id' => 99 ) ) );
        $this->assertFalse( unq_agev_cart_is_gated() );
    }

    // ------------------------------------------------------------------
    // 44. cart_is_gated() returns true when targeting=selected_only and
    //     one product has _unq_agev_required = 'yes'
    // ------------------------------------------------------------------

    public function test_cart_is_gated_true_when_product_has_required_meta(): void {
        Functions\when( 'get_option' )->alias( function ( $opt, $def = null ) {
            if ( 'unq_agev_enabled' === $opt )          return 'yes';
            if ( 'unq_agev_test_public_key' === $opt )  return 'pk_test_key';
            if ( 'unq_agev_use_production' === $opt )   return 'no';
            if ( 'unq_agev_targeting' === $opt )        return 'selected_only';
            return $def;
        } );
        Functions\when( 'get_post_meta' )->alias( function ( $id, $key, $single = false ) {
            if ( 42 === $id && '_unq_agev_required' === $key ) return 'yes';
            return '';
        } );
        Functions\when( 'get_the_terms' )->alias( fn() => array() );

        $this->set_cart_items( array( array( 'product_id' => 42 ) ) );
        $this->assertTrue( unq_agev_cart_is_gated() );
    }

    // ------------------------------------------------------------------
    // 45. cart_is_gated() returns true when product belongs to a gated
    //     category (term meta unq_agev_category_required = 'yes')
    // ------------------------------------------------------------------

    public function test_cart_is_gated_true_when_category_has_required_meta(): void {
        Functions\when( 'get_option' )->alias( function ( $opt, $def = null ) {
            if ( 'unq_agev_enabled' === $opt )          return 'yes';
            if ( 'unq_agev_test_public_key' === $opt )  return 'pk_test_key';
            if ( 'unq_agev_use_production' === $opt )   return 'no';
            if ( 'unq_agev_targeting' === $opt )        return 'selected_only';
            return $def;
        } );
        // Product itself is NOT flagged, but its category IS.
        Functions\when( 'get_post_meta' )->alias( fn() => '' );

        $fake_term = new \stdClass();
        $fake_term->term_id = 7;
        Functions\when( 'get_the_terms' )->alias( fn() => array( $fake_term ) );
        Functions\when( 'get_term_meta' )->alias( function ( $id, $key, $single = false ) {
            if ( 7 === $id && 'unq_agev_category_required' === $key ) return 'yes';
            return '';
        } );

        $this->set_cart_items( array( array( 'product_id' => 55 ) ) );
        $this->assertTrue( unq_agev_cart_is_gated() );
    }

    // ------------------------------------------------------------------
    // 46. cart_required_age() returns global setting when targeting=all
    // ------------------------------------------------------------------

    public function test_cart_required_age_returns_global_when_targeting_all(): void {
        Functions\when( 'get_option' )->alias( function ( $opt, $def = null ) {
            if ( 'unq_agev_targeting' === $opt )    return 'all';
            if ( 'unq_agev_required_age' === $opt ) return '21';
            return $def;
        } );

        $this->set_cart_items( array() );
        $this->assertSame( 21, unq_agev_cart_required_age() );
    }

    // ------------------------------------------------------------------
    // 47. cart_required_age() returns product override when set
    // ------------------------------------------------------------------

    public function test_cart_required_age_returns_product_override(): void {
        Functions\when( 'get_option' )->alias( function ( $opt, $def = null ) {
            if ( 'unq_agev_targeting' === $opt )    return 'selected_only';
            if ( 'unq_agev_required_age' === $opt ) return '18';
            return $def;
        } );
        Functions\when( 'get_post_meta' )->alias( function ( $id, $key, $single = false ) {
            if ( 10 === $id && '_unq_agev_required' === $key )     return 'yes';
            if ( 10 === $id && '_unq_agev_required_age' === $key ) return '21';
            return '';
        } );
        Functions\when( 'get_the_terms' )->alias( fn() => array() );

        $this->set_cart_items( array( array( 'product_id' => 10 ) ) );
        $this->assertSame( 21, unq_agev_cart_required_age() );
    }

    // ------------------------------------------------------------------
    // 48. cart_required_age() returns max age when two gated items have
    //     different age overrides (18 and 21 → should return 21)
    // ------------------------------------------------------------------

    public function test_cart_required_age_returns_max_of_multiple_items(): void {
        Functions\when( 'get_option' )->alias( function ( $opt, $def = null ) {
            if ( 'unq_agev_targeting' === $opt )    return 'selected_only';
            if ( 'unq_agev_required_age' === $opt ) return '18';
            return $def;
        } );
        Functions\when( 'get_post_meta' )->alias( function ( $id, $key, $single = false ) {
            if ( '_unq_agev_required' === $key )     return 'yes';
            if ( 10 === $id && '_unq_agev_required_age' === $key ) return '18';
            if ( 20 === $id && '_unq_agev_required_age' === $key ) return '21';
            return '';
        } );
        Functions\when( 'get_the_terms' )->alias( fn() => array() );

        $items = array(
            array( 'product_id' => 10 ),
            array( 'product_id' => 20 ),
        );
        $this->set_cart_items( $items );
        $this->assertSame( 21, unq_agev_cart_required_age() );
    }

    // ------------------------------------------------------------------
    // 49. cart_required_age() falls back to global age when gated item
    //     has no per-product age override
    // ------------------------------------------------------------------

    public function test_cart_required_age_falls_back_to_global_when_no_override(): void {
        Functions\when( 'get_option' )->alias( function ( $opt, $def = null ) {
            if ( 'unq_agev_targeting' === $opt )    return 'selected_only';
            if ( 'unq_agev_required_age' === $opt ) return '18';
            return $def;
        } );
        Functions\when( 'get_post_meta' )->alias( function ( $id, $key, $single = false ) {
            if ( '_unq_agev_required' === $key )     return 'yes';
            if ( '_unq_agev_required_age' === $key ) return '0'; // Empty override.
            return '';
        } );
        Functions\when( 'get_the_terms' )->alias( fn() => array() );

        $this->set_cart_items( array( array( 'product_id' => 5 ) ) );
        $this->assertSame( 18, unq_agev_cart_required_age() );
    }

    // ------------------------------------------------------------------
    // 50. unq_agev_strings() returns all keys required by checkout.js
    //     (verifyPrompt, verified, denied, cancelled, popupBlocked, error,
    //      modalTitle, modalBody, popupNotice, verificationStarting,
    //      testModeLabel, testModeDescription, modalVerifyBtn, modalCancelBtn).
    // ------------------------------------------------------------------

    public function test_strings_contains_all_checkout_js_keys(): void {
        $required_keys = array(
            'verifyPrompt', 'verified', 'denied', 'cancelled',
            'popupBlocked', 'error', 'modalTitle', 'modalBody',
            'popupNotice', 'verificationStarting', 'testModeLabel',
            'testModeDescription', 'modalVerifyBtn', 'modalCancelBtn',
        );

        foreach ( array( 'en', 'da' ) as $locale ) {
            $strings = unq_agev_strings( $locale );
            foreach ( $required_keys as $key ) {
                $this->assertArrayHasKey(
                    $key,
                    $strings,
                    "Missing key '$key' in unq_agev_strings('$locale')"
                );
                $this->assertNotEmpty(
                    $strings[ $key ],
                    "Empty value for key '$key' in unq_agev_strings('$locale')"
                );
            }
        }
    }

    // ------------------------------------------------------------------
    // 51. unq_agev_get() returns the same value on repeated calls for the
    //     same key (idempotence — prerequisite for safe memoization).
    // ------------------------------------------------------------------

    public function test_get_returns_same_value_on_repeated_calls(): void {
        Functions\when( 'get_option' )->alias( function ( $opt, $def = null ) {
            if ( 'unq_agev_required_age' === $opt ) return '21';
            return $def;
        } );

        $first  = unq_agev_get( 'required_age' );
        $second = unq_agev_get( 'required_age' );
        $this->assertSame( $first, $second, 'unq_agev_get() must return identical values on repeated calls' );
        $this->assertSame( 21, $first );
    }

    // ------------------------------------------------------------------
    // 52. unq_agev_strings() interpolates the $age parameter into modalBody
    //     for both EN and DA locales.
    // ------------------------------------------------------------------

    public function test_strings_interpolates_age_into_modal_body(): void {
        foreach ( array( 'en', 'da' ) as $locale ) {
            $strings = unq_agev_strings( $locale, 15 );
            $this->assertStringContainsString(
                '15',
                $strings['modalBody'],
                "modalBody in '$locale' should contain the age '15'"
            );
        }
    }

    public function test_official_mitid_call_to_action_uses_permitted_wording(): void {
        foreach ( array( 'en', 'da' ) as $locale ) {
            $strings = unq_agev_strings( $locale );
            $this->assertStringContainsString( 'MitID', $strings['modalVerifyBtn'] );
            $this->assertStringContainsString( 'MitID', $strings['modalBody'] );
            $this->assertStringContainsString( 'MitID', $strings['popupNotice'] );
        }

        $this->assertSame( 'Confirm with MitID', unq_agev_strings( 'en' )['modalVerifyBtn'] );
        $this->assertSame( 'Bekræft med MitID', unq_agev_strings( 'da' )['modalVerifyBtn'] );
    }

    // ------------------------------------------------------------------
    // 53. unq_agev_effective_product_age() returns the product-level
    //     override when _unq_agev_required_age meta is > 0.
    // ------------------------------------------------------------------

    public function test_effective_age_uses_product_override(): void {
        Functions\when( 'get_option' )->alias( function ( $opt, $def = null ) {
            if ( 'unq_agev_required_age' === $opt ) return '18';
            return $def;
        } );
        Functions\when( 'get_post_meta' )->alias( function ( $id, $key ) {
            if ( '_unq_agev_required_age' === $key ) return '16';
            return '';
        } );

        $this->assertSame( 16, unq_agev_effective_product_age( 10 ) );
    }

    // ------------------------------------------------------------------
    // 54. unq_agev_effective_product_age() falls back to a gated category's
    //     age when there is no product-level override.
    // ------------------------------------------------------------------

    public function test_effective_age_falls_back_to_category(): void {
        Functions\when( 'get_option' )->alias( function ( $opt, $def = null ) {
            if ( 'unq_agev_required_age' === $opt ) return '18';
            return $def;
        } );
        Functions\when( 'get_post_meta' )->alias( function ( $id, $key ) {
            if ( '_unq_agev_required_age' === $key ) return '0'; // no product override
            return '';
        } );

        $mock_term         = new \stdClass();
        $mock_term->term_id = 99;
        Functions\when( 'get_the_terms' )->justReturn( array( $mock_term ) );
        Functions\when( 'get_term_meta' )->alias( function ( $term_id, $key ) {
            if ( 'unq_agev_category_required'     === $key ) return 'yes';
            if ( 'unq_agev_category_required_age' === $key ) return '17';
            return '';
        } );

        $this->assertSame( 17, unq_agev_effective_product_age( 10 ) );
    }

    // ------------------------------------------------------------------
    // 55. When a product belongs to multiple gated categories with
    //     different ages, the maximum age is used.
    // ------------------------------------------------------------------

    public function test_effective_age_takes_max_of_multiple_categories(): void {
        Functions\when( 'get_option' )->alias( function ( $opt, $def = null ) {
            if ( 'unq_agev_required_age' === $opt ) return '18';
            return $def;
        } );
        Functions\when( 'get_post_meta' )->alias( function ( $id, $key ) {
            if ( '_unq_agev_required_age' === $key ) return '0';
            return '';
        } );

        $term_a          = new \stdClass();
        $term_a->term_id = 10;
        $term_b          = new \stdClass();
        $term_b->term_id = 20;
        Functions\when( 'get_the_terms' )->justReturn( array( $term_a, $term_b ) );
        Functions\when( 'get_term_meta' )->alias( function ( $term_id, $key ) {
            if ( 'unq_agev_category_required' === $key ) return 'yes';
            if ( 'unq_agev_category_required_age' === $key ) {
                return $term_id === 10 ? '15' : '21';
            }
            return '';
        } );

        $this->assertSame( 21, unq_agev_effective_product_age( 5 ) );
    }

    // ------------------------------------------------------------------
    // 56. When neither product nor category carries an age override,
    //     the store-wide global is returned.
    // ------------------------------------------------------------------

    public function test_effective_age_falls_back_to_global(): void {
        Functions\when( 'get_option' )->alias( function ( $opt, $def = null ) {
            if ( 'unq_agev_required_age' === $opt ) return '18';
            return $def;
        } );
        Functions\when( 'get_post_meta' )->alias( function ( $id, $key ) {
            if ( '_unq_agev_required_age' === $key ) return '0';
            return '';
        } );

        $mock_term          = new \stdClass();
        $mock_term->term_id = 5;
        Functions\when( 'get_the_terms' )->justReturn( array( $mock_term ) );
        Functions\when( 'get_term_meta' )->alias( function ( $term_id, $key ) {
            if ( 'unq_agev_category_required'     === $key ) return 'yes';
            if ( 'unq_agev_category_required_age' === $key ) return '0'; // no category override
            return '';
        } );

        $this->assertSame( 18, unq_agev_effective_product_age( 7 ) );
    }

    // ------------------------------------------------------------------
    // 57. Age from a non-gated category is never used (must not leak
    //     through even when the category has an age meta value).
    // ------------------------------------------------------------------

    public function test_effective_age_ignores_non_gated_category(): void {
        Functions\when( 'get_option' )->alias( function ( $opt, $def = null ) {
            if ( 'unq_agev_required_age' === $opt ) return '18';
            return $def;
        } );
        Functions\when( 'get_post_meta' )->alias( function ( $id, $key ) {
            if ( '_unq_agev_required_age' === $key ) return '0';
            return '';
        } );

        $gated_term          = new \stdClass();
        $gated_term->term_id = 1;
        $free_term           = new \stdClass();
        $free_term->term_id  = 2;
        Functions\when( 'get_the_terms' )->justReturn( array( $gated_term, $free_term ) );
        Functions\when( 'get_term_meta' )->alias( function ( $term_id, $key ) {
            if ( 'unq_agev_category_required' === $key ) {
                return $term_id === 1 ? 'yes' : 'no'; // only term 1 is gated
            }
            if ( 'unq_agev_category_required_age' === $key ) {
                return $term_id === 1 ? '17' : '99'; // term 2 has a value but must be ignored
            }
            return '';
        } );

        // Should be 17 (from gated term 1), not 99 (from non-gated term 2).
        $this->assertSame( 17, unq_agev_effective_product_age( 8 ) );
    }

    // ------------------------------------------------------------------
    // 58. unq_agev_cart_required_age() uses the category-resolved age
    //     when targeting is 'selected_only' and no product override is set.
    // ------------------------------------------------------------------

    public function test_cart_required_age_uses_category_resolution(): void {
        Functions\when( 'get_option' )->alias( function ( $opt, $def = null ) {
            if ( 'unq_agev_required_age' === $opt ) return '18';
            if ( 'unq_agev_targeting'    === $opt ) return 'selected_only';
            return $def;
        } );
        // Product 6: gated via meta, no age override.
        Functions\when( 'get_post_meta' )->alias( function ( $id, $key ) {
            if ( '_unq_agev_required' === $key )     return 'yes';
            if ( '_unq_agev_required_age' === $key ) return '0';
            return '';
        } );
        $mock_term          = new \stdClass();
        $mock_term->term_id = 30;
        Functions\when( 'get_the_terms' )->justReturn( array( $mock_term ) );
        Functions\when( 'get_term_meta' )->alias( function ( $term_id, $key ) {
            if ( 'unq_agev_category_required'     === $key ) return 'yes';
            if ( 'unq_agev_category_required_age' === $key ) return '17';
            return '';
        } );

        // Cart contains one product; its effective age should come from the category (17).
        $this->set_cart_items( array( array( 'product_id' => 6 ) ) );
        $result = unq_agev_cart_required_age();
        $this->assertSame( 17, $result );
    }

    // ------------------------------------------------------------------
    // 59. unq_agev_cart_is_gated() returns false in 'selected_only' mode
    //     when the cart product is in a non-gated category.
    // ------------------------------------------------------------------

    public function test_cart_is_gated_returns_false_when_no_categories_selected(): void {
        Functions\when( 'get_option' )->alias( function ( $opt, $def = null ) {
            if ( 'unq_agev_enabled'    === $opt ) return 'yes';
            if ( 'unq_agev_targeting'  === $opt ) return 'selected_only';
            if ( 'unq_agev_test_public_key' === $opt ) return 'pk_test_abc';
            return $def;
        } );
        // Product has no individual gate flag.
        Functions\when( 'get_post_meta' )->alias( function ( $id, $key ) {
            if ( '_unq_agev_required' === $key ) return '';
            return '';
        } );
        $term          = new \stdClass();
        $term->term_id = 5;
        Functions\when( 'get_the_terms' )->justReturn( array( $term ) );
        // Category is NOT gated.
        Functions\when( 'get_term_meta' )->alias( function ( $term_id, $key ) {
            if ( 'unq_agev_category_required' === $key ) return ''; // not 'yes'
            return '';
        } );

        $this->set_cart_items( array( array( 'product_id' => 7 ) ) );
        $result = unq_agev_cart_is_gated();
        $this->assertFalse( $result );
    }

    // ------------------------------------------------------------------
    // 60. unq_agev_cart_is_gated() returns false in 'selected_only' mode
    //     when a different category is gated but the cart product belongs
    //     only to a non-gated category.
    // ------------------------------------------------------------------

    public function test_cart_is_gated_returns_false_when_product_not_in_gated_category(): void {
        Functions\when( 'get_option' )->alias( function ( $opt, $def = null ) {
            if ( 'unq_agev_enabled'    === $opt ) return 'yes';
            if ( 'unq_agev_targeting'  === $opt ) return 'selected_only';
            if ( 'unq_agev_test_public_key' === $opt ) return 'pk_test_abc';
            return $def;
        } );
        // Product has no individual gate flag.
        Functions\when( 'get_post_meta' )->alias( function ( $id, $key ) {
            if ( '_unq_agev_required' === $key ) return '';
            return '';
        } );
        // Product belongs only to category B (term_id=2). Category A (term_id=1) is gated but
        // the product is not in it.
        $term_b          = new \stdClass();
        $term_b->term_id = 2;
        Functions\when( 'get_the_terms' )->justReturn( array( $term_b ) );
        Functions\when( 'get_term_meta' )->alias( function ( $term_id, $key ) {
            if ( 'unq_agev_category_required' === $key ) {
                return $term_id === 1 ? 'yes' : ''; // only category A is gated; product is in B
            }
            return '';
        } );

        $this->set_cart_items( array( array( 'product_id' => 8 ) ) );
        $result = unq_agev_cart_is_gated();
        $this->assertFalse( $result );
    }

    public function test_cart_is_gated_when_only_the_selected_variation_is_flagged(): void {
        Functions\when( 'get_option' )->alias( function ( $option, $default = null ) {
            if ( 'unq_agev_enabled' === $option ) return 'yes';
            if ( 'unq_agev_targeting' === $option ) return 'selected_only';
            if ( 'unq_agev_test_public_key' === $option ) return 'pk_test_key';
            return $default;
        } );
        Functions\when( 'get_post_meta' )->alias( function ( $id, $key ) {
            return 101 === $id && '_unq_agev_required' === $key ? 'yes' : '';
        } );
        Functions\when( 'get_the_terms' )->justReturn( array() );

        $this->set_cart_items( array( array( 'product_id' => 100, 'variation_id' => 101 ) ) );

        $this->assertTrue( unq_agev_cart_is_gated() );
    }

    public function test_cart_does_not_leak_a_variation_rule_to_a_sibling(): void {
        Functions\when( 'get_option' )->alias( function ( $option, $default = null ) {
            if ( 'unq_agev_enabled' === $option ) return 'yes';
            if ( 'unq_agev_targeting' === $option ) return 'selected_only';
            if ( 'unq_agev_test_public_key' === $option ) return 'pk_test_key';
            return $default;
        } );
        Functions\when( 'get_post_meta' )->alias( function ( $id, $key ) {
            return 101 === $id && '_unq_agev_required' === $key ? 'yes' : '';
        } );
        Functions\when( 'get_the_terms' )->justReturn( array() );

        $this->set_cart_items( array( array( 'product_id' => 100, 'variation_id' => 102 ) ) );

        $this->assertFalse( unq_agev_cart_is_gated() );
    }

    public function test_variation_age_override_wins_over_parent_and_category(): void {
        Functions\when( 'get_option' )->alias( function ( $option, $default = null ) {
            if ( 'unq_agev_targeting' === $option ) return 'selected_only';
            if ( 'unq_agev_required_age' === $option ) return '18';
            return $default;
        } );
        Functions\when( 'get_post_meta' )->alias( function ( $id, $key ) {
            if ( 101 === $id && '_unq_agev_required' === $key ) return 'yes';
            if ( 101 === $id && '_unq_agev_required_age' === $key ) return '21';
            if ( 100 === $id && '_unq_agev_required_age' === $key ) return '19';
            return '';
        } );
        $term          = new \stdClass();
        $term->term_id = 9;
        Functions\when( 'get_the_terms' )->justReturn( array( $term ) );
        Functions\when( 'get_term_meta' )->alias( function ( $term_id, $key ) {
            if ( 'unq_agev_category_required' === $key ) return 'yes';
            if ( 'unq_agev_category_required_age' === $key ) return '20';
            return '';
        } );

        $this->set_cart_items( array( array( 'product_id' => 100, 'variation_id' => 101 ) ) );

        $this->assertSame( 21, unq_agev_cart_required_age() );
    }
}
