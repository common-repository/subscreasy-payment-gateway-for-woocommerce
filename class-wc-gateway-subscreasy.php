<?php

defined( 'ABSPATH' ) or exit;

class WC_Gateway_Subscreasy extends WC_Payment_Gateway {
    private $logger;
    public $testmode;
    public $api_key;
    public $secret_key;

    public function __construct() {
        $this->logger = new WC_Logger();

        $this->id = "subscreasy";
        $this->icon = "";

        // required for direct gateway (i.e., one that takes payment on the actual checkout page)
        // This tells the checkout to output a ‘payment_box’ containing your direct payment form that you define next.
        $this->has_fields = true;

        // Title of the payment method shown on the admin page.
        $this->method_title = "Subscreasy";

        // Description for the payment method shown on the admin page.
        $this->method_description = "Payment gateway with subscriptions feature. This version supports PayU, Iyzico, PayTR payment gateways";

        $this->supports           = array(
            'products',
            'refunds',
            'tokenization',
            'add_payment_method',
            'subscriptions',
            'subscription_cancellation',
            'subscription_suspension',
            'subscription_reactivation',
            'subscription_amount_changes',
            'subscription_date_changes',
            'subscription_payment_method_change',
            'subscription_payment_method_change_customer',
            //'subscription_payment_method_change_admin',
            //'multiple_subscriptions',
            //'pre-orders',
        );

        // Options shown in admin on the gateway settings page and make use of the WC Settings API
        $this->init_form_fields();

        // After init_settings() is called, settings can be loaded into variables, e.g: $this->title = $this->get_option( 'title' );
        $this->init_settings();

        $this->title = $this->get_option( 'title' );
        $payment_gateway_image = $this->get_option("payment_gateway_img");
        if ($payment_gateway_image) {
        } else {
        }

        $this->description = $this->get_option( 'description' );
        $this->enabled = $this->get_option( 'enabled' );
        $this->testmode = 'yes' === $this->get_option( 'testmode' );
        $this->api_key = $this->testmode ? $this->get_option( 'sandbox_api_key' ) : $this->get_option( 'api_key' );
        $this->secret_key = $this->testmode ? $this->get_option( 'sandbox_secret_key' ) : $this->get_option( 'secret_key' );

        // register webhooks
        // webhooks (callback URLs) in Woo look like this: http://rudrastyh.com/wc-api/{webhook name}/
        add_action( 'woocommerce_api_subscreasy', array( $this, 'webhook' ) );
        add_action( 'woocommerce_api_subscreasy_customer_redirect', array(&$this, 'gateway_customer_redirect'));

        // This action hook saves the settings
        add_action('woocommerce_update_options_payment_gateways_subscreasy', array($this, 'process_admin_options'));

        // We need custom JavaScript to obtain a token
        add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
    }

    public function webhook() {
        $post = filter_input_array(INPUT_POST, FILTER_SANITIZE_STRING);
        $this->log("Webhook POST request from gateway: " . print_r($post, true));

        $get = filter_input_array(INPUT_GET, FILTER_SANITIZE_STRING);
        $this->log("Webhook GET request from gateway: " . print_r($get, true));

        $response = new PurchaseResponse();
        $paymentId = $response->get_param("paymentId");
        $this->log("payment: " . $paymentId);

        $errorCode = $response->get_param("errorCode");
        $this->log("errorCode: $errorCode");

        $order = wc_get_order( $_GET['id'] );
        $order_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

        if ($response->is_authorized()) {
            $order->add_order_note(sprintf('Payment Completed. PaymentId is %s.', $paymentId));
            $order->update_status('completed', __( 'Payment Completed.', 'woocommerce' ));
            $order->payment_complete();
        } else {
            $this->log("Invalid GET request: " . print_r(filter_input_array(INPUT_GET, FILTER_SANITIZE_STRING), true));
            $errorText = $response->get_param("errorText");
            wc_add_notice($errorText, "error");
            $order->update_status('failed', $errorText);

            $this->log("Transaction failed");
            wp_redirect(wc_get_checkout_url());
            exit();
        }

        update_option('webhook_debug', $_GET);

        $order = new WC_Order($order_id);
        $order->update_meta_data( SUBSCREASY_CARD_ID_KEY,               $response->get_param("cardId") );
        $order->update_meta_data( SUBSCREASY_PAYMENT_ID_KEY,            $response->get_param("paymentId") );
        $order->update_meta_data( SUBSCREASY_CUSTOMER_ID_KEY,           $response->get_param("subscriberId") );
        $order->update_meta_data( SUBSCREASY_CUSTOMER_SECURE_ID_KEY,    $response->get_param("subscriberSecureId") );
        $order->save();

        $this->store_payment_response_to_subscription($order, $response);
        $this->ensure_user_has_customer_id($response->get_param("subscriberId"), $response->get_param("subscriberSecureId"));

        $wc_token_id = $this->save_source();

        wp_redirect($this->get_return_url($order));
        exit();
//            $customer_redirect_url = home_url('/wc-api/subscreasy_customer_redirect');
//            if (!$this->testmode) {
//                $customer_redirect_url = str_replace('http:', 'https:', $customer_redirect_url);
//            }
//
//            printf('REDIRECT=%s?orderId=%s&paymentId=%s', $customer_redirect_url, $order->get_id(), $paymentId);
//            exit();
    }

