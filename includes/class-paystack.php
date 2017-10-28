<?php


class CampTix_Payment_Method_Paystack extends CampTix_Payment_Method {
	/**
	 * The following variables are required for every payment method.
	 */
	public $id = 'paystack';
	public $name = 'Paystack';
	public $description = 'Paystack';

	public $supported_currencies = array( 'NGN' );

	/**
	 * We can have an array to store our options.
	 * Use $this->get_payment_options() to retrieve them.
	 */
	protected $options = array();

	function camptix_init() {
		$this->options = array_merge( array(
			'test_public_key'    => '',
			'test_secret_key'  => '',
			'live_public_key'  => '',
			'live_secret_key' => '',
			'sandbox'       => true,
		), $this->get_payment_options() );

		add_action( 'template_redirect', array( $this, 'template_redirect' ) );
	}


	function payment_settings_fields() {

		$this->add_settings_field_helper( 'test_secret_key', 'Test Secret Key', array( $this, 'field_text' ) );
		$this->add_settings_field_helper( 'test_public_key', 'Test Public Key', array( $this, 'field_text' ) );

		$this->add_settings_field_helper( 'live_secret_key', 'Live Secret Key', array( $this, 'field_text' ) );
		$this->add_settings_field_helper( 'live_public_key', 'Live Public Key', array( $this, 'field_text' ) );

		$this->add_settings_field_helper( 'sandbox', 'Test Mode', array( $this, 'field_yesno' ),
			'The Paystack Test Mode is a way to test payments without using real accounts and transactions.'
		);

	}


	function validate_options( $input ) {

		$output = $this->options;

		if ( isset( $input['test_public_key'] ) ) {
			$output['test_public_key'] = $input['test_public_key'];
		}

		if ( isset( $input['test_secret_key'] ) ) {
			$output['test_secret_key'] = $input['test_secret_key'];
		}

		if ( isset( $input['live_public_key'] ) ) {
			$output['live_public_key'] = $input['live_public_key'];
		}

		if ( isset( $input['live_secret_key'] ) ) {
			$output['live_secret_key'] = $input['live_secret_key'];
		}

		if ( isset( $input['sandbox'] ) ) {
			$output['sandbox'] = (bool) $input['sandbox'];
		}

		return $output;

	}


	function template_redirect() {

		if ( ! isset( $_REQUEST['tix_payment_method'] ) || 'paystack' != $_REQUEST['tix_payment_method'] ) {
			return;
		}

		if ( isset( $_GET['tix_action'] ) ) {
			if ( 'payment_return' == $_GET['tix_action'] ) {
				$this->payment_return();
			}

			if ( 'payment_notify' == $_GET['tix_action'] ) {
				$this->payment_notify();
			}
		}

	}


	function payment_notify() {
		/** @var $camptix CampTix_Plugin */
		global $camptix;

		$payment_token = isset( $_REQUEST['tix_payment_token'] ) ? trim( $_REQUEST['tix_payment_token'] ) : '';

		// Verify the IPN came from PayPal.
		$payload = stripslashes_deep( $_POST );
		$response = $this->verify_ipn( $payload );
		if ( '200' != wp_remote_retrieve_response_code( $response ) || 'VERIFIED' != wp_remote_retrieve_body( $response ) ) {
			$camptix->log( 'Could not verify PayPal IPN.', 0, null );
			return;
		}

		// Grab the txn id (or the parent id in case of refunds, cancels, etc)
		$txn_id = ! empty( $payload['txn_id'] ) ? $payload['txn_id'] : 'None';
		if ( ! empty( $payload['parent_txn_id'] ) ) {
			$txn_id = $payload['parent_txn_id'];
		}

		// Make sure we have a status
		if ( empty( $payload['payment_status'] ) ) {
			$camptix->log( sprintf( 'Received IPN with no payment status %s', $txn_id ), 0, $payload );
			return;
		}

		// Fetch latest transaction details to avoid race conditions.
		$txn_details_payload = array(
			'METHOD' => 'GetTransactionDetails',
			'TRANSACTIONID' => $txn_id,
		);
		$txn_details = wp_parse_args( wp_remote_retrieve_body( $this->request( $txn_details_payload ) ) );
		if ( ! isset( $txn_details['ACK'] ) || 'Success' != $txn_details['ACK'] ) {
			$camptix->log( sprintf( 'Fetching transaction after IPN failed %s.', $txn_id, 0, $txn_details ) );
			return;
		}

		$camptix->log( sprintf( 'Payment details for %s via IPN', $txn_id ), null, $txn_details );
		$payment_status = $txn_details['PAYMENTSTATUS'];

		$payment_data = array(
			'transaction_id' => $txn_id,
			'transaction_details' => array(
				// @todo maybe add more info about the payment
				'raw' => $txn_details,
			),
		);

		/**
		 * Returns the payment result back to CampTix. Don't be afraid to return a
		 * payment result twice. In fact, it's typical for payment methods with IPN support.
		 */
		return $camptix->payment_result( $payment_token, $this->get_status_from_string( $payment_status ), $payment_data );
	}


