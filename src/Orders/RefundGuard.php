<?php
/**
 * Keeps Tutor's refunds away from ifthenpay orders.
 *
 * @package Ifthenpay\TutorLMS
 */

declare(strict_types=1);

namespace Ifthenpay\TutorLMS\Orders;

use Ifthenpay\TutorLMS\Plugin;
use RuntimeException;
use Tutor\Models\OrderModel;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hides the Refund button on an ifthenpay order and turns down Tutor's refund AJAX for it,
 * since ifthenpay doesn't support refunds.
 *
 * Tutor's order details are a compiled React app with no filter for the button, so
 * assets/js/order-refund.js removes it once React draws it.
 */
final class RefundGuard {

	public const HANDLE = 'iftp-tutor-order-refund';

	/**
	 * Tutor's Orders admin page slug.
	 */
	private const ORDERS_PAGE = 'tutor_orders';

	/**
	 * Wires the Tutor hooks.
	 */
	public function register(): void {
		add_action( 'tutor_before_initiate_refund', array( $this, 'block_refund' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ), 20 );
	}

	/**
	 * Stops a refund before Tutor records it. Tutor catches the exception and sends its message back.
	 *
	 * @param mixed $order Tutor's order row.
	 * @throws RuntimeException When the order was paid through ifthenpay.
	 */
	public function block_refund( $order ): void {
		if ( is_object( $order ) && Plugin::GATEWAY === ( $order->payment_method ?? '' ) ) {
			throw new RuntimeException( esc_html__( 'ifthenpay orders cannot be refunded from Tutor LMS. Please refund the customer directly.', 'ifthenpay-payments-for-tutorlms' ) );
		}
	}

	/**
	 * Loads the button remover on an ifthenpay order's details screen only.
	 */
	public function enqueue(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- I only read the screen's URL to pick the order.
		$page     = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$action   = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		$order_id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( self::ORDERS_PAGE !== $page || 'edit' !== $action || $order_id <= 0 || ! wp_script_is( 'tutor-order-details', 'enqueued' ) ) {
			return;
		}

		$order = ( new OrderModel() )->get_order_by_id( $order_id );

		if ( ! is_object( $order ) || Plugin::GATEWAY !== ( $order->payment_method ?? '' ) ) {
			return;
		}

		wp_enqueue_script( self::HANDLE, IFTP_TUTOR_URL . 'assets/js/order-refund.js', array( 'wp-i18n', 'tutor-order-details' ), Plugin::asset_version( 'assets/js/order-refund.js' ), true );
	}
}
