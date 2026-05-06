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
        if ( ! $order ) return [ 'result' => 'failure' ];

        $params = $GLOBALS['cco_payment_data'] ?? $_POST;
        $card_num   = str_replace( [ ' ', '-' ], '', $params['bankful_card_num'] ?? '' );
        $expiry_str = $params['bankful_card_expiry'] ?? '';
        $expiry     = explode( '/', $expiry_str );
        $month      = str_pad( trim( $expiry[0] ?? '' ), 2, '0', STR_PAD_LEFT );
        $year       = trim( $expiry[1] ?? '' );
        if ( strlen( $year ) === 2 ) $year = '20' . $year;
        $cvc        = $params['bankful_card_cvc'] ?? '';

        $api_url = $this->test_mode
            ? 'https://api-dev1.bankfulportal.com/api/woocommerce-v2/transaction'
            : 'https://api.paybybankful.com/api/woocommerce-v2/transaction';

        $bf_access_token = get_option( 'bankful_options_api_access_token' );
        $bf_public_key   = get_option( 'bankful_options_public_key' );
        $bf_hash_salt    = get_option( 'bankful_options_hash_salt' );
        $bf_site_id      = get_option( 'bankful_options_site_id' );

        // Fallback: If no token, try to get one now
        if ( empty( $bf_access_token ) ) {
            $fresh = $this->get_fresh_token();
            $bf_access_token = $fresh['token'] ?? '';
            $bf_public_key   = $fresh['public_key'] ?? $bf_public_key;
            $bf_hash_salt    = $fresh['salt'] ?? $bf_hash_salt;
        }

        $payload = [
            'bf_api_key'        => trim( (string) $this->api_key ),
            'bf_api_secret'     => trim( (string) $this->secret_key ),
            'pmt_numb'          => $card_num,
            'pmt_key'           => $cvc,
            'pmt_expiry'        => $month . '/' . $year,
            'request_currency'  => $order->get_currency(),
            'amount'            => number_format( (float) $order->get_total(), 2, '.', '' ),
            'cust_fname'        => $order->get_billing_first_name(),
            'cust_lname'        => $order->get_billing_last_name(),
            'cust_email'        => $order->get_billing_email(),
            'bill_addr'         => trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() ),
            'bill_addr_city'    => $order->get_billing_city(),
            'bill_addr_zip'     => $order->get_billing_postcode(),
            'bill_addr_country' => $order->get_billing_country(),
            'order_id'          => $order_id,
            'xtl_order_id'      => $order_id,
            'request_action'    => 'CCAUTHCAP',
        ];

        if ( ! empty( $bf_site_id ) ) $payload['site_id'] = $bf_site_id;

        if ( ! empty( $bf_public_key ) ) {
            $payload['pmt_numb']   = $this->encrypt_payload( $card_num, $bf_public_key );
            $payload['pmt_key']    = $this->encrypt_payload( $cvc, $bf_public_key );
            $payload['pmt_expiry'] = $this->encrypt_payload( $month . '/' . $year, $bf_public_key );
        }

        $headers = [ 'Content-Type' => 'application/json' ];
        if ( ! empty( $bf_hash_salt ) ) $headers['X-Bankful-Hmac-SHA256'] = $this->generate_signature( $payload, $bf_hash_salt );
        if ( ! empty( $bf_access_token ) ) $headers['Authorization'] = 'Bearer ' . $bf_access_token;

        $response = wp_remote_post( $api_url, [
            'method'    => 'POST',
            'headers'   => $headers,
            'body'      => wp_json_encode( $payload ),
            'timeout'   => 45,
            'sslverify' => true,
        ] );

        $response_body = wp_remote_retrieve_body( $response );
        $order->add_order_note( 'Bankful Debug Response: ' . $response_body );

        $body_obj = json_decode( $response_body, true );
        
        // Final Fallback: If Token was the issue, refresh it once and retry
        if ( isset( $body_obj['errorMessage'] ) && strpos( $body_obj['errorMessage'], 'Token' ) !== false ) {
            error_log( 'Bankful: Token invalid, refreshing...' );
            $fresh = $this->get_fresh_token();
            if ( ! empty( $fresh['token'] ) ) {
                $headers['Authorization'] = 'Bearer ' . $fresh['token'];
                if ( ! empty( $fresh['salt'] ) ) $headers['X-Bankful-Hmac-SHA256'] = $this->generate_signature( $payload, $fresh['salt'] );
                $response = wp_remote_post( $api_url, [
                    'method'  => 'POST',
                    'headers' => $headers,
                    'body'    => wp_json_encode( $payload ),
                    'timeout' => 45,
                ] );
                $response_body = wp_remote_retrieve_body( $response );
                $body_obj = json_decode( $response_body, true );
            }
        }

        $status = strtoupper( (string) ($body_obj['TRANS_STATUS_NAME'] ?? $body_obj['status'] ?? $body_obj['result'] ?? '') );
        if ( $status === 'APPROVED' || $status === 'SUCCESS' ) {
            $order->set_transaction_id( $body_obj['TRANS_RECORD_ID'] ?? $body_obj['transaction_id'] ?? '' );
            $order->payment_complete();
            return [ 'result' => 'success', 'redirect' => $this->get_return_url( $order ) ];
        } else {
            wc_add_notice( 'Bankful Error: ' . ($body_obj['ERROR_MESSAGE'] ?? $body_obj['errorMessage'] ?? 'Transaction declined.'), 'error' );
            return [ 'result' => 'failure' ];
        }
    }

    /**
     * Get a fresh Bearer Token from Bankful
     */
    private function get_fresh_token() {
        global $woocommerce;
        $api_url = 'https://api.paybybankful.com/api/woocommerce-v2/validate-credentials';
        $params = [
            'bf_api_key'        => trim( (string) $this->api_key ),
            'bf_api_secret'     => trim( (string) $this->secret_key ),
            'site_url'          => get_site_url(),
            'woo_version'       => $woocommerce->version ?? '8.0.0',
            'bf_plugin_version' => '3.0.4',
        ];

        $response = wp_remote_post( $api_url, [ 'body' => $params ] );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $body['status'] ) && 'success' === $body['status'] ) {
            update_option( 'bankful_options_api_access_token', $body['data']['bf_api_access_token'] );
            update_option( 'bankful_options_public_key', $body['data']['merchant_public_key'] );
            update_option( 'bankful_options_hash_salt', $body['data']['bf_salt'] );
            return [
                'token'      => $body['data']['bf_api_access_token'],
                'public_key' => $body['data']['merchant_public_key'],
                'salt'       => $body['data']['bf_salt']
            ];
        }
        return [];
    }

    public function validate_fields() { return true; }

    /**
     * Ported from official Bankful plugin: RSA Encryption
     */
    private function encrypt_payload( $payload, $publicKey ) {
        if ( ! function_exists( 'openssl_public_encrypt' ) ) {
            error_log( 'Bankful: OpenSSL extension is missing on this server.' );
            return $payload;
        }

        $encrypted = '';
        $startPublicKey = '-----BEGIN PUBLIC KEY-----' . PHP_EOL;
        $endPublicKey = PHP_EOL . '-----END PUBLIC KEY-----';
        $full_key = $startPublicKey . $publicKey . $endPublicKey;
        
        if ( @openssl_public_encrypt( $payload, $encrypted, $full_key ) ) {
            return base64_encode( $encrypted );
        }
        return $payload; 
    }

    /**
     * Ported from official Bankful plugin: HMAC Signature Generation
     */
    private function generate_signature( $data, $salt ) {
        if ( ! is_array( $data ) || empty( $salt ) ) return '';

        $notInclude = [ 'SIGNATURE' ];
        $paramData  = array_filter( array_keys( $data ), function ( $key ) use ( $notInclude ) {
            return ! in_array( $key, $notInclude );
        } );

        sort( $paramData );

        $strField = '';
        foreach ( $paramData as $paramName ) {
            $paramValue = $data[ $paramName ];
            if ( is_array( $paramValue ) || is_object( $paramValue ) ) {
                $strField .= $paramName . wp_json_encode( $paramValue );
            } elseif ( is_string( $paramValue ) || is_numeric( $paramValue ) || is_bool( $paramValue ) ) {
                if ( is_string( $paramValue ) && '' !== (string) $paramValue ) {
                    $strField .= $paramName . $paramValue;
                } elseif ( ! is_string( $paramValue ) ) {
                    $strField .= $paramName . $paramValue;
                }
            } elseif ( '' !== (string) $paramValue ) {
                $strField .= $paramName . $paramValue;
            }
        }

        return hash_hmac( 'sha256', $strField, $salt );
    }
}