	function payment_return() {

		/** @var $camptix CampTix_Plugin */
		global $camptix;

		$payment_token = isset( $_REQUEST['tix_payment_token'] ) ? trim( $_REQUEST['tix_payment_token'] ) : '';

		$reference      = isset( $_REQUEST['reference'] ) ? trim( $_REQUEST['reference'] ) : '';

		$camptix->log( 'User returning from Paystack', null, compact( 'payment_token', 'reference' ) );

		if ( ! $payment_token || ! $reference  ) {
			$camptix->log( 'Dying because invalid Paystack return data', null, compact( 'payment_token', 'reference' ) );
			wp_die( 'empty payment reference' );
		}

		$order = $this->get_order( $payment_token );

		if ( ! $order ) {
			$camptix->log( "Dying because couldn't find order", null, compact( 'payment_token' ) );
			wp_die( 'could not find order' );
		}

		$options = $this->options;

		$secret_key = $options['sandbox'] ? $options['test_secret_key'] : $options['live_secret_key'];

		$paystack_url 	= 'https://api.paystack.co/transaction/verify/'  . rawurlencode($reference);

		$headers = array(
			'Content-Type'	=> 'application/json',
			'Authorization' => 'Bearer ' . $secret_key,
			'Cache-Control'	=> 'no-cache'
		);

		$args = array(
			'headers'	=> $headers,
			'timeout'	=> 60
		);

		$request = wp_remote_get( $paystack_url, $args );

        if ( ! is_wp_error( $request ) && 200 == wp_remote_retrieve_response_code( $request ) ) {

        	$txn = json_decode( wp_remote_retrieve_body( $request ) );

        	if( 'success' == $txn->data->status ) {

				$txn_id = $txn->data->reference;

				$camptix->log( sprintf( 'Payment details for %s', $txn_id ), $order['attendee_id'], $txn );

				/**
				 * Note that when returning a successful payment, CampTix will be
				 * expecting the transaction_id and transaction_details array keys.
				 */
				$payment_data = array(
					'transaction_id' => $txn_id,
					'transaction_details' => array(
						'raw' => $txn,
					),
				);

				return $camptix->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_COMPLETED, $payment_data );

        	} else {

				$payment_data = array(
					'error' => 'Error verifying payment',
					'data' => $txn,
				);

				$camptix->log( 'Error verifying payment.', $order['attendee_id'], $txn );
				return $camptix->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED, $payment_data );

        	}

		} else {

        	$txn = json_decode( wp_remote_retrieve_body( $request ) );

			$payment_data = array(
				'error' => 'Error verifying payment',
				'data' => $txn,
			);
			$camptix->log( 'Error verifying payment.', $order['attendee_id'], $txn );
			return $camptix->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED, $payment_data );

		}
	}


	function payment_checkout( $payment_token ) {

		global $camptix;

		if ( ! $payment_token || empty( $payment_token ) )
			return false;

		if ( ! in_array( $this->camptix_options['currency'], $this->supported_currencies ) ) {
			wp_die( 'The selected currency is not supported by this payment method.' );
		}

		$return_url = add_query_arg( array(
			'tix_action'         => 'payment_return',
			'tix_payment_token'  => $payment_token,
			'tix_payment_method' => 'paystack',
		), $camptix->get_tickets_url() );

		$options = $this->options;

		$order = $this->get_order( $payment_token );

		$attendee_email = get_post_meta( $order['attendee_id'], 'tix_receipt_email', true );

		$secret_key = $options['sandbox'] ? $options['test_secret_key'] : $options['live_secret_key'];

		$paystack_url 	= 'https://api.paystack.co/transaction/initialize';

		$headers = array(
			'Content-Type'	=> 'application/json',
			'Authorization' => 'Bearer ' . $secret_key,
			'Cache-Control'	=> 'no-cache'
		);

		$body = array(
			'amount'		=> $order['total'] * 100,
			'email'			=> $attendee_email,
			'callback_url'	=> $return_url
		);

		$args = array(
			'body'		=> json_encode( $body ),
			'headers'	=> $headers,
			'timeout'	=> 60
		);

		$request = wp_remote_post( $paystack_url, $args );

        if ( ! is_wp_error( $request ) && 200 == wp_remote_retrieve_response_code( $request ) ) {

        	$paystack_response = json_decode( wp_remote_retrieve_body( $request ) );

        	$transaction_reference = $paystack_response->data->reference;

        	$payment_url = $paystack_response->data->authorization_url;

			wp_redirect( $payment_url );

			die();

        } else {

        	$paystack_response = json_decode( wp_remote_retrieve_body( $request ) );

			$camptix->error( 'Paystack error: ' . $paystack_response->message );

			return $camptix->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED, array() );

        }
	}

	/**
	 * Validate an incoming IPN request
	 *
	 * @param array $payload
	 *
	 * @return mixed A WP_Error for a failed request, or an array for a successful response
	 */
	function verify_ipn( $payload = array() ) {
		// Replace credentials from a predefined account if any.
		$options = $this->options;

		$url          = $options['sandbox'] ? 'https://www.sandbox.paypal.com/cgi-bin/webscr' : 'https://www.paypal.com/cgi-bin/webscr';
		$payload      = 'cmd=_notify-validate&' . http_build_query( $payload );
		$request_args = array(
			'body'        => $payload,
			'timeout'     => apply_filters( 'camptix_paypal_timeout', 20 ),
			'httpversion' => '1.1'
		);

		return wp_remote_post( $url, $request_args );
	}

}