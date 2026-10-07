<?php
/**
 * Payment method labels.
 *
 * @package Ifthenpay\TutorLMS
 */

declare(strict_types=1);

namespace Ifthenpay\TutorLMS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Human labels for ifthenpay method codes, for the admin card and Tutor's order details.
 */
final class Methods {

	/**
	 * The label for a method code, e.g. `MBWAY` → "MB WAY". Unknown codes come back as-is.
	 *
	 * @param string $entity The ifthenpay method code.
	 */
	public static function label( string $entity ): string {
		$labels = array(
			'MB'      => __( 'Multibanco', 'ifthenpay-payments-for-tutorlms' ),
			'MBWAY'   => __( 'MB WAY', 'ifthenpay-payments-for-tutorlms' ),
			'PAYSHOP' => __( 'Payshop', 'ifthenpay-payments-for-tutorlms' ),
			'CCARD'   => __( 'Credit card', 'ifthenpay-payments-for-tutorlms' ),
			'COFIDIS' => __( 'Cofidis Pay', 'ifthenpay-payments-for-tutorlms' ),
			'GOOGLE'  => __( 'Google Pay', 'ifthenpay-payments-for-tutorlms' ),
			'APPLE'   => __( 'Apple Pay', 'ifthenpay-payments-for-tutorlms' ),
			'PIX'     => __( 'Pix', 'ifthenpay-payments-for-tutorlms' ),
			'BIZUM'   => __( 'Bizum', 'ifthenpay-payments-for-tutorlms' ),
		);

		$code = strtoupper( $entity );

		return $labels[ $code ] ?? $entity;
	}
}
