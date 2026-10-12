/**
 * Keeps Tutor's success page in step with the payment: while the order waits for ifthenpay,
 * I check it every few seconds and put Tutor's own message back once the callback marks it paid.
 * Tutor's template has no hook around the message, so I find it by its wrapper.
 */
( function () {
	'use strict';

	var cfg = window.iftpTutorOrderStatus || {};
	var INTERVAL = 5000;
	var GIVE_UP_AFTER = 30 * 60 * 1000;
	var startedAt = Date.now();
	var message = null;
	var timer = null;
	var busy = false;
	var done = false;

	function stop() {
		done = true;
		window.clearTimeout( timer );
	}

	function schedule() {
		window.clearTimeout( timer );

		// A hidden tab doesn't poll; coming back to it checks straight away.
		if ( ! done && ! document.hidden ) {
			timer = window.setTimeout( check, INTERVAL );
		}
	}

	function check() {
		var body;

		if ( done || busy ) {
			return;
		}

		if ( Date.now() - startedAt > GIVE_UP_AFTER ) {
			stop();
			return;
		}

		busy = true;
		body = new window.URLSearchParams( { action: cfg.action, nonce: cfg.nonce, order_id: String( cfg.orderId ) } );

		window.fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				// 403/404 won't change on retry, so I stop rather than hammer the server.
				if ( 403 === response.status || 404 === response.status ) {
					stop();
				}

				return response.ok ? response.json() : null;
			} )
			.then( function ( json ) {
				if ( json && json.success && json.data && json.data.paid ) {
					message.textContent = cfg.paidMessage;
					stop();
				}
			} )
			.catch( function () {} )
			.then( function () {
				busy = false;
				schedule();
			} );
	}

	function start() {
		message = document.querySelector( '.tutor-order-status-content p' );

		if ( ! message || ! cfg.ajaxUrl || ! cfg.orderId || ! cfg.paidMessage ) {
			return;
		}

		message.setAttribute( 'aria-live', 'polite' );

		document.addEventListener( 'visibilitychange', function () {
			if ( ! document.hidden ) {
				check();
			}
		} );

		schedule();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
