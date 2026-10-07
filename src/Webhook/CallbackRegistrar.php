<?php
/**
 * Callback registration with ifthenpay.
 *
 * @package Ifthenpay\TutorLMS
 */

declare(strict_types=1);

namespace Ifthenpay\TutorLMS\Webhook;

use Ifthenpay\TutorLMS\Api\IfthenpayClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers this site's callback URL for the selected Gateway Key, and re-registers it when
 * the key or the URL drifts (permalink change, domain move), since ifthenpay keeps calling the old one.
 */
final class CallbackRegistrar {

	// Throttles automatic retries while ifthenpay keeps rejecting them.
	private const RETRY_LOCK = 'iftp_tutor_callback_retry_lock';
	private const RETRY_WAIT = 15 * MINUTE_IN_SECONDS;

	/**
	 * Ifthenpay API client.
	 *
	 * @var IfthenpayClient
	 */
	private IfthenpayClient $client;

	/**
	 * Registration state.
	 *
	 * @var CallbackStore
	 */
	private CallbackStore $store;

	/**
	 * Sets up the registrar.
	 *
	 * @param IfthenpayClient $client Ifthenpay API client.
	 * @param CallbackStore   $store  Registration state.
	 */
	public function __construct( IfthenpayClient $client, CallbackStore $store ) {
		$this->client = $client;
		$this->store  = $store;
	}

	/**
	 * Whether ifthenpay already has the current URL and a key for this Gateway Key.
	 *
	 * @param string $gateway_key The Gateway Key.
	 */
	public function is_in_sync( string $gateway_key ): bool {
		return self::fingerprint( $gateway_key ) === $this->store->hash()
			&& '' !== $this->store->anti_phishing_key( $gateway_key );
	}

	/**
	 * Registers the callback now. I reuse an existing anti-phishing key, since
	 * rotating it would break callbacks for payment links already handed out.
	 *
	 * @param string $gateway_key The Gateway Key.
	 */
	public function register( string $gateway_key ): bool {
		if ( '' === $gateway_key ) {
			return false;
		}

		$key    = $this->store->anti_phishing_key( $gateway_key );
		$is_new = '' === $key;

		if ( $is_new ) {
			$key = wp_generate_password( 32, false, false );
		}

		if ( ! $this->client->activate_callback( $gateway_key, $key, CallbackPayload::url_template() ) ) {
			set_transient( self::RETRY_LOCK, 1, self::RETRY_WAIT );

			return false;
		}

		// Only stored once ifthenpay accepted it; until then it keeps calling back with the old key.
		if ( $is_new ) {
			$this->store->set_anti_phishing_key( $gateway_key, $key );
		}

		$this->store->set_hash( self::fingerprint( $gateway_key ) );
		delete_transient( self::RETRY_LOCK );

		return true;
	}

	/**
	 * Re-registers when out of sync, at most once per retry window. Just a hash compare on the happy path.
	 *
	 * @param string $gateway_key The Gateway Key.
	 */
	public function resync( string $gateway_key ): void {
		if ( '' === $gateway_key || $this->is_in_sync( $gateway_key ) || false !== get_transient( self::RETRY_LOCK ) ) {
			return;
		}

		$this->register( $gateway_key );
	}

	/**
	 * A hash rather than the URL itself, so a migration's search-replace can't hide the drift.
	 *
	 * @param string $gateway_key The Gateway Key.
	 */
	private static function fingerprint( string $gateway_key ): string {
		return md5( $gateway_key . '|' . CallbackPayload::url_template() );
	}
}
