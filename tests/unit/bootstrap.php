<?php
/**
 * Bootstrap for unit tests.
 *
 * Loads production helpers with the minimum WordPress and WooCommerce test
 * doubles needed by Brain Monkey.
 */

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', '/tmp/wp/' );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
    define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
    define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( $text ) {
        return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
    }
}

if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        private $code;
        private $message;
        private $data;

        public function __construct( $code = '', $message = '', $data = '' ) {
            $this->code    = $code;
            $this->message = $message;
            $this->data    = $data;
        }

        public function get_error_message() {
            return $this->message;
        }

        public function get_error_code() {
            return $this->code;
        }

        public function get_error_data() {
            return $this->data;
        }
    }
}

class UNQ_Agev_Test_Cart {
    public function get_cart() {
        return $GLOBALS['unq_agev_test_cart_items'];
    }
}

class UNQ_Agev_Test_WooCommerce {
    public $cart;

    public function __construct() {
        $this->cart = new UNQ_Agev_Test_Cart();
    }
}

$GLOBALS['unq_agev_test_cart_items'] = array();

if ( ! function_exists( 'WC' ) ) {
    function WC() {
        static $woocommerce = null;
        if ( null === $woocommerce ) {
            $woocommerce = new UNQ_Agev_Test_WooCommerce();
        }
        return $woocommerce;
    }
}

require_once dirname( __DIR__, 2 ) . '/includes/class-unq-jwt-validator.php';
require_once dirname( __DIR__, 2 ) . '/includes/functions.php';
