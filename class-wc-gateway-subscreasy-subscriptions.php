<?php
defined( 'ABSPATH' ) or exit;

class WC_Gateway_Subscreasy_Subscriptions extends WC_Gateway_Subscreasy {
    private $logger;

    public function __construct() {
        parent::__construct();

        if ( !class_exists( 'WC_Subscriptions_Order' ) ) {
            // TODO error log
            return;
        }

        add_action( 'woocommerce_scheduled_subscription_payment_subscreasy', array( $this, 'scheduled_subscription_payment' ), 10, 2 );

        add_action( 'wcs_resubscribe_order_created', array( $this, 'delete_resubscribe_meta' ), 10 );
        add_action( 'wcs_renewal_order_created', array( $this, 'delete_renewal_meta' ), 10 );
        add_action( 'woocommerce_subscription_failing_payment_method_updated_subscreasy', array( $this, 'update_failing_payment_method' ), 10, 2 );
        add_action( 'wc_stripe_cards_payment_fields', array( $this, 'display_update_subs_payment_checkout' ) );
        add_action( 'wc_stripe_add_payment_method_' . $this->id . '_success', array( $this, 'handle_add_payment_method_success' ), 10, 2 );

        // display the credit card used for a subscription in the "My Subscriptions" table
        add_filter( 'woocommerce_my_subscriptions_payment_method', array( $this, 'maybe_render_subscription_payment_method' ), 10, 2 );

        // allow store managers to manually set Stripe as the payment method on a subscription
        add_filter( 'woocommerce_subscription_payment_meta', array( $this, 'add_subscription_payment_meta' ), 10, 2 );
        add_filter( 'woocommerce_subscription_validate_payment_meta', array( $this, 'validate_subscription_payment_meta' ), 10, 2 );
        add_filter( 'wc_stripe_display_save_payment_method_checkbox', array( $this, 'maybe_hide_save_checkbox' ) );

        /*
         * WC subscriptions hooks into the "template_redirect" hook with priority 100.
         * If the screen is "Pay for order" and the order is a subscription renewal, it redirects to the plain checkout.
         * See: https://github.com/woocommerce/woocommerce-subscriptions/blob/99a75687e109b64cbc07af6e5518458a6305f366/includes/class-wcs-cart-renewal.php#L165
         * If we are in the "You just need to authorize SCA" flow, we don't want that redirection to happen.
         */
        add_action( 'template_redirect', array( $this, 'remove_order_pay_var' ), 99 );
        add_action( 'template_redirect', array( $this, 'restore_order_pay_var' ), 101 );
    }

    public function scheduled_subscription_payment( $amount_to_charge, $renewal_order ) {
        $this->process_subscription_payment( $amount_to_charge, $renewal_order, true, false );
    }

    public function process_subscription_payment( $amount = 0.0, $renewal_order, $retry = true, $previous_error ) {
        try {
            if ( $amount * 100 < WC_Subscreasy_Util::get_minimum_amount() ) {
                /* translators: minimum amount */
                $message = sprintf( __( 'Sorry, the minimum allowed order total is %1$s to use this payment method.', 'woocommerce-gateway-stripe' ), wc_price( WC_Stripe_Helper::get_minimum_amount() / 100 ) );
                throw new WC_Subscreasy_Exception(
                    'Error while processing renewal order ' . $renewal_order->get_id() . ' : ' . $message, $message
                );
            }

            $order_id = $renewal_order->get_id();
            $this->ensure_subscription_has_customer_id($order_id);

            $this->log("Begin processing subscription payment for order {$order_id} for the amount of {$amount}");
            $subscription_id = $renewal_order->get_meta("_subscription_renewal");
            $card_id = get_post_meta($subscription_id, SUBSCREASY_CARD_ID_KEY, true);
            $subscreasy_customer_id = get_post_meta($subscription_id, SUBSCREASY_CUSTOMER_ID_KEY, true);
            // $subscreasy_customer_id = get_user_option( SUBSCREASY_CUSTOMER_ID_KEY, get_current_user_id() );

            $request = array (
                "subscriber" => array("id" => $subscreasy_customer_id),
                "offer" => array("name" => $this->get_order_name($order_id), "price" => $amount, "currency" => get_woocommerce_currency()),
                "savedCard" => array("id" => $card_id)
            );
            $url = $this->get_remote_api("api/pay/by-saved-card");
            $headers = $this->get_authentication_header();
            $response = $this::post($url, $request, $headers);

            if ($response["response"]["code"] != 200) {
                $renewal_order->update_status('failed');
                if ($response["response"]["code"] == 401) {
                    // TODO check api key
                } else {
                    // TODO generic error handling
                }
            } else {
                $responseBody = json_decode($response["body"]);
                if ($responseBody->status == "FAIL") {
                    $renewal_order->update_status('failed');

                    $errorLog = sprintf('Renewal request failed, errorCode: %s, error description: "%s"', $responseBody->errorCode, $responseBody->errorText);
                    $renewal_order->add_order_note($errorLog);
                    $this->log($errorLog);
                } else {
                    $renewal_order->update_status('completed', __( 'Renewal completed.', 'woocommerce' ));
                    $renewal_order->payment_complete();
                }
            }
        } catch ( WC_Subscreasy_Exception $e ) {
            $this->log('Error: ' . $e->getMessage());

            // do_action( 'wc_gateway_stripe_process_payment_error', $e, $renewal_order );

            /* translators: error message */
            $renewal_order->update_status( 'failed' );
        }
    }

