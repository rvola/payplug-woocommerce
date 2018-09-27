<?php

namespace Payplug\PayplugWoocommerce\Gateway;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Payplug\Authentication;
use Payplug\Exception\ConfigurationException;
use Payplug\Exception\HttpException;
use Payplug\Payplug;
use Payplug\PayplugWoocommerce\PayplugWoocommerceHelper;
use Payplug\Resource\Payment as PaymentResource;
use Payplug\Resource\Refund as RefundResource;
use WC_Payment_Gateway_CC;
use WC_Payment_Tokens;

/**
 * PayPlug WooCommerce Gateway.
 *
 * @package Payplug\PayplugWoocommerce\Gateway
 */
class PayplugGateway extends WC_Payment_Gateway_CC {

	/**
	 * @var PayplugGatewayRequirements
	 */
	private $requirements;

	/**
	 * @var PayplugPermissions
	 */
	private $permissions;

	/**
	 * @var PayplugResponse
	 */
	public $response;

	/**
	 * @var PayplugApi
	 */
	public $api;

	/**
	 * @var \WC_Logger
	 */
	protected static $log;

	/**
	 * @var bool
	 */
	protected static $log_enabled;

	/**
	 * Logging method.
	 *
	 * @param string $message Log message.
	 * @param string $level Optional. Default 'info'.
	 *     emergency|alert|critical|error|warning|notice|info|debug
	 */
	public static function log( $message, $level = 'info' ) {
		if ( self::$log_enabled ) {
			if ( empty( self::$log ) ) {
				self::$log = wc_get_logger();
			}
			self::$log->log( $level, $message, array( 'source' => 'payplug_gateway' ) );
		}
	}

	public function __construct() {
		$this->id                 = 'payplug';
		$this->icon               = '';
		$this->has_fields         = false;
		$this->method_title       = _x( 'PayPlug', 'Gateway method title', 'payplug' );
		$this->method_description = __( 'Enable PayPlug for your customers.', 'payplug' );
		$this->supports           = array(
			'products',
			'refunds',
			'tokenization',
		);
		$this->new_method_label = __( 'Pay with another credit card', 'payplug' );

		$this->init_settings();
		$this->requirements = new PayplugGatewayRequirements( $this );
		if ( $this->user_logged_in() ) {
			$this->init_payplug();
		}
		$this->init_form_fields();

		$this->title          = __( 'Credit card checkout', 'payplug' );
		$this->description    = ' ';
		$this->mode           = 'yes' === $this->get_option( 'mode', 'no' ) ? 'live' : 'test';
		$this->debug          = 'yes' === $this->get_option( 'debug', 'no' );
		$this->email          = $this->get_option( 'email' );
		$this->payment_method = $this->get_option( 'payment_method' );
		$this->oneclick       = 'yes' === $this->get_option( 'oneclick', 'no' );

		self::$log_enabled = $this->debug;

		if ( 'test' === $this->mode ) {
			$this->description = ( ! empty( $this->description ) ) ? " \n" : '';
			$this->description .= __( 'You are in TEST MODE. In test mode you can use the card 4242424242424242 with any valid expiration date and CVC.', 'payplug' );
			$this->description = trim( $this->description );
		}

		add_action( 'wp_enqueue_scripts', [ $this, 'scripts' ] );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
		add_action( 'the_post', [ $this, 'validate_payment' ] );

		add_filter( 'woocommerce_get_customer_payment_tokens', [ $this, 'filter_tokens' ], 10, 3 );
	}

