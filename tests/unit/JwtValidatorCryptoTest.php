<?php

namespace UNQVerify\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for UNQ_JWT_Validator::validate() — cryptographic and claims validation.
 *
 * These tests plant a real RSA-signed JWT and a real PEM key in the transient
 * cache so that no HTTP call is made. All crypto is exercised by PHP's own
 * openssl extension.
 */
class JwtValidatorCryptoTest extends TestCase {

    private JwtTestFactory $factory;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        $this->factory = new JwtTestFactory();

        // Stub get_option so that unq_agev_get('test_public_key') and
        // unq_agev_active_key() both resolve to the test environment, matching
        // the seeded 'unqverify_pubkey_test' transient in seed_transient().
        Functions\when( 'get_option' )->alias( function ( $option, $default = null ) {
            if ( 'unq_agev_public_key' === $option ) {
                return 'pk_test_unit'; // kept for any direct reads
            }
            if ( 'unq_agev_test_public_key' === $option ) {
                return 'pk_test_unit'; // unq_agev_active_key() reads this in test mode
            }
            if ( 'unq_agev_use_production' === $option ) {
                return 'no'; // ensure active_key() returns the test key
            }
            if ( 'unq_agev_required_age' === $option ) {
                return 18;
            }
            return $default;
        } );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Helper: seed the transient cache with the factory's public PEM so
    // get_public_key() never makes an HTTP request.
    // ------------------------------------------------------------------

    private function seed_transient(): void {
        $pem = $this->factory->public_pem();
        Functions\when( 'get_transient' )->alias( function ( $key ) use ( $pem ) {
            // pk_test_* → 'unqverify_pubkey_test'
            return ( 'unqverify_pubkey_test' === $key ) ? $pem : false;
        } );
    }

    // ------------------------------------------------------------------
    // 6. Valid JWT, age exactly meets threshold
    // ------------------------------------------------------------------

    public function test_valid_jwt_age_equal_to_required_returns_true(): void {
        $this->seed_transient();

        $jwt    = $this->factory->make_jwt( [ 'age' => 18, 'result' => true, 'exp_offset' => 3600 ] );
        $result = \UNQ_JWT_Validator::validate( $jwt, 18 );

        $this->assertTrue( $result );
    }

    // ------------------------------------------------------------------
    // 7. Valid JWT, age exceeds threshold
    // ------------------------------------------------------------------

    public function test_valid_jwt_age_above_required_returns_true(): void {
        $this->seed_transient();

        $jwt    = $this->factory->make_jwt( [ 'age' => 25, 'result' => true, 'exp_offset' => 3600 ] );
        $result = \UNQ_JWT_Validator::validate( $jwt, 18 );

        $this->assertTrue( $result );
    }

    // ------------------------------------------------------------------
    // 8. Valid signature but age below required threshold
    // ------------------------------------------------------------------

    public function test_valid_jwt_age_below_required_returns_under_age_error(): void {
        $this->seed_transient();

        $jwt    = $this->factory->make_jwt( [ 'age' => 17, 'result' => true, 'exp_offset' => 3600 ] );
        $result = \UNQ_JWT_Validator::validate( $jwt, 18 );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'unqverify_under_age', $result->get_error_code() );
    }

    // ------------------------------------------------------------------
    // 9. verification_result = false
    // ------------------------------------------------------------------

    public function test_jwt_with_failed_verification_result_returns_not_verified_error(): void {
        $this->seed_transient();

        $jwt    = $this->factory->make_jwt( [ 'age' => 30, 'result' => false, 'exp_offset' => 3600 ] );
        $result = \UNQ_JWT_Validator::validate( $jwt, 18 );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'unqverify_not_verified', $result->get_error_code() );
    }

    // ------------------------------------------------------------------
    // 10. Expired JWT (exp in the past)
    // ------------------------------------------------------------------

    public function test_expired_jwt_returns_expired_error(): void {
        $this->seed_transient();

        $jwt    = $this->factory->make_jwt( [ 'age' => 18, 'result' => true, 'exp_offset' => -3600 ] );
        $result = \UNQ_JWT_Validator::validate( $jwt, 18 );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'unqverify_expired', $result->get_error_code() );
    }

    // ------------------------------------------------------------------
    // 11. Tampered payload — signature will not correspond to changed payload
    // ------------------------------------------------------------------

    public function test_tampered_payload_returns_invalid_signature_error(): void {
        $this->seed_transient();

        $jwt    = $this->factory->make_tampered_jwt();
        $result = \UNQ_JWT_Validator::validate( $jwt, 18 );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'unqverify_invalid_signature', $result->get_error_code() );
    }
}
