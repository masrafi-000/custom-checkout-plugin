<?php
/**
 * CCO_Payment_Gateway
 *
 * Bankful Payment Gateway integration.
 */

defined( 'ABSPATH' ) || exit;

class CCO_Payment_Gateway extends WC_Payment_Gateway {

    public function __construct() {
        $this->id                 = 'bankful';
        $this->method_title       = __( 'Bankful Payment', 'custom-checkout' );
        $this->method_description = __( 'Accept credit card payments via Bankful.', 'custom-checkout' );
        $this->has_fields         = true; 
        $this->supports           = [ 'products' ];

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option( 'title', 'Credit Card (Bankful)' );
        $this->description = $this->get_option( 'description' );
        $this->api_key     = $this->get_option( 'api_key' );
        $this->secret_key  = $this->get_option( 'secret_key' );
        $this->test_mode   = 'yes' === $this->get_option( 'test_mode' );

        add_action(
            'woocommerce_update_options_payment_gateways_' . $this->id,
            [ $this, 'process_admin_options' ]
        );
    }

    public function init_form_fields() {
        $this->form_fields = [
            'enabled'     => [
                'title'   => __( 'Enable/Disable', 'custom-checkout' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable Bankful Payment', 'custom-checkout' ),
                'default' => 'yes',
            ],
            'test_mode'       => [
                'title'   => __( 'Test Mode', 'custom-checkout' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable Sandbox/Test Mode', 'custom-checkout' ),
                'default' => 'no',
            ],
            'title'       => [
                'title'   => __( 'Title', 'custom-checkout' ),
                'type'    => 'text',
                'default' => __( 'Credit Card (Bankful)', 'custom-checkout' ),
            ],
            'description' => [
                'title'   => __( 'Description', 'custom-checkout' ),
                'type'    => 'textarea',
                'default' => __( 'Pay securely via your credit card.', 'custom-checkout' ),
            ],
            'api_key'     => [
                'title'       => __( 'API Key', 'custom-checkout' ),
                'type'        => 'text',
                'description' => __( 'Your Bankful API Key.', 'custom-checkout' ),
                'default'     => '',
                'desc_tip'    => true,
            ],
            'secret_key' => [
                'title'       => __( 'Secret Key', 'custom-checkout' ),
                'type'        => 'password',
                'description' => __( 'Your Bankful Secret Key.', 'custom-checkout' ),
                'default'     => '',
                'desc_tip'    => true,
            ],
        ];
    }

    /**
     * Render payment fields for the checkout page.
     */
    public function payment_fields() {
        if ( $this->description ) {
            echo wpautop( wptexturize( $this->description ) );
        }
        ?>
        <fieldset id="bankful-card-form" class="cco-bankful-fields">
            <div class="cco-field">
                <label>Card Number <span class="required">*</span></label>
                <input id="bankful-card-num" type="text" autocomplete="off" name="bankful_card_num" placeholder="0000 0000 0000 0000">
            </div>
            <div class="cco-row">
                <div class="cco-field">
                    <label>Expiry Date (MM/YY) <span class="required">*</span></label>
                    <input id="bankful-card-expiry" placeholder="MM / YY" type="text" name="bankful_card_expiry">
                </div>
                <div class="cco-field">
                    <label>Card Code (CVC) <span class="required">*</span></label>
                    <input id="bankful-card-cvc" type="password" name="bankful_card_cvc" placeholder="***">
                </div>
            </div>
        </fieldset>
        <?php
    }

    /**
     * Process payment.
     */
    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return [ 'result' => 'failure' ];
        }

        // Extract payment data from the request.
        // Check global storage first (populated by CCO_API::place_order).
        $params = $GLOBALS['cco_payment_data'] ?? [];

        if ( empty( $params ) ) {
            $params = $_POST;
            if ( empty( $params['bankful_card_num'] ) ) {
                $json = json_decode( file_get_contents( 'php://input' ), true );
                $params = $json['payment_data'] ?? [];
            }
        }

        $card_num   = str_replace( ' ', '', $params['bankful_card_num'] ?? '' );
        $expiry_str = $params['bankful_card_expiry'] ?? '';
        $expiry     = explode( '/', $expiry_str );
        $cvc        = $params['bankful_card_cvc'] ?? '';

        if ( empty( $card_num ) || count( $expiry ) < 2 || empty( $cvc ) ) {
            wc_add_notice( 'Invalid credit card details.', 'error' );
            return [ 'result' => 'failure' ];
        }


        // ── Determine API endpoint (Using V2 for better AUD support) ──
        $api_url = $this->test_mode
            ? 'https://api-dev1.bankfulportal.com/api/woocommerce-v2/transaction'
            : 'https://api.paybybankful.com/api/woocommerce-v2/transaction';

        $year = '';
        $month = '';

        if ( strpos( $expiry_str, '/' ) !== false ) {
            $expiry = explode( '/', $expiry_str );
            $month  = str_pad( trim( $expiry[0] ), 2, '0', STR_PAD_LEFT );
            $year   = trim( $expiry[1] );
            if ( strlen( $year ) === 2 ) {
                $year = '20' . $year;
            }
        }

        if ( empty( $month ) || empty( $year ) ) {
            wc_add_notice( 'Invalid expiry date format. Please use MM/YY.', 'error' );
            return [ 'result' => 'failure' ];
        }

        // Bankful API uses application/x-www-form-urlencoded and body-based auth.
        $payload = [
            'req_username'     => trim( (string) $this->api_key ),
            'username'         => trim( (string) $this->api_key ), // Dual field support
            'req_password'     => trim( (string) $this->secret_key ),
            'password'         => trim( (string) $this->secret_key ), // Dual field support
            'transaction_type' => 'CAPTURE',
            'request_action'   => 'CCAUTHCAP', // V2 Action
            'amount'           => number_format( (float) $order->get_total(), 2, '.', '' ),
            'request_currency' => 'AUD', 
            'pmt_numb'         => $card_num,
            'pmt_expiry'       => $month . '/' . $year,
            'pmt_key'          => $cvc,
            'cust_fname'       => $order->get_billing_first_name(),
            'cust_lname'       => $order->get_billing_last_name(),
            'cust_email'       => $order->get_billing_email(),
            'bill_addr'        => $order->get_billing_address_1(),
            'bill_addr_city'   => $order->get_billing_city(),
            'bill_addr_state'  => $order->get_billing_state(),
            'bill_addr_zip'    => $order->get_billing_postcode(),
            'bill_addr_country'=> $order->get_billing_country(),
            'cart_name'        => 'WooCommerce',
            'xtl_order_id'     => (string) $order_id,
        ];

        $log_payload = $payload;
        $log_payload['pmt_numb'] = 'XXXX-XXXX-XXXX-' . substr($card_num, -4);
        $log_payload['pmt_key']  = 'XXX';
        error_log( 'Bankful Request Payload: ' . json_encode( $log_payload ) );

        error_log( 'Bankful: Sending JSON to ' . $api_url . ' | Order #' . $order_id );

        $response = wp_remote_post( $api_url, [
            'method'      => 'POST',
            'headers'     => [
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
                'cache-control' => 'no-cache',
            ],
            'body'        => json_encode( $payload ), 
            'timeout'     => 45,
            'sslverify'   => true, 
        ] );

        if ( is_wp_error( $response ) ) {
            $err_msg = $response->get_error_message();
            error_log( 'Bankful Connection Error: ' . $err_msg );
            wc_add_notice( 'Payment gateway connection error: ' . $err_msg, 'error' );
            return [ 'result' => 'failure' ];
        }

        $http_code     = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );

