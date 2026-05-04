<?php
/**
 * CCO_Payment_Gateway
 *
 * A WooCommerce payment gateway registered under the gateway ID "cco_custom".
 * This gateway is available store-wide (including default checkout) but will
 * primarily be used by our custom checkout plugin.
 *
 * Replace the process_payment() method body with your real payment logic
 * (Stripe, SSLCommerz, bKash, Nagad, etc.)
 */

defined( 'ABSPATH' ) || exit;

class CCO_Payment_Gateway extends WC_Payment_Gateway {

    public function __construct() {
        $this->id                 = 'cco_custom';
        $this->method_title       = __( 'Custom Checkout Payment', 'custom-checkout' );
        $this->method_description = __( 'Custom payment gateway used by the Custom Checkout plugin.', 'custom-checkout' );
        $this->has_fields         = false;   // We render fields ourselves in the JS checkout.
        $this->supports           = [ 'products' ];

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option( 'title' );
        $this->description = $this->get_option( 'description' );
        $this->enabled     = $this->get_option( 'enabled' );

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
                'label'   => __( 'Enable Custom Checkout Payment', 'custom-checkout' ),
                'default' => 'yes',
            ],
            'title'       => [
                'title'   => __( 'Title', 'custom-checkout' ),
                'type'    => 'text',
                'default' => __( 'Custom Payment', 'custom-checkout' ),
            ],
            'description' => [
                'title'   => __( 'Description', 'custom-checkout' ),
                'type'    => 'textarea',
                'default' => __( 'Pay securely via our payment system.', 'custom-checkout' ),
            ],
            'api_key'     => [
                'title'       => __( 'API Key', 'custom-checkout' ),
                'type'        => 'password',
                'description' => __( 'Secret key for your payment provider.', 'custom-checkout' ),
                'default'     => '',
            ],
        ];
    }

    /**
     * Process payment.
     *
     * Called by WooCommerce (including via Store API checkout endpoint) after
     * order creation. Replace the stub below with your real payment provider logic.
     *
     * @param int $order_id
     * @return array
     */
    public function process_payment( $order_id ) {
        $order   = wc_get_order( $order_id );
        $api_key = $this->get_option( 'api_key' );

        // ---------------------------------------------------------------
        // TODO: Replace this block with real payment provider integration.
        // e.g. call bKash / Nagad / SSLCommerz / Stripe charge API here.
        //
        // On success:
        //   $order->payment_complete( $transaction_id );
        //   $order->add_order_note( 'Payment captured: ' . $transaction_id );
        //
        // On failure:
        //   wc_add_notice( 'Payment failed: ' . $error_message, 'error' );
        //   return [ 'result' => 'failure' ];
        // ---------------------------------------------------------------

        // --- STUB: mark as on-hold and redirect to thank-you page ---
        $order->update_status(
            'on-hold',
            __( 'Awaiting payment confirmation.', 'custom-checkout' )
        );

        WC()->cart->empty_cart();

        return [
            'result'   => 'success',
            'redirect' => $this->get_return_url( $order ),
        ];
    }

    /**
     * For Store API: validate payment fields (called before process_payment).
     * Override to add server-side validation of any payment data you collect.
     */
    public function validate_fields() {
        return true;
    }
}
