<?php
/**
 * Template Name: Custom Checkout Page
 *
 * This is a standalone page template that does NOT extend any theme.
 * It only uses wp_head() / wp_footer() so theme styles still load.
 *
 * Override by placing this file at:
 *   {your-theme}/custom-checkout/checkout.php
 */

defined( 'ABSPATH' ) || exit;

// If cart is empty, redirect to shop.
if ( WC()->cart->is_empty() ) {
    wp_redirect( wc_get_page_permalink( 'shop' ) );
    exit;
}
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php wp_title( '|', true, 'right' ); ?></title>
    <?php wp_head(); ?>
</head>
<body class="cco-checkout-body">

<div id="cco-checkout-wrap">

    <!-- HEADER -->
    <header class="cco-header">
        <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="cco-logo">
            <?php bloginfo( 'name' ); ?>
        </a>
        <span class="cco-secure-badge">🔒 <?php esc_html_e( 'Secure Checkout', 'custom-checkout' ); ?></span>
    </header>

    <!-- NOTICES -->
    <div id="cco-notices" role="alert" aria-live="polite"></div>

    <!-- MAIN LAYOUT -->
    <main class="cco-main">

        <!-- LEFT: FORM -->
        <section class="cco-form-section">

            <!-- CONTACT -->
            <div class="cco-block">
                <h2><?php esc_html_e( 'Contact Information', 'custom-checkout' ); ?></h2>
                <div class="cco-row">
                    <div class="cco-field">
                        <label for="cco-email"><?php esc_html_e( 'Email *', 'custom-checkout' ); ?></label>
                        <input type="email" id="cco-email" name="email" required autocomplete="email" placeholder="you@example.com">
                    </div>
                    <div class="cco-field">
                        <label for="cco-phone"><?php esc_html_e( 'Phone *', 'custom-checkout' ); ?></label>
                        <input type="tel" id="cco-phone" name="phone" required autocomplete="tel" placeholder="+880 1X XX XXXXXX">
                    </div>
                </div>
            </div>

            <!-- BILLING -->
            <div class="cco-block">
                <h2><?php esc_html_e( 'Billing Address', 'custom-checkout' ); ?></h2>
                <div class="cco-row">
                    <div class="cco-field">
                        <label for="cco-first-name"><?php esc_html_e( 'First Name *', 'custom-checkout' ); ?></label>
                        <input type="text" id="cco-first-name" name="first_name" required autocomplete="given-name">
                    </div>
                    <div class="cco-field">
                        <label for="cco-last-name"><?php esc_html_e( 'Last Name *', 'custom-checkout' ); ?></label>
                        <input type="text" id="cco-last-name" name="last_name" required autocomplete="family-name">
                    </div>
                </div>
                <div class="cco-field">
                    <label for="cco-address1"><?php esc_html_e( 'Street Address *', 'custom-checkout' ); ?></label>
                    <input type="text" id="cco-address1" name="address_1" required autocomplete="address-line1" placeholder="<?php esc_attr_e( 'House no. and street name', 'custom-checkout' ); ?>">
                </div>
                <div class="cco-field">
                    <label for="cco-address2"><?php esc_html_e( 'Apartment, suite, etc.', 'custom-checkout' ); ?></label>
                    <input type="text" id="cco-address2" name="address_2" autocomplete="address-line2">
                </div>
                <div class="cco-row">
                    <div class="cco-field">
                        <label for="cco-city"><?php esc_html_e( 'City *', 'custom-checkout' ); ?></label>
                        <input type="text" id="cco-city" name="city" required autocomplete="address-level2">
                    </div>
                    <div class="cco-field">
                        <label for="cco-postcode"><?php esc_html_e( 'Postcode', 'custom-checkout' ); ?></label>
                        <input type="text" id="cco-postcode" name="postcode" autocomplete="postal-code">
                    </div>
                </div>
                <div class="cco-row">
                    <div class="cco-field">
                        <label for="cco-country"><?php esc_html_e( 'Country *', 'custom-checkout' ); ?></label>
                        <select id="cco-country" name="country" required autocomplete="country">
                            <?php
                            $countries = WC()->countries->get_allowed_countries();
                            $default   = WC()->countries->get_base_country();
                            foreach ( $countries as $code => $name ) {
                                printf(
                                    '<option value="%s"%s>%s</option>',
                                    esc_attr( $code ),
                                    selected( $code, $default, false ),
                                    esc_html( $name )
                                );
                            }
                            ?>
                        </select>
                    </div>
                    <div class="cco-field">
                        <label for="cco-state"><?php esc_html_e( 'State / Division', 'custom-checkout' ); ?></label>
                        <input type="text" id="cco-state" name="state" autocomplete="address-level1">
                    </div>
                </div>
            </div>

            <!-- SHIPPING SAME AS BILLING -->
            <div class="cco-block">
                <label class="cco-checkbox-label">
                    <input type="checkbox" id="cco-ship-to-different" name="ship_to_different">
                    <?php esc_html_e( 'Ship to a different address', 'custom-checkout' ); ?>
                </label>
                <div id="cco-shipping-fields" style="display:none;" class="cco-shipping-address">
                    <!-- Shipping address fields are cloned by JS -->
                </div>
            </div>

            <!-- ORDER NOTES -->
            <div class="cco-block">
                <h2><?php esc_html_e( 'Order Notes', 'custom-checkout' ); ?></h2>
                <div class="cco-field">
                    <label for="cco-order-note"><?php esc_html_e( 'Notes about your order (optional)', 'custom-checkout' ); ?></label>
                    <textarea id="cco-order-note" name="order_note" rows="3" placeholder="<?php esc_attr_e( 'Special instructions for delivery…', 'custom-checkout' ); ?>"></textarea>
                </div>
            </div>

            <!-- PAYMENT -->
            <div class="cco-block" id="cco-payment-block">
                <h2><?php esc_html_e( 'Payment', 'custom-checkout' ); ?></h2>
                <div class="cco-payment-methods" id="cco-payment-methods">
                    <!-- Rendered by JS from CCO.paymentMethods or hardcoded below -->
                    <label class="cco-payment-option selected">
                        <input type="radio" name="payment_method" value="cco_custom" checked>
                        <span class="cco-payment-label"><?php esc_html_e( 'Custom Payment', 'custom-checkout' ); ?></span>
                    </label>
                </div>

                <!-- Extra payment fields (card number etc.) — render from JS -->
                <div id="cco-payment-fields"></div>
            </div>

            <!-- PLACE ORDER -->
            <button type="button" id="cco-place-order" class="cco-btn-primary" disabled>
                <span class="cco-btn-text"><?php esc_html_e( 'Place Order', 'custom-checkout' ); ?></span>
                <span class="cco-btn-spinner" style="display:none;" aria-hidden="true">⏳</span>
            </button>

        </section>

        <!-- RIGHT: ORDER SUMMARY -->
        <aside class="cco-summary-section">
            <div class="cco-block">
                <h2><?php esc_html_e( 'Order summary', 'custom-checkout' ); ?></h2>
                <p class="cco-subtitle"><?php esc_html_e( 'Review your items and total before completing your purchase.', 'custom-checkout' ); ?></p>

                <div id="cco-cart-items" class="cco-cart-items">
                    <p class="cco-loading"><?php esc_html_e( 'Loading cart…', 'custom-checkout' ); ?></p>
                </div>

                <!-- Coupon -->
                <div class="cco-coupon-row">
                    <input type="text" id="cco-coupon-input" placeholder="<?php esc_attr_e( 'Discount code', 'custom-checkout' ); ?>">
                    <button type="button" id="cco-apply-coupon" class="cco-btn-secondary">
                        <?php esc_html_e( 'Apply', 'custom-checkout' ); ?>
                    </button>
                </div>

                <div id="cco-cart-totals" class="cco-cart-totals"></div>
            </div>
        </aside>

    </main>

</div><!-- #cco-checkout-wrap -->

<?php wp_footer(); ?>
</body>
</html>
