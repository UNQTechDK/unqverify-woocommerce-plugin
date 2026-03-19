<?php

namespace UNQVerify\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for UNQ_JWT_Validator::get_public_key() — JWKS fetching, caching,
 * JWK-to-PEM conversion and all HTTP error paths.
 */
class JwkToPemTest extends TestCase {

    private JwtTestFactory $factory;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        $this->factory = new JwtTestFactory();

        // Default: resolve to test environment so unq_agev_active_key() returns
        // 'pk_test_unit' and get_public_key() hits the test JWKS endpoint.
        Functions\when( 'get_option' )->alias( function ( $option, $default = null ) {
            if ( 'unq_agev_public_key' === $option ) {
                return 'pk_test_unit';
            }
            if ( 'unq_agev_test_public_key' === $option ) {
                return 'pk_test_unit';
            }
            if ( 'unq_agev_use_production' === $option ) {
                return 'no';
            }
            return $default;
        } );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Helper: build a minimal valid JWKS JSON body from the factory's key.
    // ------------------------------------------------------------------

    private function factory_jwks_body(): string {
        return $this->factory->jwks_body();
    }

    // ------------------------------------------------------------------
    // Helper: minimal fake wp_remote_get() successful response struct.
    // ------------------------------------------------------------------

    private function ok_response( string $body ): array {
        return [
            'response' => [ 'code' => 200 ],
            'body'     => $body,
        ];
    }

    // ------------------------------------------------------------------
    // 12. Known RSA key roundtrip: JWK → PEM → openssl accepts it.
    //     This exercises the full jwk_rsa_to_pem + DER encoding path.
    // ------------------------------------------------------------------

    public function test_valid_jwk_round_trips_to_openssl_accepted_pem(): void {
        Functions\when( 'get_transient' )->justReturn( false );
        Functions\when( 'set_transient' )->justReturn( true );
        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'wp_remote_get' )->justReturn( $this->ok_response( $this->factory_jwks_body() ) );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->alias( fn( $r ) => $r['body'] );
        Functions\when( 'is_wp_error' )->justReturn( false );

        $pem = \UNQ_JWT_Validator::get_public_key();

