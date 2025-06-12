<?php
/**
 * Qpay Payment Gateway
 *
 * @package WooCommerce Gateway Qpay
 */

if ( ! class_exists( 'WC_Abstract_Privacy' ) ) {
	return;
}

/**
 * Privacy/GDPR related functionality which ties into WordPress functionality.
 */
class WC_Gateway_Qpay_Privacy extends WC_Abstract_Privacy {
	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct( __( 'Qpay', 'woocommerce-gateway-qpay' ) );

		$this->add_exporter( 'woocommerce-gateway-qpay-order-data', __( 'WooCommerce Qpay Order Data', 'woocommerce-gateway-qpay' ), array( $this, 'order_data_exporter' ) );

		$this->add_eraser( 'woocommerce-gateway-qpay-order-data', __( 'WooCommerce Qpay Data', 'woocommerce-gateway-qpay' ), array( $this, 'order_data_eraser' ) );
	}

	/**
	 * Returns a list of orders that are using one of Qpay's payment methods.
	 *
	 * The list of orders is paginated to 10 orders per page.
	 *
	 * @param string $email_address The user email address.
	 * @param int    $page          Page number to query.
	 * @return WC_Order[]|stdClass Number of pages and an array of order objects.
	 */
	protected function get_qpay_orders( $email_address, $page ) {
		$user = get_user_by( 'email', $email_address ); // Check if user has an ID in the DB to load stored personal data.

		$order_query = array(
			'payment_method' => 'qpay',
			'limit'          => 10,
			'page'           => $page,
		);

		if ( $user instanceof WP_User ) {
			$order_query['customer_id'] = (int) $user->ID;
		} else {
			$order_query['billing_email'] = $email_address;
		}

		return wc_get_orders( $order_query );
	}


	/**
	 * Gets the message of the privacy to display.
	 */
	public function get_privacy_message() {
		return wpautop(
			sprintf(
				/* translators: 1: anchor tag 2: closing anchor tag */
				esc_html__( 'By using this extension, you may be storing personal data or sharing data with an external service. %1$sLearn more about how this works, including what you may want to include in your privacy policy.%2$s', 'woocommerce-gateway-qpay' ),
				'<a href="https://merchant.qpay.com.qa/portal/#!/en/login" target="_blank" rel="noopener noreferrer">',
				'</a>'
			)
		);
	}

	/**
	 * Handle exporting data for Orders.
	 *
	 * @param string $email_address E-mail address to export.
	 * @param int    $page          Pagination of data.
	 *
	 * @return array
	 */
	public function order_data_exporter( $email_address, $page = 1 ) {
		$done           = false;
		$data_to_export = array();

		$orders = $this->get_qpay_orders( $email_address, (int) $page );

		$done = true;

		if ( 0 < count( $orders ) ) {
			foreach ( $orders as $order ) {
				$data_to_export[] = array(
					'group_id'    => 'woocommerce_orders',
					'group_label' => esc_attr__( 'Orders', 'woocommerce-gateway-qpay' ),
					'item_id'     => 'order-' . $order->get_id(),
					'data'        => array(
						array(
							'name'  => esc_attr__( 'Qpay token', 'woocommerce-gateway-qpay' ),
							'value' => $order->get_meta( '_qpay_pre_order_token', true ),
						),
					),
				);
			}

			$done = 10 > count( $orders );
		}

		return array(
			'data' => $data_to_export,
			'done' => $done,
		);
	}

	/**
	 * Finds and erases order data by email address.
	 *
	 * @since 1.0.0
	 * @param string $email_address The user email address.
	 * @param int    $page  Page.
	 * @return array An array of personal data in name value pairs
	 */
	public function order_data_eraser( $email_address, $page ) {
		$orders = $this->get_qpay_orders( $email_address, (int) $page );

		$items_removed  = false;
		$items_retained = false;
		$messages       = array();

		foreach ( (array) $orders as $order ) {
			$order = wc_get_order( $order->get_id() );

			list( $removed, $retained, $msgs ) = $this->maybe_handle_order( $order );
			$items_removed                    |= $removed;
			$items_retained                   |= $retained;
			$messages                          = array_merge( $messages, $msgs );
		}

		// Tell core if we have more orders to work on still.
		$done = count( $orders ) < 10;

		return array(
			'items_removed'  => $items_removed,
			'items_retained' => $items_retained,
			'messages'       => $messages,
			'done'           => $done,
		);
	}

	/**
	 * Handle eraser of data tied to Orders
	 *
	 * @since 1.0.0
	 *
	 * @param WC_Order $order The order object.
	 * @return array
	 */
	protected function maybe_handle_order( $order ) {
		$qpay_token = $order->get_meta( '_qpay_pre_order_token', true );

		if ( empty( $qpay_token ) ) {
			return array( false, false, array() );
		}

		$order->delete_meta_data( '_qpay_pre_order_token' );
		$order->save_meta_data();

		return array( true, false, array( esc_html__( 'Qpay Order Data Erased.', 'woocommerce-gateway-qpay' ) ) );
	}
}

new WC_Gateway_Qpay_Privacy();