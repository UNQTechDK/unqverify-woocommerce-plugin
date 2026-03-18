<?php
/**
 * Helper that generates real RSA key pairs and builds signed JWTs for tests.
 *
 * Uses PHP's openssl extension — no network, no real MitID keys needed.
 * All cryptographic operations are genuine RS256; tests are not faked.
 */

namespace UNQVerify\Tests\Unit;

class JwtTestFactory {

    /** @var resource|\OpenSSLAsymmetricKey */
    private $private_key;

    /** @var string PEM of the public key */
    private string $public_pem;

    public function __construct() {
        $res = openssl_pkey_new( [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ] );
        if ( ! $res ) {
            throw new \RuntimeException( 'openssl_pkey_new() failed: ' . openssl_error_string() );
        }
        $this->private_key = $res;
        $details           = openssl_pkey_get_details( $res );
        $this->public_pem  = $details['key'];
    }

    /** Returns the PEM public key (used to pre-seed the transient). */
    public function public_pem(): string {
        return $this->public_pem;
    }

    /**
     * Build a minimal JWKS JSON body containing the factory's public key.
     *
     * The n/e values are extracted from the key details so the validator's
     * jwk_rsa_to_pem() path is exercised end-to-end.
     */
    public function jwks_body(): string {
        $details = openssl_pkey_get_details( $this->private_key );
        $n_b64u  = rtrim( strtr( base64_encode( $details['rsa']['n'] ), '+/', '-_' ), '=' );
        $e_b64u  = rtrim( strtr( base64_encode( $details['rsa']['e'] ), '+/', '-_' ), '=' );

        return json_encode( [
            'keys' => [
                [
                    'kty' => 'RSA',
                    'use' => 'sig',
                    'alg' => 'RS256',
                    'n'   => $n_b64u,
                    'e'   => $e_b64u,
                ],
            ],
        ] );
    }

    /**
     * Build and sign a JWT with the given payload overrides.
     *
     * Supports shorthand keys for convenience in tests:
     *   'age'        → aldersverificeringdk_verification_age
     *   'result'     → aldersverificeringdk_verification_result
     *   'exp_offset' → exp = time() + $value  (default: +300)
     *
     * Any other key is passed directly into the payload.
     */
    public function make_jwt( array $overrides = [] ): string {
        $header = $this->b64u( json_encode( [ 'alg' => 'RS256', 'typ' => 'JWT' ] ) );

        $exp_offset = isset( $overrides['exp_offset'] ) ? (int) $overrides['exp_offset'] : 300;
        unset( $overrides['exp_offset'] );

        if ( array_key_exists( 'age', $overrides ) ) {
            $overrides['aldersverificeringdk_verification_age'] = $overrides['age'];
            unset( $overrides['age'] );
        }
        if ( array_key_exists( 'result', $overrides ) ) {
            $overrides['aldersverificeringdk_verification_result'] = $overrides['result'];
            unset( $overrides['result'] );
        }

        $payload = array_merge(
            [
                'aldersverificeringdk_verification_result' => true,
                'aldersverificeringdk_verification_age'    => 18,
                'exp' => time() + $exp_offset,
                'iat' => time(),
                'iss' => 'https://test.aldersverificering.dk',
            ],
            $overrides
        );
        $payload_enc = $this->b64u( json_encode( $payload ) );

        $signing_input = $header . '.' . $payload_enc;
        openssl_sign( $signing_input, $signature, $this->private_key, OPENSSL_ALGO_SHA256 );

        return $signing_input . '.' . $this->b64u( $signature );
    }

    /**
     * Build a JWT signed with a DIFFERENT (wrong) private key — signature will
     * fail verification against the injected public key.
     */
    public function make_tampered_jwt(): string {
        $header      = $this->b64u( json_encode( [ 'alg' => 'RS256', 'typ' => 'JWT' ] ) );
        $payload_enc = $this->b64u( json_encode( [
            'aldersverificeringdk_verification_result' => true,
            'aldersverificeringdk_verification_age'    => 18,
            'exp' => time() + 300,
        ] ) );
        $signing_input = $header . '.' . $payload_enc;

        // Sign with a fresh key that doesn't match the injected public key.
        $wrong_key = openssl_pkey_new( [ 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ] );
        openssl_sign( $signing_input, $signature, $wrong_key, OPENSSL_ALGO_SHA256 );

        return $signing_input . '.' . $this->b64u( $signature );
    }

    /** Build a JWT with alg=none (no signature). */
    public function make_alg_none_jwt(): string {
        $header  = $this->b64u( json_encode( [ 'alg' => 'none', 'typ' => 'JWT' ] ) );
        $payload = $this->b64u( json_encode( [ 'exp' => time() + 300 ] ) );
        return $header . '.' . $payload . '.';
    }

    /** Build a JWT with alg=HS256 header (algorithm confusion attempt). */
    public function make_hs256_jwt(): string {
        $header  = $this->b64u( json_encode( [ 'alg' => 'HS256', 'typ' => 'JWT' ] ) );
        $payload = $this->b64u( json_encode( [ 'exp' => time() + 300 ] ) );
        $sig     = $this->b64u( hash_hmac( 'sha256', $header . '.' . $payload, 'secret', true ) );
        return $header . '.' . $payload . '.' . $sig;
    }

    // ------------------------------------------------------------------

    private function b64u( string $data ): string {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
    }
}
