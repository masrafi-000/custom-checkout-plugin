<?php
defined( 'ABSPATH' ) || exit;

class CCO_Payment_Gateway extends WC_Payment_Gateway {

    public function __construct() {
        $this->id = 'bankful';
        $this->method_title = __( 'Bankful Payment', 'custom-checkout' );
        $this->method_description = __( 'Accept credit card payments via Bankful Hosted Page.', 'custom-checkout' );
        $this->has_fields = false;
        $this->supports = [ 'products' ];

        $this->init_form_fields();
        $this->init_settings();

        $this->title             = $this->get_option( 'title', 'Credit Card (Bankful)' );
        $this->description       = $this->get_option( 'description' );
        $this->merchant_username = trim( $this->get_option( 'merchant_username' ) );
        $this->merchant_password = trim( $this->get_option( 'merchant_password' ) );
        $this->test_mode         = 'yes' === $this->get_option( 'test_mode' );

        add_action(
            'woocommerce_update_options_payment_gateways_' . $this->id,
            [ $this, 'process_admin_options' ]
        );

        add_action( 'woocommerce_api_cco_payment_gateway', [ $this, 'handle_callback' ] );
        add_action( 'woocommerce_thankyou_' . $this->id, [ $this, 'check_thankyou_status' ] );
        add_action( 'template_redirect', [ $this, 'check_thankyou_status' ] );
    }

    public function init_form_fields() {
        $this->form_fields = [
            'enabled'           => [ 'title' => 'Enable/Disable', 'type' => 'checkbox', 'label' => 'Enable Bankful', 'default' => 'yes' ],
            'test_mode'         => [ 'title' => 'Test Mode', 'type' => 'checkbox', 'label' => 'Enable Sandbox', 'default' => 'no' ],
            'title'             => [ 'title' => 'Title', 'type' => 'text', 'default' => 'Credit Card (Bankful)' ],
            'description'       => [ 'title' => 'Description', 'type' => 'textarea', 'default' => 'Pay securely via credit card.' ],
            'merchant_username' => [ 'title' => 'Merchant Username', 'type' => 'text', 'default' => 'testsandbox8@sanbox.com' ],
            'merchant_password' => [ 'title' => 'Password (Salt)', 'type' => 'password', 'default' => 'Testsandbox@8' ],
        ];
    }

    public function payment_fields() {
        if ( $this->description ) {
            echo wpautop( wp_kses_post( $this->description ) );
        }
        echo '<p>' . __( 'You will be redirected to Bankful to complete your payment securely.', 'custom-checkout' ) . '</p>';
    }

    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return [ 'result' => 'failure' ];

        $username = $this->merchant_username;
        $salt     = $this->merchant_password;

        $api_url = $this->test_mode
            ? 'https://api-dev1.bankfulportal.com/front-calls/go-in/hosted-page-pay'
            : 'https://api.bankfulportal.com/front-calls/go-in/hosted-page-pay';

        $payload = [
            'req_username'        => $username,
            'transaction_type'    => 'CAPTURE',
            'amount'              => (string) $order->get_total(),
            'request_currency'    => $order->get_currency(),
            'cust_email'          => $order->get_billing_email(),
            'bill_addr_country'   => $order->get_billing_country(),
            'cust_fname'          => $order->get_billing_first_name(),
            'cust_lname'          => $order->get_billing_last_name(),
            'cust_phone'          => $order->get_billing_phone(),
            'bill_addr'           => $order->get_billing_address_1(),
            'bill_addr_2'         => $order->get_billing_address_2(),
            'bill_addr_city'      => $order->get_billing_city(),
            'bill_addr_state'     => $order->get_billing_state(),
            'bill_addr_zip'       => $order->get_billing_postcode(),
            'xtl_order_id'        => (string) $order_id,
            'cart_name'           => 'Hosted-Page',
            'url_cancel'          => wc_get_checkout_url(),
            'url_complete'        => $this->get_return_url( $order ),
            'url_failed'          => $order->get_checkout_payment_url( false ),
            'url_callback'        => home_url( '/' ) . 'wc-api/CCO_Payment_Gateway',
            'url_pending'         => $this->get_return_url( $order ),
            'return_redirect_url' => 'Y',
        ];

        $payload['signature'] = $this->generate_signature( $payload, $salt );

        $response = wp_remote_post( $api_url, [
            'method'    => 'POST',
            'headers'   => [ 'Content-Type' => 'application/json' ],
            'body'      => wp_json_encode( $payload ),
            'timeout'   => 45,
            'sslverify' => true,
        ]);

