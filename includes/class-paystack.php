<?php


class CampTix_Payment_Method_Paystack extends CampTix_Payment_Method {

	public $id = 'paystack';

	public $name = 'Paystack';

	public $description;

	public $supported_currencies = array( 'NGN', 'USD', 'GHS', 'ZAR', 'KES', 'XOF', 'EGP', 'RWF' );

	protected array $options = array();

	public function __construct() {
		$this->description = $this->paystack_gateway_description();
		parent::__construct();
	}

	public function paystack_gateway_description() {
		global $camptix;

		$notify_url = add_query_arg(
			array(
				'tix_action'         => 'payment_notify',
				'tix_payment_method' => 'paystack',
			),
			$camptix->get_tickets_url()
		);

		return 'Paystack <h4>Required: To avoid situations where bad network makes it impossible to verify transactions, set your webhook URL <a href="https://dashboard.paystack.co/#/settings/developer" target=_blank rel="noopener noreferrer">here</a> to the URL below<strong style="color: red"><pre><code>' . esc_url( $notify_url ) . '</code></pre></strong></h4>';
	}

	public function camptix_init() {
		$this->options = array_merge(
			array(
				'test_public_key' => '',
				'test_secret_key' => '',
				'live_public_key' => '',
				'live_secret_key' => '',
				'sandbox'         => true,
			),
			$this->get_payment_options()
		);

		add_action( 'template_redirect', array( $this, 'template_redirect' ) );
		add_action( 'camptix_pre_attendee_timeout', array( $this, 'pre_attendee_timeout' ) );
	}

	public function payment_settings_fields() {
		$this->add_settings_field_helper(
			'test_secret_key',
			__( 'Test Secret Key', 'tbz-camptix-paystack' ),
			array( $this, 'field_text' )
		);
		$this->add_settings_field_helper(
			'test_public_key',
			__( 'Test Public Key', 'tbz-camptix-paystack' ),
			array( $this, 'field_text' )
		);

		$this->add_settings_field_helper(
			'live_secret_key',
			__( 'Live Secret Key', 'tbz-camptix-paystack' ),
			array( $this, 'field_text' )
		);
		$this->add_settings_field_helper(
			'live_public_key',
			__( 'Live Public Key', 'tbz-camptix-paystack' ),
			array( $this, 'field_text' )
		);

		$this->add_settings_field_helper(
			'sandbox',
			'Test Mode',
			array( $this, 'field_yesno' ),
			__(
				'Paystack Test Mode is a way to test payments without using real accounts and transactions.',
				'tbz-camptix-paystack'
			)
		);
	}

	public function validate_options( $input ) {
		$output = $this->options;

		if ( isset( $input['test_public_key'] ) ) {
			$output['test_public_key'] = trim( sanitize_text_field( $input['test_public_key'] ) );
		}

		if ( isset( $input['test_secret_key'] ) ) {
			$output['test_secret_key'] = trim( sanitize_text_field( $input['test_secret_key'] ) );
		}

		if ( isset( $input['live_public_key'] ) ) {
			$output['live_public_key'] = trim( sanitize_text_field( $input['live_public_key'] ) );
		}

		if ( isset( $input['live_secret_key'] ) ) {
			$output['live_secret_key'] = trim( sanitize_text_field( $input['live_secret_key'] ) );
		}

		if ( isset( $input['sandbox'] ) ) {
			$output['sandbox'] = (bool) $input['sandbox'];
		}

		return $output;
	}

