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
            'permission_callback' => '__return_true',
        ] );

        // Returns states/provinces for a given country code.
        // GET /wp-json/cco/v1/states?country=AU
        register_rest_route( 'cco/v1', '/states', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ __CLASS__, 'get_states' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'country' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ] );

        register_rest_route( 'cco/v1', '/apply-coupon', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'apply_coupon' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( 'cco/v1', '/remove-coupon', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'remove_coupon' ],
            'permission_callback' => '__return_true',
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
    // GET /cco/v1/states?country=AU
    // ------------------------------------------------------------------

    public static function get_states( WP_REST_Request $request ): WP_REST_Response {
        $country = strtoupper( $request->get_param( 'country' ) );
        $all_states = WC()->countries->get_states( $country );

        if ( empty( $all_states ) || ! is_array( $all_states ) ) {
            // Country has no states (e.g. Singapore) — return empty array.
            return new WP_REST_Response( [], 200 );
        }

        $states = [];
        foreach ( $all_states as $code => $name ) {
            $states[] = [
                'code' => $code,
                'name' => html_entity_decode( $name ),
            ];
        }

        return new WP_REST_Response( $states, 200 );
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

            // ── Tax Fix ──────────────────────────────────────────────────────
            // In a REST API context WC_Customer starts empty, so WC calculates
            // zero tax.  We restore the customer location from the session data
            // (written by the WC Store API update-customer endpoint when the
            // shopper fills in their address), and fall back to the store's
            // base address so taxes are always estimated — exactly as WooCommerce
            // behaves on the cart/product pages before an address is entered.
            if ( ! is_null( WC()->customer ) && ! is_null( WC()->session ) ) {
                $session_customer = (array) WC()->session->get( 'customer', [] );

                $base_country  = WC()->countries->get_base_country();
                $base_state    = WC()->countries->get_base_state();
                $base_postcode = WC()->countries->get_base_postcode();
                $base_city     = WC()->countries->get_base_city();

                // Billing location — prefer session, fall back to store base.
                WC()->customer->set_billing_country(
                    ! empty( $session_customer['country'] ) ? $session_customer['country'] : $base_country
                );
                WC()->customer->set_billing_state(
                    ! empty( $session_customer['state'] ) ? $session_customer['state'] : $base_state
                );
                WC()->customer->set_billing_postcode(
                    ! empty( $session_customer['postcode'] ) ? $session_customer['postcode'] : $base_postcode
                );
                WC()->customer->set_billing_city(
                    ! empty( $session_customer['city'] ) ? $session_customer['city'] : $base_city
                );

                // Shipping location — prefer shipping keys, then billing, then base.
                WC()->customer->set_shipping_country(
                    ! empty( $session_customer['shipping_country'] ) ? $session_customer['shipping_country']
                    : ( ! empty( $session_customer['country'] )         ? $session_customer['country']          : $base_country )
                );
                WC()->customer->set_shipping_state(
                    ! empty( $session_customer['shipping_state'] ) ? $session_customer['shipping_state']
                    : ( ! empty( $session_customer['state'] )         ? $session_customer['state']           : $base_state )
                );
                WC()->customer->set_shipping_postcode(
                    ! empty( $session_customer['shipping_postcode'] ) ? $session_customer['shipping_postcode']
                    : ( ! empty( $session_customer['postcode'] )         ? $session_customer['postcode']          : $base_postcode )
                );
                WC()->customer->set_shipping_city(
                    ! empty( $session_customer['shipping_city'] ) ? $session_customer['shipping_city']
                    : ( ! empty( $session_customer['city'] )         ? $session_customer['city']             : $base_city )
                );

                WC()->customer->save();
            }
            // ─────────────────────────────────────────────────────────────────

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
            $packages = $cart->get_shipping_packages();
            WC()->shipping()->calculate_shipping( $packages );

            $shipping_packages = WC()->shipping()->get_packages();
            foreach ( $shipping_packages as $i => $package ) {
                if ( isset( $package['rates'] ) ) {
                    foreach ( $package['rates'] as $rate_id => $rate ) {
                        $shipping_rates[] = [
                            'id'       => $rate_id,
                            'label'    => $rate->get_label(),
                            'cost'     => (float) $rate->get_cost(),
                            'selected' => ( $rate_id === current( $cart->get_shipping_methods() ) ),
                        ];
                    }
                }
            }
        }

        // Build applied coupon data (code + discount amount).
        $coupon_data = [];
        foreach ( $cart->get_applied_coupons() as $coupon_code ) {
            $coupon        = new WC_Coupon( $coupon_code );
            $coupon_data[] = [
                'code'   => $coupon_code,
                'label'  => strtoupper( $coupon_code ),
                'amount' => (float) $cart->get_coupon_discount_amount( $coupon_code, $cart->display_prices_including_tax() ),
            ];
        }

        // Build individual tax lines from WooCommerce's own tax totals.
        // get_tax_totals() returns one entry per distinct tax rate configured in
        // WooCommerce (e.g. "GST 10%", "PST 5%", "Federal 2%"), each with its
        // admin label and calculated amount. This is the same data WC uses on
        // the cart page to display itemised taxes.
        $session_customer = (array) WC()->session->get( 'customer', [] );
        $has_address      = ! empty( $session_customer['country'] );
        $prefix           = $has_address ? '' : 'Estimated ';

        $tax_lines = [];
        foreach ( $cart->get_tax_totals() as $key => $tax ) {
            $tax_lines[] = [
                'label'       => $prefix . $tax->label,
                'amount'      => (float) $tax->amount,
                'is_compound' => ! empty( $tax->is_compound ),
            ];
        }

        return new WP_REST_Response( [
            'items'              => $items,
            'subtotal'           => (float) $cart->get_subtotal(),
            'discount_total'     => (float) $cart->get_discount_total(),
            'shipping_total'     => (float) $cart->get_shipping_total(),
            'tax_total'          => (float) $cart->get_taxes_total(),
            'tax_lines'          => $tax_lines,
            'tax_enabled'        => wc_tax_enabled(),
            'total'              => (float) $cart->get_total( 'edit' ),
            'currency_symbol'    => get_woocommerce_currency_symbol(),
            'needs_shipping'     => $cart->needs_shipping(),
            'shipping_rates'     => $shipping_rates,
            'coupons'            => $cart->get_applied_coupons(),
            'coupon_data'        => $coupon_data,
            'item_count'         => $cart->get_cart_contents_count(),
            'prices_include_tax' => wc_prices_include_tax(),
        ], 200 );
    }


    // ------------------------------------------------------------------
    // POST /cco/v1/apply-coupon
    // ------------------------------------------------------------------

    public static function apply_coupon( WP_REST_Request $request ): WP_REST_Response {
        // Ensure cart is available.
        if ( is_null( WC()->cart ) ) {
            if ( ! function_exists( 'wc_load_cart' ) ) {
                include_once WC_ABSPATH . 'includes/wc-cart-functions.php';
            }
            wc_load_cart();
        }

        if ( ! is_null( WC()->session ) && ! WC()->session->has_session() ) {
            WC()->session->init_session_cookie();
        }

        $body = $request->get_json_params();
        $code = isset( $body['code'] ) ? sanitize_text_field( $body['code'] ) : '';

        if ( empty( $code ) ) {
            return new WP_REST_Response( [ 'message' => 'Coupon code is required.' ], 400 );
        }

        // Check if already applied.
        if ( WC()->cart->has_discount( $code ) ) {
            return new WP_REST_Response( [ 'message' => 'Coupon "' . strtoupper( $code ) . '" is already applied.' ], 400 );
        }

        // Suppress WC notices – capture them instead.
        $result = WC()->cart->apply_coupon( $code );

        if ( ! $result ) {
            $notices = wc_get_notices( 'error' );
            $msg     = ! empty( $notices ) ? wp_strip_all_tags( $notices[0]['notice'] ) : 'Invalid coupon code.';
            wc_clear_notices();
            return new WP_REST_Response( [ 'message' => $msg ], 400 );
        }

        wc_clear_notices();
        WC()->cart->calculate_totals();

        return new WP_REST_Response( [ 'success' => true, 'message' => 'Coupon applied!' ], 200 );
    }

    // ------------------------------------------------------------------
    // POST /cco/v1/remove-coupon
    // ------------------------------------------------------------------

    public static function remove_coupon( WP_REST_Request $request ): WP_REST_Response {
        if ( is_null( WC()->cart ) ) {
            if ( ! function_exists( 'wc_load_cart' ) ) {
                include_once WC_ABSPATH . 'includes/wc-cart-functions.php';
            }
            wc_load_cart();
        }

        if ( ! is_null( WC()->session ) && ! WC()->session->has_session() ) {
            WC()->session->init_session_cookie();
        }

        $body = $request->get_json_params();
        $code = isset( $body['code'] ) ? sanitize_text_field( $body['code'] ) : '';

        if ( empty( $code ) ) {
            return new WP_REST_Response( [ 'message' => 'Coupon code is required.' ], 400 );
        }

        WC()->cart->remove_coupon( $code );
        wc_clear_notices();
        WC()->cart->calculate_totals();

        return new WP_REST_Response( [ 'success' => true, 'message' => 'Coupon removed.' ], 200 );
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
