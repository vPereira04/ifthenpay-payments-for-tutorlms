<?php
/**
 * The ifthenpay callback's query, parsed.
 *
 * @package Ifthenpay\TutorLMS
 */

declare(strict_types=1);

namespace Ifthenpay\TutorLMS\Webhook;

use Ifthenpay\TutorLMS\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The callback URL we register with ifthenpay, and the typed query it comes back with.
 * ifthenpay only calls back for paid payments, so there's no status field.
 */
final class CallbackPayload {

	// Prefixed so nothing else reading the query (affiliate plugins love `ref`) picks them up.
	public const ARG_ORDER   = 'iftp_tutor_ref';
	public const ARG_APK     = 'iftp_tutor_apk';
	public const ARG_AMOUNT  = 'iftp_tutor_val';
	public const ARG_METHOD  = 'iftp_tutor_mtd';
	public const ARG_REQUEST = 'iftp_tutor_req';

	/**
	 * The Tutor order id (our Pay by Link `id`).
	 *
	 * @var int
	 */
	private int $order_id;

	/**
	 * The anti-phishing key ifthenpay echoed back.
	 *
	 * @var string
	 */
	private string $apk;

	/**
	 * The amount ifthenpay says was paid.
	 *
	 * @var float
	 */
	private float $amount;

	/**
	 * The method code the customer paid with.
	 *
	 * @var string
	 */
	private string $method;

	/**
	 * Ifthenpay's request id for the payment.
	 *
	 * @var string
	 */
	private string $request_id;

	/**
	 * Use from_query().
	 *
	 * @param int    $order_id   The Tutor order id.
	 * @param string $apk        The anti-phishing key.
	 * @param float  $amount     The paid amount.
	 * @param string $method     The method code.
	 * @param string $request_id Ifthenpay's request id.
	 */
	private function __construct( int $order_id, string $apk, float $amount, string $method, string $request_id ) {
		$this->order_id   = $order_id;
		$this->apk        = $apk;
		$this->amount     = $amount;
		$this->method     = $method;
		$this->request_id = $request_id;
	}

	/**
	 * The URL template ifthenpay calls, placeholders included. It goes through Tutor's own
	 * webhook route; `rest_url()` keeps it working with plain permalinks.
	 */
	public static function url_template(): string {
		$url = rest_url( 'tutor/v1/ecommerce-webhook/' . Plugin::GATEWAY );

		// add_query_arg() doesn't encode values, so the [PLACEHOLDERS] reach ifthenpay intact.
		return add_query_arg(
			array(
				self::ARG_ORDER   => '[ORDER_ID]',
				self::ARG_APK     => '[ANTI_PHISHING_KEY]',
				self::ARG_AMOUNT  => '[AMOUNT]',
				self::ARG_METHOD  => '[PAYMENT_METHOD]',
				self::ARG_REQUEST => '[REQUEST_ID]',
			),
			$url
		);
	}

	/**
	 * Parses the query Tutor hands the gateway (`$_GET` as received), or null when it isn't ours.
	 *
	 * @param array<mixed> $query The raw request query.
	 */
	public static function from_query( array $query ): ?self {
		$text = static function ( string $key ) use ( $query ): string {
			$value = $query[ $key ] ?? '';

			return is_string( $value ) ? sanitize_text_field( wp_unslash( $value ) ) : '';
		};

		$order_id = absint( $text( self::ARG_ORDER ) );

		if ( 0 === $order_id ) {
			return null;
		}

		return new self(
			$order_id,
			$text( self::ARG_APK ),
			(float) str_replace( ',', '.', $text( self::ARG_AMOUNT ) ),
			strtoupper( (string) preg_replace( '/[^A-Za-z0-9_-]/', '', $text( self::ARG_METHOD ) ) ),
			$text( self::ARG_REQUEST )
		);
	}

	/**
	 * The Tutor order id.
	 */
	public function order_id(): int {
		return $this->order_id;
	}

	/**
	 * The anti-phishing key ifthenpay echoed back.
	 */
	public function apk(): string {
		return $this->apk;
	}

	/**
	 * The paid amount.
	 */
	public function amount(): float {
		return $this->amount;
	}

	/**
	 * The method code the customer paid with.
	 */
	public function method(): string {
		return $this->method;
	}

	/**
	 * Ifthenpay's request id.
	 */
	public function request_id(): string {
		return $this->request_id;
	}
}
