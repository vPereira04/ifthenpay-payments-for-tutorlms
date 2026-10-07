/**
 * Mounts the ifthenpay panel inside Tutor's ifthenpay payment card and drives it.
 *
 * Tutor's payment cards are a compiled React app with no slot for custom markup, so I keep one
 * panel node (cloned from the PHP <template>) and move it into the card body whenever React
 * (re)renders the card. Everything saves through our own AJAX, never Tutor's payment_settings.
 */
( function ( $ ) {
	'use strict';

	var cfg = window.iftpTutorAdmin || {};
	var i18n = cfg.i18n || {};
	var panel = null;
	var saveTimer = null;
	var saving = false;
	var saveQueued = false;
	var saveFailed = false;
	var mountQueued = false;

	function post( action, data ) {
		return $.post( cfg.ajaxUrl, $.extend( { action: action, nonce: cfg.nonce }, data || {} ), null, 'json' );
	}

	function errorMessage( xhr ) {
		var json = xhr && xhr.responseJSON;

		return ( json && json.data && json.data.message ) || i18n.error || '';
	}

	// One node for the page's lifetime, so a React re-render never loses what the admin typed.
	function getPanel() {
		var template;
		var source;

		if ( panel ) {
			return panel;
		}

		template = document.getElementById( 'iftp-tutor-panel-template' );
		source = template && template.content ? template.content.querySelector( '.iftp-tutor-panel' ) : null;

		if ( source ) {
			panel = document.importNode( source, true );
		}

		return panel;
	}

	// Card root → animated body → measured inner div → Tutor's field wrapper. I go in at the top of that wrapper.
	function cardBody() {
		var card = document.querySelector( '[data-payment-item-' + cfg.gateway + ']' );
		var inner = card && card.children[ 1 ] ? card.children[ 1 ].firstElementChild : null;

		if ( ! inner ) {
			return null;
		}

		return inner.firstElementChild || inner;
	}

	function mount() {
		var target = cardBody();
		var node = getPanel();

		mountQueued = false;

		if ( target && node && target.firstElementChild !== node ) {
			target.insertBefore( node, target.firstElementChild );
		}
	}

	function queueMount() {
		if ( ! mountQueued ) {
			mountQueued = true;
			window.requestAnimationFrame( mount );
		}
	}

	function $panel() {
		return $( getPanel() );
	}

	function setStatus( text, isError ) {
		$panel().find( '[data-iftp-tutor-status]' )
			.text( text || '' )
			.toggleClass( 'iftp-tutor-panel__status--error', !! isError );
	}

	function showReadiness( ready ) {
		setStatus( ready ? '' : i18n.notReady, false );
	}

	function applyFragments( data ) {
		var $p = $panel();

		$p.find( '#iftp-tutor-connection' ).html( data.connectionHtml || '' );
		$p.find( '[data-iftp-tutor-gateway-key]' ).html( data.gatewayKeysHtml || '' );
		$p.find( '#iftp-tutor-methods' ).html( data.methodsHtml || '' );
		$p.find( '[data-iftp-tutor-config]' ).prop( 'hidden', ! data.connected );

		showReadiness( !! data.ready );
	}

	// Connect modal: Tutor's modal markup, but I own its open/closed state.
	function openModal() {
		var $modal = $( '#iftp-tutor-connect-modal' );

		$modal.prop( 'hidden', false ).addClass( 'tutor-is-active' );
		$( '#iftp-tutor-connect-message' ).text( '' );
		$( '#iftp-tutor-backoffice-key' ).val( '' ).trigger( 'focus' );
	}

	function closeModal() {
		$( '#iftp-tutor-connect-modal' ).removeClass( 'tutor-is-active' ).prop( 'hidden', true );
		$panel().find( '[data-iftp-tutor-open-connect]' ).trigger( 'focus' );
	}

	function connect() {
		var $button = $( '#iftp-tutor-connect-submit' );
		var $message = $( '#iftp-tutor-connect-message' );
		var key = String( $( '#iftp-tutor-backoffice-key' ).val() || '' ).trim();

		if ( ! key ) {
			$message.text( i18n.enterKey || '' );

			return;
		}

		$button.prop( 'disabled', true ).text( i18n.connecting || '' );
		$message.text( '' );

		post( 'iftp_tutor_connect', { backoffice_key: key } )
			.done( function ( response ) {
				if ( response && response.success ) {
					applyFragments( response.data || {} );
					closeModal();

					return;
				}

				$message.text( ( response && response.data && response.data.message ) || i18n.error || '' );
			} )
			.fail( function ( xhr ) {
				$message.text( errorMessage( xhr ) );
			} )
			.always( function () {
				$( '#iftp-tutor-backoffice-key' ).val( '' );
				$button.prop( 'disabled', false ).text( i18n.connect || '' );
			} );
	}

	function collectPreferences() {
		var $p = $panel();
		var enabled = [];

		$p.find( '[data-iftp-tutor-enabled]:checked' ).each( function () {
			enabled.push( String( this.value ) );
		} );

		return {
			enabled: enabled,
			default_method: String( $p.find( '[data-iftp-tutor-star]:checked' ).val() || '' ),
			description: String( $p.find( '#iftp-tutor-description' ).val() || '' ),
			expiry_days: String( $p.find( '#iftp-tutor-expiry-days' ).val() || '' ),
		};
	}

	// One request at a time; a change made mid-save goes out right after, with the latest values.
	function save() {
		window.clearTimeout( saveTimer );
		saveTimer = null;

		if ( saving ) {
			saveQueued = true;

			return;
		}

		saving = true;
		setStatus( i18n.saving, false );

		post( 'iftp_tutor_save_preferences', collectPreferences() )
			.done( function ( response ) {
				saveFailed = ! ( response && response.success );

				if ( saveFailed ) {
					setStatus( i18n.saveFailed, true );

					return;
				}

				setStatus( response.data && response.data.ready ? i18n.saved : i18n.notReady, false );
			} )
			.fail( function () {
				saveFailed = true;
				setStatus( i18n.saveFailed, true );
			} )
			.always( function () {
				saving = false;

				if ( saveQueued ) {
					saveQueued = false;
					save();
				}
			} );
	}

	function saveSoon() {
		window.clearTimeout( saveTimer );
		saveTimer = window.setTimeout( save, 600 );
	}

	function setStar( $star, isDefault ) {
		$star.next( '.iftp-tutor-star' ).find( '.iftp-tutor-star__icon' )
			.toggleClass( 'tutor-icon-star-bold', isDefault )
			.toggleClass( 'tutor-icon-star-line', ! isDefault );
	}

	// Re-triggered by class, since a CSS-only :checked animation can't replay on the same state.
	function wink( $star ) {
		var $label = $star.next( '.iftp-tutor-star' );

		$label.removeClass( 'iftp-tutor-star--wink' );
		void $label[ 0 ].offsetWidth;
		$label.addClass( 'iftp-tutor-star--wink' );
	}

	$( document ).on( 'click', '[data-iftp-tutor-open-connect]', function ( e ) {
		e.preventDefault();
		openModal();
	} );

	$( document ).on( 'click', '[data-iftp-tutor-close-connect]', function ( e ) {
		e.preventDefault();
		closeModal();
	} );

	$( document ).on( 'keydown', function ( e ) {
		if ( 'Escape' === e.key && $( '#iftp-tutor-connect-modal' ).hasClass( 'tutor-is-active' ) ) {
			closeModal();
		}
	} );

	$( document ).on( 'click', '#iftp-tutor-connect-submit', function ( e ) {
		e.preventDefault();
		connect();
	} );

	$( document ).on( 'keydown', '#iftp-tutor-backoffice-key', function ( e ) {
		if ( 'Enter' === e.key ) {
			e.preventDefault();
			connect();
		}
	} );

	$( document ).on( 'click', '[data-iftp-tutor-disconnect]', function ( e ) {
		var $button = $( this );

		e.preventDefault();

		if ( ! window.confirm( i18n.confirmDisconnect || '' ) ) {
			return;
		}

		$button.prop( 'disabled', true );

		post( 'iftp_tutor_disconnect', {} )
			.done( function ( response ) {
				if ( response && response.success ) {
					applyFragments( response.data || {} );
				}
			} )
			.fail( function ( xhr ) {
				window.alert( errorMessage( xhr ) );
				$button.prop( 'disabled', false );
			} );
	} );

	$( document ).on( 'change', '[data-iftp-tutor-gateway-key]', function () {
		var $select = $( this );

		$select.prop( 'disabled', true );

		post( 'iftp_tutor_select_gateway_key', { gateway_key: String( $select.val() || '' ) } )
			.done( function ( response ) {
				if ( response && response.success ) {
					applyFragments( response.data || {} );
				}
			} )
			.fail( function ( xhr ) {
				window.alert( errorMessage( xhr ) );
			} )
			.always( function () {
				$panel().find( '[data-iftp-tutor-gateway-key]' ).prop( 'disabled', false );
			} );
	} );

	// A method switched off can't stay the default: hide and disable its star, and clear it.
	$( document ).on( 'change', '[data-iftp-tutor-enabled]', function () {
		var enabled = $( this ).prop( 'checked' );
		var $star = $( this ).closest( 'tr' ).find( '[data-iftp-tutor-star]' );

		$star.prop( 'disabled', ! enabled );
		$star.next( '.iftp-tutor-star' ).toggleClass( 'iftp-tutor-star--hidden', ! enabled );

		if ( ! enabled && $star.prop( 'checked' ) ) {
			$star.prop( 'checked', false );
			setStar( $star, false );
		}

		save();
	} );

	// Native radio group: one default, and clicking the starred one again is a no-op.
	$( document ).on( 'change', '[data-iftp-tutor-star]', function () {
		$panel().find( '[data-iftp-tutor-star]' ).each( function () {
			setStar( $( this ), $( this ).prop( 'checked' ) );
		} );

		wink( $( this ) );
		save();
	} );

	$( document ).on( 'input', '[data-iftp-tutor-pref]', saveSoon );
	$( document ).on( 'change', '[data-iftp-tutor-pref]', save );

	// The panel sits inside Tutor's settings form; Enter there would submit Tutor's whole form.
	$( document ).on( 'keydown', '.iftp-tutor-panel input', function ( e ) {
		if ( 'Enter' === e.key ) {
			e.preventDefault();
			save();
		}
	} );

	$( document ).on( 'click', '[data-iftp-tutor-request-activation]', function () {
		var $button = $( this );
		var label = $button.text();

		$button.prop( 'disabled', true ).text( i18n.requesting || label );

		post( 'iftp_tutor_request_activation', { entity: String( $button.data( 'entity' ) || '' ) } )
			.done( function ( response ) {
				if ( response && response.success ) {
					// Stays disabled: the server just started this method's 24-hour cooldown.
					$button.text( i18n.requested || label );
					window.alert( i18n.requestSent || '' );

					return;
				}

				window.alert( ( response && response.data && response.data.message ) || i18n.requestFailed || '' );
				$button.prop( 'disabled', false ).text( label );
			} )
			.fail( function ( xhr ) {
				window.alert( errorMessage( xhr ) || i18n.requestFailed || '' );
				$button.prop( 'disabled', false ).text( label );
			} );
	} );

	// Tutor's own "Save Changes" also flushes a pending debounce, so nothing typed is left behind.
	document.addEventListener(
		'click',
		function ( e ) {
			if ( saveTimer && e.target && e.target.closest && e.target.closest( '#save_tutor_option' ) ) {
				save();
			}
		},
		true
	);

	window.addEventListener( 'beforeunload', function ( e ) {
		if ( saveTimer || saving || saveFailed ) {
			e.preventDefault();
			e.returnValue = i18n.unsaved || '';
		}
	} );

	$( function () {
		if ( ! getPanel() ) {
			return;
		}

		showReadiness( !! cfg.ready );
		mount();
		new window.MutationObserver( queueMount ).observe( document.body, { childList: true, subtree: true } );
	} );
}( jQuery ) );
