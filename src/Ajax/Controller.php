<?php
/**
 * Admin AJAX behind the ifthenpay panel.
 *
 * @package Ifthenpay\TutorLMS
 */

declare(strict_types=1);

namespace Ifthenpay\TutorLMS\Ajax;

use Ifthenpay\TutorLMS\Admin\SettingsPanel;
use Ifthenpay\TutorLMS\Mail\IfthenpayEmailHelper;
use Ifthenpay\TutorLMS\Settings\SettingsRepository;
use Ifthenpay\TutorLMS\Sync\GatewaySync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Connect/disconnect, Gateway Key switch, preference saves and activation requests.
 * Every endpoint checks the panel nonce and `manage_options`, and answers with freshly
 * rendered fragments so the panel never needs a page reload.
 */
final class Controller {

	private const BACKOFFICE_KEY_FORMAT = '/^\d{4}-\d{4}-\d{4}-\d{4}$/';

	/**
	 * Settings storage.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Gateway Key + methods sync.
	 *
	 * @var GatewaySync
	 */
	private GatewaySync $sync;

	/**
	 * Panel renderer, for the fragments.
	 *
	 * @var SettingsPanel
	 */
	private SettingsPanel $panel;

	/**
	 * Sets up the controller.
	 *
	 * @param SettingsRepository $settings Settings storage.
	 * @param GatewaySync        $sync     Gateway Key + methods sync.
	 * @param SettingsPanel      $panel    Panel renderer.
	 */
	public function __construct( SettingsRepository $settings, GatewaySync $sync, SettingsPanel $panel ) {
		$this->settings = $settings;
		$this->sync     = $sync;
		$this->panel    = $panel;
	}

	/**
	 * Wires the admin-only AJAX actions.
	 */
	public function register(): void {
		add_action( 'wp_ajax_iftp_tutor_connect', array( $this, 'connect' ) );
		add_action( 'wp_ajax_iftp_tutor_disconnect', array( $this, 'disconnect' ) );
		add_action( 'wp_ajax_iftp_tutor_select_gateway_key', array( $this, 'select_gateway_key' ) );
		add_action( 'wp_ajax_iftp_tutor_save_preferences', array( $this, 'save_preferences' ) );
		add_action( 'wp_ajax_iftp_tutor_request_activation', array( $this, 'request_activation' ) );
	}

