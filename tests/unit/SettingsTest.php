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
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
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
        $this->assertSame( 'Verify age with MitID', $s['modalVerifyBtn'] );
    }

    // ------------------------------------------------------------------
    // 27. unq_agev_strings() returns DA strings for 'da'
    // ------------------------------------------------------------------

    public function test_strings_returns_danish_for_da(): void {
        $s = unq_agev_strings( 'da', 18 );

        $this->assertSame( 'Bekræft alder for at fortsætte', $s['verifyPrompt'] );
        $this->assertSame( 'Alder bekræftet', $s['verified'] );
        $this->assertSame( 'Bekræft alder med MitID', $s['modalVerifyBtn'] );
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
}
