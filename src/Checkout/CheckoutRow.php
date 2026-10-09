<?php
/**
 * The ifthenpay row on Tutor's checkout.
 *
 * @package Ifthenpay\TutorLMS
 */

declare(strict_types=1);

namespace Ifthenpay\TutorLMS\Checkout;

use Ifthenpay\TutorLMS\Methods;
use Ifthenpay\TutorLMS\Plugin;
use Ifthenpay\TutorLMS\Settings\SettingsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shows "ifthenpay icon + name | enabled method logos" in the ifthenpay checkout row.
 *
 * Tutor's templates/ecommerce/checkout.php prints each gateway as `<img icon> label` with no
 * hook, so I append the row content as a <template> to Tutor's checkout output (its
 * `tutor_ecommerce/checkout` filter, which both the shortcode and the checkout page go through)
 * and assets/js/checkout.js swaps it into our row. Without JS the row keeps Tutor's icon + label.
 */
final class CheckoutRow {

	public const HANDLE = 'iftp-tutor-checkout';

	/**
	 * Settings storage.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Sets up the row.
	 *
	 * @param SettingsRepository $settings Settings storage.
	 */
	public function __construct( SettingsRepository $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Wires the hook.
	 */
	public function register(): void {
		add_filter( 'tutor_ecommerce/checkout', array( $this, 'append_template' ) );
	}

	/**
	 * Loads the row's assets and appends its markup, only when ifthenpay is actually on offer.
	 *
	 * @param mixed $html Tutor's checkout markup.
	 * @return mixed
	 */
	public function append_template( $html ) {
		if ( ! is_string( $html ) || ! $this->settings->is_ready() || ! Plugin::is_eur_store() ) {
			return $html;
		}

		// Enqueued mid-page: WordPress prints both in the footer, which is where the script runs anyway.
		wp_enqueue_style( self::HANDLE, IFTP_TUTOR_URL . 'assets/css/checkout.css', array(), Plugin::asset_version( 'assets/css/checkout.css' ) );
		wp_enqueue_script( self::HANDLE, IFTP_TUTOR_URL . 'assets/js/checkout.js', array(), Plugin::asset_version( 'assets/js/checkout.js' ), true );

		return $html . $this->template_html();
	}

	/**
	 * The row content for checkout.js.
	 */
	private function template_html(): string {
		$methods = $this->settings->enabled_methods();

		ob_start();
		?>
		<template id="iftp-tutor-checkout-row" data-iftp-tutor-gateway="<?php echo esc_attr( Plugin::GATEWAY ); ?>">
			<span class="iftp-tutor-checkout">
				<span class="iftp-tutor-checkout__brand-group">
					<img class="iftp-tutor-checkout__brand" src="<?php echo esc_url( IFTP_TUTOR_URL . 'assets/img/icon-normal.svg' ); ?>" alt="" width="24" height="24" />
				</span>
				<?php if ( array() !== $methods ) : ?>
					<span class="iftp-tutor-checkout__divider" aria-hidden="true"></span>
					<span class="iftp-tutor-checkout__methods">
						<?php foreach ( $methods as $method ) : ?>
							<?php if ( '' !== $method['logo'] ) : ?>
								<img class="iftp-tutor-checkout__method" src="<?php echo esc_url( $method['logo'] ); ?>" alt="<?php echo esc_attr( Methods::label( $method['entity'] ) ); ?>" />
							<?php else : ?>
								<span class="iftp-tutor-checkout__method-name"><?php echo esc_html( Methods::label( $method['entity'] ) ); ?></span>
							<?php endif; ?>
						<?php endforeach; ?>
					</span>
				<?php endif; ?>
			</span>
		</template>
		<?php

		return (string) ob_get_clean();
	}
}
