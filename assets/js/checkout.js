/**
 * Swaps the ifthenpay checkout row's content for "ifthenpay logo | enabled method logos".
 * Tutor's checkout template has no hook for it, so the markup comes from a PHP <template>.
 * Only the content span changes: Tutor's own row/radio handlers stay untouched.
 */
( function () {
	'use strict';

	var queued = false;

	function apply() {
		var template = document.getElementById( 'iftp-tutor-checkout-row' );
		var gateway = template ? template.getAttribute( 'data-iftp-tutor-gateway' ) : '';
		var inputs;

		queued = false;

		if ( ! template || ! template.content || ! gateway ) {
			return;
		}

		inputs = document.querySelectorAll( '.tutor-checkout-payment-item input[name="payment_method"]' );

		Array.prototype.forEach.call( inputs, function ( input ) {
			var row = input.closest( '.tutor-checkout-payment-item' );
			var content = row ? row.querySelector( '.tutor-payment-item-content' ) : null;

			if ( input.value !== gateway || ! content || content.querySelector( '.iftp-tutor-checkout' ) ) {
				return;
			}

			content.textContent = '';
			content.appendChild( document.importNode( template.content, true ) );
		} );
	}

	function queue() {
		if ( ! queued ) {
			queued = true;
			window.requestAnimationFrame( apply );
		}
	}

	function start() {
		apply();

		// Tutor re-renders parts of the checkout over AJAX; this puts the row back if it does.
		new window.MutationObserver( queue ).observe( document.body, { childList: true, subtree: true } );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
