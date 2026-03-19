<?php
/**
 * Uninstall script — runs when the plugin is deleted from the WordPress admin.
 *
 * Removes all options, transients, post meta, and term meta created by the plugin
 * so no orphaned data is left in the database.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// ---------------------------------------------------------------------------
// Plugin options saved by the settings page.
// ---------------------------------------------------------------------------
$options = array(
    'unq_agev_enabled',
    'unq_agev_public_key',
    'unq_agev_test_public_key',
    'unq_agev_use_production',
    'unq_agev_required_age',
    'unq_agev_verification_mode',
    'unq_agev_locale',
    'unq_agev_targeting',
);
foreach ( $options as $option ) {
    delete_option( $option );
}

// ---------------------------------------------------------------------------
// Stale JWKS cache options (set by UNQ_JWT_Validator as a fallback store).
// ---------------------------------------------------------------------------
delete_option( 'unqverify_pubkey_stale_test' );
delete_option( 'unqverify_pubkey_stale_live' );

// ---------------------------------------------------------------------------
// Transients.
// ---------------------------------------------------------------------------
delete_transient( 'unqverify_pubkey_test' );
delete_transient( 'unqverify_pubkey_live' );
delete_transient( 'unq_agev_save_errors' );

// ---------------------------------------------------------------------------
// Term meta — category-level gating flags and age overrides.
// ---------------------------------------------------------------------------
if ( function_exists( 'get_terms' ) && function_exists( 'delete_term_meta' ) ) {
    // Fetch all product categories that have either plugin meta key set.
    $gated_terms = get_terms( array(
        'taxonomy'   => 'product_cat',
        'hide_empty' => false,
        'fields'     => 'ids',
        'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
            'relation' => 'OR',
            array( 'key' => 'unq_agev_category_required' ),
            array( 'key' => 'unq_agev_category_required_age' ),
        ),
    ) );

    if ( is_array( $gated_terms ) ) {
        foreach ( $gated_terms as $term_id ) {
            delete_term_meta( (int) $term_id, 'unq_agev_category_required' );
            delete_term_meta( (int) $term_id, 'unq_agev_category_required_age' );
        }
    }
}

// ---------------------------------------------------------------------------
// Post meta — product-level gating flag and age override.
// delete_post_meta_by_key() removes the meta row from ALL posts in one query.
// ---------------------------------------------------------------------------
if ( function_exists( 'delete_post_meta_by_key' ) ) {
    delete_post_meta_by_key( '_unq_agev_required' );
    delete_post_meta_by_key( '_unq_agev_required_age' );
}
