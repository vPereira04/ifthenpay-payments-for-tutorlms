<?php
/**
 * How ifthenpay orders read in Tutor.
 *
 * @package Ifthenpay\TutorLMS
 */

declare(strict_types=1);

namespace Ifthenpay\TutorLMS\Orders;

use Ifthenpay\TutorLMS\Methods;
use Ifthenpay\TutorLMS\Plugin;
use stdClass;
use Tutor\Models\OrderModel;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Labels ifthenpay orders in Tutor's own Orders screens, and tells the customer on Tutor's
 * success page that ifthenpay still has to confirm the payment.
 */
final class OrderDisplay {

	/**
	 * Wires the Tutor filters.
	 */
	public function register(): void {
		add_filter( 'tutor_payment_method_labels', array( $this, 'add_label' ) );
		add_filter( 'tutor_order_details', array( $this, 'label_paid_method' ) );
		add_filter( 'tutor_order_placement_success_message', array( $this, 'pending_message' ), 10, 2 );
	}

	/**
	 * "ifthenpay" in the Orders list and wherever Tutor names the payment method.
	 *
	 * @param mixed $labels Tutor's method => label map.
	 * @return mixed
	 */
	public function add_label( $labels ) {
		if ( is_array( $labels ) ) {
			$labels[ Plugin::GATEWAY ] = __( 'ifthenpay', 'ifthenpay-payments-for-tutorlms' );
		}

		return $labels;
	}

	/**
	 * "ifthenpay · MB WAY" in the order details' Payment Method box, once the callback told us the method.
	 *
	 * @param mixed $order Tutor's order details object.
	 * @return mixed
	 */
	public function label_paid_method( $order ) {
		if ( ! $order instanceof stdClass || Plugin::GATEWAY !== ( $order->payment_method ?? '' ) ) {
			return $order;
		}

		$method = self::paid_method( (string) ( $order->payment_payloads ?? '' ) );

		if ( '' !== $method ) {
			$order->payment_method_readable = sprintf(
				/* translators: %s: payment method, e.g. "MB WAY". */
				__( 'ifthenpay · %s', 'ifthenpay-payments-for-tutorlms' ),
				Methods::label( $method )
			);
		}

		return $order;
	}

	/**
	 * Swaps Tutor's "confirmation email shortly" line while an ifthenpay order is still unpaid,
	 * e.g. a Multibanco reference the customer hasn't paid yet.
	 *
	 * @param mixed $message  Tutor's message.
	 * @param mixed $order_id The order id from the success URL.
	 * @return mixed
	 */
	public function pending_message( $message, $order_id ) {
		$order = absint( $order_id ) > 0 ? ( new OrderModel() )->get_order_by_id( absint( $order_id ) ) : false;

		if ( ! is_object( $order ) || Plugin::GATEWAY !== ( $order->payment_method ?? '' ) || OrderModel::PAYMENT_PAID === ( $order->payment_status ?? '' ) ) {
			return $message;
		}

		return __( 'Thank you! We are waiting for ifthenpay to confirm your payment, and your course access opens as soon as it does. If you chose Multibanco or Payshop, that happens once you pay the reference.', 'ifthenpay-payments-for-tutorlms' );
	}

	/**
	 * The method code stored with the paid callback, or ''.
	 *
	 * @param string $payloads The order's `payment_payloads` column.
	 */
	private static function paid_method( string $payloads ): string {
		$data = json_decode( $payloads, true );

		// Tutor's own gateways store payloads slashed, so I read both forms.
		if ( ! is_array( $data ) ) {
			$data = json_decode( stripslashes( $payloads ), true );
		}

		return is_array( $data ) && isset( $data['method'] ) && is_string( $data['method'] ) ? $data['method'] : '';
	}
}