        error_log( 'Bankful Response (' . $http_code . '): ' . $response_body );
        
        // Save the raw response as an order note for easier debugging
        $order->add_order_note( 'Bankful Debug Response: ' . $response_body );

        // Try to parse as JSON first.
        $body_obj = json_decode( $response_body, true );
        
        // If not JSON, it might be form-encoded (less likely for V2 but possible for error cases).
        if ( ! is_array( $body_obj ) ) {
            parse_str( $response_body, $body_obj );
        }

        // Standardize status checks.
        $status_raw = $body_obj['TRANS_STATUS_NAME'] ?? $body_obj['status'] ?? $body_obj['result'] ?? $body_obj['response_message'] ?? '';
        $status     = strtoupper( (string) $status_raw );
        $txn_id     = $body_obj['TRANS_RECORD_ID'] ?? $body_obj['transaction_id'] ?? '';

        if ( $status === 'APPROVED' ) {
            $order->set_transaction_id( $txn_id );
            $order->payment_complete();
            $order->add_order_note( 'Bankful payment successful. Transaction ID: ' . $txn_id );
            $order->save();
            WC()->cart->empty_cart();
            return [
                'result'   => 'success',
                'redirect' => $this->get_return_url( $order ),
            ];
        }

        // Failure handling - Try to find the most specific error message
        $error_msg = $body_obj['ERROR_MESSAGE'] 
                  ?? $body_obj['API_ADVICE'] 
                  ?? $body_obj['response_message'] 
                  ?? $body_obj['message'] 
                  ?? $body_obj['RESP_MSG']
                  ?? 'Transaction declined.';
        
        error_log( 'Bankful Payment Declined: ' . $error_msg . ' | Status: ' . $status );
        wc_add_notice( 'Bankful Error: ' . $error_msg, 'error' );
        return [ 'result' => 'failure' ];
    }

    public function validate_fields() { return true; }
}
