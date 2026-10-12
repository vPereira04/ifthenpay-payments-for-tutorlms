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
 *
 * Tutor renders that page once, so assets/js/order-status.js keeps asking whether the callback
 * has marked the order paid, and puts Tutor's own message back when it has.
 */
final class OrderDisplay {

	public const STATUS_HANDLE = 'iftp-tutor-order-status';

	private const STATUS_ACTION = 'iftp_tutor_order_status';

	/**
	 * Wires the Tutor filters.
	 */
	public function register(): void {
		add_filter( 'tutor_payment_method_labels', array( $this, 'add_label' ) );
		add_filter( 'tutor_order_details', array( $this, 'label_paid_method' ) );
		add_filter( 'tutor_order_placement_success_message', array( $this, 'pending_message' ), 10, 2 );
		add_action( 'wp_ajax_' . self::STATUS_ACTION, array( $this, 'order_status' ) );
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
	 * e.g. a Multibanco reference the customer hasn't paid yet, and starts watching for the payment.
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

		$this->enqueue_status_poll( absint( $order_id ), is_string( $message ) ? $message : '' );

		return __( 'Thank you! We are waiting for ifthenpay to confirm your payment, and your course access opens as soon as it does. If you chose Multibanco or Payshop, that happens once you pay the reference.', 'ifthenpay-payments-for-tutorlms' );
	}

	/**
	 * Tells order-status.js whether the customer's own ifthenpay order is paid yet.
	 */
	public function order_status(): void {
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;

		check_ajax_referer( self::STATUS_ACTION . '_' . $order_id, 'nonce' );

		$order = $order_id > 0 ? ( new OrderModel() )->get_order_by_id( $order_id ) : false;

		if ( ! is_object( $order ) || Plugin::GATEWAY !== ( $order->payment_method ?? '' ) || get_current_user_id() !== (int) ( $order->user_id ?? 0 ) ) {
			wp_send_json_error( null, 404 );
		}

		wp_send_json_success( array( 'paid' => OrderModel::PAYMENT_PAID === ( $order->payment_status ?? '' ) ) );
	}

	/**
	 * Loads the poller for the success page. Enqueued mid-page, so WordPress prints it in the footer.
	 *
	 * @param int    $order_id     The pending order.
	 * @param string $paid_message Tutor's message, shown once the payment lands.
	 */
	private function enqueue_status_poll( int $order_id, string $paid_message ): void {
		if ( ! is_user_logged_in() || '' === $paid_message ) {
			return;
		}

		wp_enqueue_script( self::STATUS_HANDLE, IFTP_TUTOR_URL . 'assets/js/order-status.js', array(), Plugin::asset_version( 'assets/js/order-status.js' ), true );
		wp_localize_script(
			self::STATUS_HANDLE,
			'iftpTutorOrderStatus',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'action'      => self::STATUS_ACTION,
				'nonce'       => wp_create_nonce( self::STATUS_ACTION . '_' . $order_id ),
				'orderId'     => $order_id,
				'paidMessage' => $paid_message,
			)
		);
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
