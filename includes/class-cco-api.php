<?php
/**
 * CCO_API
 *
 * Registers custom REST endpoints under /wp-json/cco/v1/
 * These are separate from the WC Store API and handle plugin-specific operations.
 *
 * Endpoints:
 *   GET  /wp-json/cco/v1/cart-summary     — fetch cart data via Store API (server-side)
 *   POST /wp-json/cco/v1/place-order      — create order via WC Store API checkout
 */

defined( 'ABSPATH' ) || exit;

class CCO_API {

    public static function init() {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
    }

    public static function register_routes() {

        register_rest_route( 'cco/v1', '/cart-summary', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ __CLASS__, 'get_cart_summary' ],
            'permission_callback' => '__return_true', // public, cookie-based session
        ] );

        register_rest_route( 'cco/v1', '/place-order', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'place_order' ],
            'permission_callback' => [ __CLASS__, 'verify_nonce' ],
            'args'                => self::place_order_args(),
        ] );
    }

    // ------------------------------------------------------------------
    // Permission callback
    // ------------------------------------------------------------------

    public static function verify_nonce( WP_REST_Request $request ): bool {
        $nonce = $request->get_header( 'X-CCO-Nonce' )
               ?: $request->get_param( '_cco_nonce' );
        return (bool) wp_verify_nonce( $nonce, 'cco_ajax' );
    }

    // ------------------------------------------------------------------
    // GET /cco/v1/cart-summary
    // ------------------------------------------------------------------

    public static function get_cart_summary( WP_REST_Request $request ): WP_REST_Response {
        // Ensure WooCommerce cart is initialized.
        if ( is_null( WC()->cart ) ) {
            if ( ! function_exists( 'wc_load_cart' ) ) {
                include_once WC_ABSPATH . 'includes/wc-cart-functions.php';
            }
            wc_load_cart();
        }

        // Ensure session is loaded from cookie.
        if ( ! is_null( WC()->session ) && ! WC()->session->has_session() ) {
            WC()->session->init_session_cookie();
        }

        if ( ! is_null( WC()->cart ) ) {
            if ( 0 === WC()->cart->get_cart_contents_count() ) {
                WC()->cart->get_cart_from_session();
            }
            WC()->cart->calculate_totals();
        }

        $cart = WC()->cart;

        if ( is_null( $cart ) ) {
            return new WP_REST_Response( [ 'message' => 'Could not initialize WooCommerce cart.' ], 500 );
        }

        $items = [];
        foreach ( $cart->get_cart() as $item_key => $item ) {
            $product  = $item['data'];
            $items[]  = [
                'key'        => $item_key,
                'product_id' => $item['product_id'],
                'name'       => $product->get_name(),
                'quantity'   => $item['quantity'],
                'price'      => (float) $product->get_price(),
                'line_total' => (float) $item['line_total'],
                'image'      => wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ) ?: wc_placeholder_img_src(),
            ];
        }

        // Shipping rates.
        $shipping_rates = [];
        if ( $cart->needs_shipping() ) {
            // Ensure shipping is calculated.
            $packages = $cart->get_shipping_packages();
            WC()->shipping()->calculate_shipping( $packages );
            
            $shipping_packages = WC()->shipping()->get_packages();
            foreach ( $shipping_packages as $i => $package ) {
                if ( isset( $package['rates'] ) ) {
                    foreach ( $package['rates'] as $rate_id => $rate ) {
                        $shipping_rates[] = [
                            'id'      => $rate_id,
                            'label'   => $rate->get_label(),
                            'cost'    => (float) $rate->get_cost(),
                            'selected'=> ( $rate_id === current( $cart->get_shipping_methods() ) ),
                        ];
                    }
                }
            }
        }

        return new WP_REST_Response( [
            'items'              => $items,
            'subtotal'           => (float) $cart->get_subtotal(),
            'discount_total'     => (float) $cart->get_discount_total(),
            'shipping_total'     => (float) $cart->get_shipping_total(),
            'tax_total'          => (float) $cart->get_taxes_total(),
            'total'              => (float) $cart->get_total( 'edit' ),
            'currency_symbol'    => get_woocommerce_currency_symbol(),
            'needs_shipping'     => $cart->needs_shipping(),
            'shipping_rates'     => $shipping_rates,
            'coupons'            => $cart->get_applied_coupons(),
            'item_count'         => $cart->get_cart_contents_count(),
        ], 200 );
    }

    // ------------------------------------------------------------------
    // POST /cco/v1/place-order
    // Proxies to WC Store API POST /wc/store/v1/checkout
    // ------------------------------------------------------------------

    public static function place_order( WP_REST_Request $request ): WP_REST_Response {
        $body = $request->get_json_params();

        if ( empty( $body ) ) {
            return new WP_REST_Response( [ 'message' => 'Empty request body.' ], 400 );
        }

        // Build payload for WC Store API.
        $payload = [
            'payment_method'  => 'cco_custom',
            'billing_address' => self::sanitize_address( $body['billing'] ?? [] ),
            'shipping_address'=> self::sanitize_address( $body['shipping'] ?? $body['billing'] ?? [] ),
            'customer_note'   => sanitize_textarea_field( $body['order_note'] ?? '' ),
            // Pass any extra payment data your gateway needs.
            'payment_data'    => $body['payment_data'] ?? [],
        ];

        // Internal WC Store API request.
        $store_request = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
        $store_request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
        $store_request->set_body( wp_json_encode( $payload ) );
        $store_request->set_header( 'Content-Type', 'application/json' );

        $response = rest_do_request( $store_request );
        $data     = rest_get_server()->response_to_data( $response, false );

        if ( $response->is_error() ) {
            return new WP_REST_Response( [
                'message' => $data['message'] ?? 'Order failed.',
                'data'    => $data,
            ], $response->get_status() );
        }

        return new WP_REST_Response( [
            'order_id'    => $data['order_id'] ?? null,
            'order_key'   => $data['order_key'] ?? null,
            'redirect_url'=> $data['payment_result']['redirect_url'] ?? wc_get_endpoint_url( 'order-received', $data['order_id'] ?? '', wc_get_page_permalink( 'checkout' ) ),
            'status'      => $data['status'] ?? 'pending',
        ], 200 );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private static function sanitize_address( array $addr ): array {
        return [
            'first_name' => sanitize_text_field( $addr['first_name'] ?? '' ),
            'last_name'  => sanitize_text_field( $addr['last_name']  ?? '' ),
            'address_1'  => sanitize_text_field( $addr['address_1']  ?? '' ),
            'address_2'  => sanitize_text_field( $addr['address_2']  ?? '' ),
            'city'       => sanitize_text_field( $addr['city']       ?? '' ),
            'state'      => sanitize_text_field( $addr['state']      ?? '' ),
            'postcode'   => sanitize_text_field( $addr['postcode']   ?? '' ),
            'country'    => sanitize_text_field( $addr['country']    ?? '' ),
            'email'      => sanitize_email(      $addr['email']      ?? '' ),
            'phone'      => sanitize_text_field( $addr['phone']      ?? '' ),
        ];
    }

    private static function place_order_args(): array {
        return [
            'billing'      => [ 'required' => true,  'type' => 'object' ],
            'shipping'     => [ 'required' => false, 'type' => 'object' ],
            'order_note'   => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ],
            'payment_data' => [ 'required' => false, 'type' => 'object' ],
        ];
    }
}
