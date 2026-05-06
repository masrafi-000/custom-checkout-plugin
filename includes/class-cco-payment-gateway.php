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
        $this->merchant_id = $this->get_option( 'merchant_id' );
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
            'merchant_id' => [
                'title'       => __( 'Merchant ID / Account ID', 'custom-checkout' ),
                'type'        => 'text',
                'description' => __( 'Your numeric Bankful Merchant ID or Account ID.', 'custom-checkout' ),
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


        // ── Determine API endpoint ──
        $api_url = $this->test_mode
            ? 'https://api-dev1.bankfulportal.com/api/transaction/api'
            : 'https://api.paybybankful.com/api/transaction/api';

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
            'req_password'     => trim( (string) $this->secret_key ),
            'merchant_id'      => trim( (string) $this->merchant_id ),
            'transaction_type' => 'CAPTURE',
            'amount'           => number_format( (float) $order->get_total(), 2, '.', '' ),
            'request_currency' => get_woocommerce_currency(),
            'pmt_numb'         => $card_num,
            'pmt_expiry'       => $month . '/' . $year,
            'pmt_key'          => $cvc,
            'xtl_order_id'     => (string) $order_id,
            'cust_fname'       => $order->get_billing_first_name(),
            'cust_lname'       => $order->get_billing_last_name(),
            'cust_email'       => $order->get_billing_email(),
            'cust_phone'       => $order->get_billing_phone(),
            'bill_addr'        => $order->get_billing_address_1(),
            'bill_addr_city'   => $order->get_billing_city(),
            'bill_addr_state'  => $order->get_billing_state(),
            'bill_addr_zip'    => $order->get_billing_postcode(),
            'bill_addr_country'=> $order->get_billing_country(),
        ];

        $log_payload = $payload;
        $log_payload['pmt_numb'] = 'XXXX-XXXX-XXXX-' . substr($card_num, -4);
        $log_payload['pmt_key']  = 'XXX';
        error_log( 'Bankful Request Payload: ' . json_encode( $log_payload ) );

        error_log( 'Bankful: Sending to ' . $api_url . ' | Order #' . $order_id . ' | Amount: ' . $payload['amount'] );

        $response = wp_remote_post( $api_url, [
            'method'      => 'POST',
            'headers'     => [
                'Content-Type'  => 'application/x-www-form-urlencoded; charset=utf-8',
                'cache-control' => 'no-cache',
            ],
            'body'        => $payload, 
            'timeout'     => 45,
            'sslverify'   => true, 
        ] );

        if ( is_wp_error( $response ) ) {
            $err = $response->get_error_message();
            error_log( 'Bankful cURL Error: ' . $err );
            $order->add_order_note( 'Bankful connection error: ' . $err );
            wc_add_notice( 'Connection error with payment provider: ' . $err, 'error' );
            return [ 'result' => 'failure' ];
        }

        $http_code     = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
        
        // Log for debugging.
        error_log( 'Bankful HTTP ' . $http_code . ' Response: ' . $response_body );
        $order->add_order_note( 'Bankful Debug: ' . $response_body );

        // Try to parse as JSON first.
        $body_obj = json_decode( $response_body, true );
        
        // If not JSON, try to parse as form-encoded (common for legacy gateway APIs).
        if ( ! is_array( $body_obj ) ) {
            parse_str( $response_body, $body_obj );
        }

        // Standardize status checks (Bankful uses uppercase for some fields).
        $status_raw = $body_obj['TRANS_STATUS_NAME'] ?? $body_obj['status'] ?? $body_obj['result'] ?? $body_obj['response'] ?? $body_obj['response_code'] ?? '';
        $status     = strtoupper( (string) $status_raw );
        $txn_id     = $body_obj['TRANS_RECORD_ID'] ?? $body_obj['transaction_id'] ?? $body_obj['id'] ?? $body_obj['txn_id'] ?? '';

        $success_keywords = [ 'APPROVED', 'SUCCESS', 'CAPTURED', 'PAID', 'COMPLETED', '1' ];
        $is_success = in_array( $status, $success_keywords, true );

        // Even if status string is missing, check HTTP code and presence of a transaction ID.
        if ( ! $is_success && $http_code >= 200 && $http_code < 300 && ! empty( $txn_id ) ) {
            $is_success = true;
        }

        if ( $is_success ) {
            $final_txn_id = $txn_id ?: ( 'BF-' . $order_id );
            $order->set_transaction_id( $final_txn_id );
            $order->payment_complete();
            $order->add_order_note( 'Bankful payment successful. Transaction ID: ' . $final_txn_id );
            $order->save();
            WC()->cart->empty_cart();
            return [
                'result'   => 'success',
                'redirect' => $this->get_return_url( $order ),
            ];
        }

        // Payment failed or was rejected.
        $error_msg = $body_obj['API_ADVICE'] ?? $body_obj['message'] ?? $body_obj['error'] ?? $body_obj['error_message'] ?? $body_obj['response_text'] ?? $body_obj['response_msg'] ?? $body_obj['reason'] ?? $body_obj['desc'] ?? '';
        
        if ( empty( $error_msg ) ) {
            // If we can't find a specific error field, show the raw response (truncated for safety) to see what's happening.
            $clean_body = strip_tags( $response_body );
            $error_msg  = 'Payment rejected (HTTP ' . $http_code . '). Response: ' . substr( $clean_body, 0, 200 );
        }

        error_log( 'Bankful Payment Failed: ' . $error_msg );
        wc_add_notice( $error_msg, 'error' );
        return [ 'result' => 'failure' ];
    }

    public function validate_fields() { return true; }
}
