<?php
defined( 'ABSPATH' ) || exit;

class CCO_Payment_Gateway extends WC_Payment_Gateway {

    public function __construct() {
        $this->id = 'bankful';
        $this->method_title = __( 'Bankful Payment', 'custom-checkout' );
        $this->method_description = __( 'Accept credit card payments via Bankful.', 'custom-checkout' );
        $this->has_fields = true;
        $this->supports = [ 'products' ];

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option( 'title', 'Credit Card (Bankful)' );
        $this->description = $this->get_option( 'description' );
        $this->api_key     = trim( $this->get_option( 'api_key' ) );
        $this->secret_key  = trim( $this->get_option( 'secret_key' ) );
        $this->test_mode   = 'yes' === $this->get_option( 'test_mode' );

        add_action(
            'woocommerce_update_options_payment_gateways_' . $this->id,
            [ $this, 'process_admin_options' ]
        );
    }

    public function init_form_fields() {
        $this->form_fields = [
            'enabled'     => [ 'title' => 'Enable/Disable', 'type' => 'checkbox', 'label' => 'Enable Bankful', 'default' => 'yes' ],
            'test_mode'   => [ 'title' => 'Test Mode', 'type' => 'checkbox', 'label' => 'Enable Sandbox', 'default' => 'no' ],
            'title'       => [ 'title' => 'Title', 'type' => 'text', 'default' => 'Credit Card (Bankful)' ],
            'description' => [ 'title' => 'Description', 'type' => 'textarea', 'default' => 'Pay securely via credit card.' ],
            'api_key'     => [ 'title' => 'API Key', 'type' => 'text' ],
            'secret_key'  => [ 'title' => 'Secret Key', 'type' => 'password' ],
        ];
    }

    public function payment_fields() {
        ?>
        <fieldset id="bankful-card-form">
            <div class="cco-field"><label>Card Number</label><input type="text" name="bankful_card_num" placeholder="0000 0000 0000 0000"></div>
            <div class="cco-row" style="display:flex;gap:10px;">
                <div class="cco-field"><label>Expiry (MM/YY)</label><input type="text" name="bankful_card_expiry" placeholder="MM / YY"></div>
                <div class="cco-field"><label>CVC</label><input type="password" name="bankful_card_cvc" placeholder="***"></div>
            </div>
        </fieldset>
        <?php
    }

    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return [ 'result' => 'failure' ];

        $params = $GLOBALS['cco_payment_data'] ?? $_POST;
        $card_num = str_replace( [ ' ', '-' ], '', $params['bankful_card_num'] ?? '' );
        $expiry   = explode( '/', $params['bankful_card_expiry'] ?? '' );
        $month    = str_pad( trim( $expiry[0] ?? '' ), 2, '0', STR_PAD_LEFT );
        $year     = trim( $expiry[1] ?? '' );
        if ( strlen( $year ) === 2 ) $year = '20' . $year;

        $expiry_formatted = $month . '/' . $year;
        $cvc = $params['bankful_card_cvc'] ?? '';

        if ( empty($card_num) || empty($month) || empty($cvc) ) {
            wc_add_notice('Invalid card details.', 'error');
            return [ 'result' => 'failure' ];
        }

        $api_url = $this->test_mode
            ? 'https://api-dev1.bankfulportal.com/api/woocommerce-v2/transaction'
            : 'https://api.paybybankful.com/api/woocommerce-v2/transaction';

        $bf_access_token = get_option( 'bankful_options_api_access_token' );
        $bf_public_key   = get_option( 'bankful_options_public_key' );
        $bf_hash_salt    = get_option( 'bankful_options_hash_salt' );
        $bf_site_id      = get_option( 'bankful_options_site_id' );

        if ( empty( $bf_access_token ) ) {
            $fresh = $this->get_fresh_token();
            $bf_access_token = $fresh['token'] ?? '';
            $bf_public_key   = $fresh['public_key'] ?? $bf_public_key;
            $bf_hash_salt    = $fresh['salt'] ?? $bf_hash_salt;
        }

