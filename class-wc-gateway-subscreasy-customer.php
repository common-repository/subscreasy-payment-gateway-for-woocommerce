<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Subscreasy_Customer {

	private $id = '';

	private $user_id = 0;

	private $customer_data = array();

	public function __construct( $user_id = 0 ) {
		if ( $user_id ) {
			$this->set_user_id( $user_id );
			$this->set_id( $this->get_id_from_meta( $user_id ) );
		}
	}

	public function get_id() {
		return $this->id;
	}

	public function set_id( $id ) {
		// Backwards compat for customer ID stored in array format. (Pre 3.0)
		if ( is_array( $id ) && isset( $id['customer_id'] ) ) {
			$id = $id['customer_id'];

			$this->update_id_in_meta( $id );
		}

		$this->id = wc_clean( $id );
	}

	public function get_user_id() {
		return absint( $this->user_id );
	}

	public function set_user_id( $user_id ) {
		$this->user_id = absint( $user_id );
	}

	protected function get_user() {
		return $this->get_user_id() ? get_user_by( 'id', $this->get_user_id() ) : false;
	}

	public function set_customer_data( $data ) {
		$this->customer_data = $data;
	}

	protected function generate_customer_request( $args = array() ) {
		$billing_email = isset( $_POST['billing_email'] ) ? filter_var( $_POST['billing_email'], FILTER_SANITIZE_EMAIL ) : '';
		$user          = $this->get_user();

		if ( $user ) {
			$billing_first_name = get_user_meta( $user->ID, 'billing_first_name', true );
			$billing_last_name  = get_user_meta( $user->ID, 'billing_last_name', true );

			// If billing first name does not exists try the user first name.
			if ( empty( $billing_first_name ) ) {
				$billing_first_name = get_user_meta( $user->ID, 'first_name', true );
			}

			// If billing last name does not exists try the user last name.
			if ( empty( $billing_last_name ) ) {
				$billing_last_name = get_user_meta( $user->ID, 'last_name', true );
			}

			// translators: %1$s First name, %2$s Second name, %3$s Username.
			$description = sprintf( __( 'Name: %1$s %2$s, Username: %s', 'woocommerce-gateway-stripe' ), $billing_first_name, $billing_last_name, $user->user_login );

			$defaults = array(
				'email'       => $user->user_email,
				'description' => $description,
			);
		} else {
			$billing_first_name = isset( $_POST['billing_first_name'] ) ? filter_var( wp_unslash( $_POST['billing_first_name'] ), FILTER_SANITIZE_STRING ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			$billing_last_name  = isset( $_POST['billing_last_name'] ) ? filter_var( wp_unslash( $_POST['billing_last_name'] ), FILTER_SANITIZE_STRING ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

			// translators: %1$s First name, %2$s Second name.
			$description = sprintf( __( 'Name: %1$s %2$s, Guest', 'woocommerce-gateway-stripe' ), $billing_first_name, $billing_last_name );

			$defaults = array(
				'email'       => $billing_email,
				'description' => $description,
			);
		}

		$metadata             = array();
		$defaults['metadata'] = apply_filters( 'wc_subscreasy_subscriber_metadata', $metadata, $user );

		return wp_parse_args( $args, $defaults );
	}

	public function create_customer( $args = array() ) {
		$args     = $this->generate_customer_request( $args );
		$response = WC_Stripe_API::request( apply_filters( 'wc_subscreasy_create_customer_args', $args ), 'customers' );

		if ( ! empty( $response->error ) ) {
			throw new WC_Stripe_Exception( print_r( $response, true ), $response->error->message );
		}

		$this->set_id( $response->id );
		$this->clear_cache();
		$this->set_customer_data( $response );

		if ( $this->get_user_id() ) {
			$this->update_id_in_meta( $response->id );
		}

		do_action( 'woocommerce_subscreasy_add_customer', $args, $response );

		return $response->id;
	}

	public function update_customer( $args = array(), $is_retry = false ) {
		if ( empty( $this->get_id() ) ) {
			throw new WC_Stripe_Exception( 'id_required_to_update_user', __( 'Attempting to update a Stripe customer without a customer ID.', 'woocommerce-gateway-stripe' ) );
		}

		$args     = $this->generate_customer_request( $args );
		$args     = apply_filters( 'wc_subscreasy_update_customer_args', $args );
		$response = WC_Stripe_API::request( $args, 'customers/' . $this->get_id() );

		if ( ! empty( $response->error ) ) {
			if ( $this->is_no_such_customer_error( $response->error ) && ! $is_retry ) {
				// This can happen when switching the main Stripe account or importing users from another site.
				// If not already retrying, recreate the customer and then try updating it again.
				$this->recreate_customer();
				return $this->update_customer( $args, true );
			}

			throw new WC_Stripe_Exception( print_r( $response, true ), $response->error->message );
		}

		$this->clear_cache();
		$this->set_customer_data( $response );

		do_action( 'woocommerce_subscreasy_update_customer', $args, $response );

		return $this->get_id();
	}

	public function is_no_such_customer_error( $error ) {
		return (
			$error &&
			'invalid_request_error' === $error->type &&
			preg_match( '/No such customer/i', $error->message )
		);
	}

	public function add_source( $response ) {
//		if ( ! $this->get_id() ) {
//			$this->set_id( $this->create_customer() );
//		}

//		$wc_token = false;
        $wc_token_id = 0;

        // Add token to WooCommerce.
        if ($this->get_user_id() && class_exists('WC_Payment_Token_CC')) {
            $wc_token = new WC_Payment_Token_CC();
            $wc_token->set_token($response->id);
            $wc_token->set_gateway_id('subscreasy');
            $wc_token->set_card_type(empty($response->cardAssociation) ? "visa" : strtolower($response->cardAssociation));
            $wc_token->set_last4($response->lastFourDigits);

            $expiry_date = date_parse($response->expireDate);
            $wc_token->set_expiry_month($expiry_date["month"]);
            $wc_token->set_expiry_year($expiry_date["year"]);

            $wc_token->set_user_id($this->get_user_id());
            $wc_token_id = $wc_token->save();
        }

		$this->clear_cache();

        do_action('woocommerce_subscreasy_add_source', $this->get_id(), $wc_token, $response, $response->id);

		return $wc_token_id;
	}

	public function get_sources() {
		if ( ! $this->get_id() ) {
			return array();
		}

		$sources = get_transient( 'stripe_sources_' . $this->get_id() );

		if ( false === $sources ) {
			$response = WC_Stripe_API::request(
				array(
					'limit' => 100,
				),
				'customers/' . $this->get_id() . '/sources',
				'GET'
			);

			if ( ! empty( $response->error ) ) {
				return array();
			}

			if ( is_array( $response->data ) ) {
				$sources = $response->data;
			}

			set_transient( 'stripe_sources_' . $this->get_id(), $sources, DAY_IN_SECONDS );
		}

		return empty( $sources ) ? array() : $sources;
	}

	public function delete_source( $source_id ) {
		if ( ! $this->get_id() ) {
			return false;
		}

		$response = WC_Stripe_API::request( array(), 'customers/' . $this->get_id() . '/sources/' . sanitize_text_field( $source_id ), 'DELETE' );

		$this->clear_cache();

		if ( empty( $response->error ) ) {
			do_action( 'wc_subscreasy_delete_source', $this->get_id(), $response );

			return true;
		}

		return false;
	}

	public function set_default_source( $source_id ) {
		$response = WC_Stripe_API::request(
			array(
				'default_source' => sanitize_text_field( $source_id ),
			),
			'customers/' . $this->get_id(),
			'POST'
		);

		$this->clear_cache();

		if ( empty( $response->error ) ) {
			do_action( 'wc_subscreasy_set_default_source', $this->get_id(), $response );

			return true;
		}

		return false;
	}

	public function clear_cache() {
		delete_transient( 'stripe_sources_' . $this->get_id() );
		delete_transient( 'stripe_customer_' . $this->get_id() );
		$this->customer_data = array();
	}

	public function get_id_from_meta( $user_id ) {
		return get_user_option( '_subscreasy_subscriber_id', $user_id );
	}

	public function update_id_in_meta( $id ) {
		update_user_option( $this->get_user_id(), '_subscreasy_subscriber_id', $id, false );
	}

	public function delete_id_from_meta() {
		delete_user_option( $this->get_user_id(), '_subscreasy_subscriber_id', false );
	}

	private function recreate_customer() {
		$this->delete_id_from_meta();
		return $this->create_customer();
	}
}
