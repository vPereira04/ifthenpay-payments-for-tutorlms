<?php
/**
 * Ifthenpay HTTP API client.
 *
 * @package Ifthenpay\TutorLMS
 */

declare(strict_types=1);

namespace Ifthenpay\TutorLMS\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin wrapper around the ifthenpay HTTP API.
 */
final class IfthenpayClient {

	private const API_BASE     = 'https://api.ifthenpay.com';
	private const GATEWAY_TYPE = 'forminator';
	private const CALLBACK_CMS = 'forminator';

	// Switching Gateway Keys back and forth re-reads the same rows; a short window keeps that to one call.
	public const GATEWAY_ROWS_TRANSIENT = 'iftp_tutor_gateway_rows';
	private const GATEWAY_ROWS_TTL      = 10 * MINUTE_IN_SECONDS;

	// The methods catalog is the same for every ifthenpay account and rarely changes.
	public const CATALOG_TRANSIENT = 'iftp_tutor_methods_catalog';
	private const CATALOG_TTL      = 12 * HOUR_IN_SECONDS;

	/**
	 * Why the last payment link request failed, for the order timeline. Never shown to customers.
	 *
	 * @var string
	 */
	private string $last_error = '';

	/**
	 * Why the last payment link request failed.
	 */
	public function get_last_error(): string {
		return $this->last_error;
	}

	/**
	 * GET /gateway/get?boKey={key}&Type=TutorLMS — the Gateway Key rows for this Backoffice Key.
	 * Failed or empty lookups aren't cached, so a freshly provisioned key shows up right away.
	 *
	 * @param string $backoffice_key The Backoffice Key.
	 * @param bool   $fresh          Skip the cache, e.g. when connecting.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_gateway_rows( string $backoffice_key, bool $fresh = false ): array {
		$key_hash = md5( $backoffice_key );
		$cached   = $fresh ? false : get_transient( self::GATEWAY_ROWS_TRANSIENT );

		if ( is_array( $cached ) && ( $cached['key_hash'] ?? '' ) === $key_hash && ! empty( $cached['rows'] ) && is_array( $cached['rows'] ) ) {
			return $cached['rows'];
		}

		$response = wp_remote_get(
			add_query_arg(
				array(
					'boKey' => $backoffice_key,
					'Type'  => (string) apply_filters( 'iftp_tutor_gateway_type', self::GATEWAY_TYPE ),
				),
				self::API_BASE . '/gateway/get'
			),
			array( 'timeout' => 15 )
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$body = $this->decode_response( $response );

		if ( array() === $body ) {
			return array();
		}

		// One gateway comes back as a bare object, several as a list.
		$rows = isset( $body[0] ) ? array_values( array_filter( $body, 'is_array' ) ) : array( $body );

		set_transient(
			self::GATEWAY_ROWS_TRANSIENT,
			array(
				'key_hash' => $key_hash,
				'rows'     => $rows,
			),
			self::GATEWAY_ROWS_TTL
		);

		return $rows;
	}

	/**
	 * Drops the cached Gateway Key rows, e.g. after the Backoffice Key changed.
	 */
	public function forget_gateway_rows(): void {
		delete_transient( self::GATEWAY_ROWS_TRANSIENT );
	}

	/**
	 * GET /gateway/methods/available — the methods catalog. A failed fetch isn't cached.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_catalog(): array {
		$cached = get_transient( self::CATALOG_TRANSIENT );

		if ( is_array( $cached ) && array() !== $cached ) {
			return $cached;
		}

		$response = wp_remote_get( self::API_BASE . '/gateway/methods/available', array( 'timeout' => 15 ) );

		// An error page can still be JSON, and I don't want to keep that as the catalog for 12 hours.
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$catalog = array_values( array_filter( $this->decode_response( $response ), 'is_array' ) );

		if ( array() !== $catalog ) {
			set_transient( self::CATALOG_TRANSIENT, $catalog, self::CATALOG_TTL );
		}

		return $catalog;
	}

	/**
	 * POST /gateway/pinpay/{gateway_key} — creates a Pay by Link.
	 *
	 * @param string               $gateway_key The Gateway Key to create the link under.
	 * @param array<string, mixed> $payload     The Pay by Link request body.
	 * @return string The hosted payment page URL, or '' on failure (see get_last_error()).
	 */
	public function create_payment_link( string $gateway_key, array $payload ): string {
		$this->last_error = '';

		$response = wp_remote_post(
			self::API_BASE . '/gateway/pinpay/' . rawurlencode( $gateway_key ),
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => (string) wp_json_encode( $payload ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->last_error = $response->get_error_message();

			return '';
		}

		$body = $this->decode_response( $response );
		$url  = $body['RedirectUrl'] ?? $body['redirect_url'] ?? '';

		if ( ! is_string( $url ) || '' === $url ) {
			$this->last_error = sprintf(
				'HTTP %d: %s',
				(int) wp_remote_retrieve_response_code( $response ),
				wp_remote_retrieve_body( $response )
			);

			return '';
		}

		return $url;
	}

	/**
	 * POST /endpoint/callback/activation/?cms=tutorlms — (re-)registers the callback URL for a Gateway Key.
	 *
	 * @param string $gateway_key       The Gateway Key.
	 * @param string $anti_phishing_key The key ifthenpay echoes back as `[ANTI_PHISHING_KEY]`.
	 * @param string $callback_url      The URL template ifthenpay calls when a payment is paid.
	 */
	public function activate_callback( string $gateway_key, string $anti_phishing_key, string $callback_url ): bool {
		$response = wp_remote_post(
			self::API_BASE . '/endpoint/callback/activation/?cms=' . self::CALLBACK_CMS,
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => (string) wp_json_encode(
					array(
						'apKey' => $anti_phishing_key,
						'chave' => $gateway_key,
						'urlCb' => $callback_url,
					)
				),
				'timeout' => 15,
			)
		);

		return ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) < 300;
	}

	/**
	 * Decodes a wp_remote_* JSON body into an array ([] for anything else).
	 *
	 * @param array<string, mixed> $response The HTTP response.
	 * @return array<mixed>
	 */
	private function decode_response( array $response ): array {
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		return is_array( $body ) ? $body : array();
	}
}