        $payload = [
            'bf_api_key'        => $this->api_key,
            'pmt_numb'          => $card_num,
            'pmt_key'           => $cvc,
            'pmt_expiry'        => $expiry_formatted,
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
            // Note: pmt_expiry should NOT be encrypted in V2 protocol
        }

        $headers = [ 'Content-Type' => 'application/json', 'Accept' => 'application/json' ];
        if ( ! empty( $bf_hash_salt ) ) $headers['X-Bankful-Hmac-SHA256'] = $this->generate_signature( $payload, $bf_hash_salt );
        if ( ! empty( $bf_access_token ) ) $headers['Authorization'] = 'Bearer ' . $bf_access_token;

        $response = wp_remote_post( $api_url, [
            'method'    => 'POST',
            'headers'   => $headers,
            'body'      => wp_json_encode( $payload ),
            'timeout'   => 45,
            'sslverify' => true,
        ]);

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( isset($data['errorMessage']) && stripos($data['errorMessage'], 'token') !== false ) {
            $fresh = $this->get_fresh_token();
            if ( ! empty($fresh['token']) ) {
                $headers['Authorization'] = 'Bearer ' . $fresh['token'];
                if ( ! empty($fresh['salt']) ) $headers['X-Bankful-Hmac-SHA256'] = $this->generate_signature( $payload, $fresh['salt'] );
                $response = wp_remote_post( $api_url, [
                    'method'  => 'POST',
                    'headers' => $headers,
                    'body'    => wp_json_encode( $payload ),
                    'timeout' => 45,
                ]);
                $body = wp_remote_retrieve_body( $response );
                $data = json_decode( $body, true );
            }
        }

        $status = strtoupper( (string) ($data['TRANS_STATUS_NAME'] ?? $data['status'] ?? '') );
        if ( in_array($status, ['APPROVED','SUCCESS'], true) ) {
            $order->set_transaction_id( $data['TRANS_RECORD_ID'] ?? '' );
            $order->payment_complete();
            return [ 'result' => 'success', 'redirect' => $this->get_return_url($order) ];
        }

        wc_add_notice('Bankful Error: ' . ($data['ERROR_MESSAGE'] ?? $data['errorMessage'] ?? 'Transaction declined'), 'error');
        return [ 'result' => 'failure' ];
    }

    private function encrypt_payload( $payload, $publicKey ) {
        if ( ! function_exists('openssl_public_encrypt') ) return $payload;
        $key = "-----BEGIN PUBLIC KEY-----\n{$publicKey}\n-----END PUBLIC KEY-----";
        if ( openssl_public_encrypt( $payload, $encrypted, $key ) ) return base64_encode($encrypted);
        return $payload;
    }

    private function generate_signature( $data, $salt ) {
        if ( empty($salt) ) return '';
        $keys = array_keys($data);
        sort($keys);
        $str = '';
        foreach ($keys as $k) $str .= $k . $data[$k];
        return hash_hmac('sha256', $str, $salt);
    }

    private function get_fresh_token() {
        $api_url = $this->test_mode
            ? 'https://api-dev1.bankfulportal.com/api/woocommerce-v2/validate-credentials'
            : 'https://api.paybybankful.com/api/woocommerce-v2/validate-credentials';
        $response = wp_remote_post( $api_url, [
            'body' => [ 'bf_api_key' => $this->api_key, 'bf_api_secret' => $this->secret_key, 'site_url' => get_site_url() ]
        ]);
        $body = json_decode( wp_remote_retrieve_body($response), true );
        if ( isset($body['status']) && $body['status'] === 'success' ) {
            update_option('bankful_options_api_access_token', $body['data']['bf_api_access_token']);
            update_option('bankful_options_public_key', $body['data']['merchant_public_key']);
            update_option('bankful_options_hash_salt', $body['data']['bf_salt']);
            return [ 'token' => $body['data']['bf_api_access_token'], 'public_key' => $body['data']['merchant_public_key'], 'salt' => $body['data']['bf_salt'] ];
        }
        return [];
    }
    public function validate_fields() { return true; }
}