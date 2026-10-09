<?php
/**
 * PaymentHub payment for ifthenpay.
 *
 * @package Ifthenpay\TutorLMS
 */

declare(strict_types=1);

namespace Ifthenpay\TutorLMS\Gateway;

use Ifthenpay\TutorLMS\Api\IfthenpayClient;
use Ifthenpay\TutorLMS\Api\IfthenpayPayload;
use Ifthenpay\TutorLMS\Methods;
use Ifthenpay\TutorLMS\Plugin;
use Ifthenpay\TutorLMS\Settings\SettingsRepository;
use Ifthenpay\TutorLMS\Webhook\CallbackPayload;
use Ifthenpay\TutorLMS\Webhook\CallbackStore;
use Ollyo\PaymentHub\Core\Payment\BasePayment;
use RuntimeException;
use stdClass;
use Tutor\Models\OrderActivitiesModel;
use Tutor\Models\OrderMetaModel;
use Tutor\Models\OrderModel;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates the Pay by Link at checkout, and turns ifthenpay's paid callback into Tutor's order update.
 *
 * The customer's return trip never touches the order: ifthenpay sends them straight to Tutor's
 * success/failed pages. Only the callback (anti-phishing key + amount checked) marks an order paid.
 */
final class IfthenpayPayment extends BasePayment {

	/**
	 * Order meta with the Gateway Key and amount of the latest link, so the callback can verify both.
	 */
	public const ORDER_META = 'iftp_tutor_payment';

	private const AMOUNT_TOLERANCE = 0.01;

	/**
	 * Runs while PaymentHub boots; false makes it refuse to build the payment.
	 * Currency isn't checked here on purpose, so a callback for an existing link still verifies.
	 */
	public function check(): bool {
		$settings = new SettingsRepository();

		return $settings->is_connected() && '' !== $settings->gateway_key();
	}

	/**
	 * Nothing to initialise: ifthenpay has no SDK.
	 */
	public function setup(): void {
	}

	/**
	 * Creates the Pay by Link for the order Tutor just placed and redirects the customer to it.
	 * Tutor catches what I throw and shows its message on the "order failed" page.
	 *
	 * @throws RuntimeException When the order can't be paid with ifthenpay or the link can't be created.
	 */
	public function createPayment() {
		$data     = $this->getData();
		$order_id = is_object( $data ) && isset( $data->order_id ) ? absint( $data->order_id ) : 0;

		if ( 0 === $order_id ) {
			throw new RuntimeException( esc_html( self::generic_error() ) );
		}

		$currency = isset( $data->currency->code ) ? strtoupper( (string) $data->currency->code ) : '';

		if ( Plugin::CURRENCY !== $currency ) {
			/* translators: Tutor passes this through a URL, which drops apostrophes and cuts at "#". */
			throw new RuntimeException( esc_html__( 'ifthenpay only accepts payments in euros (EUR). Please choose another payment method.', 'ifthenpay-payments-for-tutorlms' ) );
		}

		$amount = round( (float) ( $data->total_price ?? 0 ), 2 );

		if ( $amount <= 0 ) {
			throw new RuntimeException( esc_html( self::generic_error() ) );
		}

		$settings = new SettingsRepository();
		$methods  = $settings->enabled_methods();

		if ( array() === $methods ) {
			$this->add_activity( $order_id, __( 'ifthenpay couldn\'t create the payment link: no payment method is enabled in the ifthenpay settings.', 'ifthenpay-payments-for-tutorlms' ) );

			throw new RuntimeException( esc_html( self::generic_error() ) );
		}

		$expires = time() + $settings->expiry_days() * DAY_IN_SECONDS;
		$payload = array(
			'id'          => (string) $order_id,
			'amount'      => IfthenpayPayload::format_amount( $amount ),
			'description' => $settings->payment_description(),
			'accounts'    => IfthenpayPayload::accounts_string( $methods ),
			'success_url' => (string) $this->config->get( 'success_url' ),
			'error_url'   => (string) $this->config->get( 'cancel_url' ),
			'cancel_url'  => (string) $this->config->get( 'cancel_url' ),
			'expiredate'  => (string) wp_date( 'Ymd', $expires ),
			'otp'         => 'true',
			'lang'        => IfthenpayPayload::lang( determine_locale() ),
		);

		$default = $settings->default_method();

		if ( '' !== $default && $methods[ $default ]['position'] > 0 ) {
			$payload['selected_method'] = (string) $methods[ $default ]['position'];
		}

		$client = new IfthenpayClient();
		$link   = $client->create_payment_link( $settings->gateway_key(), $payload );
		$host   = strtolower( (string) wp_parse_url( $link, PHP_URL_HOST ) );

		if ( ! self::is_ifthenpay_host( $host ) ) {
			$reason = '' !== $client->get_last_error() ? $client->get_last_error() : $link;

			$this->add_activity(
				$order_id,
				sprintf(
					/* translators: %s: the error ifthenpay (or the connection) returned. */
					__( 'ifthenpay couldn\'t create the payment link: %s', 'ifthenpay-payments-for-tutorlms' ),
					wp_html_excerpt( $reason, 300, '…' )
				)
			);

			throw new RuntimeException( esc_html( self::generic_error() ) );
		}

		OrderMetaModel::update_meta(
			$order_id,
			self::ORDER_META,
			array(
				'gateway_key' => $settings->gateway_key(),
				'amount'      => $payload['amount'],
			)
		);

		$this->add_activity(
			$order_id,
			sprintf(
				/* translators: 1: amount in euros, 2: expiry date. */
				__( 'ifthenpay payment link created for %1$s EUR. It expires on %2$s.', 'ifthenpay-payments-for-tutorlms' ),
				$payload['amount'],
				(string) wp_date( (string) get_option( 'date_format' ), $expires )
			)
		);

		add_filter(
			'allowed_redirect_hosts',
			static function ( $hosts ) use ( $host ) {
				$hosts   = is_array( $hosts ) ? $hosts : array();
				$hosts[] = $host;

				return $hosts;
			}
		);

		wp_safe_redirect( $link );
		exit;
	}

