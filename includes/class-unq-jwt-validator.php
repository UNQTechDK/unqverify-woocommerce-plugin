<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Validates the unqverify_token JWT cookie server-side.
 *
 * Intentionally has zero external dependencies — no Composer, no vendor folder.
 * Uses openssl_verify() with the RS256 public key fetched from the UNQVerify
 * well-known endpoint and cached as a WP transient.
 */
class UNQ_JWT_Validator {

    const TEST_JWKS_URL = 'https://test.api.aldersverificering.dk/well-known/openid-configuration/jwks';
    const LIVE_JWKS_URL = 'https://api.aldersverificering.dk/well-known/openid-configuration/jwks';
    const TRANSIENT_TTL = DAY_IN_SECONDS;
    const ALLOWED_ALG   = 'RS256';

    /**
     * Fetch and cache the RS256 public key (PEM) from the JWKS endpoint.
     *
     * Automatically selects the test or live endpoint based on whether the
     * configured public key starts with 'pk_test_'.
     *
     * @return string|WP_Error PEM string or WP_Error on failure.
     */
    public static function get_public_key() {
        // Use the ACTIVE key (respects test/production toggle) to select the
        // correct JWKS endpoint. Using the raw 'public_key' option instead would
        // always hit the live endpoint when the production key field is empty,
        // breaking every test-mode verification for new merchants.
        $active_key   = unq_agev_active_key();
        $is_test      = ( strpos( $active_key, 'pk_test_' ) === 0 );
        $jwks_url     = $is_test ? self::TEST_JWKS_URL : self::LIVE_JWKS_URL;
        $transient    = $is_test ? 'unqverify_pubkey_test' : 'unqverify_pubkey_live';
        $stale_option = $is_test ? 'unqverify_pubkey_stale_test' : 'unqverify_pubkey_stale_live';

        $cached = get_transient( $transient );
        if ( false !== $cached ) {
            return $cached;
        }

        $response = wp_remote_get(
            $jwks_url,
            array(
                'timeout'   => 5,
                'sslverify' => true,
            )
        );

        if ( is_wp_error( $response ) ) {
            // Extend stale cache by 1 hour rather than hard-blocking checkout.
            $stale = get_option( $stale_option );
            if ( $stale ) {
                set_transient( $transient, $stale, HOUR_IN_SECONDS );
                return $stale;
            }
            return new WP_Error(
                'unqverify_key_fetch_failed',
                'Could not fetch UNQVerify JWKS: ' . $response->get_error_message()
            );
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== (int) $code ) {
            return new WP_Error(
                'unqverify_key_http_error',
                'UNQVerify JWKS endpoint returned HTTP ' . $code
            );
        }

        $body = wp_remote_retrieve_body( $response );
        $jwks = json_decode( $body, true );

        if ( ! is_array( $jwks ) || empty( $jwks['keys'] ) ) {
            return new WP_Error( 'unqverify_key_invalid', 'UNQVerify JWKS response is not valid JSON or contains no keys.' );
        }

        // Find the first RSA key intended for signing (RS256).
        $jwk = null;
        foreach ( $jwks['keys'] as $key ) {
            if ( isset( $key['kty'] ) && 'RSA' === $key['kty'] &&
                 ( ! isset( $key['use'] ) || 'sig' === $key['use'] ) &&
                 ( ! isset( $key['alg'] ) || 'RS256' === $key['alg'] ) &&
                 ! empty( $key['n'] ) && ! empty( $key['e'] ) ) {
                $jwk = $key;
                break;
            }
        }

        if ( null === $jwk ) {
            return new WP_Error( 'unqverify_key_not_found', 'No RS256 RSA key found in UNQVerify JWKS.' );
        }

        $pem = self::jwk_rsa_to_pem( $jwk['n'], $jwk['e'] );
        if ( is_wp_error( $pem ) ) {
            return $pem;
        }

        set_transient( $transient, $pem, self::TRANSIENT_TTL );
        // autoload=false: the stale key is only needed when the transient is gone
        // and the live endpoint is unreachable, so it should not inflate every request.
        update_option( $stale_option, $pem, false );

        return $pem;
    }

    /**
     * Convert a JWK RSA public key (base64url n and e components) to PEM.
     *
     * Constructs a SubjectPublicKeyInfo DER structure without any external
     * dependencies — pure PHP bit manipulation.
     *
     * @param  string      $n_b64url  Base64url-encoded modulus.
     * @param  string      $e_b64url  Base64url-encoded public exponent.
     * @return string|WP_Error        PEM string on success, WP_Error on failure.
     */
    private static function jwk_rsa_to_pem( $n_b64url, $e_b64url ) {
        $n = base64_decode( strtr( $n_b64url, '-_', '+/' ) );
        $e = base64_decode( strtr( $e_b64url, '-_', '+/' ) );

        if ( ! is_string( $n ) || ! strlen( $n ) || ! is_string( $e ) || ! strlen( $e ) ) {
            return new WP_Error( 'unqverify_jwk_decode_err', 'Failed to base64url-decode JWK n/e components.' );
        }

        // Ensure integers are positive in two\'s complement: prepend 0x00 when
        // the most-significant bit is set (which would otherwise be the sign bit).
        if ( ord( $n[0] ) > 0x7f ) {
            $n = "\x00" . $n;
        }
        if ( ord( $e[0] ) > 0x7f ) {
            $e = "\x00" . $e;
        }

        // DER INTEGER: tag 0x02 + length + value.
        $n_der = "\x02" . self::der_length( strlen( $n ) ) . $n;
        $e_der = "\x02" . self::der_length( strlen( $e ) ) . $e;

        // RSAPublicKey SEQUENCE { INTEGER modulus, INTEGER publicExponent }.
        $inner = $n_der . $e_der;
        $inner = "\x30" . self::der_length( strlen( $inner ) ) . $inner;

        // BIT STRING wrapper: 0x00 prefix = zero unused bits.
        $bit_str = "\x00" . $inner;
        $bit_str = "\x03" . self::der_length( strlen( $bit_str ) ) . $bit_str;

        // AlgorithmIdentifier: rsaEncryption OID (1.2.840.113549.1.1.1) + NULL.
        $alg_id = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";

        // SubjectPublicKeyInfo SEQUENCE (PKCS#8 / OpenSSL "PUBLIC KEY" format).
        $spki = $alg_id . $bit_str;
        $spki = "\x30" . self::der_length( strlen( $spki ) ) . $spki;

        return "-----BEGIN PUBLIC KEY-----\n" .
               chunk_split( base64_encode( $spki ), 64, "\n" ) .
               "-----END PUBLIC KEY-----\n";
    }