    function gateway_customer_redirect() {
        $order_id = filter_input(INPUT_GET, 'orderId', FILTER_VALIDATE_INT);
        $payment_id = filter_input(INPUT_GET, 'paymentId', FILTER_SANITIZE_STRING);

        if (!$order_id || !$payment_id) {
            $this->log("Invalid GET request: " . print_r(filter_input_array(INPUT_GET, FILTER_SANITIZE_STRING), true));
            exit();
        }

        $order = new WC_Order($order_id);
        wp_redirect($this->get_return_url($order));
        exit();
    }

    public function payment_scripts() {
        // we need JavaScript to process a token only on cart/checkout pages, right?
        if ( ! is_cart() && ! is_checkout() && ! isset( $_GET['pay_for_order'] ) ) {
            return;
        }

        // if our payment gateway is disabled, we do not have to enqueue JS too
        if ( 'no' === $this->enabled ) {
            return;
        }

        // no reason to enqueue JavaScript if API keys are not set
        //if ( empty( $this->api_key ) || empty( $this->secret_key ) ) {
        //    return;
        //}

        // do not work with card details without SSL unless your website is in a test mode
        if ( !$this->testmode && !is_ssl() ) {
            return;
        }

        $src = plugins_url('assets/css/subscreasy.css', __FILE__);
        wp_register_style('subscreasy-styles', $src, array(), WC_SUBSCREASY_VERSION);
        wp_enqueue_style('subscreasy-styles');

        wp_register_script('woocommerce_subscreasy', plugins_url('subscreasy.js', __FILE__), array('jquery'));
        wp_enqueue_script('woocommerce_subscreasy');

        // let's suppose it is our payment processor JavaScript that allows to obtain a token
        // wp_enqueue_script( 'subscreasy', 'http://localhost/wp/token.js' );

        // and this is our custom JS in your plugin directory that works with token.js
        // wp_register_script( 'woocommerce_subscreasy', plugins_url( 'subscreasy.js', __FILE__ ), array( 'jquery', 'subscreasy' ) );

        // in most payment processors you have to use PUBLIC KEY to obtain a token
        // wp_localize_script( 'woocommerce_subscreasy', 'subscreasy_params', array(
        //     'secret_key' => $this->secret_key
        // ) );

        // wp_enqueue_script( 'woocommerce_subscreasy' );
    }

    public function process_admin_options() {
        parent::process_admin_options();
    }

