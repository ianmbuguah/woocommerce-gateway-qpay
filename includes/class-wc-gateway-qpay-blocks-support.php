<?php
/**
 * Qpay Payment Gateway
 *
 * @package Qpay Woocommerce Gateway
 */

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Qpay payment method integration
 *
 * @since 1.0.0
 */
final class WC_Qpay_Blocks_Support extends AbstractPaymentMethodType {
	/**
	 * Name of the payment method.
	 *
	 * @var string
	 */
	protected $name = 'qpay';

	/**
	 * Initializes the payment method type.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_qpay_settings', array() );
	}

	/**
	 * Returns if this payment method should be active. If false, the scripts will not be enqueued.
	 *
	 * @return boolean
	 */
	public function is_active() {
		$payment_gateways_class = WC()->payment_gateways();
		$payment_gateways       = $payment_gateways_class->payment_gateways();

		return $payment_gateways['qpay']->is_available();
	}

	/**
	 * Returns an array of scripts/handles to be registered for this payment method.
	 *
	 * @return array
	 */
	public function get_payment_method_script_handles() {
		$asset_path   = WC_GATEWAY_QPAY_PATH . '/build/payment-method.asset.php';
		$version      = WC_GATEWAY_QPAY_VERSION;
		$dependencies = array();
		if ( file_exists( $asset_path ) ) {
			$asset        = require $asset_path;
			$version      = is_array( $asset ) && isset( $asset['version'] )
				? $asset['version']
				: $version;
			$dependencies = is_array( $asset ) && isset( $asset['dependencies'] )
				? $asset['dependencies']
				: $dependencies;
		}
		wp_register_script(
			'wc-qpay-blocks-integration',
			WC_GATEWAY_QPAY_URL . '/build/payment-method.js',
			$dependencies,
			$version,
			true
		);
		wp_set_script_translations(
			'wc-qpay-blocks-integration',
			'woocommerce-gateway-qpay'
		);
		wp_localize_script('wc-qpay-blocks-intergration','wcQpayblockData', array(
			'ajaxUrl' =>admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('wc-qpay-block-intergration'),
			'logo_url' => WC_GATEWAY_QPAY_URL. '/assets/images/qpay.png',
		));
		return array( 'wc-qpay-blocks-integration' );
	}

	/**
	 * Returns an array of key=>value pairs of data made available to the payment methods script.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		return array(
			'title'       => $this->get_setting( 'title' ),
			'description' => $this->get_setting( 'description' ),
			'supports'    => $this->get_supported_features(),
			'logo_url'    => WC_GATEWAY_QPAY_URL . '/assets/images/qpay.png',
		);
	}

	/**
	 * Returns an array of supported features.
	 *
	 * @return string[]
	 */
	public function get_supported_features() {
		$payment_gateways = WC()->payment_gateways->payment_gateways();
		return $payment_gateways['qpay']->supports;
	}
}
