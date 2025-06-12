<?php

/**
 * Qpay Payment Gateway
 *
 * @package WooCommerce Gateway Qpay
 */

/**
 * Qpay Payment Gateway
 *
 * Provides a Qpay Payment Gateway.
 *
 * @class  woocommerce_qpay
 */
class WC_Gateway_Qpay extends WC_Payment_Gateway{

    /**
     * Version
     *
     * @var string
     */
    public $version;

    /**
     * Request Data send to Qpay.
     *
     * @var array $request_data
     */
    protected $data_map = array();

    /**
     * Merchant ID.
     *
     * @var string $merchant_id
     */
    protected $merchant_id;

    /**
     * Merchant Key.
     *
     * @var string $merchant_key
     */
    protected $merchant_key;

    /**
     * Bank ID.
     *
     * @var string $bank_id
     */
    protected $bank_id;

    /**
     * Qpay URL.
     *
     * @var string $q_url
     */
    protected $q_url;

    /**
     * extraFields_f14.
     *
     * @var string $extraFields_f14.
     */
    protected $extraFields_f14;
    
    /**
     * Production or Sandbox mode
     * 
     * @var bool $test_mode
     */
    protected $test_mode;

    /**
     * Send debug email.
     *
     * @var bool $send_debug_email
     */
    protected $send_debug_email;

    /**
     * Debug email.
     *
     * @var string $debug_email
     */
    protected $debug_email;

    /**
     * Enable logging.
     *
     * @var bool $enable_logging
     */
    protected $enable_logging;

    /**
     * Available countries.
     *
     * @var array $available_countries
     */
    protected $available_countries;

    /**
     * Available currencies.
     *
     * @var array $currency_code.
     */
    protected $currency_code;

    /**
     * Logger instance.
     *
     * @var WC_Logger $logger
     */
    protected $logger;

    /**
     * Constructor
     */
    public function __construct(){


        $this->version      = WC_GATEWAY_QPAY_VERSION;
        $this->id           = 'qpay';
        $this->method_title = __('Qpay', 'woocommerce-gateway-qpay');
        /* translators: 1: a href link 2: closing href */
        $this->method_description  = sprintf(__('Qpay payment gateway works by Merchant Id and Merchant Key and find it from the %1$spayment gateway%2$s.', 'woocommerce-gateway-qpay'), '<a href="https://merchant.qpay.com.qa/portal/#!/en/login">', '</a>');
        $this->icon                = WP_PLUGIN_URL . '/' . plugin_basename(dirname(__DIR__)) . '/assets/images/qpay.png';
        $this->debug_email         = get_option('admin_email');
        $this->available_countries = array('QA');

        /**
         * Filter available countries for Qpay Gateway.
         *
         * @since 1.0.0
         *
         * @param string[] $available_countries Array of available countries.
         */
        $this->currency_code = (array) apply_filters('woocommerce_gateway_qpay_currency_code', array('QAR'));

        // Supported functionality.
        $this->supports = array(
            'products',
        );

        $this->init_form_fields();
        $this->init_settings();

        if (! is_admin()) {
            $this->setup_constants();
        }

        // Setup default merchant data.
        $this->enabled          = 'yes' === $this->get_option('enabled') ? 'yes' : 'no';
        $this->title            = $this->get_option('title');
        $this->description      = $this->get_option('description');
        $this->merchant_id      = $this->get_option('merchant_id');
        $this->merchant_key     = $this->get_option('merchant_key');
        $this->bank_id     = $this->get_option('bank_id');
        $this->test_mode        = 'yes' === $this->get_option('testmode');
        $this->enable_logging   = 'yes' === $this->get_option('enable_logging');
        $this->send_debug_email = 'yes' === $this->get_option('send_debug_email');
        $this->extraFields_f14  = add_query_arg('wc-api', 'WC_Gateway_Qpay', home_url('/'));


        //Qpay url based on test mode.
        $this->q_url = $this->test_mode
            ? 'https://pguat.qcb.gov.qa/qcb-pg/api/gateway/2.0'
            : 'https://pg-api.qpay.gov.qa/qcb-pg/api/gateway/2.0';

        if ($this->test_mode) {
            $this->add_testmode_admin_settings_notice();
        }

        add_action('woocommerce_api_wc_gateway_qpay', array($this, 'check_itn_response'));
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action( 'woocommerce_receipt_qpay', array( $this, 'receipt_page' ) );
        add_action( 'woocommerce_before_checkout_form', 'display_failed_qpay_error_notice' );
        add_action('admin_notices', array($this, 'admin_notices'));

        // Add fees to order.
        add_action('woocommerce_admin_order_totals_after_total', array($this, 'display_order_fee'));
        add_action('woocommerce_admin_order_totals_after_total', array($this, 'display_order_net'), 20);

        // Add support for WooPayments numeric code.
        //add_filter('woocommerce_currency', array($this, 'numeric_code'));
        add_filter('nocache_headers', array($this, 'no_store_cache_headers'));
  
    }
     