	/**
	 * Validate order payment when the user is redirected to the success confirmation page.
	 *
	 * @throws \WC_Data_Exception
	 */
	public function validate_payment() {
		if ( ! is_wc_endpoint_url( 'order-received' ) || empty( $_GET['key'] ) ) {
			return;
		}

		$order_id = wc_get_order_id_by_order_key( wc_clean( $_GET['key'] ) );
		if ( empty( $order_id ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$payment_method = PayplugWoocommerceHelper::is_pre_30() ? $order->payment_method : $order->get_payment_method();
		if ( 'payplug' !== $payment_method ) {
			return;
		}

		$transaction_id = PayplugWoocommerceHelper::is_pre_30() ? get_post_meta( $order_id, '_transaction_id', true ) : $order->get_transaction_id();
		if ( empty( $transaction_id ) ) {
			PayplugGateway::log( sprintf( 'Order #%s : Missing transaction id.', $order_id ), 'error' );

			return;
		}

		try {
			$payment = $this->api->payment_retrieve( $transaction_id );
		} catch ( \Exception $e ) {
			PayplugGateway::log(
				sprintf( 'Order #%s : An error occurred while retrieving the payment data with the message : %s',
					$order_id,
					$e->getMessage()
				)
			);

			return;
		}

		$this->response->process_payment( $payment );
	}

	/**
	 * Get payment icons.
	 *
	 * @return string
	 */
	public function get_icon() {
		$icons = apply_filters( 'payplug_payment_icons', [
			'payplug' => '<img src="' . PAYPLUG_GATEWAY_PLUGIN_URL . '/assets/images/cards_icons.svg" alt="Visa & Mastercard" class="payplug-payment-icon" />',
		] );

		$icons_str = '';
		foreach ( $icons as $icon ) {
			$icons_str .= $icon;
		}

		return $icons_str;
	}

	/**
	 * Check if this gateway is enabled
	 */
	public function is_available() {
		if ( 'yes' === $this->enabled ) {
			return $this->requirements->satisfy_requirements() && ! empty( $this->get_api_key( $this->get_current_mode() ) );
		}

		return parent::is_available();
	}

	/**
	 * Load gateway settings.
	 */
	public function init_settings() {
		parent::init_settings();
		$this->enabled = ! empty( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'] ? 'yes' : 'no';
	}

	/**
	 * Register gateway settings.
	 */
	public function init_form_fields() {
		$fields = [
			'enabled'                 => [
				'title'       => __( 'Enable/Disable', 'payplug' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable PayPlug', 'payplug' ),
				'description' => __( 'Only Euro payments can be processed with PayPlug.', 'payplug' ),
				'default'     => 'no',
			],
			'title_connexion'         => [
				'title' => __( 'Connection', 'payplug' ),
				'type'  => 'title',
			],
			'email'                   => [
				'type'    => 'hidden',
				'default' => '',
			],
			'login'                   => [
				'type'    => 'login',
				'default' => '',
			],
			'payplug_test_key'        => [
				'type'    => 'hidden',
				'default' => '',
			],
			'payplug_live_key'        => [
				'type'    => 'hidden',
				'default' => '',
			],
			'payplug_merchant_id'     => [
				'type'    => 'hidden',
				'default' => '',
			],
			'title_testmode'          => [
				'title' => __( 'Mode', 'payplug' ),
				'type'  => 'title',
			],
			'mode'                    => [
				'title'       => '',
				'label'       => '',
				'type'        => 'yes_no',
				'yes'         => 'Live',
				'no'          => 'Test',
				'description' => __( 'In TEST mode, all payments will be simulations and will not generate real transactions.', 'payplug' ),
				'default'     => 'no',
				'hide_label'  => true,
			],
			'title_settings'          => [
				'title' => __( 'Settings', 'payplug' ),
				'type'  => 'title',
			],
			'payment_method'          => [
				'title'       => __( 'Payment page', 'payplug' ),
				'type'        => 'radio',
				'description' => __( 'Customers will be redirected to a PayPlug payment page to finalize the transaction, or payments will be performed in an embeddable payment form on your website.', 'payplug' ),
				'default'     => 'redirect',
				'desc_tip'    => true,
				'options'     => array(
					'redirect' => __( 'Redirect', 'payplug' ),
					'embedded' => __( 'Integrated', 'payplug' ),
				),
			],
			'debug'                   => [
				'title'   => __( 'Debug', 'payplug' ),
				'type'    => 'checkbox',
				'description' => __( 'Debug mode saves additional information on your server for each operation done via the PayPlug plugin (Developer setting).', 'payplug' ),
				'label'   => __( 'Activate debug mode', 'payplug' ),
				'default' => 'no',
				'desc_tip'    => true,
			],
			'title_advanced_settings' => [
				'title'       => __( 'Advanced Settings', 'payplug' ),
				'description' => __( 'This feature is available to PREMIUM accounts only. You can try it in TEST mode.',
					'payplug' ),
				'type'        => 'title',
			],
			'oneclick'                => [
				'title'       => __( 'One Click Payment', 'payplug' ),
				'type'        => 'checkbox',
				'label'       => __( 'Activate', 'payplug' ),
				'description' => __( 'Allow your customers to save their credit card information for later purchases.', 'payplug' ),
				'default'     => 'no',
				'desc_tip'    => true,
			],
		];

		// Disable One-Click checkbox if the user doesn't have the permission to use it.
		if ( $this->user_logged_in() && 'live' === $this->get_current_mode() ) {
			$fields['oneclick']['disabled'] = ! $this->permissions->has_permissions( PayplugPermissions::SAVE_CARD );
		}

		/**
		 * Filter PayPlug gateway settings.
		 *
		 * @param array $fields
		 */
		$fields            = apply_filters( 'payplug_gateway_settings', $fields );
		$this->form_fields = $fields;
	}

	/**
	 * Set global configuration for PayPlug instance.
	 */
	public function init_payplug() {
		$this->api = new PayplugApi( $this );
		$this->api->init();

		$this->permissions = new PayplugPermissions( $this );
		$this->response    = new PayplugResponse( $this );

		// Register IPN handler
		new PayplugIpnResponse( $this );
	}

	/**
	 * Embedded payment form scripts.
	 *
	 * Register scripts and additionnal data needed for the
	 * embedded payment form.
	 */
	public function scripts() {
		if ( ! is_cart() && ! is_checkout() && ! isset( $_GET['pay_for_order'] ) && ! is_add_payment_method_page() && ! isset( $_GET['change_payment_method'] ) ) {
			return;
		}

		// If PayPlug is not enabled bail.
		if ( 'no' === $this->enabled ) {
			return;
		}

		// If keys are not set bail.
		if ( empty( $this->get_api_key( $this->mode ) ) ) {
			PayplugGateway::log( 'Keys are not set correctly.' );

			return;
		}

		// Register checkout styles.
		wp_register_style( 'payplug-checkout', PAYPLUG_GATEWAY_PLUGIN_URL . 'assets/css/payplug-checkout.css', [], PAYPLUG_GATEWAY_VERSION );
		wp_enqueue_style( 'payplug-checkout' );


		// Register scripts for embedded payment form.
		if ( 'embedded' !== $this->payment_method ) {
			return;
		}

		wp_register_script( 'payplug', 'https://api.payplug.com/js/1.2/form.js', [], '1.2', true );
		wp_register_script( 'payplug-checkout', PAYPLUG_GATEWAY_PLUGIN_URL . 'assets/js/payplug-checkout.js', [
			'jquery',
			'payplug'
		], PAYPLUG_GATEWAY_VERSION, true );
		wp_localize_script( 'payplug-checkout', 'payplug_checkout_params', [
			'ajax_url' => \WC_AJAX::get_endpoint( 'payplug_create_order' ),
			'nonce'    => [
				'checkout' => wp_create_nonce( 'woocommerce-process_checkout' ),
			],
		] );
		wp_enqueue_script( 'payplug-checkout' );
	}

	/**
	 * Filter saved tokens for the gateway.
	 *
	 * A token will be removed if :
	 * - it doesn't match the current merchant logged in,
	 * - or it doesn't match the current gateway mode,
	 * - or it is expired.
	 *
	 * @param array $tokens
	 * @param int $user_id
	 * @param string $gateway_id
	 *
	 * @return array
	 */
	public function filter_tokens( $tokens, $user_id, $gateway_id ) {

		if ( ! is_user_logged_in() || ! class_exists( 'WC_Payment_Gateway_CC' ) ) {
			return $tokens;
		}

		/* @var \WC_Payment_Token_CC $token */
		foreach ( $tokens as $k => $token ) {

			if ( $this->id !== $token->get_gateway_id() ) {
				continue;
			}

			// check if token is associated with a merchant id and if it match the current one
			$token_merchant_id = $token->get_meta( 'payplug_account', true );
			if ( empty( $token_merchant_id ) || $this->get_merchant_id() !== $token_merchant_id ) {
				unset( $tokens[ $k ] );
				continue;
			}

			// check if token is available for the current gateway mode
			if ( $this->mode !== $token->get_meta( 'mode', true ) ) {
				unset( $tokens[ $k ] );
				continue;
			}

			// check if token is not expired
			$current_month = \absint( date( 'n' ) );
			$current_year  = \absint( date( 'Y' ) );
			if ( $current_year > (int) $token->get_expiry_year() ) {
				unset( $tokens[ $k ] );
				continue;
			}

			if ( $current_year === (int) $token->get_expiry_year() && $current_month >= (int) $token->get_expiry_month() ) {
				unset( $tokens[ $k ] );
				continue;
			}
		}

		return $tokens;
	}

	public function payment_fields() {
		$description = $this->get_description();
		if ( ! empty( $description ) ) {
			echo wpautop( wptexturize( $description ) );
		}

		if ( $this->oneclick_available() ) {
			$this->tokenization_script();
			$this->saved_payment_methods();
		}
	}

	/**
	 * Handle admin display.
	 */
	public function admin_options() {
		wp_enqueue_style(
			'payplug-gateway-style',
			PAYPLUG_GATEWAY_PLUGIN_URL . 'assets/css/app.css',
			[],
			PAYPLUG_GATEWAY_VERSION
		);

		$payplug_requirements = new PayplugGatewayRequirements( $this ); ?>

		<h2 class="title--logo"><?php esc_html( $this->get_method_title() ) ?></h2>
		<p><?php _e( sprintf( 'Version %s', PAYPLUG_GATEWAY_VERSION ) ); ?></p>
		<div class="payplug-requirements">
			<?php echo $payplug_requirements->curl_requirement(); ?>
			<?php echo $payplug_requirements->php_requirement(); ?>
			<?php echo $payplug_requirements->openssl_requirement(); ?>
			<?php echo $payplug_requirements->account_requirement(); ?>
			<?php echo $payplug_requirements->currency_requirement(); ?>
		</div>
		<?php echo wp_kses_post( wpautop( $this->get_method_description() ) ); ?>

		<?php if ( $this->user_logged_in() ) : ?>
			<table class="form-table">
				<?php $this->generate_settings_html( $this->get_form_fields() ); ?>
			</table>
		<?php else:
			$GLOBALS['hide_save_button'] = true; ?>
			<h3 class="wc-settings-sub-title"><?php _e( 'Connection', 'payplug' ); ?></h3>
			<table class="form-table">
				<tbody>
				<tr valign="top">
					<th scope="row" class="titledesc">
						<label for="payplug_email"><?php _e( 'Email', 'payplug' ); ?></label>
					</th>
					<td class="forminp">
						<fieldset>
							<legend class="screen-reader-text"><span><?php _e( 'Email', 'payplug' ); ?></span></legend>
							<input class="input-text regular-input" type="text" name="payplug_email" id="payplug_email"
							       value="" placeholder="<?php _e( 'your@email.com', 'payplug' ); ?>"/>
						</fieldset>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row" class="titledesc">
						<label for="payplug_password"><?php _e( 'Password', 'payplug' ); ?></label>
					</th>
					<td class="forminp">
						<fieldset>
							<legend class="screen-reader-text"><span><?php _e( 'Password', 'payplug' ); ?></span>
							</legend>
							<input class="input-text regular-input" type="password" name="payplug_password"
							       id="payplug_password" value=""/>
						</fieldset>
					</td>
				</tr>
				<tr valign="top">
					<td class="forminp">
						<input class="button" type="submit" value="<?php _e( 'Login', 'payplug' ); ?>">
						<?php wp_nonce_field( 'payplug_user_login', '_loginaction' ); ?>
					</td>
				</tr>
				</tbody>
			</table>
		<?php
		endif;
	}

	/**
	 * Process admin options.
	 *
	 * @return bool
	 */
	public function process_admin_options() {
		$data = $this->get_post_data();

		// Handle logout process
		if (
			isset( $data['submit_logout'] )
			&& false !== check_admin_referer( 'payplug_user_logout', '_logoutaction' )
		) {

			$this->permissions->clear_permissions();

			$data                        = get_option( $this->get_option_key() );
			$data['payplug_test_key']    = '';
			$data['payplug_live_key']    = '';
			$data['payplug_merchant_id'] = '';
			$data['enabled']             = 'no';
			$data['mode']                = 'no';
			update_option( $this->get_option_key(),
				apply_filters( 'woocommerce_settings_api_sanitized_fields_' . $this->id, $data ) );
			\WC_Admin_Settings::add_message( __( 'Successfully logged out.', 'payplug' ) );

			return true;
		}

		// Handle login process
		if (
			isset( $data['payplug_email'] )
			&& false !== check_admin_referer( 'payplug_user_login', '_loginaction' )
		) {
			$email    = $data['payplug_email'];
			$password = $data['payplug_password'];
			$response = $this->retrieve_user_api_keys( $email, $password );
			if ( is_wp_error( $response ) ) {
				\WC_Admin_Settings::add_error( $response->get_error_message() );

				return false;
			}

			// try to use the api keys to retrieve the merchant id
			$merchant_id = isset( $response['test'] ) ? $this->retrieve_merchant_id( $response['test'] ) : '';

			$this->init_form_fields();
			$fields = $this->get_form_fields();
			$data   = [];

			// Load existing values if the user is re-login.
			foreach ( $fields as $key => $field ) {
				if ( in_array( $field['type'], [ 'title', 'login' ] ) ) {
					continue;
				}

				switch ( $key ) {
					case 'enabled':
						$val = 'yes';
						break;
					case 'mode':
						$val = 'no';
						break;
					case 'payplug_test_key':
						$val = esc_attr( $response['test'] );
						break;
					case 'payplug_live_key':
						$val = esc_attr( $response['live'] );
						break;
					case 'payplug_merchant_id':
						$val = esc_attr( $merchant_id );
						break;
					case 'email':
						$val = esc_html( $email );
						break;
					default:
						$val = $this->get_option( $key );
				}

				$data[ $key ] = $val;
			}

			$this->set_post_data( $data );
			update_option( $this->get_option_key(),
				apply_filters( 'woocommerce_settings_api_sanitized_fields_' . $this->id, $data ) );
			\WC_Admin_Settings::add_message( __( 'Successfully logged in.', 'payplug' ) );

			return true;
		}

		// Don't let user without live key leave TEST mode.
		$mode_fieldkey     = $this->get_field_key( 'mode' );
		$live_key_fieldkey = $this->get_field_key( 'payplug_live_key' );
		if ( isset( $data[ $mode_fieldkey ] ) && '1' === $data[ $mode_fieldkey ] && empty( $data[ $live_key_fieldkey ] ) ) {
			$data[ $mode_fieldkey ] = '0';
			$this->set_post_data( $data );
			\WC_Admin_Settings::add_error( __( 'Your account does not support LIVE mode at the moment, it must be validated first. If your account has already been validated, please log out and log in again.', 'payplug' ) );
		}

		// Check user permissions before activating one-click feature.
		$oneclick_fieldkey = $this->get_field_key( 'oneclick' );
		if (
			isset( $data[ $oneclick_fieldkey ] )
			&& '1' === $data[ $oneclick_fieldkey ]
			&& false === $this->permissions->has_permissions( PayplugPermissions::SAVE_CARD )
		) {
			$data[ $oneclick_fieldkey ] = '0';
			\WC_Admin_Settings::add_error( __( 'Only PREMIUM accounts can enable the One Click option in LIVE mode.', 'payplug' ) );
		}

		parent::process_admin_options();
	}

	/**
	 * Process payment.
	 *
	 * @param int $order_id
	 *
	 * @return array
	 * @throws \Exception
	 */
	public function process_payment( $order_id ) {

		PayplugGateway::log( sprintf( 'Processing payment for order #%s', $order_id ) );

		$order       = wc_get_order( $order_id );
		$customer_id = PayplugWoocommerceHelper::is_pre_30() ? $order->customer_user : $order->get_customer_id();
		$amount      = (int) PayplugWoocommerceHelper::get_payplug_amount( $order->get_total() );
		$amount      = $this->validate_order_amount( $amount );
		if ( is_wp_error( $amount ) ) {
			PayplugGateway::log( sprintf( 'Invalid amount %s for the order.', $order->get_total() ), 'error' );
			throw new \Exception( $amount->get_error_message() );
		}

		$payment_token_id = ( isset( $_POST[ 'wc-' . $this->id . '-payment-token' ] ) && 'new' !== $_POST[ 'wc-' . $this->id . '-payment-token' ] )
			? wc_clean( $_POST[ 'wc-' . $this->id . '-payment-token' ] )
			: false;

		if ( $payment_token_id && $this->oneclick_available() && (int) $customer_id > 0 ) {
			PayplugGateway::log( sprintf( 'Payment token found.', $amount ) );

			return $this->process_payment_with_token( $order, $amount, $customer_id, $payment_token_id );
		}

		return $this->process_standard_payment( $order, $amount, $customer_id );
	}

	/**
	 * @param \WC_Order $order
	 * @param int $amount
	 * @param int $customer_id
	 *
	 * @return array
	 * @throws \Exception
	 */
	public function process_standard_payment( $order, $amount, $customer_id ) {

		$order_id         = PayplugWoocommerceHelper::is_pre_30() ? $order->id : $order->get_id();
		$customer_details = $this->prepare_customer_data( $order );

		if (
			! empty( $customer_details['country'] )
			&& ! PayplugWoocommerceHelper::is_country_supported( $customer_details['country'] )
		) {
			$customer_details['country'] = '';
		}

		try {
			$payment_data = [
				'amount'           => $amount,
				'currency'         => get_woocommerce_currency(),
				'allow_save_card'  => $this->oneclick_available() && (int) $customer_id > 0,
				'customer'         => [
					'first_name' => $this->limit_length( $customer_details['first_name'] ),
					'last_name'  => $this->limit_length( $customer_details['last_name'] ),
					'email'      => $this->limit_length( $customer_details['email'], 255 ),
					'address1'   => ( ! empty( $customer_details['address1'] ) ) ? $this->limit_length( $customer_details['address1'], 255 ) : null,
					'postcode'   => ( ! empty( $customer_details['postcode'] ) ) ? $this->limit_length( $customer_details['postcode'], 16 ) : null,
					'city'       => ( ! empty( $customer_details['city'] ) ) ? $this->limit_length( $customer_details['city'] ) : null,
					'country'    => ( ! empty( $customer_details['country'] ) ) ? $this->limit_length( $customer_details['country'], 2 ) : null,
				],
				'hosted_payment'   => [
					'return_url' => esc_url_raw( $order->get_checkout_order_received_url() ),
					'cancel_url' => esc_url_raw( $order->get_cancel_order_url_raw() ),
				],
				'notification_url' => esc_url_raw( WC()->api_request_url( 'PayplugGateway' ) ),
				'metadata'         => [
					'order_id'    => $order_id,
					'customer_id' => ( (int) $customer_id > 0 ) ? $customer_id : 'guest',
					'domain'      => $this->limit_length( esc_url_raw( home_url() ), 500 ),
				],
			];

			/**
			 * Filter the payment data before it's used
			 *
			 * @param array $payment_data
			 * @param int $order_id
			 * @param array $customer_details
			 */
			$payment_data = apply_filters( 'payplug_gateway_payment_data', $payment_data, $order_id, $customer_details );
			$payment      = $this->api->payment_create( $payment_data );

			// Save transaction id for the order
			PayplugWoocommerceHelper::is_pre_30()
				? update_post_meta( $order_id, '_transaction_id', $payment->id )
				: $order->set_transaction_id( $payment->id );

			if ( is_callable( [ $order, 'save' ] ) ) {
				$order->save();
			}

			/**
			 * Fires once a payment has been created.
			 *
			 * @param int $order_id Order ID
			 * @param PaymentResource $payment Payment resource
			 */
			\do_action( 'payplug_gateway_payment_created', $order_id, $payment );

			$metadata = PayplugWoocommerceHelper::extract_transaction_metadata( $payment );
			PayplugWoocommerceHelper::save_transaction_metadata( $order, $metadata );

			PayplugGateway::log( sprintf( 'Payment creation complete for order #%s', $order_id ) );

			return [
				'result'   => 'success',
				'redirect' => $payment->hosted_payment->payment_url,
				'cancel'   => $payment->hosted_payment->cancel_url,
			];
		} catch ( HttpException $e ) {
			PayplugGateway::log( sprintf( 'Error while processing order #%s : %s', $order_id, wc_print_r( $e->getErrorObject(), true ) ), 'error' );
			throw new \Exception( __( 'Payment processing failed. Please retry.', 'payplug' ) );
		} catch ( \Exception $e ) {
			PayplugGateway::log( sprintf( 'Error while processing order #%s : %s', $order_id, $e->getMessage() ), 'error' );
			throw new \Exception( __( 'Payment processing failed. Please retry.', 'payplug' ) );
		}
	}

	/**
	 * @param \WC_Order $order
	 * @param int $amount
	 * @param int $customer_id
	 * @param string $token_id
	 *
	 * @return array
	 * @throws \Exception
	 */
	public function process_payment_with_token( $order, $amount, $customer_id, $token_id ) {

		$order_id         = PayplugWoocommerceHelper::is_pre_30() ? $order->id : $order->get_id();
		$customer_details = $this->prepare_customer_data( $order );
		$payment_token    = WC_Payment_Tokens::get( $token_id );
		if ( ! $payment_token || (int) $customer_id !== (int) $payment_token->get_user_id() ) {
			PayplugGateway::log( 'Could not find the payment token or the payment doesn\'t belong to the current user.', 'error' );
			throw new \Exception( __( 'Invalid payment method.', 'payplug' ) );
		}

		if (
			! empty( $customer_details['country'] )
			&& ! PayplugWoocommerceHelper::is_country_supported( $customer_details['country'] )
		) {
			$customer_details['country'] = '';
		}

		try {
			$payment_data = [
				'amount'           => $amount,
				'currency'         => get_woocommerce_currency(),
				'payment_method'   => $payment_token->get_token(),
				'customer'         => [
					'first_name' => $this->limit_length( $customer_details['first_name'] ),
					'last_name'  => $this->limit_length( $customer_details['last_name'] ),
					'email'      => $this->limit_length( $customer_details['email'], 255 ),
					'address1'   => ( ! empty( $customer_details['address1'] ) ) ? $this->limit_length( $customer_details['address1'], 255 ) : null,
					'postcode'   => ( ! empty( $customer_details['postcode'] ) ) ? $this->limit_length( $customer_details['postcode'], 16 ) : null,
					'city'       => ( ! empty( $customer_details['city'] ) ) ? $this->limit_length( $customer_details['city'] ) : null,
					'country'    => ( ! empty( $customer_details['country'] ) ) ? $this->limit_length( $customer_details['country'], 2 ) : null,
				],
				'notification_url' => esc_url_raw( WC()->api_request_url( 'PayplugGateway' ) ),
				'metadata'         => [
					'order_id'    => $order_id,
					'customer_id' => ( (int) $customer_id > 0 ) ? $customer_id : 'guest',
					'domain'      => $this->limit_length( esc_url_raw( home_url() ), 500 ),
				],
			];

			/** This filter is documented in src/Gateway/PayplugGateway */
			$payment_data = apply_filters( 'payplug_gateway_payment_data', $payment_data, $order_id, $customer_details );
			$payment      = $this->api->payment_create( $payment_data );

			/** This action is documented in src/Gateway/PayplugGateway */
			\do_action( 'payplug_gateway_payment_created', $order_id, $payment );

			$this->response->process_payment( $payment, true );

			PayplugGateway::log( sprintf( 'Payment process complete for order #%s', $order_id ) );

			return [
				'result'   => 'success',
				'redirect' => $order->get_checkout_order_received_url(),
			];
		} catch ( HttpException $e ) {
			PayplugGateway::log( sprintf( 'Error while processing order #%s : %s', $order_id, wc_print_r( $e->getErrorObject(), true ) ), 'error' );
			throw new \Exception( __( 'Payment processing failed. Please retry.', 'payplug' ) );
		} catch ( \Exception $e ) {
			PayplugGateway::log( sprintf( 'Error while processing order #%s : %s', $order_id, $e->getMessage() ), 'error' );
			throw new \Exception( __( 'Payment processing failed. Please retry.', 'payplug' ) );
		}
	}

	/**
	 * Process refund for an order paid with PayPlug gateway.
	 *
	 * @param int $order_id
	 * @param null $amount
	 * @param string $reason
	 *
	 * @return bool|\WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {

		PayplugGateway::log( sprintf( 'Processing refund for order #%s', $order_id ) );

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			PayplugGateway::log( sprintf( 'The order #%s does not exist.', $order_id ), 'error' );

			return new \WP_Error( 'process_refund_error', sprintf( __( 'The order %s does not exist.', 'payplug' ), $order_id ) );
		}

		$transaction_id = PayplugWoocommerceHelper::is_pre_30() ? get_post_meta( $order_id, '_transaction_id', true ) : $order->get_transaction_id();
		if ( empty( $transaction_id ) ) {
			PayplugGateway::log( sprintf( 'The order does not have PayPlug transaction ID associated with it.', $order_id ), 'error' );

			return new \WP_Error( 'process_refund_error', __( 'No PayPlug transaction was found for this order. The refund could not be processed.', 'payplug' ) );
		}

		$customer_id = PayplugWoocommerceHelper::is_pre_30() ? $order->customer_user : $order->get_customer_id();

		$data = [
			'metadata' => [
				'order_id'    => $order_id,
				'customer_id' => ( (int) $customer_id > 0 ) ? $customer_id : 'guest',
			]
		];

		if ( ! is_null( $amount ) ) {
			$data['amount'] = PayplugWoocommerceHelper::get_payplug_amount( $amount );
		}

		if ( ! empty( $reason ) ) {
			$data['metadata']['reason'] = $reason;
		}

		/**
		 * Filter the refund data before it's used.
		 *
		 * @param array $data
		 * @param int $order_id
		 * @param string $transaction_id
		 */
		$data = apply_filters( 'payplug_gateway_refund_data', $data, $order_id, $transaction_id );

		try {
			$refund = $this->api->refund_create( $transaction_id, $data );

			/**
			 * Fires once a refund has been created.
			 *
			 * @param int $order_id Order ID
			 * @param RefundResource $refund Refund resource
			 * @param string $transaction_id Transaction id
			 */
			\do_action( 'payplug_gateway_refund_created', $order_id, $refund, $transaction_id );

			$refund_meta_key = sprintf( '_pr_%s', wc_clean( $refund->id ) );
			if ( PayplugWoocommerceHelper::is_pre_30() ) {
				update_post_meta( $order_id, $refund_meta_key, $refund->id );
			} else {
				$order->add_meta_data( $refund_meta_key, $refund->id, true );
				$order->save();
			}

			$note = sprintf( __( 'Refund %s : Refunded %s', 'payplug' ), wc_clean( $refund->id ), wc_price( ( (int) $refund->amount ) / 100 ) );
			if ( ! empty( $refund->metadata['reason'] ) ) {
				$note .= sprintf( ' (%s)', esc_html( $refund->metadata['reason'] ) );
			}
			$order->add_order_note( $note );

			try {
				$payment  = $this->api->payment_retrieve( $transaction_id );
				$metadata = PayplugWoocommerceHelper::extract_transaction_metadata( $payment );
				PayplugWoocommerceHelper::save_transaction_metadata( $order, $metadata );
			} catch ( \Exception $e ) {
			}

			PayplugGateway::log( 'Refund process complete for the order.' );

			return true;
		} catch ( HttpException $e ) {
			PayplugGateway::log( sprintf( 'Refund request error for the order %s from PayPlug API : %s', $order_id, wc_print_r( $e->getErrorObject(), true ) ), 'error' );

			return new \WP_Error( 'process_refund_error', __( 'The transaction could not be refunded. Please try again.', 'payplug' ) );
		} catch ( \Exception $e ) {
			PayplugGateway::log( sprintf( 'Refund request error for the order %s : %s', $order_id, wc_clean( $e->getMessage() ) ), 'error' );

			return new \WP_Error( 'process_refund_error', __( 'The transaction could not be refunded. Please try again.', 'payplug' ) );
		}
	}

	/**
	 * @param \WC_Order $order
	 *
	 * @return array
	 */
	public function prepare_customer_data( $order ) {
		return [
			'first_name' => PayplugWoocommerceHelper::is_pre_30() ? $order->billing_first_name : $order->get_billing_first_name(),
			'last_name'  => PayplugWoocommerceHelper::is_pre_30() ? $order->billing_last_name : $order->get_billing_last_name(),
			'email'      => PayplugWoocommerceHelper::is_pre_30() ? $order->billing_email : $order->get_billing_email(),
			'address1'   => PayplugWoocommerceHelper::is_pre_30() ? $order->billing_address_1 : $order->get_billing_address_1(),
			'address2'   => PayplugWoocommerceHelper::is_pre_30() ? $order->billing_address_2 : $order->get_billing_address_2(),
			'postcode'   => PayplugWoocommerceHelper::is_pre_30() ? $order->billing_postcode : $order->get_billing_postcode(),
			'city'       => PayplugWoocommerceHelper::is_pre_30() ? $order->billing_city : $order->get_billing_city(),
			'country'    => PayplugWoocommerceHelper::is_pre_30() ? $order->billing_country : $order->get_billing_country(),
		];
	}

	/**
	 * Check the order amount to ensure it's on the allowed range.
	 *
	 * @param int $amount
	 *
	 * @return int|\WP_Error
	 */
	public function validate_order_amount( $amount ) {
		if (
			$amount < PayplugWoocommerceHelper::get_minimum_amount()
			|| $amount > PayplugWoocommerceHelper::get_maximum_amount()
		) {
			return new \WP_Error(
				'invalid order amount',
				sprintf( __( 'Payments for this amount (%s) are not authorised with this payment gateway.', 'payplug' ), \wc_price( $amount / 100 ) )
			);
		}

		return $amount;
	}

	/**
	 * Limit string length.
	 *
	 * @param string $value
	 * @param int $maxlength
	 *
	 * @return string
	 */
	public function limit_length( $value, $maxlength = 100 ) {
		return ( strlen( $value ) > $maxlength ) ? substr( $value, 0, $maxlength ) : $value;
	}

	/**
	 * Get user's keys.
	 *
	 * @param string $email
	 * @param string $password
	 *
	 * @return array|\WP_Error
	 */
	public function retrieve_user_api_keys( $email, $password ) {
		if ( empty( $email ) || empty( $password ) ) {
			return new \WP_Error( 'missing_login_data', __( 'Please fill all login fields', 'payplug' ) );
		}

		try {
			$response = Authentication::getKeysByLogin( $email, $password );
			if ( empty( $response ) || ! isset( $response['httpResponse'] ) ) {
				return new \WP_Error( 'invalid_credentials', __( 'Invalid credentials.', 'payplug' ) );
			}

			return $response['httpResponse']['secret_keys'];
		} catch ( HttpException $e ) {
			return new \WP_Error( 'invalid_credentials', __( 'Invalid credentials.', 'payplug' ) );
		}
	}

	/**
	 * Get user merchant id.
	 *
	 * This method might be called during the login process before the global PayPlug
	 * configuration is set. In that case you can pass a valid token to make the request.
	 *
	 * @param string|null $key
	 *
	 * @return string
	 */
	public function retrieve_merchant_id( $key = null ) {
		try {
			$response    = ! is_null( $key ) ? Authentication::getAccount( new Payplug( $key ) ) : Authentication::getAccount();
			$merchant_id = isset( $response['httpResponse']['id'] ) ? $response['httpResponse']['id'] : '';
		} catch ( ConfigurationException $e ) {
			PayplugGateway::log( sprintf( 'Missing API key for PayPlug client : %s', wc_print_r( $e->getMessage(), true ) ), 'error' );

			$merchant_id = '';
		} catch ( HttpException $e ) {
			PayplugGateway::log( sprintf( 'Account request error from PayPlug API : %s', wc_print_r( $e->getErrorObject(), true ) ), 'error' );

			$merchant_id = '';
		} catch ( \Exception $e ) {
			PayplugGateway::log( sprintf( 'Account request error : %s', wc_clean( $e->getMessage() ) ), 'error' );

			$merchant_id = '';
		}

		return $merchant_id;
	}

	/**
	 * Generate Hidden HTML.
	 *
	 * @param string $key
	 * @param array $data
	 *
	 * @return string
	 */
	public function generate_hidden_html( $key, $data ) {
		$field_key = $this->get_field_key( $key );
		$defaults  = array(
			'title'             => '',
			'disabled'          => false,
			'class'             => '',
			'css'               => '',
			'placeholder'       => '',
			'type'              => 'text',
			'desc_tip'          => false,
			'description'       => '',
			'custom_attributes' => array(),
		);

		$data = wp_parse_args( $data, $defaults );

		ob_start();
		?>
		<input
				type="<?php echo esc_attr( $data['type'] ); ?>" name="<?php echo esc_attr( $field_key ); ?>"
				id="<?php echo esc_attr( $field_key ); ?>"
				value="<?php echo esc_attr( $this->get_option( $key ) ); ?>"/>
		<?php

		return ob_get_clean();
	}

	/**
	 * Generate Yes/No Input HTML.
	 *
	 * @param string $key
	 * @param array $data
	 *
	 * @return string
	 */
	public function generate_yes_no_html( $key, $data ) {
		$field_key = $this->get_field_key( $key );
		$defaults  = array(
			'title'             => '',
			'no'                => 'No',
			'yes'               => 'Yes',
			'disabled'          => false,
			'class'             => '',
			'css'               => '',
			'placeholder'       => '',
			'type'              => 'text',
			'desc_tip'          => false,
			'description'       => '',
			'custom_attributes' => [],
			'hide_label'        => false,
		);

		$data    = wp_parse_args( $data, $defaults );
		$checked = 'yes' === $this->get_option( $key ) ? '1' : '0';

		ob_start();
		?>
		<tr valign="top">
			<?php if ( ! $data['hide_label'] ) : ?>
				<th scope="row" class="titledesc">
					<label for="<?php echo esc_attr( $field_key ); ?>">
						<?php echo wp_kses_post( $data['title'] ); ?>
						<?php echo $this->get_tooltip_html( $data ); ?>
					</label>
				</th>
			<?php endif; ?>
			<td class="forminp">
				<fieldset>
					<legend class="screen-reader-text"><span><?php echo wp_kses_post( $data['title'] ); ?></span>
					</legend>
					<div class="radio--custom">
						<input class="radio radio-yes <?php echo esc_attr( $data['class'] ); ?>"
						       type="radio"
						       name="<?php echo esc_attr( $field_key ); ?>"
						       id="<?php echo esc_attr( $field_key ); ?>-yes"
						       value="1"
							<?php checked( '1', $checked ); ?>
							<?php disabled( $data['disabled'], true ); ?>
							<?php echo $this->get_custom_attribute_html( $data ); ?>>
						<label for="<?php echo esc_attr( $field_key ); ?>-yes"><?php echo esc_html( $data['yes'] ); ?></label>
					</div>
					<div class="radio--custom">
						<input class="radio radio-no <?php echo esc_attr( $data['class'] ); ?>"
						       type="radio"
						       name="<?php echo esc_attr( $field_key ); ?>"
						       id="<?php echo esc_attr( $field_key ); ?>-no"
						       value="0"
							<?php checked( '0', $checked ); ?>
							<?php disabled( $data['disabled'], true ); ?>
							<?php echo $this->get_custom_attribute_html( $data ); ?>>
						<label for="<?php echo esc_attr( $field_key ); ?>-no"><?php echo esc_html( $data['no'] ); ?></label>
					</div>
					<?php echo $this->get_description_html( $data ); ?>
				</fieldset>
			</td>
		</tr>
		<?php

		return ob_get_clean();
	}

	/**
	 * Generate Radio Input HTML.
	 *
	 * @param  string $key
	 * @param  array $data
	 *
	 * @return string
	 */
	public function generate_radio_html( $key, $data ) {
		$field_key = $this->get_field_key( $key );
		$defaults  = array(
			'title'             => '',
			'disabled'          => false,
			'class'             => '',
			'css'               => '',
			'placeholder'       => '',
			'type'              => 'text',
			'desc_tip'          => false,
			'description'       => '',
			'custom_attributes' => [],
			'options'           => [],
		);

		$data = wp_parse_args( $data, $defaults );

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field_key ); ?>">
					<?php echo wp_kses_post( $data['title'] ); ?>
					<?php echo $this->get_tooltip_html( $data ); ?>
				</label>
			</th>
			<td class="forminp">
				<fieldset>
					<legend class="screen-reader-text"><span><?php echo wp_kses_post( $data['title'] ); ?></span>
					</legend>
					<?php foreach ( $data['options'] as $option_key => $option_value ) : ?>
						<input class="radio <?php echo esc_attr( $data['class'] ); ?>"
						       type="radio"
						       name="<?php echo esc_attr( $field_key ); ?>"
						       id="<?php echo esc_attr( $field_key ); ?>-<?php echo esc_attr( $option_key ); ?>"
						       value="<?php echo esc_attr( $option_key ); ?>"
							<?php checked( $option_key, $this->get_option( $key ) ); ?>
							<?php disabled( $data['disabled'], true ); ?>
							<?php echo $this->get_custom_attribute_html( $data ); ?>>
						<label for="<?php echo esc_attr( $field_key ); ?>-<?php echo esc_attr( $option_key ); ?>"><?php echo esc_html( $option_value ); ?></label>
					<?php endforeach; ?>
				</fieldset>
			</td>
		</tr>
		<?php

		return ob_get_clean();
	}

	/**
	 * Generate Login HTML.
	 *
	 * @param string $key
	 * @param array $data
	 *
	 * @return string
	 */
	public function generate_login_html( $key, $data ) {
		$field_key = $this->get_field_key( $key );
		$defaults  = [];

		$data = wp_parse_args( $data, $defaults );

		ob_start();
		?>
		<tr valign="top">
			<td class="forminp">
				<p><?php echo $this->get_option( 'email' ); ?></p>
				<p>
					<input type="submit" name="submit_logout" value="<?php _e( 'Logout', 'payplug' ); ?>">
					<?php wp_nonce_field( 'payplug_user_logout', '_logoutaction' ); ?>
					|
					<a href="https://portal.payplug.com"
					   target="_blank"><?php _e( 'Go to your PayPlug Portal', 'payplug' ); ?></a>
				</p>
			</td>
		</tr>
		<?php

		return ob_get_clean();
	}

	/**
	 * Validate Radio Field.
	 *
	 * Make sure the data is escaped correctly, etc.
	 *
	 * @param  string $key
	 * @param  string|null $value Posted Value
	 *
	 * @return string
	 */
	public function validate_radio_field( $key, $value ) {
		$value = is_null( $value ) ? '' : $value;

		return wc_clean( stripslashes( $value ) );
	}

	/**
	 * Validate Yes/No Field.
	 *
	 * @param  string $key
	 * @param  string $value Posted Value
	 *
	 * @return string
	 */
	public function validate_yes_no_field( $key, $value ) {
		return ( '1' === (string) $value ) ? 'yes' : 'no';
	}

	/**
	 * Get PayPlug gateway mode.
	 *
	 * @return string
	 */
	public function get_current_mode() {
		return ( 'yes' === $this->get_option( 'mode' ) ) ? 'live' : 'test';
	}

	/**
	 * Get user API key.
	 *
	 * @param string $mode
	 *
	 * @return string
	 */
	public function get_api_key( $mode = 'test' ) {

		switch ( $mode ) {
			case 'test':
				$key = $this->get_option( 'payplug_test_key' );
				break;
			case 'live':
				$key = $this->get_option( 'payplug_live_key' );
				break;
			default:
				$key = '';
				break;
		}

		return $key;
	}

	/**
	 * Get current merchant id.
	 *
	 * @return string
	 */
	public function get_merchant_id() {
		return $this->get_option( 'payplug_merchant_id', '' );
	}

	/**
	 * Check if user is logged in and we have an API key for TEST mode.
	 *
	 * @return bool
	 */
	public function user_logged_in() {
		return ! empty( $this->get_option( 'payplug_test_key' ) );
	}

	/**
	 * Check if oneclick payment is activated and merchant can use it.
	 *
	 * @return bool
	 */
	public function oneclick_available() {
		return $this->oneclick && $this->permissions->has_permissions( PayplugPermissions::SAVE_CARD );
	}
}