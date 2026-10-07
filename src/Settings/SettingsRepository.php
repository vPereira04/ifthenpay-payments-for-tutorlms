<?php
/**
 * Our own settings storage.
 *
 * @package Ifthenpay\TutorLMS
 */

declare(strict_types=1);

namespace Ifthenpay\TutorLMS\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Everything the ifthenpay panel saves. Two options, both not autoloaded (they're only read on
 * Tutor's settings and checkout screens and by the callback): the Backoffice Key on its own,
 * and the rest of the gateway configuration as one array.
 *
 * @phpstan-type MethodRow array{entity: string, alias: string, logo: string, logo_dark: string, account: string, position: int, enabled: bool}
 */
final class SettingsRepository {

	public const OPTION_BACKOFFICE_KEY = 'iftp_tutor_backoffice_key';
	public const OPTION_CONFIG         = 'iftp_tutor_config';

	public const DEFAULT_EXPIRY_DAYS = 3;
	public const MAX_EXPIRY_DAYS     = 365;

	private const ACTIVATION_TRANSIENT_PREFIX = 'iftp_tutor_activation_';

	/**
	 * The config array, read once per request.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $config = null;

	/**
	 * The connected Backoffice Key, or ''.
	 */
	public function backoffice_key(): string {
		return (string) get_option( self::OPTION_BACKOFFICE_KEY, '' );
	}

	/**
	 * Whether a Backoffice Key is connected.
	 */
	public function is_connected(): bool {
		return '' !== $this->backoffice_key();
	}

	/**
	 * Stores a validated Backoffice Key.
	 *
	 * @param string $key The Backoffice Key.
	 */
	public function save_backoffice_key( string $key ): void {
		update_option( self::OPTION_BACKOFFICE_KEY, $key, false );
	}

	/**
	 * Forgets the Backoffice Key and the whole gateway configuration.
	 */
	public function disconnect(): void {
		delete_option( self::OPTION_BACKOFFICE_KEY );
		delete_option( self::OPTION_CONFIG );

		$this->config = null;
	}

	/**
	 * The selected Gateway Key, or ''.
	 */
	public function gateway_key(): string {
		return (string) ( $this->config()['gateway_key'] ?? '' );
	}

	/**
	 * The methods table for the selected Gateway Key, keyed by entity.
	 *
	 * @return array<string, MethodRow>
	 */
	public function methods(): array {
		$methods = $this->config()['methods'] ?? array();

		return is_array( $methods ) ? $methods : array();
	}

	/**
	 * Methods that are provisioned on the Gateway Key and switched on, keyed by entity.
	 *
	 * @return array<string, MethodRow>
	 */
	public function enabled_methods(): array {
		return array_filter(
			$this->methods(),
			static function ( array $method ): bool {
				return $method['enabled'] && '' !== $method['account'];
			}
		);
	}

	/**
	 * The default method's entity, or '' for none (only ever an enabled method).
	 */
	public function default_method(): string {
		$default = (string) ( $this->config()['default_method'] ?? '' );

		return isset( $this->enabled_methods()[ $default ] ) ? $default : '';
	}

	/**
	 * The text shown on ifthenpay's payment page, as saved ('' means "site name").
	 */
	public function description(): string {
		return (string) ( $this->config()['description'] ?? '' );
	}

	/**
	 * The text to send to ifthenpay, falling back to the site name.
	 */
	public function payment_description(): string {
		if ( '' !== $this->description() ) {
			return $this->description();
		}

		$site_name = wp_strip_all_tags( (string) get_bloginfo( 'name' ) );

		return '' !== $site_name ? $site_name : __( 'Course purchase', 'ifthenpay-payments-for-tutorlms' );
	}

	/**
	 * How many days a payment link stays valid.
	 */
	public function expiry_days(): int {
		$days = (int) ( $this->config()['expiry_days'] ?? self::DEFAULT_EXPIRY_DAYS );

		return $days >= 1 && $days <= self::MAX_EXPIRY_DAYS ? $days : self::DEFAULT_EXPIRY_DAYS;
	}

	/**
	 * Whether checkout can create a payment link: connected, a Gateway Key, and at least one method on.
	 */
	public function is_ready(): bool {
		return $this->is_connected() && '' !== $this->gateway_key() && array() !== $this->enabled_methods();
	}

	/**
	 * Switches to a Gateway Key with its freshly built methods table. The default resets,
	 * since it may not exist on the new key.
	 *
	 * @param string                   $gateway_key The Gateway Key.
	 * @param array<string, MethodRow> $methods     Its methods table.
	 */
	public function save_gateway( string $gateway_key, array $methods ): void {
		$config = $this->config();

		if ( ( $config['gateway_key'] ?? '' ) !== $gateway_key ) {
			$config['default_method'] = '';
		}

		$config['gateway_key'] = $gateway_key;
		$config['methods']     = $methods;

		$this->write( $config );
	}

	/**
	 * Saves the panel's editable part. Methods only flip `enabled`; their accounts come from ifthenpay.
	 *
	 * @param array<int, string> $enabled_entities Entities the admin switched on.
	 * @param string             $default_method   The starred entity, or ''.
	 * @param string             $description      The payment description.
	 * @param int                $expiry_days      Payment link lifetime in days.
	 */
	public function save_preferences( array $enabled_entities, string $default_method, string $description, int $expiry_days ): void {
		$config  = $this->config();
		$methods = $this->methods();

		foreach ( $methods as $entity => $method ) {
			$methods[ $entity ]['enabled'] = '' !== $method['account'] && in_array( $entity, $enabled_entities, true );
		}

		$config['methods']        = $methods;
		$config['default_method'] = isset( $methods[ $default_method ] ) && $methods[ $default_method ]['enabled'] ? $default_method : '';
		$config['description']    = $description;
		$config['expiry_days']    = max( 1, min( self::MAX_EXPIRY_DAYS, $expiry_days ) );

		$this->write( $config );
	}

	/**
	 * Whether activation of a method was requested in the last 24 hours.
	 *
	 * @param string $gateway_key The Gateway Key.
	 * @param string $entity      The method entity.
	 */
	public function is_activation_requested( string $gateway_key, string $entity ): bool {
		return false !== get_transient( self::activation_transient( $gateway_key, $entity ) );
	}

	/**
	 * Starts the 24-hour cooldown for one method on one Gateway Key.
	 *
	 * @param string $gateway_key The Gateway Key.
	 * @param string $entity      The method entity.
	 */
	public function mark_activation_requested( string $gateway_key, string $entity ): void {
		set_transient( self::activation_transient( $gateway_key, $entity ), 1, DAY_IN_SECONDS );
	}

	/**
	 * The cooldown transient name for a Gateway Key + method.
	 *
	 * @param string $gateway_key The Gateway Key.
	 * @param string $entity      The method entity.
	 */
	private static function activation_transient( string $gateway_key, string $entity ): string {
		return self::ACTIVATION_TRANSIENT_PREFIX . md5( $gateway_key . '|' . strtoupper( $entity ) );
	}

	/**
	 * The stored config array.
	 *
	 * @return array<string, mixed>
	 */
	private function config(): array {
		if ( null === $this->config ) {
			$stored       = get_option( self::OPTION_CONFIG, array() );
			$this->config = is_array( $stored ) ? $stored : array();
		}

		return $this->config;
	}

	/**
	 * Writes the config array.
	 *
	 * @param array<string, mixed> $config The config array.
	 */
	private function write( array $config ): void {
		update_option( self::OPTION_CONFIG, $config, false );

		$this->config = $config;
	}
}
