# UNQVerify Age Verification for WooCommerce

MitID-based age verification for WooCommerce checkout using the [UNQVerify SDK](https://unqverify.com).

---

## Requirements

- WordPress 6.0+
- WooCommerce 7.0+
- PHP 7.4+

---

## Installation

1. Upload the plugin folder to `wp-content/plugins/`.
2. Activate via **Plugins** in the WordPress admin.
3. Go to **WooCommerce → Settings → UNQVerify** and enter your public key.

---

## Settings

| Setting | Option | Values | Description |
|---|---|---|---|
| Enable Age Verification | `unq_agev_enabled` | `yes` / `no` | Activate or deactivate the gate at checkout |
| Public Key | `unq_agev_public_key` | string | Your UNQVerify public key |
| Required Age | `unq_agev_required_age` | integer ≥ 1 | Minimum age required (default: 18) |
| Verification Mode | `unq_agev_verification_mode` | `popup` / `redirect` | How the MitID flow opens |
| Popup Language | `unq_agev_locale` | `auto` / `en` / `da` | Language used in the customer-facing popup. `auto` inherits the WP site language (Danish for `da_DK`, English otherwise). |

---

## Development

### Running tests

```bash
# Install PHP dependencies
composer install

# Install JS dev dependencies (gettext compiler, etc.)
pnpm install

# Run unit tests
pnpm test

# Compile .po → .mo translation files
pnpm make:mo
```

### Unit test coverage

| # | Test class | Test name | What it verifies |
|---|---|---|---|
| 1 | `JwtValidatorInputTest` | `test_empty_string_returns_missing_token_error` | Empty string → `unqverify_missing_token` |
| 2 | `JwtValidatorInputTest` | `test_null_returns_missing_token_error` | `null` input → `unqverify_missing_token` |
| 3 | `JwtValidatorInputTest` | `test_two_segment_jwt_returns_malformed_error` | `header.payload` (no signature segment) → `unqverify_malformed` |
| 4 | `JwtValidatorInputTest` | `test_alg_none_returns_bad_alg_error` | `alg=none` unsigned token attack → `unqverify_bad_alg` |
| 5 | `JwtValidatorInputTest` | `test_alg_hs256_returns_bad_alg_error` | `alg=HS256` algorithm confusion attack → `unqverify_bad_alg` |
| 6 | `JwtValidatorCryptoTest` | `test_valid_jwt_age_equal_to_required_returns_true` | Valid RS256 JWT, age = 18, threshold = 18 → `true` |
| 7 | `JwtValidatorCryptoTest` | `test_valid_jwt_age_above_required_returns_true` | Valid RS256 JWT, age = 25, threshold = 18 → `true` |
| 8 | `JwtValidatorCryptoTest` | `test_valid_jwt_age_below_required_returns_under_age_error` | Valid signature but age = 17, threshold = 18 → `unqverify_under_age` |
| 9 | `JwtValidatorCryptoTest` | `test_jwt_with_failed_verification_result_returns_not_verified_error` | `verification_result = false` claim → `unqverify_not_verified` |
| 10 | `JwtValidatorCryptoTest` | `test_expired_jwt_returns_expired_error` | `exp` in the past → `unqverify_expired` |
| 11 | `JwtValidatorCryptoTest` | `test_tampered_payload_returns_invalid_signature_error` | Payload signed with wrong key → `unqverify_invalid_signature` |
| 12 | `JwkToPemTest` | `test_valid_jwk_round_trips_to_openssl_accepted_pem` | JWK → DER → PEM roundtrip; `openssl_get_publickey()` accepts it |
| 13 | `JwkToPemTest` | `test_transient_cache_hit_returns_pem_without_http` | Cached PEM is returned; `wp_remote_get` is never called |
| 14 | `JwkToPemTest` | `test_http_failure_with_stale_cache_returns_stale_pem` | Network error + stale option exists → returns stale PEM (graceful degradation) |
| 15 | `JwkToPemTest` | `test_http_failure_without_stale_cache_returns_error` | Network error + no stale → `unqverify_key_fetch_failed` |
| 16 | `JwkToPemTest` | `test_http_404_returns_key_http_error` | JWKS endpoint returns HTTP 404 → `unqverify_key_http_error` |
| 17 | `JwkToPemTest` | `test_empty_jwks_keys_returns_key_invalid_error` | `{ "keys": [] }` response → `unqverify_key_invalid` |
| 18 | `JwkToPemTest` | `test_non_rsa_only_jwks_returns_key_not_found_error` | JWKS contains only an EC key, no RSA → `unqverify_key_not_found` |
| 19 | `JwkToPemTest` | `test_invalid_json_response_returns_key_invalid_error` | Non-JSON response body → `unqverify_key_invalid` |
| 20 | `SettingsTest` | `test_enabled_returns_yes_when_option_not_set` | `unq_agev_get('enabled')` default → `'yes'` |
| 21 | `SettingsTest` | `test_required_age_stored_as_zero_is_clamped_to_one` | Stored value `'0'` → clamped to `1` via `max(1, …)` |
| 22 | `SettingsTest` | `test_invalid_mode_falls_back_to_popup` | Unknown mode string → falls back to `'popup'` |
| 23 | `SettingsTest` | `test_unknown_key_returns_empty_string` | Any unrecognised key → `''`; `get_option` never called |
| 24 | `SettingsTest` | `test_locale_default_returns_auto` | `unq_agev_get('locale')` default → `'auto'` |
| 25 | `SettingsTest` | `test_invalid_locale_falls_back_to_auto` | Unknown locale value → falls back to `'auto'` |
| 26 | `SettingsTest` | `test_strings_returns_english_for_en` | `unq_agev_strings('en')` → English copy |
| 27 | `SettingsTest` | `test_strings_returns_danish_for_da` | `unq_agev_strings('da')` → Danish copy |
| 28 | `SettingsTest` | `test_strings_falls_back_to_en_for_unknown_locale` | Unknown locale → falls back to EN strings |
| 29 | `SettingsTest` | `test_strings_interpolates_age_in_modal_body` | Age value (21) appears in `modalBody` string |
| 30 | `SettingsTest` | `test_resolve_locale_returns_en_when_set_to_en` | Setting `'en'` → `unq_agev_resolve_locale()` returns `'en'` |
| 31 | `SettingsTest` | `test_resolve_locale_returns_da_when_set_to_da` | Setting `'da'` → `unq_agev_resolve_locale()` returns `'da'` |