        if ( is_wp_error( $response ) ) {
            wc_add_notice( 'Connection Error: ' . $response->get_error_message(), 'error' );
            return [ 'result' => 'failure' ];
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        $order->add_order_note( 'Bankful Hosted Page Request: ' . wp_json_encode( $payload ) );
        $order->add_order_note( 'Bankful Hosted Page Response: ' . $body );

        $hosted_url = $data['hosted_page_url'] ?? $data['redirect_url'] ?? $data['hosted_url'] ?? '';

        if ( ! empty( $hosted_url ) ) {
            return [
                'result'   => 'success',
                'redirect' => $hosted_url
            ];
        }

        $error = $data['errorMessage'] ?? $data['message'] ?? 'Unknown Error';
        wc_add_notice( 'Bankful Error: ' . $error, 'error' );
        return [ 'result' => 'failure' ];
    }

    private function generate_signature( $payload, $salt ) {
        unset( $payload['signature'] );
        ksort( $payload );
        $payloadString = '';
        foreach ( $payload as $key => $value ) {
            if ( $value !== null && $value !== '' ) {
                $payloadString .= $key . $value;
            }
        }
        return hash_hmac( 'sha256', $payloadString, $salt );
    }

    public function handle_callback() {
        error_log( 'Bankful Callback Hit!' );
        $json = file_get_contents( 'php://input' );
        error_log( 'Bankful Callback Raw Body: ' . $json );
        $data = json_decode( $json, true );

        if ( ! $data ) {
            $data = $_POST;
            error_log( 'Bankful Callback POST: ' . wp_json_encode( $_POST ) );
        }

        // Try various common field names for Order ID
        $order_id = $data['xtl_order_id'] ?? $data['TRANS_ORDER_ID'] ?? $data['order_id'] ?? $data['TRANS_RECORD_ID'] ?? $data['record_id'] ?? 0;
        
        error_log( 'Bankful Callback Resolved Order ID: ' . $order_id );
        
        $order = wc_get_order( $order_id );

        if ( $order ) {
            $order->add_order_note( 'Bankful Callback Received: ' . wp_json_encode( $data ) );

            // Try various common field names for Status
            $status = strtoupper( (string) (
                $data['transaction_status'] ?? 
                $data['TRANS_STATUS_NAME'] ?? 
                $data['status'] ?? 
                $data['response_text'] ?? 
                $data['result'] ?? 
                ''
            ) );
            
            $txn_id = $data['transaction_id'] ?? $data['TRANS_RECORD_ID'] ?? $data['record_id'] ?? '';
            
            error_log( 'Bankful Callback Resolved Status: ' . $status );

            if ( in_array( $status, [ 'APPROVED', 'SUCCESS', 'COMPLETE', 'CAPTURED', 'OK', '1' ], true ) ) {
                $order->payment_complete( $txn_id );
                $order->add_order_note( 'Bankful payment approved via callback. Status: ' . $status );
                error_log( 'Bankful Callback: Order ' . $order_id . ' marked as completed.' );
            } elseif ( in_array( $status, [ 'DECLINED', 'FAILED', 'ERROR' ], true ) ) {
                $order->update_status( 'failed', 'Bankful payment failed/declined. Status: ' . $status );
                error_log( 'Bankful Callback: Order ' . $order_id . ' marked as failed.' );
            }
        } else {
            error_log( 'Bankful Callback: Order not found for ID ' . $order_id );
        }

        echo 'OK';
        exit;
    }

    public function check_thankyou_status( $order_id = 0 ) {
        if ( ! $order_id ) {
            $order_id = get_query_var( 'cco_order_received' ) ?: get_query_var( 'cco_order_pay' ) ?: ( $_GET['xtl_order_id'] ?? 0 );
        }
        if ( ! $order_id ) return;
        $order = wc_get_order( $order_id );
        if ( ! $order || $order->is_paid() ) return;

        $data = array_merge( $_GET, $_POST );

        // Check if Bankful returned status in the URL or POST
        $status = strtoupper( (string) (
            $data['transaction_status'] ?? 
            $data['status'] ?? 
            $data['TRANS_STATUS_NAME'] ?? 
            $data['response_text'] ?? 
            $data['result'] ?? 
            ''
        ) );
        
        $txn_id = $data['transaction_id'] ?? $data['record_id'] ?? $data['TRANS_RECORD_ID'] ?? '';

        if ( in_array( $status, [ 'APPROVED', 'SUCCESS', 'COMPLETE', 'CAPTURED', 'OK', '1' ], true ) ) {
            $order->payment_complete( $txn_id );
            $order->add_order_note( 'Bankful payment approved via return redirect. Status: ' . $status );
        }
    }

    public function validate_fields() { return true; }
}