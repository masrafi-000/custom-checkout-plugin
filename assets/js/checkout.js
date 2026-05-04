/**
 * Custom Checkout – Frontend JS
 */

( function ( $ ) {
    'use strict';

    const { storeApiBase, apiBase, wpNonce, nonce, ajaxNonce, i18n, currency } = window.CCO || {};

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    const headers = () => ( {
        'Content-Type': 'application/json',
        'Nonce':        nonce,
    } );

    window.CCO.fmt = ( amount ) =>
        currency + parseFloat( amount ).toFixed( 2 );

    const fmt = window.CCO.fmt;

    function debounce( func, wait ) {
        let timeout;
        return function( ...args ) {
            clearTimeout( timeout );
            timeout = setTimeout( () => func.apply( this, args ), wait );
        };
    }

    async function apiCall( url, options = {} ) {
        const isStoreApi = url.includes( '/wc/store/v1/' );
        const fetchOptions = {
            credentials: 'same-origin',
            ...options,
            headers: {
                ...headers(),
                ...( ! isStoreApi ? { 'X-WP-Nonce': wpNonce, 'X-CCO-Nonce': ajaxNonce } : {} ),
                ...( options.headers || {} ),
            }
        };

        const res = await fetch( url, fetchOptions );
        const data = await res.json();

        if ( ! res.ok ) {
            throw new Error( data.message || 'API Error' );
        }

        return data;
    }

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
        if ( loading ) {
            $btn.find( '.cco-btn-text' ).text( 'Processing...' );
            $( '.cco-summary-card' ).addClass( 'cco-is-loading' );
        } else {
            $btn.find( '.cco-btn-text' ).text( 'Complete order' );
            $( '.cco-summary-card' ).removeClass( 'cco-is-loading' );
        }
    }

    // ---------------------------------------------------------------
    // 1. Cart Summary
    // ---------------------------------------------------------------

    async function loadCart() {
        try {
            const data = await apiCall( `${apiBase}/cart-summary` );
            renderCartItems( data.items );
            renderCartTotals( data );
            $( '#cco-place-order' ).prop( 'disabled', data.item_count === 0 );
        } catch ( err ) {
            console.error( 'Error loading cart:', err );
        } finally {
            $( '.cco-summary-card' ).removeClass( 'cco-is-loading' );
        }
    }

    function renderCartItems( items ) {
        if ( ! items || ! items.length ) {
            $( '#cco-cart-items' ).html( '<p>Your cart is empty.</p>' );
            return;
        }

        const html = items.map( item => `
            <div class="cco-cart-item">
                <div class="cco-cart-item__img-wrapper">
                    <img src="${item.image}" alt="${item.name}" class="cco-cart-item__img">
                    <span class="cco-cart-item__qty-badge">${item.quantity}</span>
                </div>
                <div class="cco-cart-item__info">
                    <span class="cco-cart-item__name">${item.name}</span>
                    <span class="cco-cart-item__variant">${item.name}</span>
                </div>
                <span class="cco-cart-item__total">${fmt( item.line_total )}</span>
            </div>
        ` ).join( '' );
        $( '#cco-cart-items' ).html( html );
    }

    function renderCartTotals( data ) {
        let html = `
            <div class="cco-totals-row">
                <span>Subtotal - ${data.item_count} items</span>
                <span class="cco-weight-bold">${fmt( data.subtotal )}</span>
            </div>
            <div class="cco-totals-row">
                <span>Shipping</span>
                <span>${ data.shipping_total > 0 ? fmt(data.shipping_total) : 'Free' }</span>
            </div>
            <div class="cco-totals-row">
                <span>Estimated taxes</span>
                <span>${fmt( data.tax_total )}</span>
            </div>
            <div class="cco-totals-row cco-totals-row--total">
                <span class="cco-total-label">Total</span>
                <span class="cco-total-price">
                    <small class="cco-currency-code">AUD</small>${fmt( data.total )}
                </span>
            </div>
        `;
        $( '#cco-cart-totals' ).html( html );
    }

    // ---------------------------------------------------------------
    // 2. Coupon
    // ---------------------------------------------------------------

    $( '#cco-apply-coupon' ).on( 'click', async function () {
        const code = $( '#cco-coupon-input' ).val().trim();
        if ( ! code ) return;
        clearNotice();
        try {
            await apiCall( `${storeApiBase}/cart/apply-coupon`, {
                method: 'POST',
                body: JSON.stringify( { code } )
            } );
            showNotice( 'Coupon applied!', 'success' );
            loadCart();
        } catch ( err ) {
            showNotice( err.message );
        }
    } );

    // ---------------------------------------------------------------
    // 3. Address Sync
    // ---------------------------------------------------------------

    function collectAddress() {
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

    const syncAddress = debounce( async function() {
        try {
            $( '.cco-summary-card' ).addClass( 'cco-is-loading' );
            await apiCall( `${storeApiBase}/cart/update-customer`, {
                method: 'POST',
                body: JSON.stringify( {
                    billing_address: collectAddress(),
                    shipping_address: collectAddress() // Always sync for now
                } )
            } );
            loadCart();
        } catch ( e ) {
            console.error( 'Address sync failed:', e );
        }
    }, 800 );

    $( document ).on( 'input change', '.cco-form-column input, .cco-form-column select', syncAddress );

    // ---------------------------------------------------------------
    // 4. Timer & Payment Toggles
    // ---------------------------------------------------------------

    function startTimer( durationSeconds ) {
        let timer = durationSeconds, minutes, seconds;
        const $display = $( '#cco-countdown' );
        const interval = setInterval( function () {
            minutes = parseInt( timer / 60, 10 );
            seconds = parseInt( timer % 60, 10 );

            minutes = minutes < 10 ? "0" + minutes : minutes;
            seconds = seconds < 10 ? "0" + seconds : seconds;

            $display.text( minutes + "m " + seconds + "s" );

            if ( --timer < 0 ) {
                clearInterval( interval );
                $display.text( "Expired" );
            }
        }, 1000 );
    }

    $( document ).on( 'change', 'input[name="payment_method"]', function() {
        const val = $( this ).val();
        $( '.cco-payment-method' ).removeClass( 'cco-payment-method--active' );
        $( this ).closest( '.cco-payment-method' ).addClass( 'cco-payment-method--active' );
        
        if ( val === 'cco_card' ) {
            $( '#cco-card-element' ).slideDown();
        } else {
            $( '#cco-card-element' ).slideUp();
        }
    } );

    // ---------------------------------------------------------------
    // 5. Place Order
    // ---------------------------------------------------------------

    $( '#cco-place-order' ).on( 'click', async function () {
        clearNotice();
        setLoading( true );

        const payload = {
            billing:      collectAddress(),
            shipping:     collectAddress(),
            payment_data: {}, 
        };

        try {
            const data = await apiCall( `${apiBase}/place-order`, {
                method: 'POST',
                body:   JSON.stringify( payload ),
            } );
            window.location.href = data.redirect_url;
        } catch ( err ) {
            showNotice( 'Order failed. Please check your details.' );
            setLoading( false );
        }
    } );

    // ---------------------------------------------------------------
    // Init
    // ---------------------------------------------------------------

    $( function () {
        loadCart();
        startTimer( 300 ); // 5 minutes
    } );

} )( jQuery );
