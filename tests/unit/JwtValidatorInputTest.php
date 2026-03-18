<?php

namespace UNQVerify\Tests\Unit;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

/**
 * Tests for UNQ_JWT_Validator::validate() input-level guard rails.
 *
 * These tests inject no key — they all trip wire before the key-fetch stage.
 */
class JwtValidatorInputTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // 1. Empty string
    // ------------------------------------------------------------------

    public function test_empty_string_returns_missing_token_error(): void {
        $result = \UNQ_JWT_Validator::validate( '' );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'unqverify_missing_token', $result->get_error_code() );
    }

    // ------------------------------------------------------------------
    // 2. Non-string (null)
    // ------------------------------------------------------------------

    public function test_null_returns_missing_token_error(): void {
        $result = \UNQ_JWT_Validator::validate( null );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'unqverify_missing_token', $result->get_error_code() );
    }

    // ------------------------------------------------------------------
    // 3. Only two segments (malformed JWT)
    // ------------------------------------------------------------------

    public function test_two_segment_jwt_returns_malformed_error(): void {
        $result = \UNQ_JWT_Validator::validate( 'header.payload' );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'unqverify_malformed', $result->get_error_code() );
    }

    // ------------------------------------------------------------------
    // 4. alg=none — algorithm confusion / unsigned token attack
    // ------------------------------------------------------------------

    public function test_alg_none_returns_bad_alg_error(): void {
        $factory = new JwtTestFactory();
        $jwt     = $factory->make_alg_none_jwt();

        $result = \UNQ_JWT_Validator::validate( $jwt );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'unqverify_bad_alg', $result->get_error_code() );
    }

    // ------------------------------------------------------------------
    // 5. alg=HS256 — algorithm substitution attack
    // ------------------------------------------------------------------

    public function test_alg_hs256_returns_bad_alg_error(): void {
        $factory = new JwtTestFactory();
        $jwt     = $factory->make_hs256_jwt();

        $result = \UNQ_JWT_Validator::validate( $jwt );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'unqverify_bad_alg', $result->get_error_code() );
    }
}