	/**
	 * Tutor's webhook route hands me the request; I return the order update for a valid paid
	 * callback, or an empty object (Tutor then does nothing) for anything else.
	 *
	 * @param object $payload Tutor's `{get, post, server, stream}` request snapshot.
	 */
	public function verifyAndCreateOrderData( object $payload ): object {
		$query    = isset( $payload->get ) && is_array( $payload->get ) ? $payload->get : array();
		$callback = CallbackPayload::from_query( $query );

		if ( null === $callback ) {
			return new stdClass();
		}

		$order_id = $callback->order_id();
		$order    = ( new OrderModel() )->get_order_by_id( $order_id );

		// Already paid is a repeat callback; Tutor would skip it too.
		if ( ! is_object( $order ) || OrderModel::PAYMENT_PAID === ( $order->payment_status ?? '' ) ) {
			return new stdClass();
		}

		// The meta proves I created a link for this order, whatever payment_method the order started with.
		$link = OrderMetaModel::get_meta_value( $order_id, self::ORDER_META, true );

		if ( ! is_array( $link ) || empty( $link['gateway_key'] ) || ! isset( $link['amount'] ) ) {
			return new stdClass();
		}

		$expected_key = ( new CallbackStore() )->anti_phishing_key( (string) $link['gateway_key'] );

		if ( '' === $expected_key || ! hash_equals( $expected_key, $callback->apk() ) ) {
			return new stdClass();
		}

		// Only logged past the key check, so nobody can fill an order's timeline from outside.
		if ( abs( $callback->amount() - (float) $link['amount'] ) > self::AMOUNT_TOLERANCE ) {
			$this->add_activity(
				$order_id,
				sprintf(
					/* translators: 1: amount ifthenpay reported, 2: amount the payment link was for. */
					__( 'ifthenpay reported a payment of %1$s EUR, but the payment link was for %2$s EUR. The order was not marked as paid.', 'ifthenpay-payments-for-tutorlms' ),
					IfthenpayPayload::format_amount( $callback->amount() ),
					(string) $link['amount']
				)
			);

			return new stdClass();
		}

		$this->add_activity(
			$order_id,
			sprintf(
				/* translators: 1: payment method, e.g. "MB WAY", 2: ifthenpay request id. */
				__( 'Payment confirmed by ifthenpay: %1$s, request ID %2$s.', 'ifthenpay-payments-for-tutorlms' ),
				'' !== $callback->method() ? Methods::label( $callback->method() ) : __( 'unknown method', 'ifthenpay-payments-for-tutorlms' ),
				'' !== $callback->request_id() ? $callback->request_id() : '-'
			)
		);

		// Same shape as PaymentHub's System::defaultOrderData(), which Tutor's order update reads.
		$result                       = new stdClass();
		$result->type                 = 'payment';
		$result->id                   = $order_id;
		$result->payment_status       = OrderModel::PAYMENT_PAID;
		$result->payment_error_reason = '';
		$result->transaction_id       = $callback->request_id();
		$result->payment_method       = Plugin::GATEWAY;
		$result->tax_amount           = '';
		$result->payment_payload      = (string) wp_json_encode(
			array(
				'gateway'    => Plugin::GATEWAY,
				'reference'  => (string) $order_id,
				'method'     => $callback->method(),
				'request_id' => $callback->request_id(),
				'amount'     => IfthenpayPayload::format_amount( $callback->amount() ),
			)
		);

		// Null, not '': Tutor writes these straight into decimal columns.
		$result->fees     = null;
		$result->earnings = null;

		return $result;
	}

	/**
	 * Adds a line to the order's timeline in Tutor → Orders.
	 *
	 * @param int    $order_id The order id.
	 * @param string $message  The line to add.
	 */
	private function add_activity( int $order_id, string $message ): void {
		$entry             = new stdClass();
		$entry->order_id   = $order_id;
		$entry->meta_key   = OrderActivitiesModel::META_KEY_HISTORY;
		$entry->meta_value = $message;

		( new OrderActivitiesModel() )->add_order_meta( $entry );
	}

	/**
	 * Whether a host is ifthenpay's (or a subdomain of it). Pay by Links come back on pinpay.pt.
	 *
	 * @param string $host The host to check.
	 */
	private static function is_ifthenpay_host( string $host ): bool {
		foreach ( array( 'ifthenpay.com', 'pinpay.pt' ) as $domain ) {
			if ( $domain === $host || str_ends_with( $host, '.' . $domain ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The message customers see on Tutor's "order failed" page. Tutor carries it in the redirect URL,
	 * where wp_sanitize_redirect() drops apostrophes and a "#" starts the fragment, so I avoid both.
	 */
	private static function generic_error(): string {
		/* translators: Tutor passes this through a URL, which drops apostrophes and cuts at "#". */
		return __( 'We could not start your ifthenpay payment. Please try again in a moment, or choose another payment method.', 'ifthenpay-payments-for-tutorlms' );
	}
}
