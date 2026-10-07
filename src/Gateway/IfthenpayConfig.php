<?php
/**
 * PaymentHub config for ifthenpay.
 *
 * @package Ifthenpay\TutorLMS
 */

declare(strict_types=1);

namespace Ifthenpay\TutorLMS\Gateway;

use Ifthenpay\TutorLMS\Plugin;
use Ifthenpay\TutorLMS\Settings\SettingsRepository;
use Ollyo\PaymentHub\Contracts\Payment\ConfigContract;
use Ollyo\PaymentHub\Core\Payment\BaseConfig;
use Tutor\PaymentGateways\Configs\PaymentUrlsTrait;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hands PaymentHub Tutor's own success/cancel URLs (PaymentUrlsTrait), which already carry the
 * order id by the time checkout builds us. Our own settings live in SettingsRepository.
 */
final class IfthenpayConfig extends BaseConfig implements ConfigContract {

	use PaymentUrlsTrait;

	/**
	 * The gateway name PaymentHub reports.
	 *
	 * @var string
	 */
	protected $name = Plugin::GATEWAY;

	/**
	 * Tutor hides the gateway at checkout unless this is true: connected, a Gateway Key, at least
	 * one method on, and a euro store (ifthenpay only settles euros).
	 */
	public function is_configured(): bool {
		return ( new SettingsRepository() )->is_ready() && Plugin::is_eur_store();
	}
}
