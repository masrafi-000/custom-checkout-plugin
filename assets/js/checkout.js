/**
 * Custom Checkout – Frontend JS
 * Communicates with WC Store API and our own /cco/v1/ endpoints.
 */

( function ( $ ) {
    'use strict';

    const { storeApiBase, apiBase, wpNonce, nonce, ajaxNonce, i18n, currency } = window.CCO || {};

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    const headers = () => ( {
        'Content-Type': 'application/json',
        'Nonce':        nonce,          // WC Store API nonce header
    } );

    const fmt = ( amount ) =>
        currency + parseFloat( amount ).toFixed( 2 );

    function showNotice( msg, type = 'error' ) {
        const $el = $( '#cco-notices' );
        $el.html(
            `<div class="cco-notice cco-notice--${type}">${msg}</div>`
        ).show();
        $( 'html, body' ).animate( { scrollTop: $el.offset().top - 20 }, 400 );
    }

    function clearNotice() {
        $( '#cco-notices' ).hide().html( '' );
    }

    function setLoading( loading ) {
        const $btn = $( '#cco-place-order' );
        $btn.prop( 'disabled', loading );
        $btn.find( '.cco-btn-text' ).toggle( ! loading );
        $btn.find( '.cco-btn-spinner' ).toggle( loading );
    }

    // ---------------------------------------------------------------
    // 1. Load cart summary from our server-side endpoint
    // ---------------------------------------------------------------

    async function loadCart() {
        try {
            const res  = await fetch( `${apiBase}/cart-summary`, {
                credentials: 'same-origin',
                headers: {
                    'X-WP-Nonce': wpNonce
                }
            } );
            const data = await res.json();

            if ( ! res.ok ) {
                showNotice( data.message || 'Could not load cart.' );
                return;
            }

            renderCartItems( data.items );
            renderCartTotals( data );

            // Enable Place Order only when cart has items.
            if ( data.item_count > 0 ) {
                $( '#cco-place-order' ).prop( 'disabled', false );
            }

        } catch ( err ) {
            showNotice( 'Network error loading cart.' );
            console.error( err );
        }
    }

    function renderCartItems( items ) {
        if ( ! items || ! items.length ) {
            $( '#cco-cart-items' ).html(
                '<p>' + ( window.CCO?.i18n?.empty_cart || 'Your cart is empty.' ) + '</p>'
            );
            return;
        }

        const rows = items.map( item => `
            <div class="cco-cart-item" data-key="${item.key}">
                <img src="${item.image}" alt="${item.name}" class="cco-cart-item__img">
                <div class="cco-cart-item__info">
                    <span class="cco-cart-item__name">${item.name}</span>
                    <div class="cco-cart-item__controls">
                        <input type="number" class="cco-item-qty" value="${item.quantity}" min="1" step="1">
                        <button type="button" class="cco-item-remove" title="Remove">✕</button>
                    </div>
                </div>
                <span class="cco-cart-item__total">${fmt( item.line_total )}</span>
            </div>
        ` ).join( '' );

        $( '#cco-cart-items' ).html( rows );
    }

    function renderCartTotals( data ) {
        let html = `
            <div class="cco-totals-row">
                <span>${ 'Subtotal' }</span>
                <span>${fmt( data.subtotal )}</span>
            </div>`;

        if ( data.discount_total > 0 ) {
            html += `
            <div class="cco-totals-row cco-totals-row--discount">
                <span>Discount</span>
                <span>−${fmt( data.discount_total )}</span>
            </div>`;
            
            if ( data.coupons && data.coupons.length ) {
                html += `<div class="cco-applied-coupons">`;
                data.coupons.forEach( code => {
                    html += `<span class="cco-coupon-tag">${code} <button type="button" class="cco-remove-coupon" data-code="${code}">✕</button></span>`;
                } );
                html += `</div>`;
            }
        }

        if ( data.needs_shipping ) {
            html += `<div class="cco-shipping-block">
                <h4>Shipping</h4>
                <div class="cco-shipping-rates">`;
            
            if ( data.shipping_rates.length ) {
                data.shipping_rates.forEach( rate => {
                    html += `
                        <label class="cco-shipping-option">
                            <input type="radio" name="cco_shipping_method" value="${rate.id}" ${rate.selected ? 'checked' : ''}>
                            <span>${rate.label}: ${fmt( rate.cost )}</span>
                        </label>`;
                } );
            } else {
                html += `<p class="cco-small">Enter your address to see shipping rates.</p>`;
            }
            
            html += `</div></div>`;
        }

        if ( data.tax_total > 0 ) {
            html += `
            <div class="cco-totals-row">
                <span>Tax</span>
                <span>${fmt( data.tax_total )}</span>
            </div>`;
        }

        html += `
            <div class="cco-totals-row cco-totals-row--total">
                <span>Total</span>
                <span>${fmt( data.total )}</span>
            </div>`;

        $( '#cco-cart-totals' ).html( html );
    }

    // ---------------------------------------------------------------
    // 2. Apply coupon via WC Store API
    // ---------------------------------------------------------------

    $( '#cco-apply-coupon' ).on( 'click', async function () {
        const code = $( '#cco-coupon-input' ).val().trim();
        if ( ! code ) return;

        clearNotice();

        try {
            const res  = await fetch( `${storeApiBase}/cart/apply-coupon`, {
                method:      'POST',
                credentials: 'same-origin',
                headers:     headers(),
                body:        JSON.stringify( { code } ),
            } );
            const data = await res.json();

            if ( ! res.ok ) {
                showNotice( data.message || 'Invalid coupon.' );
                return;
            }

            showNotice( 'Coupon applied!', 'success' );
            await loadCart(); // Refresh totals.

        } catch ( err ) {
            showNotice( 'Error applying coupon.' );
        }
    } );

    // Handle coupon removal
    $( document ).on( 'click', '.cco-remove-coupon', async function() {
        const code = $( this ).data( 'code' );
        try {
            await fetch( `${storeApiBase}/cart/remove-coupon`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: headers(),
                body: JSON.stringify( { code } )
            } );
            await loadCart();
        } catch ( e ) {
            console.error('Failed to remove coupon', e);
        }
    } );

    // ---------------------------------------------------------------
    // 3. Collect form data
    // ---------------------------------------------------------------

    function collectAddress( prefix ) {
        const get = ( name ) =>
            $( `#cco-${prefix}${name}` ).length
                ? $( `#cco-${prefix}${name}` ).val()
                : $( `[name="${name}"]` ).val() || '';

        return {
            first_name: $( '#cco-first-name' ).val(),
            last_name:  $( '#cco-last-name'  ).val(),
            address_1:  $( '#cco-address1'   ).val(),
            address_2:  $( '#cco-address2'   ).val(),
            city:       $( '#cco-city'        ).val(),
            state:      $( '#cco-state'       ).val(),
            postcode:   $( '#cco-postcode'    ).val(),
            country:    $( '#cco-country'     ).val(),
            email:      $( '#cco-email'       ).val(),
            phone:      $( '#cco-phone'       ).val(),
        };
    }

    function collectShippingAddress() {
        if ( ! $( '#cco-ship-to-different' ).is( ':checked' ) ) {
            return collectAddress( '' );
        }
        return {
            first_name: $( '#cco-ship-first-name' ).val(),
            last_name:  $( '#cco-ship-last-name'  ).val(),
            address_1:  $( '#cco-ship-address1'   ).val(),
            address_2:  $( '#cco-ship-address2'   ).val(),
            city:       $( '#cco-ship-city'        ).val(),
            state:      $( '#cco-ship-state'       ).val(),
            postcode:   $( '#cco-ship-postcode'    ).val(),
            country:    $( '#cco-ship-country'     ).val(),
            email:      $( '#cco-email'            ).val(),
            phone:      $( '#cco-phone'            ).val(),
        };
    }

    function validateForm() {
        const required = [
            '#cco-email', '#cco-phone', '#cco-first-name',
            '#cco-last-name', '#cco-address1', '#cco-city', '#cco-country',
        ];
        for ( const sel of required ) {
            if ( ! $( sel ).val().trim() ) {
                $( sel ).addClass( 'cco-field--error' ).focus();
                return false;
            }
        }
        return true;
    }

    $( '.cco-form-section input, .cco-form-section select' ).on( 'input change', function () {
        $( this ).removeClass( 'cco-field--error' );
    } );

    // Sync address with Store API on blur to recalculate shipping/taxes
    $( '.cco-form-section input, .cco-form-section select' ).on( 'blur change', async function() {
        if ( $(this).closest('.cco-shipping-address').length && !$( '#cco-ship-to-different' ).is( ':checked' ) ) return;
        
        const payload = {
            billing_address: collectAddress(''),
            shipping_address: collectShippingAddress()
        };

        try {
            await fetch( `${storeApiBase}/cart/update-customer`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: headers(),
                body: JSON.stringify( payload )
            } );
            await loadCart();
        } catch ( e ) {
            console.error('Failed to sync customer data', e);
        }
    } );

    // Handle shipping rate selection
    $( document ).on( 'change', 'input[name="cco_shipping_method"]', async function() {
        const rate_id = $( this ).val();
        try {
            await fetch( `${storeApiBase}/cart/select-shipping-rate`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: headers(),
                body: JSON.stringify( { id: rate_id } )
            } );
            await loadCart();
        } catch ( e ) {
            console.error('Failed to select shipping rate', e);
        }
    } );

    // Handle cart item removal
    $( document ).on( 'click', '.cco-item-remove', async function() {
        const key = $( this ).closest( '.cco-cart-item' ).data( 'key' );
        try {
            await fetch( `${storeApiBase}/cart/items/${key}`, {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: headers()
            } );
            await loadCart();
        } catch ( e ) {
            console.error('Failed to remove item', e);
        }
    } );

    // Handle quantity changes
    $( document ).on( 'change', '.cco-item-qty', async function() {
        const key = $( this ).closest( '.cco-cart-item' ).data( 'key' );
        const quantity = $( this ).val();
        try {
            await fetch( `${storeApiBase}/cart/items/${key}`, {
                method: 'PUT',
                credentials: 'same-origin',
                headers: headers(),
                body: JSON.stringify( { quantity: parseInt(quantity) } )
            } );
            await loadCart();
        } catch ( e ) {
            console.error('Failed to update quantity', e);
        }
    } );

    // ---------------------------------------------------------------
    // 4. Place order
    // ---------------------------------------------------------------

    $( '#cco-place-order' ).on( 'click', async function () {
        clearNotice();

        if ( ! validateForm() ) {
            showNotice( i18n.fill_required );
            return;
        }

        setLoading( true );

        const payload = {
            billing:      collectAddress( '' ),
            shipping:     collectShippingAddress(),
            order_note:   $( '#cco-order-note' ).val(),
            payment_data: {}, // Attach extra payment fields here (token, etc.)
        };

        try {
            const res  = await fetch( `${apiBase}/place-order`, {
                method:      'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CCO-Nonce':  ajaxNonce,
                },
                body: JSON.stringify( payload ),
            } );

            const data = await res.json();

            if ( ! res.ok ) {
                showNotice( data.message || i18n.order_failed );
                setLoading( false );
                return;
            }

            // Redirect to WC thank-you / payment page.
            window.location.href = data.redirect_url;

        } catch ( err ) {
            showNotice( i18n.order_failed );
            setLoading( false );
            console.error( err );
        }
    } );

    // ---------------------------------------------------------------
    // 5. "Ship to different address" toggle
    // ---------------------------------------------------------------

    $( '#cco-ship-to-different' ).on( 'change', function () {
        const $shipping = $( '#cco-shipping-fields' );
        if ( $( this ).is( ':checked' ) ) {
            if ( ! $shipping.children().length ) {
                // Build shipping fields once.
                const html = `
                <h3>Shipping Address</h3>
                <div class="cco-row">
                    <div class="cco-field"><label>First Name *</label><input type="text" id="cco-ship-first-name" required></div>
                    <div class="cco-field"><label>Last Name *</label><input type="text" id="cco-ship-last-name" required></div>
                </div>
                <div class="cco-field"><label>Address *</label><input type="text" id="cco-ship-address1" required></div>
                <div class="cco-field"><label>Apt / Suite</label><input type="text" id="cco-ship-address2"></div>
                <div class="cco-row">
                    <div class="cco-field"><label>City *</label><input type="text" id="cco-ship-city" required></div>
                    <div class="cco-field"><label>Postcode</label><input type="text" id="cco-ship-postcode"></div>
                </div>
                <div class="cco-row">
                    <div class="cco-field"><label>Country *</label><input type="text" id="cco-ship-country" required value="BD"></div>
                    <div class="cco-field"><label>State</label><input type="text" id="cco-ship-state"></div>
                </div>`;
                $shipping.html( html );
            }
            $shipping.slideDown();
        } else {
            $shipping.slideUp();
        }
    } );

    // ---------------------------------------------------------------
    // Init
    // ---------------------------------------------------------------

    $( function () {
        loadCart();
    } );

} )( jQuery );
