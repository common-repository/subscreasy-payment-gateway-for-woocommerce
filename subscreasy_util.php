<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_Subscreasy_Util {
    /**
     * Checks Stripe minimum order value authorized per currency
     */
    public static function get_minimum_amount() {
        // Check order amount
        switch ( get_woocommerce_currency() ) {
            default:
                $minimum_amount = 0;
                break;
        }

        return $minimum_amount;
    }

    public static function delete_subscreasy_data( $order = null ) {
        if ( is_null( $order ) ) {
            return false;
        }
    }

    public static function translateException($response) {
        $e = null;
        if ($response == 401) {
            $e = new Exception("Configuration error. Please check your Subscreasy API key");
        } else if ($response == 404) {
            $e = new Exception('Connection error.');
        } else {
            // TODO generic error handling
            // TODO do not continue to the next step, return to checkout page and display an error to the user
            $e = new Exception($response, null);
        }
        return $e;
    }
}