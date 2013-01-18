<?php
/**
 * This class provides a model for a payment processor. To implement a
 * different credit card payment gateway, create a new class that extends
 * Group_Buying_Credit_Card_Processors. The new class should implement
 * the following methods (at a minimum):
 *  - get_instance()
 *  - process_payment()
 *  - register()
 *  - get_payment_method()
 *
 * You may also want to register some settings for the Payment Options page
 */

class Group_Buying_ArgoFire extends Group_Buying_Credit_Card_Processors {
	const API_ENDPOINT_SANDBOX = 'https://dev.ftipgw.com/smartpayments/transact.asmx/ProcessCreditCard';
	const API_ENDPOINT_LIVE = 'https://secure.ftipgw.com/smartpayments/transact.asmx/ProcessCreditCard';
	const MODE_TEST = 'sandbox';
	const MODE_LIVE = 'live';
	const API_VENDER_OPTION = 'gb_argofire_vender';
	const API_MERCHANT_OPTION = 'gb_argofire_merchant';
	const API_USERNAME_OPTION = 'gb_argofire_username';
	const API_PASSWORD_OPTION = 'gb_argofire_password';
	const API_MODE_OPTION = 'gb_argofire_mode';
	const PAYMENT_METHOD = 'Credit (ArgoFire)';
	const AVS_CHECK = FALSE;
	protected static $instance;
	private $api_mode = self::MODE_TEST;
	private $api_username = '';
	private $api_password = '';