    public function process_payment($order_id) {
        if ($this->has_subscription($order_id)) {
            if ($this->is_subs_change_payment()) {
                return $this->change_subs_payment_method($order_id);
            }

            // Regular payment with force customer enabled
            return parent::process_payment($order_id);
        } else {
            return parent::process_payment($order_id);
        }
    }

    public function is_subs_change_payment() {
        return ( isset( $_GET['pay_for_order'] ) && isset( $_GET['change_payment_method'] ) );
    }

    public function change_subs_payment_method($order_id) {
        try {
            $subscription = wc_get_order($order_id);
            $subscreasy_customer_id = get_post_meta($subscription->get_id(), SUBSCREASY_CUSTOMER_ID_KEY, true);

            $prepared_source = $this->save_card($subscreasy_customer_id);

            // $this->maybe_disallow_prepaid_card( $prepared_source );
            // $this->check_source( $prepared_source );
            $this->store_card_details_to_subscription($subscription, $prepared_source->id, null, null); // ==> implement for subscreasy

            do_action( 'wc_subscreasy_change_subs_payment_method_success', $prepared_source, $prepared_source );

            return array(
                'result'   => 'success',
                'redirect' => $this->get_return_url( $subscription ),
            );
        } catch ( Exception $e ) {
            return $this->request_failed($e);
        }
    }

    public function ensure_subscription_has_customer_id( $order_id ) {
        $subscreasy_customer_id = get_user_option( SUBSCREASY_CUSTOMER_ID_KEY, get_current_user_id() );
        if (!$subscreasy_customer_id) {
            $this->log(SUBSCREASY_CUSTOMER_ID_KEY . " not found in user " . get_current_user_id());
        } else {
            update_post_meta( $order_id, SUBSCREASY_CUSTOMER_ID_KEY, $subscreasy_customer_id );

            $subscriptions = wcs_get_subscriptions_for_order( $order_id, array( 'order_type' => 'any' ) );
            foreach( $subscriptions as $subscription_id => $subscription ) {
                if ( ! metadata_exists( 'post', $subscription_id, SUBSCREASY_CUSTOMER_ID_KEY ) ) {
                    update_post_meta( $subscription_id, SUBSCREASY_CUSTOMER_ID_KEY, $subscreasy_customer_id );
                }
            }
        }
    }

