<?php
/**
 * Warnings and the callback drift check on Tutor's settings screen.
 *
 * @package Ifthenpay\TutorLMS
 */

declare(strict_types=1);

namespace Ifthenpay\TutorLMS\Admin;

use Ifthenpay\TutorLMS\Plugin;
use Ifthenpay\TutorLMS\Settings\SettingsRepository;
use Ifthenpay\TutorLMS\Webhook\CallbackRegistrar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Re-registers the callback when the site URL or permalinks moved, and flags a non-EUR store
 * or a callback ifthenpay hasn't accepted. Only on Tutor's settings screen.
 */
final class Notices {

	/**
	 * Settings storage.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Callback registration.
	 *
	 * @var CallbackRegistrar
	 */
	private CallbackRegistrar $registrar;

	/**
	 * Sets up the notices.
	 *
	 * @param SettingsRepository $settings  Settings storage.
	 * @param CallbackRegistrar  $registrar Callback registration.
	 */
	public function __construct( SettingsRepository $settings, CallbackRegistrar $registrar ) {
		$this->settings  = $settings;
		$this->registrar = $registrar;
	}

	/**
	 * Wires the hooks.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'maybe_resync' ), 10, 0 );
		add_action( 'admin_notices', array( $this, 'render' ), 10, 0 );
	}

	/**
	 * Just a hash compare on the happy path; a failed retry waits out a 15-minute lock.
	 */
	public function maybe_resync(): void {
		if ( wp_doing_ajax() || ! SettingsPanel::is_settings_screen() ) {
			return;
		}

		$this->registrar->resync( $this->settings->gateway_key() );
	}

	/**
	 * The warnings.
	 */
	public function render(): void {
		if ( ! SettingsPanel::is_settings_screen() || ! $this->settings->is_connected() ) {
			return;
		}

		if ( ! Plugin::is_eur_store() ) {
			wp_admin_notice(
				esc_html(
					sprintf(
						/* translators: %s: the store currency code, e.g. USD. */
						__( 'ifthenpay only takes payments in euros, and your Tutor LMS currency is %s, so ifthenpay is hidden at checkout. Switch the currency to EUR to offer it.', 'ifthenpay-payments-for-tutorlms' ),
						'' !== Plugin::store_currency() ? Plugin::store_currency() : '?'
					)
				),
				array( 'type' => 'warning' )
			);
		}

		$gateway_key = $this->settings->gateway_key();

		if ( '' !== $gateway_key && ! $this->registrar->is_in_sync( $gateway_key ) ) {
			wp_admin_notice(
				esc_html__( 'ifthenpay could not register this site\'s callback URL yet, so paid orders won\'t be confirmed automatically. This page retries it at most every 15 minutes; contact ifthenpay support if it keeps failing.', 'ifthenpay-payments-for-tutorlms' ),
				array( 'type' => 'warning' )
			);
		}
	}
}