	public static function get_instance() {
		if ( !( isset( self::$instance ) && is_a( self::$instance, __CLASS__ ) ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function get_api_url() {
		if ( $this->api_mode == self::MODE_LIVE ) {
			return self::API_ENDPOINT_LIVE;
		} else {
			return self::API_ENDPOINT_SANDBOX;
		}
	}

	public function get_payment_method() {
		return self::PAYMENT_METHOD;
	}

	protected function __construct() {
		parent::__construct();
		$this->api_vendor = get_option( self::API_VENDER_OPTION, '' );
		$this->api_merchant = get_option( self::API_MERCHANT_OPTION, '' );
		$this->api_username = get_option( self::API_USERNAME_OPTION, '' );
		$this->api_password = get_option( self::API_PASSWORD_OPTION, '' );
		$this->api_mode = get_option( self::API_MODE_OPTION, self::MODE_TEST );

		add_action( 'admin_init', array( $this, 'register_settings' ), 10, 0 );
		add_action( 'purchase_completed', array( $this, 'complete_purchase' ), 10, 1 );

		// Limitations
		add_filter( 'group_buying_template_meta_boxes/deal-expiration.php', array( $this, 'display_exp_meta_box' ), 10 );
		add_filter( 'group_buying_template_meta_boxes/deal-price.php', array( $this, 'display_price_meta_box' ), 10 );
		add_filter( 'group_buying_template_meta_boxes/deal-limits.php', array( $this, 'display_limits_meta_box' ), 10 );
	}

	public static function register() {
		self::add_payment_processor( __CLASS__, self::__( 'ArgoFire' ) );
	}

	public static function accepted_cards() {
		$accepted_cards = array(
			'visa',
			'mastercard',
			'amex',
			'diners',
			// 'discover',
			'jcb',
			// 'maestro'
		);
		return apply_filters( 'gb_accepted_credit_cards', $accepted_cards );
	}

	/**
	 * Process a payment
	 *
	 * @param Group_Buying_Checkouts $checkout
	 * @param Group_Buying_Purchase $purchase
	 * @return Group_Buying_Payment|bool FALSE if the payment failed, otherwise a Payment object
	 */
	public function process_payment( Group_Buying_Checkouts $checkout, Group_Buying_Purchase $purchase ) {
		if ( $purchase->get_total( $this->get_payment_method() ) < 0.01 ) {
			// Nothing to do here, another payment handler intercepted and took care of everything
			// See if we can get that payment and just return it
			$payments = Group_Buying_Payment::get_payments_for_purchase( $purchase->get_id() );
			foreach ( $payments as $payment_id ) {
				$payment = Group_Buying_Payment::get_instance( $payment_id );
				return $payment;
			}
		}

		$post_data = $this->txn_data( $checkout, $purchase );
		if ( self::DEBUG ) error_log( '----------ArgoFire DATA----------' . print_r( $post_data, true ) );

		$post_data_array = array();
		foreach ( $post_data as $key => $value ) {
			$post_data_array[] = $key . '='. $value;
		}
		$post_string = implode( "&", $post_data_array );
		if ( self::DEBUG ) error_log( "post_string: " . print_r( $post_string, true ) );

		$raw_response = wp_remote_post( $this->get_api_url(), array(
				'method' => 'POST',
				'body' => $post_string,
				'timeout' => apply_filters( 'http_request_timeout', 15 ),
				'sslverify' => false
			) );

		if ( is_wp_error( $raw_response ) ) {
			return FALSE;
		}

		$xml = wp_remote_retrieve_body( $raw_response );
		$response = json_decode(json_encode((array) simplexml_load_string($xml)), 1);

		if ( self::DEBUG ) error_log( '----------ArgoFire Response----------' . print_r( $response, TRUE ) );

		if ( $response['Result'] != 0 ) {
			$this->set_error_messages( (string) $response['RespMSG'] );
			return FALSE;
		}

		if ( self::AVS_CHECK && $response['GetAVSResult'] != 'Y' ) {
			$this->set_error_messages( 'AVS Failed: ' . $response['GetAVSResultTXT'] );
			return FALSE;
		}

		$deal_info = array(); // creating purchased products array for payment below
		foreach ( $purchase->get_products() as $item ) {
			if ( isset( $item['payment_method'][$this->get_payment_method()] ) ) {
				if ( !isset( $deal_info[$item['deal_id']] ) ) {
					$deal_info[$item['deal_id']] = array();
				}
				$deal_info[$item['deal_id']][] = $item;
			}
		}
		if ( isset( $checkout->cache['shipping'] ) ) {
			$shipping_address = array();
			$shipping_address['first_name'] = $checkout->cache['shipping']['first_name'];
			$shipping_address['last_name'] = $checkout->cache['shipping']['last_name'];
			$shipping_address['street'] = $checkout->cache['shipping']['street'];
			$shipping_address['city'] = $checkout->cache['shipping']['city'];
			$shipping_address['zone'] = $checkout->cache['shipping']['zone'];
			$shipping_address['postal_code'] = $checkout->cache['shipping']['postal_code'];
			$shipping_address['country'] = $checkout->cache['shipping']['country'];
		}
		$payment_id = Group_Buying_Payment::new_payment( array(
				'payment_method' => $this->get_payment_method(),
				'purchase' => $purchase->get_id(),
				'amount' => $post_data['amount'],
				'data' => array(
					'api_response' => $response,
					'masked_cc_number' => $this->mask_card_number( $this->cc_cache['cc_number'] ), // save for possible credits later,
					'uncaptured_deals' => $deal_info
				),
				'transaction_id' => $response['PNRef'],
				'deals' => $deal_info,
				'shipping_address' => $shipping_address,
			), Group_Buying_Payment::STATUS_AUTHORIZED );
		if ( !$payment_id ) {
			return FALSE;
		}
		$payment = Group_Buying_Payment::get_instance( $payment_id );
		do_action( 'payment_authorized', $payment );
		$payment->set_data( $response );
		return $payment;
	}

	/**
	 * Complete the purchase after the process_payment action, otherwise vouchers will not be activated.
	 *
	 * @param Group_Buying_Purchase $purchase
	 * @return void
	 */
	public function complete_purchase( Group_Buying_Purchase $purchase ) {
		$items_captured = array(); // Creating simple array of items that are captured
		foreach ( $purchase->get_products() as $item ) {
			$items_captured[] = $item['deal_id'];
		}
		$payments = Group_Buying_Payment::get_payments_for_purchase( $purchase->get_id() );
		foreach ( $payments as $payment_id ) {
			$payment = Group_Buying_Payment::get_instance( $payment_id );
			do_action( 'payment_captured', $payment, $items_captured );
			do_action( 'payment_complete', $payment );
			$payment->set_status( Group_Buying_Payment::STATUS_COMPLETE );
		}
	}


	/**
	 * Grabs error messages from a ArgoFire response and displays them to the user
	 *
	 * @param array   $response
	 * @param bool    $display
	 * @return void
	 */
	private function set_error_messages( $response, $display = TRUE ) {
		if ( $display ) {
			self::set_message( $response, self::MESSAGE_STATUS_ERROR );
		} else {
			error_log( $response );
		}
	}

	/**
	 * Build the NVP data array for submitting the current checkout to ArgoFire as an Authorization request
	 *
	 * @param Group_Buying_Checkouts $checkout
	 * @param Group_Buying_Purchase $purchase
	 * @return array
	 */
	private function txn_data( Group_Buying_Checkouts $checkout, Group_Buying_Purchase $purchase ) {
		if ( self::DEBUG ) error_log( "checkout: " . print_r( $checkout->cache, true ) );
		$user = get_userdata( $purchase->get_user() );

		$txn_data= array();
		//$txn_data['vendor'] = $this->api_vendor;
		//$txn_data['merchant'] = $this->api_merchant;
		$txn_data['UserName'] = $this->api_username;
		$txn_data['Password'] = $this->api_password;
		$txn_data['PNRef'] = '';

		$txn_data['TransType'] = 'Sale'; // TransType - Auth | Sale | Return | Void | Force | Capture | RepeatSale | CaptureAll | Adjustment
		$txn_data['CardNum'] = $this->cc_cache['cc_number'];
		$txn_data['ExpDate'] = substr( '0' . $this->cc_cache['cc_expiration_month'], -2 ) . substr( $this->cc_cache['cc_expiration_year'], -2 );

		$txn_data['MagData'] = ''; 
		$txn_data['NameOnCard'] = $this->cc_cache['cc_name'];

		$txn_data['Amount'] = gb_get_number_format( $purchase->get_total( $this->get_payment_method() ) );

		$txn_data['InvNum'] = $purchase->get_id(); // Optional

		$txn_data['PNRef'] = ''; // Reference number assigned by the payment server
		
		$txn_data['Zip'] = $checkout->cache['billing']['postal_code'];
		$txn_data['Street'] = $checkout->cache['billing']['street'];

		$txn_data['CVNum'] = $this->cc_cache['cc_cvv'];
		/**/
		$txn_data['ExtData']['City'] = $checkout->cache['billing']['city'];
		$txn_data['ExtData']['BillToState'] = $checkout->cache['billing']['zone'];
		$txn_data['ExtData']['CustomerID'] = $user->ID;

		$txn_data['ExtData']['ShippingAmt'] = gb_get_number_format( $purchase->get_shipping_total( $this->get_payment_method() ) );
		$txn_data['ExtData']['TaxAmt'] = gb_get_number_format( $purchase->get_tax_total( $this->get_payment_method() ) );
		/**/
		return $txn_data;
	}

	/**
	 * The the NVP data for submitting a DoCapture request
	 *
	 * @param string  $transaction_id
	 * @param array   $items
	 * @param string  $status
	 * @return array
	 */
	private function capture_txn_data( $transaction_id, $items, $status = 'Complete' ) {
		$total = 0;
		foreach ( $items as $price ) {
			$total += $price;
		}
		$txn_data= array();
		$txn_data['UserName'] = $this->api_username;
		$txn_data['Password'] = $this->api_password;
		$txn_data['TransType'] = 'Capture';
		$txn_data['PNRef'] = $transaction_id;
		$txn_data['Amount'] = $transaction_id;

		return $txn_data;
	}

	public function register_settings() {
		$page = Group_Buying_Payment_Processors::get_settings_page();
		$section = 'gb_authorizenet_settings';
		add_settings_section( $section, self::__( 'ArgoFire' ), array( $this, 'display_settings_section' ), $page );
		register_setting( $page, self::API_MODE_OPTION );
		register_setting( $page, self::API_VENDER_OPTION );
		register_setting( $page, self::API_MERCHANT_OPTION );
		register_setting( $page, self::API_USERNAME_OPTION );
		register_setting( $page, self::API_PASSWORD_OPTION );
		add_settings_field( self::API_MODE_OPTION, self::__( 'Mode' ), array( $this, 'display_api_mode_field' ), $page, $section );
		// add_settings_field( self::API_VENDER_OPTION, self::__( 'Partner/ResellerKey' ), array( $this, 'display_api_vendor_field' ), $page, $section );
		// add_settings_field( self::API_MERCHANT_OPTION, self::__( 'Vendor/MerchantKey/RPNum' ), array( $this, 'display_api_merchant_field' ), $page, $section );
		add_settings_field( self::API_USERNAME_OPTION, self::__( 'Username' ), array( $this, 'display_api_username_field' ), $page, $section );
		add_settings_field( self::API_PASSWORD_OPTION, self::__( 'Password' ), array( $this, 'display_api_password_field' ), $page, $section );
		//add_settings_field(null, self::__('Currency'), array($this, 'display_currency_code_field'), $page, $section);
	}

	public function display_api_vendor_field() {
		echo '<input type="text" name="'.self::API_VENDER_OPTION.'" value="'.$this->api_vendor.'" size="80" />';
	}

	public function display_api_merchant_field() {
		echo '<input type="text" name="'.self::API_MERCHANT_OPTION.'" value="'.$this->api_merchant.'" size="80" />';
	}

	public function display_api_username_field() {
		echo '<input type="text" name="'.self::API_USERNAME_OPTION.'" value="'.$this->api_username.'" size="80" />';
	}

	public function display_api_password_field() {
		echo '<input type="text" name="'.self::API_PASSWORD_OPTION.'" value="'.$this->api_password.'" size="80" />';
	}

	public function display_api_mode_field() {
		echo '<label><input type="radio" name="'.self::API_MODE_OPTION.'" value="'.self::MODE_LIVE.'" '.checked( self::MODE_LIVE, $this->api_mode, FALSE ).'/> '.self::__( 'Live' ).'</label><br />';
		echo '<label><input type="radio" name="'.self::API_MODE_OPTION.'" value="'.self::MODE_TEST.'" '.checked( self::MODE_TEST, $this->api_mode, FALSE ).'/> '.self::__( 'Sandbox' ).'</label>';
	}

	public function display_currency_code_field() {
		echo 'Specified in your ArgoFire Merchant Interface.';
	}

	public function display_exp_meta_box() {
		return GB_PATH . '/controllers/payment_processors/meta-boxes/exp-only.php';
	}

	public function display_price_meta_box() {
		return GB_PATH . '/controllers/payment_processors/meta-boxes/no-dyn-price.php';
	}

	public function display_limits_meta_box() {
		return GB_PATH . '/controllers/payment_processors/meta-boxes/no-tipping.php';
	}
}
Group_Buying_ArgoFire::register();