    /**
     * Use the no-store, private cache directive on the order-pay endpoint.
     * @since 1.0.0
     *
     * @param string[] $headers Array of caching headers.
     * @return string[] Modified caching headers.
     */
    public function no_store_cache_headers($headers)
    {
        if (! is_wc_endpoint_url('order-pay')) {
            return $headers;
        }

        $headers['Cache-Control'] = 'no-cache, must-revalidate, max-age=0, no-store, private';
        return $headers;
    }

    /**
     * Initialise Gateway Settings Form Fields
     *
     * @since 1.0.0
     */
    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled'          => array(
                'title'       => __('Enable/Disable', 'woocommerce-gateway-qpay'),
                'label'       => __('Enable Qpay', 'woocommerce-gateway-qpay'),
                'type'        => 'checkbox',
                'description' => __('This controls whether or not this gateway is enabled within WooCommerce.', 'woocommerce-gateway-qpay'),
                'default'     => 'no', // User should enter the required information before enabling the gateway.
                'desc_tip'    => true,
            ),
            'title'            => array(
                'title'       => __('Title', 'woocommerce-gateway-qpay'),
                'type'        => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'woocommerce-gateway-qpay'),
                'default'     => __('Qpay', 'woocommerce-gateway-qpay'),
                'desc_tip'    => true,
            ),
            'description'      => array(
                'title'       => __('Description', 'woocommerce-gateway-qpay'),
                'type'        => 'text',
                'description' => __('This controls the description which the user sees during checkout.', 'woocommerce-gateway-qpay'),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'testmode'         => array(
                'title'       => __('Qpay Sandbox', 'woocommerce-gateway-qpay'),
                'type'        => 'checkbox',
                'description' => __('Place the payment gateway in development mode.', 'woocommerce-gateway-qpay'),
                'default'     => 'yes',
            ),
            'merchant_id'      => array(
                'title'       => __('Merchant ID', 'woocommerce-gateway-qpay'),
                'type'        => 'text',
                'description' => __('This is the merchant ID, received from Qpay.', 'woocommerce-gateway-qpay'),
                'default'     => '',
            ),
            'merchant_key'     => array(
                'title'       => __('Merchant Key', 'woocommerce-gateway-qpay'),
                'type'        => 'text',
                'description' => __('This is the merchant key, received from Qpay.', 'woocommerce-gateway-qpay'),
                'default'     => '',
            ),
            'bank_id'      => array(
                'title'       => __('Bank ID', 'woocommerce-gateway-qpay'),
                'type'        => 'text',
                'description' => __('* Required. Needed to ensure the data passed through is secure.', 'woocommerce-gateway-qpay'),
                'default'     => '',
            ),
            'send_debug_email' => array(
                'title'   => __('Send Debug Emails', 'woocommerce-gateway-qpay'),
                'type'    => 'checkbox',
                'label'   => __('Send debug e-mails for transactions through the Qpay gateway (sends on successful transaction as well).', 'woocommerce-gateway-qpay'),
                'default' => 'yes',
            ),
            'debug_email'      => array(
                'title'       => __('Who Receives Debug E-mails?', 'woocommerce-gateway-qpay'),
                'type'        => 'text',
                'description' => __('The e-mail address to which debugging error e-mails are sent when in test mode.', 'woocommerce-gateway-qpay'),
                'default'     => get_option('admin_email'),
            ),
            'enable_logging'   => array(
                'title'   => __('Enable Logging', 'woocommerce-gateway-qpay'),
                'type'    => 'checkbox',
                'label'   => __('Enable transaction logging for gateway.', 'woocommerce-gateway-qpay'),
                'default' => 'no',
            ),
        );
    }

    /**
     * Get the required form field keys for setup.
     *
     * @return array
     */
    public function get_required_settings_keys()
    {
        return array(
            'merchant_id',
            'merchant_key',
            'bank_id',
        );
    }

    /**
     * Determine if the gateway still requires setup.
     *
     * @return bool
     */
    public function needs_setup()
    {
        return ! $this->get_option('merchant_id') || ! $this->get_option('merchant_key') || ! $this->get_option('bank_id');
    }

    /**
     * Add a notice to the merchant_key and merchant_id fields when in test mode.
     *
     * @since 1.0.0
     */
    public function add_testmode_admin_settings_notice()
    {
        $this->form_fields['merchant_id']['description']  .= ' <strong>' . esc_html__('Staging Merchant ID currently in use', 'woocommerce-gateway-qpay') . ' ( ' . esc_html($this->merchant_id) . ' ).</strong>';
        $this->form_fields['merchant_key']['description'] .= ' <strong>' . esc_html__('Staging Merchant Key currently in use', 'woocommerce-gateway-qpay') . ' ( ' . esc_html($this->merchant_key) . ' ).</strong>';
    }

    /**
     * Check if this gateway is enabled and available in the base currency being traded with.
     *
     * @since 1.0.0
     * @return array
     */
    public function check_requirements()
    {
        $errors = array(
            // Check if the store currency is supported by Qpay.
            ! in_array(get_woocommerce_currency(), $this->currency_code, true) ? 'wc-gateway-qpay-error-bad-currency' : null,
            // Check if user entered the merchant ID.
            empty($this->get_option('merchant_id')) ? 'wc-gateway-qpay-error-missing-merchant-id' : null,
            // Check if user entered the merchant key.
            empty($this->get_option('merchant_key')) ? 'wc-gateway-qpay-error-missing-merchant-key' : null,
            // Check if user entered a Bank ID.
            empty($this->get_option('bank_id')) ? 'wc-gateway-qpay-error-missing-bank-id' : null,
            // Check if Qpay credentials are valid.
            ('yes' === get_option('woocommerce_qpay_bad_credentials')) ? 'wc-gateway-qpay-error-bad-credentials' : null,
        );

        return array_filter($errors);
    }

    /**
     * Check if the gateway is available for use.
     *
     * @return bool
     */
    public function is_available()
    {
        if ('yes' === $this->enabled) {
            $errors = $this->check_requirements();
            // Prevent using this gateway on frontend if there are any configuration errors.
            return 0 === count($errors);
        }

        return parent::is_available();
    }

    /**
     * Admin Panel Options
     * - Options for bits like 'title' and availability on a country-by-country basis
     *
     * @since 1.0.0
     */
    public function admin_options()
    {
        if (in_array(get_woocommerce_currency(), $this->currency_code, true)) {
            parent::admin_options();
        } else {
        ?>
            <h3><?php esc_html_e('Qpay', 'woocommerce-gateway-qpay'); ?></h3>
            <div class="inline error">
                <p>
                    <strong><?php esc_html_e('Gateway Disabled', 'woocommerce-gateway-qpay'); ?></strong>
                    <?php
                    /* translators: 1: a href link 2: closing href */
                    echo wp_kses_post(sprintf(__('Choose Qatar Riyal (QAR) as your store currency in %1$sGeneral Settings%2$s to enable the Qpay Gateway.', 'woocommerce-gateway-qpay'), '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=general')) . '">', '</a>'));
                    ?>
                </p>
            </div>
        <?php
        }
    }

    /**
	 * Generate the Qpay Request data.
	 *
	 * @since 1.0.0
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
    public function generateQpayRequest( $order_id ) { 

    $order = wc_get_order( $order_id );
    
    
    //necessary details from the request
    $secret_Key = $this->merchant_key;
    $action = '0';
    $pun = $order->get_id();
    $transactionRequestDate = date('dmYHis');
    $amount = intval( $order->get_total() * 100 ); // Convert amount to smallest currency unit
    $quantity = count( $order->get_items() );
    $merchantId = $this->merchant_id;
    $merchantModuleSessionId = $order->get_order_key();
    $bankId = $this->bank_id;
    $currencyCode = '634';
    $extraFields = $this->extraFields_f14;
    
    $locale = determine_locale();
    $lang   = ( strpos( $locale, 'ar' ) !== false ) ? 'AR' : 'EN';

    
    // Retrieve customer details
    $customer_name    = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
    $customer_email   = $order->get_billing_email();
    $customer_phone   = $order->get_billing_phone();

    $paymentdescription = sprintf(
            'Order #%d - Name: %s, Email: %s, Phone: %s',
            $order->get_id(),
            $customer_name,
            $customer_email,
            $customer_phone 
    );
      
    $sign_hash = $secret_Key. $action. $amount. $bankId. $currencyCode. $extraFields. $lang. $merchantId. $merchantModuleSessionId. $pun. $quantity. $transactionRequestDate;
	$hash = hash('sha256', $sign_hash);
	
      $this->data_map = array(
            'Action'=> $action,
            'Amount' => $amount,
            'BankID' => $bankId,
            'CurrencyCode'=> $currencyCode,
            'ExtraFields_f14' => $extraFields,
            'Lang'=> $lang,
            'MerchantID' => $merchantId,
            'MerchantModuleSessionID'=> $merchantModuleSessionId,
            'PUN' => $pun,
            'Quantity' => $quantity,
            'TransactionRequestDate' => $transactionRequestDate,
            'SecureHash' => ($hash),
    );
    
    /**
    * Allow others to modify payment data before that is sent to Qpay.
    *
    * @since 1.0.0
    *
    * @param array $this->data_map  Payment request data.
    * @param int   $order_id           Order id.
    */

    $this->data_map = apply_filters( 'woocommerce_gateway_qpay_payment_data_map',  $this->data_map, $order_id );
    
    // Log the modified data_map after filters are applied
    error_log('Modified data_map after filters: ' . print_r($this->data_map, true));

    //This is the data to be send to Qpay url in Html format. 
   $qpay_args_array = array();
         foreach ( $this->data_map as $key => $value ) {                
               $qpay_args_array[] = '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" />';
       }

    //order payment form.
    echo '<form action="' . esc_url( $this->q_url) . '" method="post" id="qpay_payment_form">';
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in foreach loop above.
    echo implode( '', $qpay_args_array );
    //order payment form button.
    echo ' <input
            type="submit"
            class="button-alt"
            id="submit_qpay_payment_form"
            value="' . esc_attr__( 'Pay via Qpay', 'woocommerce-gateway-qpay' ) . '"
        />
        <a
            class="button cancel"
            href="' . esc_url( $order->get_cancel_order_url() ) . '"
        >' .
            esc_html__( 'Cancel order &amp; restore cart', 'woocommerce-gateway-qpay' ) .
        '</a>
        <script type="text/javascript">
            jQuery(function(){
                // Feature detect.
                if (
                    typeof PerformanceNavigationTiming !== "undefined" &&
                    typeof window.performance !== "undefined" &&
                    typeof performance.getEntriesByType === "function"
                ) {
                var isBackForward = false;
                var entries = performance.getEntriesByType("navigation");
                entries.forEach((entry) => {
                    if (entry.type === "back_forward") {
                        isBackForward = true;
                    }
                });
                if (isBackForward) {
                    /*
                     * Do not submit form on back or forward.
                     * Ensure that the body is unblocked/not showing the redirect message.
                     */
                    jQuery("body").unblock();
                    return;
                }
            }

            jQuery("body").block(
                {
                    message: "' . esc_html__( 'Thank you for your order. We are now redirecting you to Qpay to make payment.', 'woocommerce-gateway-qpay' ) . '",
                    overlayCSS:
                    {
                        background: "#fff",
                        opacity: 0.6
                    },
                    css: {
                        padding:        20,
                        textAlign:      "center",
                        color:          "#555",
                        border:         "3px solid #aaa",
                        backgroundColor:"#fff",
                        cursor:         "wait"
                    }
                });
            jQuery( "#submit_qpay_payment_form" ).click();
            });
        </script>
    </form>';       
    }

    /**
     * Process the payment and return the result.
     *
     * @since 1.0.0
     *
     * @throws Exception When there is an error processing the payment.
     *
     * @param int $order_id Order ID.
     * @return string[] Payment result {
     *    @var string $result   Result of payment.
     *    @var string $redirect Redirect URL.
     * }
     */
    public function process_payment( $order_id ) {
        $order    = wc_get_order( $order_id );
        $redirect = $order->get_checkout_payment_url( true );

        return array(
            'result'   => 'success',
            'redirect' => $redirect,
        );
    }

    /**
     * Reciept page.
     *
     * Display text and a button to direct the user to Qpay.
     *
     * @param WC_Order $order Order object.
     * @since 1.0.0
     */
    public function receipt_page( $order ) {
        echo '<p>' . esc_html__( 'Thank you for your order, please click the button below to pay with Qpay.', 'woocommerce-gateway-qpay' ) . '</p>';
        $this->generateQpayRequest( $order );
    }

	/**
	 * Check Qpay ITN response.
	 *
	 * @since 1.0.0
	 */
	public function check_itn_response() {
	    // phpcs:ignore.WordPress.Security.NonceVerification.Missing
		$this->handle_itn_request( stripslashes_deep( $_POST ) );

		// Notify Qpay that information has been received.
		header( 'HTTP/1.0 200 OK');
		echo 'Received ITN data';//debugging purposes
		flush();
	}
	
	
	/**
	 * Check Qpay ITN validity.
	 *
	 * @param array $data Data.
	 * @since 1.0.0
	 */
    public function handle_itn_request( $data ) {
        $this->log(
			PHP_EOL
			. '----------'
			. PHP_EOL . 'Qpay ITN call received'
			. PHP_EOL . '----------'
		);
		$this->log( 'Get posted data' );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- debug info for logging.
		$this->log( 'Qpay Data: ' . print_r( $data, true ) );
		
		$qpay_error  = false;
		$qpay_done   = false;
		$debug_email    = $this->get_option( 'debug_email', get_option( 'admin_email' ) );
		$session_id     = $data['Response_MerchantModuleSessionID'] ?? '';
        $vendor_name    = get_bloginfo( 'name', 'display' );
		$vendor_url     = home_url( '/' );
		$order_id       = absint( $data['Response_PUN'] ?? '');
		$order_key      = wc_clean( $session_id );
		$order          = wc_get_order( $order_id );
		$original_order = $order;
		
		if ( false === $data ) {
			$qpay_error         = true;
			$qpay_error_message = PF_ERR_BAD_ACCESS;
		}
		
		// Verify security SecureHash.
		if ( ! $qpay_error && ! $qpay_done ) {
			$this->log( 'Verify security SecureHash' );
			// Log the $api_data for debugging
            $this->log( 'Qpay Data: ' . print_r( $data, true ) );
		    $hash = hash( 'sha256', $this->_generate_parameter_string( $data ) );
		    
			// If hash different, log for debugging.
			if ( ! $this->validate_hash( $data, $hash ) ) {
				$qpay_error         = true;
				$qpay_error_message = PF_ERR_INVALID_SIGNATURE;
			}
		}

		// Verify source IP (If not in debug mode).
		if ( ! $qpay_error && ! $qpay_done
			&& $this->get_option( 'testmode' ) !== 'yes' ) {
			$this->log( 'Verify source IP' );

			if ( isset( $_SERVER['REMOTE_ADDR'] ) && ! $this->is_valid_ip( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) ) ) {
				$qpay_error         = true;
				$qpay_error_message = PF_ERR_BAD_SOURCE_IP;
			}
        }
        
        $status_code = isset( $data['Response_Status'] ) ? $data['Response_Status'] : null;
        $status = ( $status_code === '0000' ) ? 'complete' : 'failed';
         
		//status messages
		if ( 'complete' === $status ) {
				$this->handle_itn_payment_complete( $data, $order );
		} elseif ( 'failed' === $status ) {
		        $this->handle_itn_payment_failed( $data, $order );
		}
       
		$this->log(
			PHP_EOL
			. '----------'
			. PHP_EOL . 'End ITN call'
			. PHP_EOL . '----------'
		);
    }
    
	/**
	 * Handle logging the order details.
	 *
	 * @since 1.0.0
	 *
	 * @param WC_Order $order Order object.
	 */
	public function log_order_details( $order ) {
		$customer_id = $order->get_user_id();

		$details = 'Order Details:'
		. PHP_EOL . 'customer id:' . $customer_id
		. PHP_EOL . 'order id:   ' . $order->get_id()
		. PHP_EOL . 'parent id:  ' . $order->get_parent_id()
		. PHP_EOL . 'status:     ' . $order->get_status()
		. PHP_EOL . 'total:      ' . $order->get_total()
		. PHP_EOL . 'currency:   ' . $order->get_currency()
		. PHP_EOL . 'key:        ' . $order->get_order_key()
		. '';

		$this->log( $details );
	}
	
	
	/**
	 * This function handles payment complete request by Qpay.
	 *
	 * @version 1.0.0
	 *
	 * @param array             $data          Should be from the Gateway ITN callback.
	 * @param WC_Order          $order         Order object.
	 */
	public function handle_itn_payment_complete( $data, $order ) {
		$this->log( '- Complete' );
		$order->add_order_note( esc_html__( 'ITN payment completed', 'woocommerce-gateway-qpay' ) );
        $order->update_meta_data( 'qpay_amount_net', isset($data['Response_Amount']) ? number_format($data['Response_Amount'] / 100, 2, '.', '') : '' );

		// Mark payment as complete.
		$order->payment_complete( $data['Response_Status'] ?? '');

		$debug_email = $this->get_option( 'debug_email', get_option( 'admin_email' ) );
		$vendor_name = get_bloginfo( 'name', 'display' );
		$vendor_url  = home_url( '/' );
		if ( $this->send_debug_email ) {
			$subject = 'Qpay ITN on your site';
			$body    =
				"Hi,\n\n"
				. "A Qpay transaction has been completed on your website\n"
				. "------------------------------------------------------------\n"
				. 'Site: ' . esc_html( $vendor_name ) . ' (' . esc_url( $vendor_url ) . ")\n"
				. 'Purchase ID: ' . esc_html( $data['Response_PUN'] ?? '') . "\n"
				. 'Qpay transaction Date: ' . esc_html( $data['Response_EZConnectResponseDate'] ?? '') . "\n"
				. 'Qpay Payment Status: ' . esc_html( $data['Response_StatusMessage'] ?? '') . "\n"
				. 'Order Status Code: ' . $order->get_status();
			wp_mail( $debug_email, $subject, $body );
		}
	
		/**
		 * Fires after handling the Payment Complete ITN from Qpay.
		 *
		 * @since 1.0.0
		 *
		 * @param array             $data          ITN Payload.
		 * @param WC_Order          $order         Order Object.
		 */
		do_action( 'woocommerce_qpay_handle_itn_payment_complete', $data, $order );

		// Get the return URL for successful payment (Thank You page)
		$redirect_url = $order->get_checkout_order_received_url();

		// Redirect to the Thank You page
		wp_redirect($redirect_url);
		exit;

	}
	
	
	/**
	 * Handle payment failed request by Qpay.
	 *
	 * @param array    $data  Should be from the Gateway ITN callback.
	 * @param WC_Order $order Order object.
	 */
	public function handle_itn_payment_failed( $data, $order ) {
		$this->log( '- Failed' );
		/* translators: 1: payment status */
		$order->update_status( 'failed', sprintf( __( 'Payment %s via ITN.', 'woocommerce-gateway-qpay' ), strtolower( sanitize_text_field( $data['Response_Status'] ?? '') ) ) );
		//Call the helper to send failed payment email
        $this->send_failed_payment_email_to_customer( $order );
        $debug_email = $this->get_option( 'debug_email', get_option( 'admin_email' ) );
		$vendor_name = get_bloginfo( 'name', 'display' );
		$vendor_url  = home_url( '/' );

		if ( $this->send_debug_email ) {
			$subject = 'Qpay ITN Transaction on your site';
			$body    =
				"Hi,\n\n" .
				"A failed Qpay transaction on your website requires attention\n" .
				"------------------------------------------------------------\n" .
				'Site: ' . esc_html( $vendor_name ) . ' (' . esc_url( $vendor_url ) . ")\n" .
				'Purchase ID: ' . $order->get_id() . "\n" .
				'User ID: ' . $order->get_user_id() . "\n" .
				'Qpay Transaction Date: ' . esc_html( $data['Response_EZConnectResponseDate'] ?? '' ) . "\n" .
				'Qpay Payment Status: ' . esc_html( $data['Response_StatusMessage'] ??'' );
			wp_mail( $debug_email, $subject, $body );
		}

        // Add the notice to the session (WooCommerce notices)
        wc_add_notice(
        sprintf(
                __( 'Payment with Debit Card failed for Order #%d. Please try again or use a different payment method.', 'woocommerce-gateway-qpay' ),
                $order->get_id()
            ),
            'error'
        );

        // Store the error message for 2 minute using a transient
        set_transient( 'failed_qpay_error_notice_' . $order->get_id(), $order->get_id(), 120 ); // 120 seconds

        // Redirect to checkout page and pass the order_id in the URL
        wp_redirect( add_query_arg( 'order_id', $order->get_id(), wc_get_checkout_url() ) );
        exit;

	}

	/**
	 * Generate the parameter string to send to Qpay.
	 *
	 * @since 1.0.0 
	 *
	 * @param array $api_data               Data to send to the Qpay API.
	 * @param bool  $sort_data_before_merge Whether to sort before merge. Default true.
	 * @param bool  $skip_empty_values      Should key value pairs be ignored when generating signature? Default true.
	 *
	 * @return string
	 */
	protected function _generate_parameter_string( $data ) {
	    
	    if ( ! isset( $this->merchant_key ) ) {
		        return '';
	    }
       
       $status_message = str_replace( ' ', '+', $data['Response_StatusMessage'] );
       
       // Concatenate fields in the exact order required by QPay.
        $parameter_string = $this->merchant_key. $data['Response_AcquirerID']. $data['Response_Amount']. $data['Response_BankID']. $data['Response_CardExpiryDate']. $data['Response_CardHolderName']. $data['Response_CardNumber']. $data['Response_ConfirmationID']. $data['Response_CurrencyCode']. $data['Response_EZConnectResponseDate']. $data['Response_Lang']. $data['Response_MerchantID']. $data['Response_MerchantModuleSessionID']. $data['Response_PUN']. $data['Response_Status']. $status_message;
       
		return  $parameter_string;
	}

	
    /**
	 * Setup constants.
	 *
	 * Setup common values and messages used by the Qpay gateway.
	 *
	 * @since 1.0.0
	 */
	public function setup_constants() {
		// Create user agent string.
		define( 'PF_SOFTWARE_NAME', 'WooCommerce' );
		define( 'PF_SOFTWARE_VER', 'WC_VERSION' );
		define( 'PF_MODULE_NAME', 'WooCommerce-Qpay-Free' );
		define( 'PF_MODULE_VER', $this->version );

		// Features
		// - PHP.
		$pf_features = 'PHP ' . phpversion() . ';';

		// - cURL.
		if ( in_array( 'curl', get_loaded_extensions(), true ) ) {
			define( 'PF_CURL', '' );
			$pf_version   = curl_version();
			$pf_features .= ' curl ' . $pf_version['version'] . ';';
		} else {
			$pf_features .= ' nocurl;';
		}

		// Create user agent.
		define( 'PF_USER_AGENT', PF_SOFTWARE_NAME . '/' . PF_SOFTWARE_VER . ' (' . trim( $pf_features ) . ') ' . PF_MODULE_NAME . '/' . PF_MODULE_VER );

		// General Defines.
		define( 'PF_TIMEOUT', 15 );
		define( 'PF_EPSILON', 0.01 );
		
		// Error messages.
		define( 'PF_ERR_BAD_ACCESS', esc_html__( 'Bad access of page', 'woocommerce-gateway-qpay' ) );
        define( 'PF_ERR_BAD_SOURCE_IP', esc_html__( 'Bad source IP address', 'woocommerce-gateway-qpay' ) );
        define( 'PF_ERR_INVALID_SIGNATURE', esc_html__( 'Security signature mismatch', 'woocommerce-gateway-qpay' ) );

		/**
		 * Fires after Qpay constants are setup.
		 *
		 * @since 1.0.0
		 */
		do_action( 'woocommerce_gateway_qpay_setup_constants' );
	}
	
	/**
	 * Log system processes.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message Log message.
	 */
	public function log( $message ) {
        if ( empty( $this->logger ) ) {
            $this->logger = new WC_Logger();
        }
        $this->logger->add( 'qpay', $message );
    }
    
    /**
	 * Validate the signature against the returned data.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $data      Returned data.
	 * @param string $signature Signature to check.
	 * @return bool Whether the signature is valid.
	 */
	public function validate_hash( $data, $hash ) {
		$result = $data['Response_SecureHash'] === $hash;
		$this->log( 'SecureHash = ' . ( $result ? 'valid' : 'invalid' ) );
		return $result;
	}


	/**
	 * Validate the IP address to make sure it's coming from Qpay.
	 *
	 * @param string $source_ip Source IP.
	 * @since 1.0.0
	 * @return bool
	 */
	public function is_valid_ip( $source_ip ) {
		// Variable initialization.
		$valid_hosts = array(
            'https://pguat.qcb.gov.qa',
            'https://pg-api.qpay.gov.qa',
		);

		$valid_ips = array();

		foreach ( $valid_hosts as $pf_hostname ) {
			$ips = gethostbynamel( $pf_hostname );

			if ( false !== $ips ) {
				$valid_ips = array_merge( $valid_ips, $ips );
			}
		}

		// Remove duplicates.
		$valid_ips = array_unique( $valid_ips );

		// Adds support for X_Forwarded_For.
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$x_forwarded_http_header = trim( current( preg_split( '/[,:]/', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) ) ) );
			$source_ip               = rest_is_ip_address( $x_forwarded_http_header ) ? rest_is_ip_address( $x_forwarded_http_header ) : $source_ip;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- used for logging.
		$this->log( "Valid IPs:\n" . print_r( $valid_ips, true ) );
		$is_valid_ip = in_array( $source_ip, $valid_ips, true );

		/**
		 * Filter whether Qpay Gateway IP address is valid.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $is_valid_ip Whether IP address is valid.
		 * @param bool $source_ip   Source IP.
		 */
		return apply_filters( 'woocommerce_gateway_qpay_is_valid_ip', $is_valid_ip, $source_ip );
	}
	
	/**
	 * Check the given amounts are equal.
	 *
	 * Checks to see whether the given amounts are equal using a proper floating
	 * point comparison with an Epsilon which ensures that insignificant decimal
	 * places are ignored in the comparison.
	 *
	 * eg. 100.00 is equal to 100.0001
	 *
	 * @since 1.0.0
	 *
	 * @param float $amount1 1st amount for comparison.
	 * @param float $amount2 2nd amount for comparison.
	 *
	 * @return bool
	 */
	public function amounts_equal( $amount1, $amount2 ) {
		return ! ( abs( floatval( $amount1 ) - floatval( $amount2 ) ) > PF_EPSILON );
	}

	/**
	 * Gets user-friendly error message strings from keys
	 *
	 * @param   string $key  The key representing an error.
	 *
	 * @return  string        The user-friendly error message for display
	 */
	public function get_error_message( $key ) {
		switch ( $key ) {
			case 'wc-gateway-qpay-error-invalid-currency':
				return esc_html__( 'Your store uses a currency that Qpay doesn\'t support yet.', 'woocommerce-gateway-qpay' );
			case 'wc-gateway-qpay-error-missing-merchant-id':
				return esc_html__( 'You forgot to fill your Merchant ID.', 'woocommerce-gateway-qpay' );
			case 'wc-gateway-qpay-error-missing-secret-key':
				return esc_html__( 'You forgot to fill your Merchant key.', 'woocommerce-gateway-qpay' );

			default:
				return '';
		}
	}
    
    /**
    * Display error notice for failed QPay payment.
    *
    * This function checks if the 'order_id' is present in the URL and if a transient.
    * exists for that order. If both conditions are met, it displays an error notice.
    * @since 1.0.0
    */
    public function display_failed_qpay_error_notice() {
        // Check if the 'order_id' exists in the URL
        if ( isset( $_GET['order_id'] ) ) {
            $order_id = $_GET['order_id'];

        // Check if the transient exists for this order
        if ( $order_id && get_transient( 'failed_qpay_error_notice_' . $order_id ) ) {
            wc_print_notice(
                sprintf(
                    __( 'Payment with Debit Card failed for Order #%d. Please try again or use a different payment method.', 'woocommerce-gateway-qpay' ),
                    $order_id
                ),
                'error'
            );

            // Delete the transient after it is displayed
            delete_transient( 'failed_qpay_error_notice_' . $order_id );
            }
        }
    }

    /**
    * Send WooCommerce standard "Customer Failed Order" email.
    *
    * @param WC_Order $order WooCommerce order object.
    */
    public function send_failed_payment_email_to_customer( $order ) {
	    if ( ! $order instanceof WC_Order ) {
		    return;
	    }

	    $mailer = WC()->mailer();
	    $emails = $mailer->get_emails();

	    foreach ( $emails as $email ) {
		    if ( $email instanceof WC_Email_Customer_Failed_Order ) {
			    $email->trigger( $order->get_id() );
			    break;
		    }
	    }
    }
  
    /**
     * Show possible admin notices
     */
    public function admin_notices()
    {

        // Get requirement errors.
        $errors_to_show = $this->check_requirements();

        // If everything is in place, don't display it.
        if (! count($errors_to_show)) {
            return;
        }

        // If the gateway isn't enabled, don't show it.
        if ('no' === $this->enabled) {
            return;
        }

        // Use transients to display the admin notice once after saving values.
        if (! get_transient('wc-gateway-qpay-admin-notice-transient')) {
            set_transient('wc-gateway-qpay-admin-notice-transient', 1, 1);

            echo '<div class="notice notice-error is-dismissible"><p>'
                . esc_html__('To use Qpay as a payment provider, you need to fix the problems below:', 'woocommerce-gateway-qpay') . '</p>'
                . '<ul style="list-style-type: disc; list-style-position: inside; padding-left: 2em;">'
                . wp_kses_post(
                    array_reduce(
                        $errors_to_show,
                        function ($errors_list, $error_item) {
                            $errors_list = $errors_list . PHP_EOL . ('<li>' . $this->get_error_message($error_item) . '</li>');
                            return $errors_list;
                        },
                        ''
                    )
                )
                . '</ul></p></div>';
        }
    }
        
    /**
     * Displays the amount_fee as returned by Qpay.
     *
     * @param int $order_id The ID of the order.
     */
    public function display_order_fee($order_id)
    {

        $order = wc_get_order($order_id);
        $fee   = $order->get_meta('qpay_amount_fee', true);

        if (! $fee) {
            return;
        }
        ?>

        <tr>
            <td class="label qpay-fee">
                <?php echo wc_help_tip(esc_html__('This represents the fee Qpay collects for the transaction.', 'woocommerce-gateway-qpay')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped 
                ?>
                <?php esc_html_e('Qpay Fee:', 'woocommerce-gateway-qpay'); ?>
            </td>
            <td width="1%"></td>
            <td class="total">
                <?php echo wp_kses_post(wc_price($fee, array('decimals' => 2))); ?>
            </td>
        </tr>

    <?php
    }

    /**
     * Displays the amount_net as returned by Qpay.
     *
     * @param int $order_id The ID of the order.
     */
    public function display_order_net($order_id)
    {

        $order = wc_get_order($order_id);
        $net   = $order->get_meta('qpay_amount_net', true);

        if (! $net) {
            return;
        }

    ?>

        <tr>
            <td class="label qpay-net">
                <?php echo wc_help_tip(esc_html__('This represents the net total that was credited to your Qpay account.', 'woocommerce-gateway-qpay')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped 
                ?>
                <?php esc_html_e('Amount Net:', 'woocommerce-gateway-qpay'); ?>
            </td>
            <td width="1%"></td>
            <td class="total">
                <?php echo wp_kses_post(wc_price($net, array('decimals' => 2))); ?>
            </td>
        </tr>

    <?php
    }
 

} 
 
