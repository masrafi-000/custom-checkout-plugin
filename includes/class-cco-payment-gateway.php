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
        $this->api_password = $this->get_option( 'api_password' );

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
                'title'       => __( 'API Key/Username', 'custom-checkout' ),
                'type'        => 'text',
                'default'     => '',
            ],
            'api_password' => [
                'title'       => __( 'API Password', 'custom-checkout' ),
                'type'        => 'password',
                'default'     => '',
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

        // Extract payment data from the request.
        $params = $_POST;
        if ( empty( $params['bankful_card_num'] ) ) {
            $json = json_decode( file_get_contents( 'php://input' ), true );
            $params = $json['payment_data'] ?? [];
        }

        $card_num   = str_replace(' ', '', $params['bankful_card_num'] ?? '');
        $expiry_str = $params['bankful_card_expiry'] ?? '';
        $expiry     = explode('/', $expiry_str);
        $cvc        = $params['bankful_card_cvc'] ?? '';

        if ( empty($card_num) || count($expiry) < 2 || empty($cvc) ) {
            wc_add_notice( 'Invalid credit card details.', 'error' );
            return [ 'result' => 'failure' ];
        }

        $api_url = 'https://api.bankful.com/v1/transaction';

        $payload = array(
            'amount'          => $order->get_total(),
            'currency'        => get_woocommerce_currency(),
            'card_number'     => $card_num,
            'expiry_month'    => trim($expiry[0]),
            'expiry_year'     => trim($expiry[1]),
            'cvv'             => $cvc,
            'order_id'        => $order_id,
            'billing_details' => array(
                'first_name' => $order->get_billing_first_name(),
                'last_name'  => $order->get_billing_last_name(),
                'email'      => $order->get_billing_email(),
            )
        );

        $response = wp_remote_post( $api_url, array(
            'method'    => 'POST',
            'headers'   => array(
                'Authorization' => 'Basic ' . base64_encode( $this->api_key . ':' . $this->api_password ),
                'Content-Type'  => 'application/json',
            ),
            'body'      => json_encode( $payload ),
            'timeout'   => 45,
        ));

        if ( is_wp_error( $response ) ) {
            wc_add_notice( 'Connection error with payment provider.', 'error' );
            return [ 'result' => 'failure' ];
        }

        $body = json_decode( wp_remote_retrieve_body( $response ) );

        if ( isset($body->status) && $body->status == 'approved' ) {
            $order->payment_complete();
            $order->add_order_note( 'Bankful payment successful. Transaction ID: ' . $body->transaction_id );

            WC()->cart->empty_cart();

            return array(
                'result'   => 'success',
                'redirect' => $this->get_return_url( $order ),
            );
        } else {
            $msg = $body->message ?? 'Payment rejected.';
            wc_add_notice( 'Payment rejected: ' . $msg, 'error' );
            return [ 'result' => 'failure' ];
        }
    }

    public function validate_fields() { return true; }
}
