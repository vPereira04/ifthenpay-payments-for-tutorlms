<?php
/**
 * Callback registration state.
 *
 * @package Ifthenpay\TutorLMS
 */

declare(strict_types=1);

namespace Ifthenpay\TutorLMS\Webhook;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The anti-phishing key per Gateway Key, and a fingerprint of what ifthenpay last accepted.
 * Both are read only on callbacks and on Tutor's settings screen, so they aren't autoloaded.
 */
final class CallbackStore {

	public const OPTION_KEYS = 'iftp_tutor_anti_phishing_keys';
	public const OPTION_HASH = 'iftp_tutor_callback_hash';

	/**
	 * The anti-phishing key registered for a Gateway Key, or ''.
	 *
	 * @param string $gateway_key The Gateway Key.
	 */
	public function anti_phishing_key( string $gateway_key ): string {
		$keys = get_option( self::OPTION_KEYS, array() );

		return is_array( $keys ) && isset( $keys[ $gateway_key ] ) && is_string( $keys[ $gateway_key ] ) ? $keys[ $gateway_key ] : '';
	}

	/**
	 * Stores the anti-phishing key for a Gateway Key.
	 *
	 * @param string $gateway_key The Gateway Key.
	 * @param string $key         The anti-phishing key ifthenpay accepted.
	 */
	public function set_anti_phishing_key( string $gateway_key, string $key ): void {
		$keys = get_option( self::OPTION_KEYS, array() );
		$keys = is_array( $keys ) ? $keys : array();

		$keys[ $gateway_key ] = $key;

		update_option( self::OPTION_KEYS, $keys, false );
	}

	/**
	 * The fingerprint of the last accepted registration.
	 */
	public function hash(): string {
		return (string) get_option( self::OPTION_HASH, '' );
	}

	/**
	 * Stores the fingerprint of an accepted registration.
	 *
	 * @param string $hash The fingerprint.
	 */
	public function set_hash( string $hash ): void {
		update_option( self::OPTION_HASH, $hash, false );
	}
}
