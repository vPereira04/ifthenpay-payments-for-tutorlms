<?php
/**
 * Tutor gateway entry point.
 *
 * @package Ifthenpay\TutorLMS
 */

declare(strict_types=1);

namespace Ifthenpay\TutorLMS\Gateway;

use Ifthenpay\TutorLMS\Plugin;
use Tutor\PaymentGateways\GatewayBase;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What Tutor instantiates for checkout, `is_payment_gateway_configured()` and the webhook.
 * GatewayBase builds the PaymentHub payment (IfthenpayPayment) with our config in its constructor.
 */
final class IfthenpayGateway extends GatewayBase {

	/**
	 * Unused by Tutor, but abstract on GatewayBase.
	 */
	public function get_root_dir_name(): string {
		return 'Ifthenpay';
	}

	/**
	 * The PaymentHub payment class.
	 */
	public function get_payment_class(): string {
		return IfthenpayPayment::class;
	}

	/**
	 * The PaymentHub config class.
	 */
	public function get_config_class(): string {
		return IfthenpayConfig::class;
	}

	/**
	 * Our payment classes extend Ollyo's PaymentHub, which ships inside Tutor's PayPal library,
	 * so that's the autoloader GatewayBase needs to load before building them.
	 *
	 * @return string
	 */
	public static function get_autoload_file() {
		return Plugin::tutor_path() . 'ecommerce/PaymentGateways/Paypal/vendor/autoload.php';
	}
}
