<?php

namespace UNQVerify\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/class-unq-admin.php';

class AdminVariationProductDouble {
    private $id;
    private $parent_id;
    public $meta;
    public $save_count = 0;

    public function __construct( $id, $parent_id, array $meta = array() ) {
        $this->id        = $id;
        $this->parent_id = $parent_id;
        $this->meta      = $meta;
    }

    public function is_type( $type ) {
        return 'variation' === $type;
    }

    public function get_id() {
        return $this->id;
    }

    public function get_parent_id() {
        return $this->parent_id;
    }

    public function get_meta( $key ) {
        return $this->meta[ $key ] ?? '';
    }

    public function update_meta_data( $key, $value ) {
        $this->meta[ $key ] = $value;
    }

    public function delete_meta_data( $key ) {
        unset( $this->meta[ $key ] );
    }

    public function save_meta_data() {
        ++$this->save_count;
    }
}

class AdminVariationTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html_e' )->alias( function ( $text ) {
            echo esc_html( $text );
        } );
        Functions\when( 'esc_attr' )->alias( function ( $value ) {
            return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
        } );
        Functions\when( 'checked' )->alias( function ( $checked ) {
            if ( $checked ) {
                echo 'checked="checked"';
            }
        } );
        Functions\when( 'has_filter' )->justReturn( false );
        Functions\when( 'get_post_meta' )->justReturn( '' );
        Functions\when( 'get_the_terms' )->justReturn( array() );
        Functions\when( 'get_term_meta' )->justReturn( '' );
        Functions\when( 'get_option' )->alias( function ( $option, $default = null ) {
            return $default;
        } );
        Functions\when( 'absint' )->alias( function ( $value ) {
            return abs( (int) $value );
        } );
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'sanitize_text_field' )->alias( function ( $value ) {
            return trim( (string) $value );
        } );
        Functions\when( 'wp_unslash' )->returnArg();
        $_POST = array();
    }

    protected function tearDown(): void {
        $_POST = array();
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_registers_renderer_on_unconditional_variation_hook(): void {
        Actions\expectAdded( 'woocommerce_product_after_variable_attributes' )
            ->once()
            ->with( 'unq_agev_render_variation_fields', 20, 3 );
        Actions\expectAdded( 'woocommerce_variation_options_inventory' )->never();
        Actions\expectAdded( 'woocommerce_variation_option_inventory' )->never();
        Actions\expectAdded( 'woocommerce_save_product_variation' )
            ->once()
            ->with( 'unq_agev_save_variation_fields', 20, 2 );

        \UNQ_Admin::register();

        $this->addToAssertionCount( 1 );
    }

    public function test_renders_indexed_fields_for_unchecked_variation(): void {
        $product = new AdminVariationProductDouble( 23, 19 );
        Functions\when( 'wc_get_product' )->justReturn( $product );
        $post     = (object) array( 'ID' => 23 );

        ob_start();
        \unq_agev_render_variation_fields( 4, array(), $post );
        $output = ob_get_clean();

        $this->assertSame( 1, substr_count( $output, 'unq-agev-variation-fields' ) );
        $this->assertStringContainsString( 'name="unq_agev_variation_required[4]"', $output );
        $this->assertStringContainsString( 'name="unq_agev_variation_age[4]"', $output );
        $this->assertStringContainsString( 'unq-agev-variation-age" hidden', $output );
        $this->assertStringNotContainsString( 'show_if_variation_manage_stock', $output );
    }

    public function test_renders_checked_state_and_age_override(): void {
        $product = new AdminVariationProductDouble(
            23,
            19,
            array(
                '_unq_agev_required'     => 'yes',
                '_unq_agev_required_age' => '21',
            )
        );
        Functions\when( 'wc_get_product' )->justReturn( $product );
        $post = (object) array( 'ID' => 23 );

        ob_start();
        \unq_agev_render_variation_fields( 2, array(), $post );
        $output = ob_get_clean();

        $this->assertStringContainsString( 'checked="checked"', $output );
        $this->assertStringContainsString( 'value="21"', $output );
        $this->assertStringNotContainsString( 'unq-agev-variation-age" hidden', $output );
    }

    public function test_does_not_render_for_invalid_product(): void {
        Functions\when( 'wc_get_product' )->justReturn( false );
        $post = (object) array( 'ID' => 999 );

        ob_start();
        \unq_agev_render_variation_fields( 0, array(), $post );

        $this->assertSame( '', ob_get_clean() );
    }

    public function test_saves_direct_rule_and_valid_age_from_matching_loop(): void {
        $product = new AdminVariationProductDouble( 23, 19 );
        Functions\when( 'wc_get_product' )->justReturn( $product );
        $_POST = array(
            'unq_agev_variation_required' => array( 3 => 'yes' ),
            'unq_agev_variation_age'      => array( 3 => '21' ),
        );

        \unq_agev_save_variation_fields( 23, 3 );

        $this->assertSame( 'yes', $product->meta['_unq_agev_required'] );
        $this->assertSame( 21, $product->meta['_unq_agev_required_age'] );
        $this->assertSame( 1, $product->save_count );
    }

    public function test_unchecked_rule_deletes_stale_variation_metadata(): void {
        $product = new AdminVariationProductDouble(
            23,
            19,
            array(
                '_unq_agev_required'     => 'yes',
                '_unq_agev_required_age' => 21,
            )
        );
        Functions\when( 'wc_get_product' )->justReturn( $product );

        \unq_agev_save_variation_fields( 23, 3 );

        $this->assertArrayNotHasKey( '_unq_agev_required', $product->meta );
        $this->assertArrayNotHasKey( '_unq_agev_required_age', $product->meta );
        $this->assertSame( 1, $product->save_count );
    }

    public function test_invalid_age_is_not_persisted(): void {
        $product = new AdminVariationProductDouble(
            23,
            19,
            array( '_unq_agev_required_age' => 18 )
        );
        Functions\when( 'wc_get_product' )->justReturn( $product );
        $_POST = array(
            'unq_agev_variation_required' => array( 3 => 'yes' ),
            'unq_agev_variation_age'      => array( 3 => '121' ),
        );

        \unq_agev_save_variation_fields( 23, 3 );

        $this->assertSame( 'yes', $product->meta['_unq_agev_required'] );
        $this->assertArrayNotHasKey( '_unq_agev_required_age', $product->meta );
    }

    /**
     * @dataProvider validAgeProvider
     */
    public function test_accepts_age_boundaries( $age ): void {
        $product = new AdminVariationProductDouble( 23, 19 );
        Functions\when( 'wc_get_product' )->justReturn( $product );
        $_POST = array(
            'unq_agev_variation_required' => array( 3 => 'yes' ),
            'unq_agev_variation_age'      => array( 3 => (string) $age ),
        );

        \unq_agev_save_variation_fields( 23, 3 );

        $this->assertSame( $age, $product->meta['_unq_agev_required_age'] );
    }

    public function validAgeProvider(): array {
        return array(
            'minimum' => array( 1 ),
            'maximum' => array( 120 ),
        );
    }

    public function test_does_not_save_without_edit_capability(): void {
        $product = new AdminVariationProductDouble( 23, 19 );
        Functions\when( 'wc_get_product' )->justReturn( $product );
        Functions\when( 'current_user_can' )->justReturn( false );
        $_POST = array(
            'unq_agev_variation_required' => array( 3 => 'yes' ),
        );

        \unq_agev_save_variation_fields( 23, 3 );

        $this->assertSame( array(), $product->meta );
        $this->assertSame( 0, $product->save_count );
    }
}