	/**
	 * Validates a Backoffice Key against ifthenpay and stores it. The key is never sent back.
	 */
	public function connect(): void {
		$this->authorize();

		$key = sanitize_text_field( wp_unslash( (string) ( $_POST['backoffice_key'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize() checks the nonce.

		if ( 1 !== preg_match( self::BACKOFFICE_KEY_FORMAT, $key ) ) {
			wp_send_json_error( array( 'message' => __( 'That doesn\'t look like a Backoffice Key. It has the format 0000-0000-0000-0000.', 'ifthenpay-payments-for-tutorlms' ) ), 400 );
		}

		if ( ! $this->sync->connect( $key ) ) {
			wp_send_json_error( array( 'message' => __( 'ifthenpay didn\'t return any Tutor LMS Gateway Key for this Backoffice Key. Check the key, or ask ifthenpay to create a Tutor LMS Gateway Key.', 'ifthenpay-payments-for-tutorlms' ) ), 400 );
		}

		wp_send_json_success( $this->fragments() );
	}

	/**
	 * Clears everything; the confirm prompt happens client-side.
	 */
	public function disconnect(): void {
		$this->authorize();

		$this->settings->disconnect();

		wp_send_json_success( $this->fragments() );
	}

	/**
	 * Switches the Gateway Key and answers with its methods table.
	 */
	public function select_gateway_key(): void {
		$this->authorize();
		$this->require_connection();

		$gateway_key = sanitize_text_field( wp_unslash( (string) ( $_POST['gateway_key'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize() checks the nonce.

		if ( ! $this->sync->select( $gateway_key ) ) {
			wp_send_json_error( array( 'message' => __( 'That Gateway Key isn\'t available for your Backoffice Key anymore. Reload the page and try again.', 'ifthenpay-payments-for-tutorlms' ) ), 400 );
		}

		wp_send_json_success( $this->fragments() );
	}

	/**
	 * Saves enabled methods, the starred default, description and expiry days.
	 */
	public function save_preferences(): void {
		$this->authorize();
		$this->require_connection();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- authorize() checks the nonce.
		$enabled = isset( $_POST['enabled'] ) && is_array( $_POST['enabled'] )
			? array_map( 'sanitize_key', wp_unslash( $_POST['enabled'] ) )
			: array();

		$this->settings->save_preferences(
			array_map( 'strtoupper', $enabled ),
			strtoupper( sanitize_key( wp_unslash( (string) ( $_POST['default_method'] ?? '' ) ) ) ),
			sanitize_text_field( wp_unslash( (string) ( $_POST['description'] ?? '' ) ) ),
			absint( $_POST['expiry_days'] ?? SettingsRepository::DEFAULT_EXPIRY_DAYS )
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		wp_send_json_success( array( 'ready' => $this->settings->is_ready() ) );
	}

	/**
	 * Emails ifthenpay support to provision a method, with a 24-hour cooldown per Gateway Key + method.
	 * The Backoffice Key goes into the email server-side; it never travels through the browser.
	 */
	public function request_activation(): void {
		$this->authorize();
		$this->require_connection();

		$gateway_key = $this->settings->gateway_key();
		$entity      = strtoupper( sanitize_key( wp_unslash( (string) ( $_POST['entity'] ?? '' ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize() checks the nonce.

		if ( '' === $gateway_key || ! isset( $this->settings->methods()[ $entity ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Choose a Gateway Key and a payment method first.', 'ifthenpay-payments-for-tutorlms' ) ), 400 );
		}

		if ( $this->settings->is_activation_requested( $gateway_key, $entity ) ) {
			wp_send_json_error( array( 'message' => __( 'Activation for this method was already requested. Please wait 24 hours before asking again.', 'ifthenpay-payments-for-tutorlms' ) ), 429 );
		}

		$sent = IfthenpayEmailHelper::send_activation_email(
			array(
				'gateway_key'    => $gateway_key,
				'entity'         => $entity,
				'backoffice_key' => $this->settings->backoffice_key(),
				'customer_email' => (string) get_option( 'admin_email' ),
				'site_url'       => home_url( '/' ),
				'site_name'      => (string) get_bloginfo( 'name' ),
				'wp_version'     => (string) get_bloginfo( 'version' ),
				'tutor_version'  => defined( 'TUTOR_VERSION' ) ? (string) TUTOR_VERSION : '',
				'plugin_version' => IFTP_TUTOR_VERSION,
			)
		);

		if ( ! $sent ) {
			wp_send_json_error( array( 'message' => __( 'The activation request could not be sent. Please try again.', 'ifthenpay-payments-for-tutorlms' ) ), 500 );
		}

		$this->settings->mark_activation_requested( $gateway_key, $entity );

		wp_send_json_success();
	}

	/**
	 * The panel parts every connect/disconnect/switch response re-renders.
	 *
	 * @return array<string, mixed>
	 */
	private function fragments(): array {
		return array(
			'connected'       => $this->settings->is_connected(),
			'ready'           => $this->settings->is_ready(),
			'connectionHtml'  => $this->panel->connection_html(),
			'gatewayKeysHtml' => $this->panel->gateway_key_options_html(),
			'methodsHtml'     => $this->panel->methods_table_html(),
		);
	}

	/**
	 * Nonce + capability, or a JSON error.
	 */
	private function authorize(): void {
		check_ajax_referer( SettingsPanel::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'ifthenpay-payments-for-tutorlms' ) ), 403 );
		}
	}

	/**
	 * Stops when no Backoffice Key is connected.
	 */
	private function require_connection(): void {
		if ( ! $this->settings->is_connected() ) {
			wp_send_json_error( array( 'message' => __( 'Connect your Backoffice Key first.', 'ifthenpay-payments-for-tutorlms' ) ), 400 );
		}
	}
}
