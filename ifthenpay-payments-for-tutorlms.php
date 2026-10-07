<?php
/**
 * Plugin Name: ifthenpay | Payments for Tutor LMS
 * Plugin URI: https://ifthenpay.com
 * Description: Adds ifthenpay (card, Apple Pay, Google Pay, Cofidis, Pix, Multibanco, MB WAY) to Tutor LMS's native checkout via Pay by Link, with orders confirmed by ifthenpay's callback.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Requires Plugins: tutor
 * Author: ifthenpay
 * Author URI: https://ifthenpay.com
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ifthenpay-payments-for-tutorlms
 *
 * @package Ifthenpay\TutorLMS
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'IFTP_TUTOR_VERSION', '1.0.0' );
define( 'IFTP_TUTOR_FILE', __FILE__ );
define( 'IFTP_TUTOR_PATH', plugin_dir_path( __FILE__ ) );
define( 'IFTP_TUTOR_URL', plugin_dir_url( __FILE__ ) );

if ( file_exists( IFTP_TUTOR_PATH . 'vendor/autoload.php' ) ) {
	require_once IFTP_TUTOR_PATH . 'vendor/autoload.php';
}

// Tutor's file (and its autoloader) loads after ours alphabetically, so I wait for plugins_loaded.
// A closure, so I don't add a global function.
add_action(
	'plugins_loaded',
	static function (): void {
		// GatewayBase only exists in Tutor LMS 3.0+, the versions with native eCommerce.
		if ( ! class_exists( \Tutor\PaymentGateways\GatewayBase::class ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					wp_admin_notice( esc_html__( 'ifthenpay | Payments for Tutor LMS needs Tutor LMS 3.0 or newer installed and active.', 'ifthenpay-payments-for-tutorlms' ), array( 'type' => 'warning' ) );
				},
				10,
				0
			);

			return;
		}

		if ( ! class_exists( \Ifthenpay\TutorLMS\Plugin::class ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					wp_admin_notice( esc_html__( 'ifthenpay | Payments for Tutor LMS could not load its classes. Run "composer install" in the plugin folder.', 'ifthenpay-payments-for-tutorlms' ), array( 'type' => 'error' ) );
				},
				10,
				0
			);

			return;
		}

		\Ifthenpay\TutorLMS\Plugin::instance()->boot();
	},
	20
);