	public function template_redirect() {
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( $_REQUEST['tix_payment_method'] ) || 'paystack' !== strtolower( trim( $_REQUEST['tix_payment_method'] ) ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( $_REQUEST['tix_action'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification
		$tix_action = strtolower( trim( sanitize_text_field( $_GET['tix_action'] ) ) );

		if ( 'payment_return' === $tix_action ) {
			$this->payment_return();
		}

		if ( 'payment_notify' === $tix_action ) {
			$this->payment_notify();
		}
	}


	public function payment_checkout( $payment_token ) {
		global $camptix;

		if ( empty( $payment_token ) ) {
			return false;
		}

		if ( ! in_array( strtoupper( $this->camptix_options['currency'] ), $this->supported_currencies, true ) ) {
			wp_die(
				esc_html__(
					'The selected currency is not supported by this payment method.',
					'tbz-camptix-paystack'
				)
			);
		}

		$options = $this->options;

		$public_key = $options['sandbox'] ? $options['test_public_key'] : $options['live_public_key'];
		$secret_key = $options['sandbox'] ? $options['test_secret_key'] : $options['live_secret_key'];

		if ( empty( $public_key ) || empty( $secret_key ) ) {
			wp_die(
				esc_html__(
					'Kindly enter your Paystack API keys on the CampTix setup page.',
					'tbz-camptix-paystack'
				)
			);
		}

		$return_url = add_query_arg(
			array(
				'tix_action'         => 'payment_return',
				'tix_payment_token'  => $payment_token,
				'tix_payment_method' => 'paystack',
			),
			$camptix->get_tickets_url()
		);

		$order = $this->get_order( $payment_token );

		$attendee_email = get_post_meta( $order['attendee_id'], 'tix_receipt_email', true );

		$paystack_url = 'https://api.paystack.co/transaction/initialize';

		$headers = array(
			'Content-Type'  => 'application/json',
			'Authorization' => 'Bearer ' . $secret_key,
		);

		$body = array(
			'amount'       => $order['total'] * 100,
			'email'        => $attendee_email,
			'currency'     => $this->camptix_options['currency'],
			'callback_url' => $return_url,
		);

		$args = array(
			'body'    => wp_json_encode( $body ),
			'headers' => $headers,
			'timeout' => 60,
		);

		$request = wp_remote_post( $paystack_url, $args );

		if ( ! is_wp_error( $request ) && 200 === wp_remote_retrieve_response_code( $request ) ) {
			$paystack_response = json_decode( wp_remote_retrieve_body( $request ) );

			$payment_url = $paystack_response->data->authorization_url;

			update_post_meta( $order['attendee_id'], 'tix_transaction_id', $paystack_response->data->reference );

			// phpcs:ignore WordPress.Security.SafeRedirect
			wp_redirect( $payment_url );

			exit();
		}

		$paystack_response = json_decode( wp_remote_retrieve_body( $request ) );

		$error_message = $paystack_response->message ?? __( 'Unable to initialize payment', 'tbz-camptix-paystack' );

		$camptix->error( 'Error: ' . $error_message );

		return $camptix->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED );
	}


	public function payment_return() {
		global $camptix;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$payment_token = isset( $_REQUEST['tix_payment_token'] ) ? sanitize_text_field( trim( $_REQUEST['tix_payment_token'] ) ) : '';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$reference = isset( $_REQUEST['reference'] ) ? sanitize_text_field( trim( $_REQUEST['reference'] ) ) : '';

		$camptix->log( 'User returning from Paystack', null, compact( 'payment_token', 'reference' ) );

		if ( ! $payment_token || ! $reference ) {
			$camptix->log(
				__( 'Bailing because invalid Paystack return data', 'tbz-camptix-paystack' ),
				null,
				compact( 'payment_token', 'reference' )
			);

			wp_die( esc_html__( 'empty payment reference.', 'tbz-camptix-paystack' ) );
		}

		$order = $this->get_order( $payment_token );

		if ( ! $order ) {
			$camptix->log( "Bailing because couldn't find order", null, compact( 'payment_token' ) );

			wp_die( esc_html__( 'could not find order.', 'tbz-camptix-paystack' ) );
		}

		$paystack_transaction = $this->get_paystack_transaction( $reference );

		if ( ! $paystack_transaction ) {
			$payment_data = array(
				'error' => __( 'Error verifying payment', 'tbz-camptix-paystack' ),
				'data'  => array(),
			);
			$camptix->log( __( 'Error verifying payment', 'tbz-camptix-paystack' ), $order['attendee_id'] );

			return $camptix->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED, $payment_data );
		}

		if ( 'success' === strtolower( $paystack_transaction->data->status ) ) {
			$txn_id = $paystack_transaction->data->reference;

			$camptix->log( sprintf( 'Payment details for %s', $txn_id ), $order['attendee_id'], $paystack_transaction );

			$payment_data = array(
				'transaction_id'      => $txn_id,
				'transaction_details' => array(
					'raw' => $paystack_transaction,
				),
			);

			return $camptix->payment_result(
				$payment_token,
				CampTix_Plugin::PAYMENT_STATUS_COMPLETED,
				$payment_data
			);
		}

		$payment_data = array(
			'error' => __( 'Error verifying payment', 'tbz-camptix-paystack' ),
			'data'  => $paystack_transaction,
		);
		$camptix->log( __( 'Error verifying payment', 'tbz-camptix-paystack' ), $payment_data );

		return $camptix->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED, $payment_data );
	}