    // A basic set of settings: enabled, title and description:
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __( 'Enable/Disable', 'woocommerce' ),
                'type' => 'checkbox',
                'label' => __( 'Enable Subscreasy Payment Gateway', 'woocommerce' ),
                'default' => 'yes'
            ),
            'title' => array(
                'title' => __( 'Title', 'woocommerce' ),
                'type' => 'text',
                'description' => __( 'This controls the title which the user sees during checkout. You may enter your payment service provider name, e.g: PayU, Iyzico', 'woocommerce' ),
                'default' => __( 'Iyzico', 'woocommerce' ),
                'desc_tip'      => true,
            ),
            'description' => array(
                'title' => __( 'Customer Message', 'woocommerce' ),
                'type' => 'textarea',
                'description' => 'This controls the description which the user sees during checkout.',
                'default'     => __("Pay with your credit card via %s.", 'wc-gateway-subscreasy'),
            ),
            'testmode' => array(
                'title'       => 'Test mode',
                'label'       => 'Enable Test Mode',
                'type'        => 'checkbox',
                'description' => 'If test mode is enabled, https://sandbox.subscreasy.com is used during payments.',
                'default'     => 'yes',
                'desc_tip'    => true,
            ),
            'sandbox_api_key'   => array(
                'title'         => 'Sandbox API Key',
                'type'          => 'text',
                'description'   => 'API key provided by Subscreasy <a href="https://sandbox.subscreasy.com">sandbox environment</a>',
            ),
            // 'sandbox_secret_key'    => array('title'       => 'Sandbox Private Key', 'type' => 'password',),
            'api_key'          => array(
                'title'        => 'Production API Key',
                'type'         => 'password',
                'description'  => 'API key provided by Subscreasy <a href="https://app.subscreasy.com">production environment</a>. Do not share your production API key with anybody else!'
            ),
            // 'secret_key'            => array('title'       => 'Live Secret Key', 'type'     => 'password')
            'payment_gateway_img' => array(
                'title' => __( 'Payment Gateway Logo', 'woocommerce' ),
                'type' => 'select',
                'description' => __( 'This controls the payment method logos which the user sees during checkout. You shall select the appropriate logo based on your payment gateway', 'woocommerce' ),
                'default' => "visa-mastercard-amex",
                'desc_tip'      => true,
                'options' => array(
                    '' => 'No Image',
                    'iyzico' => 'Iyzico İle Ode',
                    'iyzicoband' => 'Iyzico Logo Seridi',
                    'paytr' => 'PayTR img',
                    'payu' => 'Payu img',
                    'visa-mastercard-amex' => 'Visa, Mastercard, Amex',
                )
            ),
        );
    }

    /**
     * When your gateway’s process_payment() is called with the ID of a subscription,
     * it means the request is to change the payment method on the subscription. (hint: wcs_is_subscription( $order_id ))
     */
    public function process_payment( $order_id ) {
        $post = filter_input_array(INPUT_POST, FILTER_SANITIZE_STRING);
        $paymenttoken = $post['wc-subscreasy-payment-token'];
        if ( isset($paymenttoken) && 'new' !== $paymenttoken) {
            $token_id = $paymenttoken;
            $token    = WC_Payment_Tokens::get( $token_id );

            // Token user ID does not match the current user... bail out of payment processing.
            if ( $token->get_user_id() !== get_current_user_id() ) {
                // Optionally display a notice with `wc_add_notice`
                $this->log("Token user ID does not match the current user");
                return;
            }
        }

        try {
            // we need it to get any order detailes
            $order = wc_get_order( $order_id );

            // Mark as on-hold (we're awaiting the cheque)
            $order->update_status('pending', __( 'Awaiting payment', 'woocommerce' ));

            $threeds = true;
            if ($threeds) {
                $response_url = home_url('/wc-api/subscreasy');
                if (!$this->testmode) {
                    $response_url  = str_replace('http:', 'https:', $response_url);
                }

                // create payment
                $user = wp_get_current_user();

                $paymentForm = new PaymentForm();
                $subscreasy_ccNo = $paymentForm->post_param('subscreasy_ccNo');
                $subscreasy_expYear = $paymentForm->post_param('subscreasy_expYear');
                $subscreasy_expMonth = $paymentForm->post_param('subscreasy_expMonth');
                $subscreasy_cvv = $paymentForm->post_param('subscreasy_cvv');

                $request = array (
                    "subscriber" => array("name" => $user->first_name, "surname" => $user->last_name , "email" => $user->user_email),
                    "offer" => array("name" => $this->get_order_name($order->get_id()), "price" => $order->get_total(), "currency" => $order->get_currency()),
                    "paymentCard" => array("cardNumber"=> $subscreasy_ccNo, "expireYear" => $subscreasy_expYear, "expireMonth" => $subscreasy_expMonth, "cvc" => $subscreasy_cvv, "cardHolderName" => "Not specified"),
                    "callbackUrl" => get_site_url(),
                    "errorCallbackUrl" => get_site_url(),
                    "callbackParams" => array("wc-api", "subscreasy", "id", $order_id)
                );

                $url = $this->get_remote_api("api/pay/create-payment");
                $response = wp_safe_remote_post(
                    $url,
                    array(
                        'method'  => "POST",
                        'headers' => $this->get_authentication_header(),
                        'body'    => json_encode($request),
                        'data_format' => 'body',
                        'timeout' => 30,
                    )
                );

                if ($response instanceof WP_Error) {
                    $this->log("create-payment error" . var_export($response, true));
                    $key = array_keys($response->errors)[0];
                    return $this->request_failed(new Exception($key), $order);
                } else {
                    $this->log_http_response($url, $response);
                }

                $body = $response["body"];
                $responseResponse = $response["response"];
                if ($responseResponse["code"] != 200) {
                    $this->log("Error code: " . $responseResponse["code"]);
                    $e = WC_Subscreasy_Util::translateException($body);

                    return $this->request_failed($e, $order);
                } else {
                    $order->add_order_note(sprintf('Payment request created, transactionId: %s.', $body));
                }

                // Redirect to the thank you page
                $resumePaymentLink = add_query_arg(array(
                    'orderId' => $order_id, 'transactionId' => $body
                ), $this->get_remote_api('na/pay/resume-payment'));
                $this->log("Redirecting to: " . $resumePaymentLink);

                return array(
                    'result' => 'success',
                    'redirect' => $resumePaymentLink
                );
            } else {
                $this->log("non-threeds not supported");
            }
        } catch ( Exception $e ) {
            return $this->request_failed($e);
        }
    }

    // Called after place order
    public function validate_fields() {
        $paymentForm = new PaymentForm();

        if (empty($paymentForm->post_param('subscreasy_ccNo'))) {
            wc_add_notice(__('<b>Credit card number</b> is a required field!', 'wc-gateway-subscreasy'), 'error');
            return false;
        }

        if (empty($paymentForm->post_param('subscreasy_expMonth'))) {
            wc_add_notice(__('Credit card expiry month is required!', 'wc-gateway-subscreasy'), 'error');
            return false;
        }

        if (empty($paymentForm->post_param('subscreasy_expYear'))) {
            wc_add_notice(__('Credit card expiry year is required!', 'wc-gateway-subscreasy'), 'error');
            return false;
        }

        if (empty($paymentForm->post_param('subscreasy_cvv'))) {
            wc_add_notice(__('Credit card CVC is required!', 'wc-gateway-subscreasy'), 'error');
            return false;
        }

        return true;
    }

    /**
     * Payment form on checkout page, required if has_fields = true
     *
     * The next but optional method to add is validate_fields().
     * Return true if the form passes validation or false if it fails.
     * You can use the wc_add_notice() function if you want to add an error and display it to the user.
     */
    public function payment_fields() {
        $display_tokenization = $this->supports( 'tokenization' ) && is_checkout();

        if ($this->description) {
            // display the description with <p> tags etc.
            $this->description = sprintf(__("Pay with your credit card via %s.", 'wc-gateway-subscreasy'), $this->get_option("title"));
            echo wpautop(wp_kses_post($this->description));
        }

        if ($this->testmode) {
            $descriptionTestMode = sprintf(__('TEST MODE ENABLED. In test mode, you can use the card numbers listed in <a href="%s" target="_blank" rel="noopener noreferrer">documentation</a>.', 'wc-gateway-subscreasy'), "https://subscreasy.com/doc/test-kartlari");
            echo wpautop(wp_kses_post($descriptionTestMode));
        }

        if ( $display_tokenization ) {
            // $this->tokenization_script();
            // $this->saved_payment_methods();
        }

        if ( is_add_payment_method_page() ) {
            $firstname       = "";
            $lastname        = "";
        }

        // I will echo() the form, but you can close PHP tags and print it directly in HTML
        echo '<fieldset id="wc-subscreasy-cc-form" class="wc-credit-card-form wc-payment-form" style="background:transparent;">';

        // Add this action hook if you want your custom payment gateway to support it
        do_action( 'woocommerce_credit_card_form_start', $this->id );

        $ccNo = $this->testmode ? "4124111111111116" : "";      // This cardNumber can be used for "Invalid cvc2" tests
        $ccNo = $this->testmode ? "5890040000000016" : "";      // iyzico
        // $ccNo = $this->testmode ? "9792030394440796" : "";   // paytr
        $expMonth = $this->testmode ? "12" : "";
        $expYear = $this->testmode ? "2024" : "";
        $cvv = $this->testmode ? "000" : "";

        $expMonthOptions = "";
        for ($i = 1; $i<=12; $i++){
            $formatted = sprintf("%02d", $i);
            $expMonthOptions .= '<option value="' . $formatted . '" ' . ($expMonth == $formatted ? 'selected' : '') . '>' . $formatted . '</option>';
        }

        $expYearOptions = "";
        $datetime = new Datetime();
        $thisYear = $datetime->format("Y");
        for ($i = $thisYear; $i<=2031; $i++){
            $expYearOptions .= '<option value="' . $i . '" ' . ($expYear == $i ? 'selected' : '') . '>' . $i . '</option>';
        }

        echo '<p class="form-row form-row-wide">
                <label>' . __('Card Number', 'wc-gateway-subscreasy') . '<span class="required">*</span></label>
                <input name="subscreasy_ccNo" id="subscreasy_ccNo" type="text" autocomplete="cc-number" value="'.$ccNo.'">
            </p>
            <div class="form-row form-row-first" style="width: 49%">
                <div class="form-row form-row-first" style="padding-right: 1rem">
                    <label>' . __('Expiry Month', 'wc-gateway-subscreasy') . '<span class="required">*</span></label>
                    <input type="text" name="subscreasy_expMonth" id="subscreasy_expMonth" style="width: 100%" autocomplete="cc-exp-month" value="'.$expMonth.'" />
                </div>

                <div class="form-row form-row-first">
                    <label>' . __('Expiry Year', 'wc-gateway-subscreasy') . '<span class="required">*</span></label>
                    <input type="text" name="subscreasy_expYear" id="subscreasy_expYear" style="width: 100%" autocomplete="cc-exp-year" value="'.$expYear.'" />
                </div>
            </div>            

            <div class="form-row form-row-last" style="width: 49%">
                <label>' . __('Card Code (CVC)', 'wc-gateway-subscreasy') . '<span class="required">*</span></label>
                <input name="subscreasy_cvv" id="subscreasy_cvv" type="text" maxlength="4" autocomplete="cc-csc" placeholder="CVC" value="'.$cvv.'">
            </div>
            <div class="clear"></div>
            <script>
            </script>';

        do_action( 'woocommerce_credit_card_form_end', $this->id );

        echo '<div class="clear"></div></fieldset>';
    }

    public function get_payment_gateway_img($payment_gateway_image) {
        $icons = $this->payment_icons();
        return $icons[$payment_gateway_image];
    }

    public function get_icon() {
        $icons = $this->payment_icons();

        $icons_str = '';

        $payment_gateway_img = $this->get_option("payment_gateway_img");
        if ($payment_gateway_img != "") {
            $icons_str = $icons["$payment_gateway_img"];
        } else {    // no payment logo preference, use default one
            $icons_str .= isset( $icons['amex'] ) ? $icons['amex'] : '';
            $icons_str .= isset( $icons['mastercard'] ) ? $icons['mastercard'] : '';
            $icons_str .= isset( $icons['visa'] ) ? $icons['visa'] : '';

            if ( 'USD' === get_woocommerce_currency() ) {
                $icons_str .= isset( $icons['discover'] ) ? $icons['discover'] : '';
                $icons_str .= isset( $icons['jcb'] ) ? $icons['jcb'] : '';
                $icons_str .= isset( $icons['diners'] ) ? $icons['diners'] : '';
            }
        }

        return apply_filters( 'woocommerce_gateway_icon', $icons_str, 'wc-gateway-subscreasy' );
    }

    public function payment_icons() {
        return apply_filters(
            'wc_subscreasy_payment_icons',
            array(
                'visa'       => '<img src="' . WC_SUBSCREASY_PLUGIN_URL . '/assets/images/visa.svg" class="subscreasy-visa-icon subscreasy-icon" alt="Visa" />',
                'amex'       => '<img src="' . WC_SUBSCREASY_PLUGIN_URL . '/assets/images/amex.svg" class="subscreasy-amex-icon subscreasy-icon" alt="American Express" />',
                'mastercard' => '<img src="' . WC_SUBSCREASY_PLUGIN_URL . '/assets/images/mastercard.svg" class="subscreasy-mastercard-icon subscreasy-icon" alt="Mastercard" />',
                'discover'   => '<img src="' . WC_SUBSCREASY_PLUGIN_URL . '/assets/images/discover.svg" class="subscreasy-discover-icon subscreasy-icon" alt="Discover" />',
                'diners'     => '<img src="' . WC_SUBSCREASY_PLUGIN_URL . '/assets/images/diners.svg" class="subscreasy-diners-icon subscreasy-icon" alt="Diners" />',
                'jcb'        => '<img src="' . WC_SUBSCREASY_PLUGIN_URL . '/assets/images/jcb.svg" class="subscreasy-jcb-icon subscreasy-icon" alt="JCB" />',
                'alipay'     => '<img src="' . WC_SUBSCREASY_PLUGIN_URL . '/assets/images/alipay.svg" class="subscreasy-alipay-icon subscreasy-icon" alt="Alipay" />',
                'wechat'     => '<img src="' . WC_SUBSCREASY_PLUGIN_URL . '/assets/images/wechat.svg" class="subscreasy-wechat-icon subscreasy-icon" alt="Wechat Pay" />',
                'bancontact' => '<img src="' . WC_SUBSCREASY_PLUGIN_URL . '/assets/images/bancontact.svg" class="subscreasy-bancontact-icon subscreasy-icon" alt="Bancontact" />',
                'ideal'      => '<img src="' . WC_SUBSCREASY_PLUGIN_URL . '/assets/images/ideal.svg" class="subscreasy-ideal-icon subscreasy-icon" alt="iDeal" />',
                'p24'        => '<img src="' . WC_SUBSCREASY_PLUGIN_URL . '/assets/images/p24.svg" class="subscreasy-p24-icon subscreasy-icon" alt="P24" />',
                'giropay'    => '<img src="' . WC_SUBSCREASY_PLUGIN_URL . '/assets/images/giropay.svg" class="subscreasy-giropay-icon subscreasy-icon" alt="Giropay" />',
                'eps'        => '<img src="' . WC_SUBSCREASY_PLUGIN_URL . '/assets/images/eps.svg" class="subscreasy-eps-icon subscreasy-icon" alt="EPS" />',
                'multibanco' => '<img src="' . WC_SUBSCREASY_PLUGIN_URL . '/assets/images/multibanco.svg" class="subscreasy-multibanco-icon subscreasy-icon" alt="Multibanco" />',
                'sofort'     => '<img src="' . WC_SUBSCREASY_PLUGIN_URL . '/assets/images/sofort.svg" class="subscreasy-sofort-icon subscreasy-icon" alt="SOFORT" />',
                'sepa'       => '<img src="' . WC_SUBSCREASY_PLUGIN_URL . '/assets/images/sepa.svg" class="subscreasy-sepa-icon subscreasy-icon" alt="SEPA" />',
                'iyzico'     => '<span>
                                  <img src="' . WC_SUBSCREASY_PLUGIN_URL . '/assets/images/iyzico-logo-pack/troy.svg" class="subscreasy-icon" style="float: right" alt="iyzico" />
                                  <img src="' . WC_SUBSCREASY_PLUGIN_URL . '/assets/images/amex.svg" class="subscreasy-icon" style="float: right" alt="iyzico" />
                                  <img src="' . WC_SUBSCREASY_PLUGIN_URL . '/assets/images/iyzico-logo-pack/visa-electron.svg" class="subscreasy-icon" style="float: right" alt="iyzico" />
                                  <img src="' . WC_SUBSCREASY_PLUGIN_URL . '/assets/images/iyzico-logo-pack/visa.svg" class="subscreasy-icon" style="float: right" alt="iyzico" />
                                  <img src="' . WC_SUBSCREASY_PLUGIN_URL . '/assets/images/iyzico-logo-pack/maestro.svg" class="subscreasy-icon" style="float: right" alt="iyzico" />
                                  <img src="' . WC_SUBSCREASY_PLUGIN_URL . '/assets/images/iyzico-logo-pack/mastercard.svg" class="subscreasy-icon" style="float: right" alt="iyzico" />
                                  <img src="' . WC_SUBSCREASY_PLUGIN_URL . '/assets/images/iyzico-logo-pack/iyzico-ile-ode-alt.svg" class="" style="float: right" alt="iyzico" />
                                 </span>',
                'iyzicoband' => '<span><img src="' . WC_SUBSCREASY_PLUGIN_URL . '/assets/images/iyzico-logo-pack/logo-band_iyzico-ile-ode.svg" class="" style="float: right" alt="iyzico" /></span>',
                'payu'       => '<span><img src="' . WC_SUBSCREASY_PLUGIN_URL . '/assets/images/iyzico-logo-pack/iyzico/iyzico-ile-ode/logo-band_iyzico ile Ode@3x.png" class="" style="float: inherit" alt="payu" /></span>',
            )
        );
    }

    public function ensure_user_has_customer_id($subscreasy_customer_id, $subscreasy_customer_secure_id ) {
        $user_id = get_current_user_id();

//        $customer_id_from_db = get_user_option( SUBSCREASY_CUSTOMER_ID_KEY, $user_id);
//        if (!$customer_id_from_db) {
        update_user_option( $user_id, SUBSCREASY_CUSTOMER_ID_KEY, $subscreasy_customer_id );
//        } else if ($subscreasy_customer_id != $customer_id_from_db) {
//            $this->log("Invalid customer id, updating from " . $customer_id_from_db . " to " . $subscreasy_customer_id);
//            update_user_option( $user_id, SUBSCREASY_CUSTOMER_ID_KEY, $subscreasy_customer_id );
//        }

//        $customer_secure_id_from_db = get_user_option( SUBSCREASY_CUSTOMER_SECURE_ID_KEY, $user_id);
//        if ($customer_secure_id_from_db == '') {
        update_user_option( $user_id, SUBSCREASY_CUSTOMER_SECURE_ID_KEY, $subscreasy_customer_secure_id );
//        } else if ($subscreasy_customer_secure_id != $customer_secure_id_from_db) {
//            $this->log("Invalid customer id, updating from " . $customer_secure_id_from_db . " to " . $subscreasy_customer_secure_id);
//            update_user_option( $user_id, SUBSCREASY_CUSTOMER_SECURE_ID_KEY, $subscreasy_customer_secure_id );
//        }
    }

    /**
     * Associates saved card with the customer
     */
    public function save_source() {
        $card_id = wc_clean($_GET['cardId']);
        $card = $this->get_saved_card($card_id);
        $this->log("card id: " . $card->id . ", bin:" . $card->binNumber);

        return $this->associate_saved_card_with_customer($card_id);
    }

    public function associate_saved_card_with_customer($card_id) {
        $card = $this->get_saved_card($card_id);
        
        $user_id = get_current_user_id();
        $customer = new WC_Subscreasy_Customer( $user_id );
        $wc_token_id = $customer->add_source($card);

        return $wc_token_id;
    }

    public function get_saved_card($card_id) {
        $this->log("Retrieving card details: " . $card_id);

        $url = $this->get_remote_api("api/saved-cards/" . $card_id);
        $response = wp_safe_remote_post(
            $url,
            array(
                'method' => "GET",
                'headers' => $this->get_authentication_header(),
                'body' => "",
                'data_format' => 'body',
                'timeout' => 30,
            )
        );

        if ($response instanceof WP_Error) {
            return $this->request_failed(new Exception("get_saved_card"), null);
        }

        $body = $response["body"];
        $responseResponse = $response["response"];
        if ($responseResponse["code"] != 200) {
            $this->log("Error code: " . $responseResponse["code"]);
            $e = WC_Subscreasy_Util::translateException($responseResponse["code"]);

            return $this->request_failed($e);
        }

        $card = json_decode($response["body"]);
        $this->log("Retrieved card details: " . $response["body"]);
        return $card;
    }

    public function save_card($subscreasy_customer_id) {
        $paymentForm = new PaymentForm();

        $url = $this->get_remote_api("api/card/subscriber");
        $request = array(
            "paymentCard" => array(
                "cardNumber" => $paymentForm->post_param("subscreasy_ccNo"),
                "expireYear" => $paymentForm->post_param("subscreasy_expYear"),
                "expireMonth" => $paymentForm->post_param("subscreasy_expMonth"),
                "cvc" => $paymentForm->post_param("subscreasy_cvv"),
                "cardHolderName" => "Not specified"
            ), "subscriber" => array("id" => $subscreasy_customer_id),
        );

        $response = wp_safe_remote_post(
            $url,
            array(
                'headers' => $this->get_authentication_header(),
                'body' => json_encode($request),
                'data_format' => 'body',
                'timeout' => 30,
            )
        );

        if ($response instanceof WP_Error) {
            $this->log("save_card error" . var_export($response, true));
            $key = array_keys($response->errors)[0];
            return $this->request_failed(new Exception($key), null);
        } else {
            $this->log_http_response($url, $response);
        }

        if ($response["response"]["code"] != 200) {
            $this->log("Response code: " . $response["response"]["code"]);
            if ($response["response"]["code"] == 401) {
                // TODO check api key
                return $this->request_failed(new Exception("save_card"), null);
            } else {
                // TODO generic error handling e.g: $e = WC_Subscreasy_Util::translateException($responseResponse["code"]);
                $errorMessage = $response["headers"]["x-subscreasy-error"];
                return $this->request_failed(new Exception("$errorMessage"), null);
            }
        }

        $card = json_decode($response["body"]);
        return $card;
    }

    protected function get_remote_api($string) {
        $host = $this->testmode ? "http://localhost:8080/" : "https://prod.subscreasy.com/";
        $host = $this->testmode ? "https://sandbox.subscreasy.com/" : "https://prod.subscreasy.com/";
        return $host . $string;
    }

    protected function get_authentication_header() {
        return array(
            'Authorization'       => 'Apikey ' . $this->get_api_key(),
            'X-Subscreasy-Client' => json_encode( self::get_user_agent() ),
            'Content-Type' => 'application/json',
        );
    }

    public static function get_user_agent() {
        $app_info = array(
            'name'    => 'WooCommerce Subscreasy Payment Gateway',
            'version' => WC_SUBSCREASY_VERSION,
            'url'     => 'https://www.subscreasy.com',
        );

        return array(
            'lang'         => 'php',
            'lang_version' => phpversion(),
            'uname'        => php_uname(),
            'application'  => $app_info,
        );
    }

    protected function log($message) {
        $this->logger->add($this->id, $message);
    }

    protected function log_http_response($url, $response2) {
        if ($response2 instanceof WP_Error) {
            $this->log("post() error: " . $response2);
        } else {
            $this->log("Response received from " . $url . "\n"
              . "response: " . json_encode($response2["response"]) . ", headers: " . json_encode($response2["headers"]) );
        }
    }

    private function get_api_key() {
        return $this->api_key;
    }

    protected function get_order_name($order_id) {
        return get_site_url() . "/wp-admin/post.php?action=edit&post=" . $order_id;
    }

    private function store_payment_response_to_subscription(WC_Order $order, PurchaseResponse $payment_log) {
        $subscriptions = wcs_get_subscriptions_for_order($order->get_id());
        foreach ($subscriptions as $subscription) {
            $this->store_card_details_to_subscription($subscription, $payment_log->get_param("cardId"), $payment_log->get_param("subscriberId"), $payment_log->get_param("subscriberSecureId"));
        }
    }

    function store_card_details_to_subscription(WC_Subscription $subscription, $cardId, $subscriberId = null, $subscriberSecureId = null) {
        $subscription_id = $subscription->get_id();
        update_post_meta($subscription_id, SUBSCREASY_CARD_ID_KEY, $cardId);
        if ($subscriberId != null) {
            update_post_meta($subscription_id, SUBSCREASY_CUSTOMER_ID_KEY, $subscriberId);
        } else {
            $this->log("subscriberId will not be updated since it is not provided for subscription " . $subscription_id);
        }

        if ($subscriberSecureId != null) {
            update_post_meta($subscription_id, SUBSCREASY_CUSTOMER_SECURE_ID_KEY, $subscriberSecureId);
        } else {
            $this->log("subscriberSecureId will not be updated since it is not provided for subscription " . $subscription_id);
        }
    }

    public function request_failed(Exception $e, WC_Order $order = null) {
        $this->log('Error occured: ' . $e->getMessage());
        $this->log($e->getFile() . ":" . $e->getLine());
        $this->log($e->getTraceAsString());

        wc_add_notice($e->getMessage(), 'error');

        do_action('wc_gateway_subscreasy_process_payment_error', $e, $order);

        if ($order != null) {
            $order->update_status('failed');
        }

        return array(
            'result' => 'fail',
            'redirect' => '',
        );
    }

    public function post($url, $request, $headers) {
        $request_encoded = json_encode($request);
        $this->log("POST " . $url . ", request: " . $request_encoded);
        $response = wp_safe_remote_post(
            $url,
            array(
                'method'  => "POST",
                'headers' => $headers,
                'body'    => $request_encoded,
                'data_format' => 'body',
                'timeout' => 30,
            )
        );

        $this->log_http_response($url, $response);

        return $response;
    }
}
