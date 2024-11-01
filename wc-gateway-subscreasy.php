<?php
/**
 * Plugin Name: Subscreasy Payment Gateway Plugin for WooCommerce and Subscriptions
 * Plugin URI: https://www.subscreasy.com/
 * Description: A payment gateway plugin for WooCommerce with subscriptions support. iyzico, PayU, PayTR payment service
 * providers can be used with this plugin.
 * Author: Subscreasy
 * Author URI: https://www.subscreasy.com/
 * Version: 1.1.2
 * Text Domain: wc-gateway-subscreasy
 * Domain Path: /i18n/languages/
 * WC requires at least: 3.0.0
 * WC tested up to: 4.7.0
 *
 * @package   WC-Gateway-Subscreasy
 * @author    Halil Karakose
 * @category  Payment, Subscriptions, Recurring Charging
 * @copyright Copyright (c) 2017-2022, Subscreasy Yazılım A.Ş.
 *
 * This plugin provides Subscreasy integration and enables recurring payments over iyzico, PayTR, PayU. This plugin
 * requires 'Woocommerce' and 'Woocommerce Subscription' plugins.
 */

defined( 'ABSPATH' ) or exit;

// Make sure WooCommerce is active
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
    return;
}

define('WC_SUBSCREASY_PLUGIN_URL', untrailingslashit(plugins_url(basename(plugin_dir_path(__FILE__)), basename(__FILE__))));
define('WC_SUBSCREASY_VERSION', '1.0.1');
define('WC_SUBSCREASY_MAIN_FILE', __FILE__);

define('SUBSCREASY_CUSTOMER_ID_KEY',            "subscreasy_customer_id");
define('SUBSCREASY_CUSTOMER_SECURE_ID_KEY',     "subscreasy_customer_secure_id");
define('SUBSCREASY_PAYMENT_ID_KEY',             "subscreasy_payment_id");
define('SUBSCREASY_PAYMENT_TRANSACTION_ID_KEY', "subscreasy_payment_transaction_id");
define('SUBSCREASY_CARD_ID_KEY',                "subscreasy_card_id");

/**
 * Subscreasy Payment Gateway
 *
 * Provides an Offline Payment Gateway; mainly for testing purposes.
 * We load it later to ensure WC is loaded first since we're extending it.
 *
 * @class 		WC_Gateway_Subscreasy
 * @extends		WC_Payment_Gateway
 * @version		1.0.0
 * @package		WooCommerce/Classes/Payment
 * @author 		Halil
 */
add_action( 'plugins_loaded', 'wc_subscreasy_gateway_init');
function wc_subscreasy_gateway_init() {
    class WC_Subscreasy {
        private static $instance;

        public static function get_instance() {
            if ( null === self::$instance ) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        public function __construct() {
            require_once dirname( __FILE__ ) . '/subscreasy_util.php';
            require_once dirname( __FILE__ ) . '/class-wc-purchase-response.php';
            require_once dirname( __FILE__ ) . '/class-wc-payment-form.php';
            require_once dirname( __FILE__ ) . '/class-wc-gateway-subscreasy.php';
            require_once dirname( __FILE__ ) . '/class-wc-gateway-subscreasy-subscriptions.php';
            require_once dirname( __FILE__ ) . '/class-wc-gateway-subscreasy-customer.php';

            add_filter( 'woocommerce_payment_gateways', array( $this, 'add_gateways' ) );

            $plugin_basename = plugin_basename(__FILE__);
            add_filter( 'plugin_action_links_' . $plugin_basename, array( $this, 'plugin_action_links' ) );

            load_plugin_textdomain( 'wc-gateway-subscreasy', false, basename( dirname( __FILE__ ) ) . '/languages' );
        }

        public function plugin_action_links( $links ) {
            $plugin_links = array(
                '<a href="admin.php?page=wc-settings&tab=checkout&section=subscreasy">' . esc_html__( 'Settings', 'woocommerce-gateway-subscreasy' ) . '</a>',
            );
            return array_merge( $plugin_links, $links );
        }

        function add_gateways( $methods ) {
            if ( class_exists( 'WC_Subscriptions_Order' ) && function_exists( 'wcs_create_renewal_order' ) ) {
                $methods[] = 'WC_Gateway_Subscreasy_Subscriptions';
            } else {
                $methods[] = 'WC_Gateway_Subscreasy';
            }

            return $methods;
        }

    }

    WC_Subscreasy::get_instance();
}