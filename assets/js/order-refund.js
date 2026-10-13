/**
 * Hides Tutor's Refund button on an ifthenpay order, since ifthenpay doesn't support refunds.
 * PHP only loads me for ifthenpay orders. Tutor's React app gives the button no stable class,
 * so I find it by Tutor's own translated label and keep watching in case React redraws it.
 */
( function () {
	'use strict';

	var root = document.getElementById( 'tutor-order-details-root' );
	var label = window.wp && window.wp.i18n ? window.wp.i18n.__( 'Refund', 'tutor' ) : 'Refund';

	function hideRefund() {
		root.querySelectorAll( 'button' ).forEach( function ( button ) {
			if ( button.textContent.trim() !== label || button.style.display === 'none' ) {
				return;
			}

			button.style.display = 'none';

			// The button sits alone in a padded footer, so I drop that too.
			if ( button.parentElement && button.parentElement.children.length === 1 ) {
				button.parentElement.style.display = 'none';
			}
		} );
	}

	if ( ! root ) {
		return;
	}

	hideRefund();
	new MutationObserver( hideRefund ).observe( root, { childList: true, subtree: true } );
}() );