	public function payment_notify() {
		global $camptix;

		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) ) {
			exit;
		}

		if ( strtoupper( $_SERVER['REQUEST_METHOD'] ) !== 'POST' ) {
			exit;
		}

		if ( ! array_key_exists( 'HTTP_X_PAYSTACK_SIGNATURE', $_SERVER ) ) {
			exit;
		}

		$json = file_get_contents( 'php://input' );

		$options = $this->options;

		$secret_key = $options['sandbox'] ? $options['test_secret_key'] : $options['live_secret_key'];

		// validate event do all at once to avoid timing attack
		if ( hash_hmac( 'sha512', $json, $secret_key ) !== $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ) {
			exit;
		}

		$event = json_decode( $json );

		if ( 'charge.success' !== strtolower( $event->event ) ) {
			exit;
		}

		$paystack_transaction = $this->get_paystack_transaction( $event->data->reference );

		if ( ! $paystack_transaction ) {
			return;
		}

		http_response_code( 200 );

		if ( 'success' !== strtolower( $paystack_transaction->data->status ) ) {
			return;
		}

		$transaction_id = $paystack_transaction->data->reference;

		$attendees = get_posts(
			array(
				'posts_per_page' => 1,
				'post_type'      => 'tix_attendee',
				'post_status'    => 'any',
				'meta_query'     => array(
					array(
						'key'   => 'tix_transaction_id',
						'value' => $transaction_id,
					),
				),
			)
		);

		if ( empty( $attendees ) ) {
			return;
		}

		if ( 'publish' === strtolower( $attendees[0]->post_status ) ) {
			return; // todo I think this can be removed.
		}

		$attendee_id = $attendees[0]->ID;

		$payment_token = get_post_meta( $attendee_id, 'tix_payment_token', true );

		$camptix->log( sprintf( 'Payment details for %s', $transaction_id ), $attendee_id, $transaction_id );

		$payment_data = array(
			'transaction_id'      => $transaction_id,
			'transaction_details' => array(
				'raw' => $paystack_transaction,
			),
		);

		$camptix->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_COMPLETED, $payment_data, false );

		exit();
	}

	/**
	 * Check if a Paystack payment timed out.
	 */
	public function pre_attendee_timeout( $attendee_id ) {
		global $camptix;

		if ( 'draft' !== get_post_field( 'post_status', $attendee_id ) ) {
			return;
		}

		$payment_method = get_post_meta( $attendee_id, 'tix_payment_method', true );

		if ( 'paystack' !== strtolower( $payment_method ) ) {
			return;
		}

		$transaction_id = get_post_meta( $attendee_id, 'tix_transaction_id', true );
		$payment_token  = get_post_meta( $attendee_id, 'tix_payment_token', true );
		if ( ! $transaction_id || ! $payment_token ) {
			return;
		}

		$paystack_transaction = $this->get_paystack_transaction( $transaction_id );

		if ( ! $paystack_transaction ) {
			return;
		}

		if ( 'success' !== strtolower( $paystack_transaction->data->status ) ) {
			return;
		}

		$txn_id = $paystack_transaction->data->reference;

		$camptix->log( sprintf( 'Paystack Payment details for %s', $txn_id ), $attendee_id, $paystack_transaction );

		$payment_data = array(
			'transaction_id'      => $txn_id,
			'transaction_details' => array(
				'raw' => $paystack_transaction,
			),
		);

		$camptix->payment_result(
			$payment_token,
			CampTix_Plugin::PAYMENT_STATUS_COMPLETED,
			$payment_data,
			false
		);
	}

	private function get_paystack_transaction( $reference ) {
		$secret_key = $this->options['sandbox'] ? $this->options['test_secret_key'] : $this->options['live_secret_key'];

		$paystack_url = 'https://api.paystack.co/transaction/verify/' . rawurlencode( $reference );

		$headers = array(
			'Content-Type'  => 'application/json',
			'Authorization' => 'Bearer ' . $secret_key,
		);

		$args = array(
			'headers' => $headers,
			'timeout' => 60,
		);

		$request = wp_remote_get( $paystack_url, $args );

		if ( ! is_wp_error( $request ) && 200 === wp_remote_retrieve_response_code( $request ) ) {
			return json_decode( wp_remote_retrieve_body( $request ) );
		}

		return false;
	}
}