        $this->assertIsString( $pem );
        $this->assertStringContainsString( '-----BEGIN PUBLIC KEY-----', $pem );
        // OpenSSL must accept the key.
        $key = openssl_get_publickey( $pem );
        $this->assertNotFalse( $key );
    }

    // ------------------------------------------------------------------
    // 13. Transient cache hit — returns PEM without making any HTTP call.
    // ------------------------------------------------------------------

    public function test_transient_cache_hit_returns_pem_without_http(): void {
        $expected_pem = $this->factory->public_pem();
        Functions\when( 'get_transient' )->justReturn( $expected_pem );
        // Ensure wp_remote_get is NOT called.
        Functions\expect( 'wp_remote_get' )->never();

        $result = \UNQ_JWT_Validator::get_public_key();

        $this->assertSame( $expected_pem, $result );
    }

    // ------------------------------------------------------------------
    // 14. HTTP request fails (WP_Error) + stale option exists → return stale.
    // ------------------------------------------------------------------

    public function test_http_failure_with_stale_cache_returns_stale_pem(): void {
        $stale_pem  = $this->factory->public_pem();
        $wp_err_obj = new \WP_Error( 'http_request_failed', 'cURL error 7' );

        Functions\when( 'get_transient' )->justReturn( false );
        Functions\when( 'wp_remote_get' )->justReturn( $wp_err_obj );
        Functions\when( 'is_wp_error' )->alias( fn( $v ) => $v instanceof \WP_Error );
        Functions\when( 'get_option' )->alias( function ( $option, $default = null ) use ( $stale_pem ) {
            if ( 'unq_agev_public_key' === $option ) {
                return 'pk_test_unit';
            }
            if ( 'unq_agev_test_public_key' === $option ) {
                return 'pk_test_unit';
            }
            if ( 'unq_agev_use_production' === $option ) {
                return 'no';
            }
            if ( 'unqverify_pubkey_stale_test' === $option ) {
                return $stale_pem;
            }
            return $default;
        } );
        Functions\when( 'set_transient' )->justReturn( true );

        $result = \UNQ_JWT_Validator::get_public_key();

        $this->assertSame( $stale_pem, $result );
    }

    // ------------------------------------------------------------------
    // 15. HTTP request fails + no stale option → WP_Error.
    // ------------------------------------------------------------------

    public function test_http_failure_without_stale_cache_returns_error(): void {
        $wp_err_obj = new \WP_Error( 'http_request_failed', 'cURL error 7' );

        Functions\when( 'get_transient' )->justReturn( false );
        Functions\when( 'wp_remote_get' )->justReturn( $wp_err_obj );
        Functions\when( 'is_wp_error' )->alias( fn( $v ) => $v instanceof \WP_Error );
        Functions\when( 'get_option' )->alias( function ( $option, $default = null ) {
            if ( 'unq_agev_public_key' === $option ) {
                return 'pk_test_unit';
            }
            if ( 'unq_agev_test_public_key' === $option ) {
                return 'pk_test_unit';
            }
            if ( 'unq_agev_use_production' === $option ) {
                return 'no';
            }
            // No stale key stored.
            return $default;
        } );

        $result = \UNQ_JWT_Validator::get_public_key();

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'unqverify_key_fetch_failed', $result->get_error_code() );
    }

    // ------------------------------------------------------------------
    // 16. HTTP 404 → WP_Error key_http_error.
    // ------------------------------------------------------------------

    public function test_http_404_returns_key_http_error(): void {
        $response_404 = [ 'response' => [ 'code' => 404 ], 'body' => 'Not Found' ];

        Functions\when( 'get_transient' )->justReturn( false );
        Functions\when( 'wp_remote_get' )->justReturn( $response_404 );
        Functions\when( 'is_wp_error' )->justReturn( false );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 404 );

        $result = \UNQ_JWT_Validator::get_public_key();

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'unqverify_key_http_error', $result->get_error_code() );
    }

    // ------------------------------------------------------------------
    // 17. Empty JWKS keys array → WP_Error key_invalid.
    //     The validator guards `empty($jwks['keys'])` before iterating,
    //     so an empty array hits the key_invalid branch, not key_not_found.
    // ------------------------------------------------------------------

    public function test_empty_jwks_keys_returns_key_invalid_error(): void {
        $empty_jwks = json_encode( [ 'keys' => [] ] );

        Functions\when( 'get_transient' )->justReturn( false );
        Functions\when( 'wp_remote_get' )->justReturn( $this->ok_response( $empty_jwks ) );
        Functions\when( 'is_wp_error' )->justReturn( false );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->alias( fn( $r ) => $r['body'] );

        $result = \UNQ_JWT_Validator::get_public_key();

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'unqverify_key_invalid', $result->get_error_code() );
    }

    // ------------------------------------------------------------------
    // 18. JWKS with non-RSA key only (EC key) → WP_Error key_not_found.
    // ------------------------------------------------------------------

    public function test_non_rsa_only_jwks_returns_key_not_found_error(): void {
        $ec_only_jwks = json_encode( [
            'keys' => [
                [
                    'kty' => 'EC',
                    'use' => 'sig',
                    'crv' => 'P-256',
                    'x'   => 'f83OJ3D2xF1Bg8vub9tLe1gHMzV76e8Tus9uPHvRVEU',
                    'y'   => 'x_FEzRu9m36HLN_tue659LNpXW6pCyStikYjKIWI5a0',
                ],
            ],
        ] );

        Functions\when( 'get_transient' )->justReturn( false );
        Functions\when( 'wp_remote_get' )->justReturn( $this->ok_response( $ec_only_jwks ) );
        Functions\when( 'is_wp_error' )->justReturn( false );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->alias( fn( $r ) => $r['body'] );

        $result = \UNQ_JWT_Validator::get_public_key();

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'unqverify_key_not_found', $result->get_error_code() );
    }

    // ------------------------------------------------------------------
    // 19. Invalid JSON body → WP_Error key_invalid.
    // ------------------------------------------------------------------

    public function test_invalid_json_response_returns_key_invalid_error(): void {
        Functions\when( 'get_transient' )->justReturn( false );
        Functions\when( 'wp_remote_get' )->justReturn( $this->ok_response( 'not-json' ) );
        Functions\when( 'is_wp_error' )->justReturn( false );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->alias( fn( $r ) => $r['body'] );

        $result = \UNQ_JWT_Validator::get_public_key();

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'unqverify_key_invalid', $result->get_error_code() );
    }
}
