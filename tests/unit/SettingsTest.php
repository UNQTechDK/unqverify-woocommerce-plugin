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
}