    public function maybe_render_subscription_payment_method( $payment_method_to_display, $subscription ) {
        $this->log("maybe_render_subscription_payment_method()");

        $customer_user = $subscription->get_customer_id();

        // bail for other payment methods
        if ( $subscription->get_payment_method() !== $this->id || ! $customer_user ) {
            return $payment_method_to_display;
        }

        $subscreasy_customer_id = get_post_meta( $subscription->get_id(), SUBSCREASY_CUSTOMER_ID_KEY, true );
        $subscreasy_card_id   = get_post_meta( $subscription->get_id(), SUBSCREASY_CARD_ID_KEY, true );
        if ($subscreasy_card_id == '') {
            $this->log("card id does not exist");
        } else {
            $this->log("card id exists");
        }
        $saved_card = $this->get_saved_card($subscreasy_card_id);

        $payment_method_to_display = empty($saved_card->lastFourDigits) ? $saved_card->binNumber : sprintf(__('Via %1$s card ending in %2$s', 'wc-gateway-subscreasy'), $saved_card->cardAssociation, $saved_card->lastFourDigits);

        return $payment_method_to_display;
    }

    /**
     * Is $order_id a subscription?
     * @param  int  $order_id
     * @return boolean
     */
    public function has_subscription( $order_id ) {
        return ( function_exists( 'wcs_order_contains_subscription' ) && ( wcs_order_contains_subscription( $order_id ) || wcs_is_subscription( $order_id ) || wcs_order_contains_renewal( $order_id ) ) );
    }

    public function delete_renewal_meta( $renewal_order ) {
        WC_Subscreasy_Util::delete_subscreasy_data( $renewal_order );

        // delete paymentId
        delete_post_meta( $renewal_order->get_id(),SUBSCREASY_PAYMENT_ID_KEY);

        return $renewal_order;
    }

    /**
     * Include the payment meta data required to process automatic recurring payments so that store managers can
     * manually set up automatic recurring payments for a customer via the Edit Subscriptions screen in 2.0+.
     *
     * @since 2.5
     * @param array $payment_meta associative array of meta data required for automatic payments
     * @param WC_Subscription $subscription An instance of a subscription object
     * @return array
     */
    public function add_subscription_payment_meta( $payment_meta, $subscription ) {
        $subscription_id = $subscription->get_id();
        $source_id          = get_post_meta($subscription_id, SUBSCREASY_CARD_ID_KEY, true);
        $subscreasy_cust_id = get_post_meta($subscription_id, SUBSCREASY_CUSTOMER_ID_KEY, true);

        $payment_meta[ $this->id ] = array(
            'post_meta' => array(
                SUBSCREASY_CUSTOMER_ID_KEY => array(
                    'value' => $subscreasy_cust_id,
                    'label' => 'Subscreasy Customer ID',
                ),
                SUBSCREASY_CARD_ID_KEY => array(
                    'value' => $source_id,
                    'label' => 'Subscreasy Card ID',
                ),
            ),
        );

        return $payment_meta;
    }

    /**
     * Add payment method via account screen.
     * We don't store the token locally, but to the Stripe API.
     *
     * @since 3.0.0
     * @version 4.0.0
     */
    public function add_payment_method() {
        $error     = false;
        $error_msg = __( 'There was a problem adding the payment method.', 'woocommerce-gateway-subscreasy' );

        $subscreasy_customer_id = get_user_option( SUBSCREASY_CUSTOMER_ID_KEY, get_current_user_id() );
        try {
            $saved_card = $this->save_card($subscreasy_customer_id);
            $this->log("save_card success: " . json_encode($saved_card));
        } catch (Exception $e) {
            $this->log("save_card failure: " . $e);
        }

        $this->associate_saved_card_with_customer($saved_card->id);
        $this->log("associate_saved_card_with_customer success");

        if ( $error ) {
            wc_add_notice( $error_msg, 'error' );
            return;
        }

        do_action( 'wc_subscreasy_add_payment_method_' . $_POST['payment_method'] . '_success', $saved_card->id, $saved_card );

        return array(
            'result'   => 'success',
            'redirect' => wc_get_endpoint_url( 'payment-methods' ),
        );
    }


    public function update_failing_payment_method( $subscription, $renewal_order ) {
        update_post_meta( $subscription->get_id(), '_subscreasy_customer_id', $renewal_order->get_meta( '_subscreasy_customer_id', true ) );
        update_post_meta( $subscription->get_id(), '_subscreasy_card_id', $renewal_order->get_meta( '_subscreasy_card_id', true ) );
    }

    function restore_order_pay_var() {

    }

    function remove_order_pay_var() {

    }

    function maybe_hide_save_checkbox() {

    }

    function display_update_subs_payment_checkout() {

    }

}