<?php
/**
 * Plugin bootstrap.
 *
 * @package Ifthenpay\TutorLMS
 */

declare(strict_types=1);

namespace Ifthenpay\TutorLMS;

use Ifthenpay\TutorLMS\Admin\Notices;
use Ifthenpay\TutorLMS\Admin\PaymentCard;
use Ifthenpay\TutorLMS\Admin\SettingsPanel;
use Ifthenpay\TutorLMS\Ajax\Controller;
use Ifthenpay\TutorLMS\Api\IfthenpayClient;
use Ifthenpay\TutorLMS\Checkout\CheckoutRow;
use Ifthenpay\TutorLMS\Gateway\IfthenpayConfig;
use Ifthenpay\TutorLMS\Gateway\IfthenpayGateway;
use Ifthenpay\TutorLMS\Orders\OrderDisplay;
use Ifthenpay\TutorLMS\Settings\SettingsRepository;
use Ifthenpay\TutorLMS\Sync\GatewaySync;
use Ifthenpay\TutorLMS\Webhook\CallbackRegistrar;
use Ifthenpay\TutorLMS\Webhook\CallbackStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires every piece together once, on `plugins_loaded`.
 */
final class Plugin {

	/**
	 * Our gateway name inside Tutor: payment settings, order `payment_method`, and the webhook path segment.
	 */
	public const GATEWAY = 'ifthenpay';

	/**
	 * The only currency ifthenpay settles in.
	 */
	public const CURRENCY = 'EUR';

	/**
	 * The singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * The singleton instance, created on first access.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Singleton — use instance() instead.
	 */
	private function __construct() {
	}

	/**
	 * Registers every hook.
	 */
	public function boot(): void {
		// Checkout and the webhook both resolve the gateway through this map, so it's always on.
		add_filter( 'tutor_payment_gateways_with_class', array( $this, 'register_gateway' ) );

		( new OrderDisplay() )->register();

		$settings = new SettingsRepository();

		// The panel, its AJAX and the notices only run in wp-admin (admin-ajax included).
		if ( is_admin() ) {
			$client    = new IfthenpayClient();
			$registrar = new CallbackRegistrar( $client, new CallbackStore() );
			$sync      = new GatewaySync( $settings, $client, $registrar );
			$panel     = new SettingsPanel( $settings, $sync );

			( new PaymentCard() )->register();
			$panel->register();
			( new Controller( $settings, $sync, $panel ) )->register();
			( new Notices( $settings, $registrar ) )->register();

			return;
		}

		( new CheckoutRow( $settings ) )->register();
	}

	/**
	 * Adds ifthenpay to Tutor's gateway → class map.
	 *
	 * @param mixed $gateways Tutor's map of gateway name => gateway_class/config_class.
	 * @return mixed
	 */
	public function register_gateway( $gateways ) {
		if ( ! is_array( $gateways ) ) {
			return $gateways;
		}

		$gateways[ self::GATEWAY ] = array(
			'gateway_class' => IfthenpayGateway::class,
			'config_class'  => IfthenpayConfig::class,
		);

		return $gateways;
	}

	/**
	 * The store currency from Tutor's Monetization settings, upper-cased.
	 */
	public static function store_currency(): string {
		if ( ! function_exists( 'tutor_utils' ) ) {
			return '';
		}

		$code = tutor_utils()->get_option( 'currency_code' );

		return strtoupper( is_string( $code ) && '' !== $code ? $code : 'USD' );
	}

	/**
	 * Whether Tutor is set to sell in euros.
	 */
	public static function is_eur_store(): bool {
		return self::CURRENCY === self::store_currency();
	}

	/**
	 * `?ver=` for our assets: the file time while debugging, so edits show without a version bump.
	 *
	 * @param string $relative Path inside the plugin, e.g. `assets/js/admin.js`.
	 */
	public static function asset_version( string $relative ): string {
		$debug = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) || ( defined( 'WP_DEBUG' ) && WP_DEBUG );
		$path  = IFTP_TUTOR_PATH . $relative;

		return $debug && file_exists( $path ) ? (string) filemtime( $path ) : IFTP_TUTOR_VERSION;
	}

	/**
	 * Tutor's plugin directory, with a trailing slash ('' if Tutor isn't loaded).
	 */
	public static function tutor_path(): string {
		return defined( 'TUTOR_FILE' ) ? plugin_dir_path( (string) TUTOR_FILE ) : '';
	}
}
