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
            'simulation_mode' => [
                'title'       => __( 'Simulation Mode', 'custom-checkout' ),
                'type'        => 'checkbox',
                'label'       => __( 'Simulate payments locally (skip real API call — for localhost/dev only)', 'custom-checkout' ),
                'default'     => 'no',
                'description' => __( 'When enabled, all payments instantly succeed without contacting Bankful. <strong>Disable before going live.</strong>', 'custom-checkout' ),
                'desc_tip'    => false,
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
                <input id="bankful-card-number" type="text" autocomplete="off" name="bankful_card_num" placeholder="0000 0000 0000 0000">
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

        // ── Simulation Mode (explicit gateway setting — not host-based) ──
        if ( 'yes' === $this->get_option( 'simulation_mode' ) ) {
            $order->set_transaction_id( 'SIM-' . time() );
            $order->payment_complete();
            $order->add_order_note( '[SIMULATION] Payment simulated. No real transaction was made.' );
            $order->save();
            return [
                'result'   => 'success',
                'redirect' => $this->get_return_url( $order ),
            ];
        }

        // ── Determine API endpoint ──
        $api_url = $this->test_mode
            ? 'https://api.sandbox.bankful.com/v1/transaction'
            : 'https://api.bankful.com/v1/transaction';

        $year = trim( $expiry[1] );
        if ( strlen( $year ) === 2 ) {
            $year = '20' . $year;
        }

        $payload = [
            'amount'          => number_format( (float) $order->get_total(), 2, '.', '' ),
            'currency'        => get_woocommerce_currency(),
            'card_number'     => $card_num,
            'expiry_month'    => str_pad( trim( $expiry[0] ), 2, '0', STR_PAD_LEFT ),
            'expiry_year'     => $year,
            'cvv'             => $cvc,
            'order_id'        => (string) $order_id,
            'billing_details' => [
                'first_name' => $order->get_billing_first_name(),
                'last_name'  => $order->get_billing_last_name(),
                'email'      => $order->get_billing_email(),
                'address'    => $order->get_billing_address_1(),
                'city'       => $order->get_billing_city(),
                'state'      => $order->get_billing_state(),
                'postcode'   => $order->get_billing_postcode(),
                'country'    => $order->get_billing_country(),
                'phone'      => $order->get_billing_phone(),
            ],
        ];

        error_log( 'Bankful: Sending to ' . $api_url . ' | Order #' . $order_id . ' | Amount: ' . $payload['amount'] );

        $response = wp_remote_post( $api_url, [
            'method'  => 'POST',
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode( $this->api_key . ':' . $this->secret_key ),
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'body'    => wp_json_encode( $payload ),
            'timeout' => 45,
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
        $body          = json_decode( $response_body );

        // Full response log for sandbox debugging.
        error_log( 'Bankful HTTP ' . $http_code . ' Response: ' . $response_body );
        $order->add_order_note( 'Bankful raw response (HTTP ' . $http_code . '): ' . $response_body );

        // Accept any common success status from the gateway.
        $success_statuses = [ 'approved', 'success', 'captured', 'paid', 'completed' ];
        $status_value     = strtolower( $body->status ?? $body->result ?? '' );

        if ( in_array( $status_value, $success_statuses, true ) || ( $http_code >= 200 && $http_code < 300 && ! empty( $body->transaction_id ?? $body->id ?? '' ) ) ) {
            $txn_id = $body->transaction_id ?? $body->id ?? ( 'BF-' . $order_id );
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

        // Payment rejected — surface the exact reason.
        $msg = $body->message ?? $body->error ?? $body->error_message ?? 'Payment rejected by provider (HTTP ' . $http_code . ').';
        error_log( 'Bankful Payment Failed: ' . $msg );
        wc_add_notice( $msg, 'error' );
        return [ 'result' => 'failure' ];
    }

    public function validate_fields() { return true; }
}