    /**
     * Encode an integer as DER length bytes (definite short or long form).
     *
     * @param  int    $len
     * @return string Binary DER length octets.
     */
    private static function der_length( $len ) {
        if ( $len <= 0x7f ) {
            return chr( $len );
        }
        $bytes = '';
        $tmp   = $len;
        while ( $tmp > 0 ) {
            $bytes = chr( $tmp & 0xff ) . $bytes;
            $tmp >>= 8;
        }
        return chr( 0x80 | strlen( $bytes ) ) . $bytes;
    }

    /**
     * Base64url decode (JWT-safe, no padding required).
     *
     * @param  string $data
     * @return string
     */
    private static function base64url_decode( $data ) {
        // Restore standard base64 padding.
        $remainder = strlen( $data ) % 4;
        if ( $remainder ) {
            $data .= str_repeat( '=', 4 - $remainder );
        }
        return base64_decode( strtr( $data, '-_', '+/' ) );
    }

    /**
     * Validate the JWT and its age claims.
     *
     * @param  string   $jwt          Raw JWT string (three base64url segments).
     * @param  int      $required_age Minimum verified age to accept.
     * @return true|WP_Error          true on success, WP_Error on any failure.
     */
    public static function validate( $jwt, $required_age = 18 ) {
        // Sanity-check input before any processing.
        if ( empty( $jwt ) || ! is_string( $jwt ) ) {
            return new WP_Error( 'unqverify_missing_token', 'No verification token present.' );
        }

        $parts = explode( '.', $jwt );
        if ( 3 !== count( $parts ) ) {
            return new WP_Error( 'unqverify_malformed', 'Verification token is malformed.' );
        }

        list( $encoded_header, $encoded_payload, $encoded_signature ) = $parts;

        // --- Decode header and assert algorithm BEFORE verifying signature ---
        $header = json_decode( self::base64url_decode( $encoded_header ), true );
        if ( ! is_array( $header ) ) {
            return new WP_Error( 'unqverify_bad_header', 'Could not decode token header.' );
        }

        $alg = isset( $header['alg'] ) ? $header['alg'] : '';
        if ( self::ALLOWED_ALG !== $alg ) {
            // Prevent algorithm substitution attacks (e.g., alg=none, alg=HS256).
            return new WP_Error(
                'unqverify_bad_alg',
                sprintf( 'Token algorithm "%s" is not allowed. Expected RS256.', esc_html( $alg ) )
            );
        }

        // --- Fetch public key ---
        $pem = self::get_public_key();
        if ( is_wp_error( $pem ) ) {
            error_log( '[UNQVerify] Public key error: ' . $pem->get_error_message() );
            return $pem;
        }

        // --- Verify RS256 signature ---
        $signing_input = $encoded_header . '.' . $encoded_payload;
        $signature     = self::base64url_decode( $encoded_signature );

        $verify_result = openssl_verify( $signing_input, $signature, $pem, OPENSSL_ALGO_SHA256 );

        if ( 1 !== $verify_result ) {
            return new WP_Error( 'unqverify_invalid_signature', 'Token signature is invalid.' );
        }

        // --- Decode and validate claims ---
        $payload = json_decode( self::base64url_decode( $encoded_payload ), true );
        if ( ! is_array( $payload ) ) {
            return new WP_Error( 'unqverify_bad_payload', 'Could not decode token payload.' );
        }

        // Expiry check.
        $exp = isset( $payload['exp'] ) ? (int) $payload['exp'] : 0;
        if ( $exp <= time() ) {
            return new WP_Error( 'unqverify_expired', 'Verification token has expired. Please verify again.' );
        }

        // Verification result claim.
        $verified = isset( $payload['aldersverificeringdk_verification_result'] )
            ? (bool) $payload['aldersverificeringdk_verification_result']
            : false;

        if ( ! $verified ) {
            return new WP_Error( 'unqverify_not_verified', 'Age verification was not successful.' );
        }

        // Age threshold check.
        $verified_age = isset( $payload['aldersverificeringdk_verification_age'] )
            ? (int) $payload['aldersverificeringdk_verification_age']
            : 0;

        if ( $verified_age < (int) $required_age ) {
            return new WP_Error(
                'unqverify_under_age',
                sprintf(
                    'You must be at least %d years old to complete this purchase.',
                    (int) $required_age
                )
            );
        }

        return true;
    }
}
