<?php
/**
 * The ifthenpay card in Tutor's payment settings.
 *
 * @package Ifthenpay\TutorLMS
 */

declare(strict_types=1);

namespace Ifthenpay\TutorLMS\Admin;

use Ifthenpay\TutorLMS\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds ifthenpay next to PayPal in Tutor LMS → Settings → Payment Methods.
 *
 * The card only declares a `webhook_url` field: it's the one type Tutor's React card doesn't
 * require a value for (so the Active toggle isn't blocked), and with no fields at all the card
 * shows "Necessary plugin is not installed". The real settings live in SettingsPanel, which
 * admin.js mounts into this card's body.
 */
final class PaymentCard {

	/**
	 * Hooks into Tutor's gateway list (its `tutor_payment_gateways` admin AJAX).
	 */
	public function register(): void {
		add_filter( 'tutor_payment_gateways', array( $this, 'add_card' ) );
	}

	/**
	 * Appends the ifthenpay card to Tutor's list.
	 *
	 * @param mixed $gateways Tutor's gateway cards.
	 * @return mixed
	 */
	public function add_card( $gateways ) {
		if ( ! is_array( $gateways ) ) {
			return $gateways;
		}

		$gateways[] = array(
			'name'                 => Plugin::GATEWAY,
			'label'                => __( 'ifthenpay', 'ifthenpay-payments-for-tutorlms' ),
			'is_installed'         => true,
			'is_plugin_active'     => true,
			'is_active'            => false,
			'icon'                 => esc_url_raw( IFTP_TUTOR_URL . 'assets/img/icon-normal.svg' ),
			'support_subscription' => false,
			'fields'               => array(
				array(
					'name'  => 'webhook_url',
					'type'  => 'webhook_url',
					'label' => __( 'Callback URL (registered with ifthenpay automatically)', 'ifthenpay-payments-for-tutorlms' ),
					'value' => '',
				),
			),
		);

		return $gateways;
	}
}
