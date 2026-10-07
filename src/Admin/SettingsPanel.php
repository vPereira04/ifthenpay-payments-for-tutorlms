<?php
/**
 * The ifthenpay settings panel inside Tutor's payment card.
 *
 * @package Ifthenpay\TutorLMS
 */

declare(strict_types=1);

namespace Ifthenpay\TutorLMS\Admin;

use Ifthenpay\TutorLMS\Methods;
use Ifthenpay\TutorLMS\Plugin;
use Ifthenpay\TutorLMS\Settings\SettingsRepository;
use Ifthenpay\TutorLMS\Sync\GatewaySync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders our settings (Connect, Gateway Key, methods table, description, expiry) and the
 * Connect modal, and loads the script that mounts them inside Tutor's ifthenpay card.
 *
 * Why the mounting dance: Tutor's payment cards are a compiled React bundle
 * (assets/js/tutor-payment-settings.js) that only knows its own field types (text, select,
 * secret_key, textarea, webhook_url, image), binds them to its payment_settings JSON by
 * position, and has no slot for custom markup. So the card itself (PaymentCard) only
 * declares a webhook_url field, I print the panel in a <template> in the footer, and
 * assets/js/admin.js moves it into the card body, re-mounting it if React re-renders.
 * The panel saves through our own AJAX (Ajax\Controller) into our own options, never into
 * Tutor's payment_settings.
 *
 * @phpstan-import-type MethodRow from SettingsRepository
 */
final class SettingsPanel {

	public const SCRIPT_HANDLE = 'iftp-tutor-admin';
	public const NONCE_ACTION  = 'iftp_tutor_admin';

	private const SETTINGS_PAGE = 'tutor_settings';

	/**
	 * Settings storage.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Gateway Key + methods sync.
	 *
	 * @var GatewaySync
	 */
	private GatewaySync $sync;

	/**
	 * Sets up the panel.
	 *
	 * @param SettingsRepository $settings Settings storage.
	 * @param GatewaySync        $sync     Gateway Key + methods sync.
	 */
	public function __construct( SettingsRepository $settings, GatewaySync $sync ) {
		$this->settings = $settings;
		$this->sync     = $sync;
	}

	/**
	 * Wires the hooks.
	 */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_footer', array( $this, 'print_templates' ) );
	}

	/**
	 * Whether this is Tutor LMS → Settings, viewed by someone who can change it. The payment
	 * tab switches client-side, so I load on the whole settings page; the script only acts
	 * once the ifthenpay card exists.
	 */
	public static function is_settings_screen(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks which screen loads the panel.
		$page = sanitize_key( wp_unslash( (string) ( $_GET['page'] ?? '' ) ) );

		return self::SETTINGS_PAGE === $page && current_user_can( 'manage_options' );
	}

	/**
	 * Loads the panel's script and styles on Tutor's settings page only.
	 */
	public function enqueue(): void {
		if ( ! self::is_settings_screen() ) {
			return;
		}

		wp_enqueue_style( self::SCRIPT_HANDLE, IFTP_TUTOR_URL . 'assets/css/admin.css', array(), Plugin::asset_version( 'assets/css/admin.css' ) );
		wp_enqueue_script( self::SCRIPT_HANDLE, IFTP_TUTOR_URL . 'assets/js/admin.js', array( 'jquery' ), Plugin::asset_version( 'assets/js/admin.js' ), true );

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'iftpTutorAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'gateway' => Plugin::GATEWAY,
				'ready'   => $this->settings->is_ready(),
				'i18n'    => array(
					'notReady'          => __( 'ifthenpay stays hidden at checkout until a Gateway Key is chosen and at least one payment method is enabled.', 'ifthenpay-payments-for-tutorlms' ),
					'connecting'        => __( 'Connecting…', 'ifthenpay-payments-for-tutorlms' ),
					'connect'           => __( 'Connect', 'ifthenpay-payments-for-tutorlms' ),
					'enterKey'          => __( 'Please enter your Backoffice Key.', 'ifthenpay-payments-for-tutorlms' ),
					'confirmDisconnect' => __( 'Disconnect ifthenpay? This clears the Backoffice Key, Gateway Key and payment methods saved here.', 'ifthenpay-payments-for-tutorlms' ),
					'saving'            => __( 'Saving…', 'ifthenpay-payments-for-tutorlms' ),
					'saved'             => __( 'All changes saved.', 'ifthenpay-payments-for-tutorlms' ),
					'saveFailed'        => __( 'Your ifthenpay changes could not be saved. Check your connection and try again.', 'ifthenpay-payments-for-tutorlms' ),
					'unsaved'           => __( 'Your ifthenpay changes are still being saved.', 'ifthenpay-payments-for-tutorlms' ),
					'error'             => __( 'Something went wrong. Please try again.', 'ifthenpay-payments-for-tutorlms' ),
					'requesting'        => __( 'Requesting…', 'ifthenpay-payments-for-tutorlms' ),
					'requested'         => __( 'Requested', 'ifthenpay-payments-for-tutorlms' ),
					'requestSent'       => __( 'Activation request sent. ifthenpay will get back to you shortly.', 'ifthenpay-payments-for-tutorlms' ),
					'requestFailed'     => __( 'The activation request could not be sent. Please try again.', 'ifthenpay-payments-for-tutorlms' ),
				),
			)
		);
	}

	/**
	 * Prints the panel (as a template for admin.js to mount) and the Connect modal.
	 */
	public function print_templates(): void {
		if ( ! self::is_settings_screen() ) {
			return;
		}

		// Picks up methods ifthenpay activated since the last visit, without a manual refresh.
		$this->sync->refresh();
		?>
		<template id="iftp-tutor-panel-template">
			<?php $this->render_panel(); ?>
		</template>
		<?php
		$this->render_connect_modal();
	}

	/**
	 * The whole panel.
	 */
	public function render_panel(): void {
		$connected = $this->settings->is_connected();
		?>
		<div class="iftp-tutor-panel" id="iftp-tutor-panel">
			<div class="iftp-tutor-panel__section">
				<div class="iftp-tutor-panel__heading">
					<span class="iftp-tutor-panel__title"><?php esc_html_e( 'Backoffice Key', 'ifthenpay-payments-for-tutorlms' ); ?></span>
					<span class="iftp-tutor-panel__hint"><?php esc_html_e( 'Connect your ifthenpay account to load your Gateway Keys and payment methods.', 'ifthenpay-payments-for-tutorlms' ); ?></span>
				</div>
				<div id="iftp-tutor-connection">
					<?php echo $this->connection_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts in connection_html(). ?>
				</div>
			</div>

			<div class="iftp-tutor-panel__config" data-iftp-tutor-config<?php echo $connected ? '' : ' hidden'; ?>>
				<div class="iftp-tutor-panel__section">
					<label class="iftp-tutor-panel__title" for="iftp-tutor-gateway-key"><?php esc_html_e( 'Gateway Key', 'ifthenpay-payments-for-tutorlms' ); ?></label>
					<select id="iftp-tutor-gateway-key" class="tutor-form-control iftp-tutor-panel__select" data-iftp-tutor-gateway-key>
						<?php echo $this->gateway_key_options_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts in gateway_key_options_html(). ?>
					</select>
					<span class="iftp-tutor-panel__hint"><?php esc_html_e( 'The ifthenpay Gateway Key for Tutor LMS. Changing it reloads the methods below.', 'ifthenpay-payments-for-tutorlms' ); ?></span>
				</div>

				<div class="iftp-tutor-panel__section">
					<span class="iftp-tutor-panel__title"><?php esc_html_e( 'Payment methods', 'ifthenpay-payments-for-tutorlms' ); ?></span>
					<div id="iftp-tutor-methods">
						<?php echo $this->methods_table_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts in methods_table_html(). ?>
					</div>
				</div>

				<div class="iftp-tutor-panel__row">
					<div class="iftp-tutor-panel__section">
						<label class="iftp-tutor-panel__title" for="iftp-tutor-description"><?php esc_html_e( 'Payment description', 'ifthenpay-payments-for-tutorlms' ); ?></label>
						<input type="text" id="iftp-tutor-description" class="tutor-form-control iftp-tutor-panel__input" maxlength="200" value="<?php echo esc_attr( $this->settings->description() ); ?>" placeholder="<?php echo esc_attr( $this->settings->payment_description() ); ?>" data-iftp-tutor-pref />
						<span class="iftp-tutor-panel__hint"><?php esc_html_e( 'Shown to the customer on the ifthenpay payment page. Empty uses your site name.', 'ifthenpay-payments-for-tutorlms' ); ?></span>
					</div>
					<div class="iftp-tutor-panel__section iftp-tutor-panel__section--narrow">
						<label class="iftp-tutor-panel__title" for="iftp-tutor-expiry-days"><?php esc_html_e( 'Expiry days', 'ifthenpay-payments-for-tutorlms' ); ?></label>
						<input type="number" id="iftp-tutor-expiry-days" class="tutor-form-control iftp-tutor-panel__input" min="1" max="<?php echo esc_attr( (string) SettingsRepository::MAX_EXPIRY_DAYS ); ?>" step="1" value="<?php echo esc_attr( (string) $this->settings->expiry_days() ); ?>" data-iftp-tutor-pref />
						<span class="iftp-tutor-panel__hint"><?php esc_html_e( 'Days an unpaid payment link stays valid.', 'ifthenpay-payments-for-tutorlms' ); ?></span>
					</div>
				</div>

				<p class="iftp-tutor-panel__status" data-iftp-tutor-status role="status" aria-live="polite"></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Connected state + Connect/Disconnect button. The Backoffice Key itself is never printed.
	 */
	public function connection_html(): string {
		ob_start();

		if ( $this->settings->is_connected() ) {
			?>
			<div class="iftp-tutor-connection iftp-tutor-connection--connected">
				<span class="iftp-tutor-connection__state">
					<span class="tutor-icon-circle-mark iftp-tutor-connection__icon" aria-hidden="true"></span>
					<?php esc_html_e( 'Connected', 'ifthenpay-payments-for-tutorlms' ); ?>
				</span>
				<button type="button" class="tutor-btn tutor-btn-outline-primary tutor-btn-sm" data-iftp-tutor-disconnect><?php esc_html_e( 'Disconnect', 'ifthenpay-payments-for-tutorlms' ); ?></button>
			</div>
			<?php
		} else {
			?>
			<div class="iftp-tutor-connection">
				<span class="iftp-tutor-connection__state iftp-tutor-connection__state--off"><?php esc_html_e( 'Not connected', 'ifthenpay-payments-for-tutorlms' ); ?></span>
				<button type="button" class="tutor-btn tutor-btn-primary tutor-btn-sm" data-iftp-tutor-open-connect aria-haspopup="dialog" aria-controls="iftp-tutor-connect-modal"><?php esc_html_e( 'Connect', 'ifthenpay-payments-for-tutorlms' ); ?></button>
			</div>
			<?php
		}

		return (string) ob_get_clean();
	}

	/**
	 * The Gateway Key `<option>`s.
	 */
	public function gateway_key_options_html(): string {
		$options  = $this->sync->gateway_key_options();
		$selected = $this->settings->gateway_key();
		$html     = '';

		if ( array() === $options ) {
			return '<option value="">' . esc_html__( 'No Tutor LMS Gateway Key found. Ask ifthenpay to create one.', 'ifthenpay-payments-for-tutorlms' ) . '</option>';
		}

		if ( '' === $selected ) {
			$html .= '<option value="">' . esc_html__( 'Choose a Gateway Key', 'ifthenpay-payments-for-tutorlms' ) . '</option>';
		}

		foreach ( $options as $key => $label ) {
			$html .= '<option value="' . esc_attr( $key ) . '"' . selected( $key, $selected, false ) . '>' . esc_html( $label ) . '</option>';
		}

		return $html;
	}

	/**
	 * The methods table: Enabled | Default (star) | Method | Account / Request Activation.
	 * The stars are one radio group, so the browser keeps a single default and a click on the
	 * starred one can't clear it.
	 */
	public function methods_table_html(): string {
		$gateway_key = $this->settings->gateway_key();
		$methods     = $this->settings->methods();

		if ( '' === $gateway_key ) {
			return '<p class="iftp-tutor-panel__empty">' . esc_html__( 'Choose a Gateway Key to see its payment methods.', 'ifthenpay-payments-for-tutorlms' ) . '</p>';
		}

		if ( array() === $methods ) {
			return '<p class="iftp-tutor-panel__empty">' . esc_html__( 'No payment methods found for this Gateway Key.', 'ifthenpay-payments-for-tutorlms' ) . '</p>';
		}

		$default = $this->settings->default_method();

		ob_start();
		?>
		<table class="tutor-table iftp-tutor-methods">
			<thead>
				<tr>
					<th scope="col" class="iftp-tutor-methods__control iftp-tutor-methods__control--enabled"><?php esc_html_e( 'Enabled', 'ifthenpay-payments-for-tutorlms' ); ?></th>
					<th scope="col" class="iftp-tutor-methods__control"><?php esc_html_e( 'Default Method', 'ifthenpay-payments-for-tutorlms' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Method', 'ifthenpay-payments-for-tutorlms' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Account', 'ifthenpay-payments-for-tutorlms' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $methods as $method ) : ?>
					<?php $this->render_method_row( $method, $gateway_key, $default ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="iftp-tutor-panel__hint"><?php esc_html_e( 'Click the star next to an enabled method to pre-select it on the ifthenpay payment page.', 'ifthenpay-payments-for-tutorlms' ); ?></p>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * One methods-table row.
	 *
	 * @param array<string, mixed> $method         The method.
	 * @param string               $gateway_key    The selected Gateway Key.
	 * @param string               $default_method The default method's entity, or ''.
	 *
	 * @phpstan-param MethodRow $method
	 */
	private function render_method_row( array $method, string $gateway_key, string $default_method ): void {
		$entity      = $method['entity'];
		$provisioned = '' !== $method['account'];
		$enabled     = $provisioned && $method['enabled'];
		$is_default  = $enabled && $entity === $default_method;
		$label       = Methods::label( $entity );
		$star_id     = 'iftp-tutor-star-' . strtolower( $entity );
		?>
		<tr class="iftp-tutor-methods__row<?php echo $provisioned ? '' : ' iftp-tutor-methods__row--off'; ?>">
			<td class="iftp-tutor-methods__control iftp-tutor-methods__control--enabled">
				<input
					type="checkbox"
					class="tutor-form-check-input iftp-tutor-methods__enabled"
					value="<?php echo esc_attr( $entity ); ?>"
					aria-label="<?php echo esc_attr( sprintf( /* translators: %s: payment method name. */ __( 'Enable %s', 'ifthenpay-payments-for-tutorlms' ), $label ) ); ?>"
					data-iftp-tutor-enabled
					<?php checked( $enabled ); ?>
					<?php disabled( ! $provisioned ); ?>
				/>
			</td>
			<td class="iftp-tutor-methods__control">
				<?php if ( $provisioned ) : ?>
					<input
						type="radio"
						id="<?php echo esc_attr( $star_id ); ?>"
						class="screen-reader-text"
						name="iftp_tutor_default_method"
						value="<?php echo esc_attr( $entity ); ?>"
						data-iftp-tutor-star
						<?php checked( $is_default ); ?>
						<?php disabled( ! $enabled ); ?>
					/>
					<label for="<?php echo esc_attr( $star_id ); ?>" class="iftp-tutor-star<?php echo $enabled ? '' : ' iftp-tutor-star--hidden'; ?>" title="<?php esc_attr_e( 'Set as default payment method', 'ifthenpay-payments-for-tutorlms' ); ?>">
						<span class="iftp-tutor-star__icon <?php echo $is_default ? 'tutor-icon-star-bold' : 'tutor-icon-star-line'; ?>" aria-hidden="true"></span>
						<span class="screen-reader-text"><?php echo esc_html( sprintf( /* translators: %s: payment method name. */ __( 'Make %s the default payment method', 'ifthenpay-payments-for-tutorlms' ), $label ) ); ?></span>
					</label>
				<?php endif; ?>
			</td>
			<td>
				<span class="iftp-tutor-methods__method">
					<span class="iftp-tutor-methods__logo-slot">
						<?php if ( '' !== $method['logo'] ) : ?>
							<img src="<?php echo esc_url( $method['logo'] ); ?>" alt="" class="iftp-tutor-methods__logo" />
						<?php endif; ?>
					</span>
					<span class="iftp-tutor-methods__label"><?php echo esc_html( $label ); ?></span>
				</span>
			</td>
			<td>
				<?php if ( $provisioned ) : ?>
					<span class="iftp-tutor-methods__pill"><?php echo esc_html( $method['account'] ); ?></span>
				<?php else : ?>
					<?php $requested = $this->settings->is_activation_requested( $gateway_key, $entity ); ?>
					<span class="iftp-tutor-methods__activation">
						<span class="iftp-tutor-methods__status"><?php esc_html_e( 'Not activated', 'ifthenpay-payments-for-tutorlms' ); ?></span>
						<button type="button" class="tutor-btn tutor-btn-outline-primary tutor-btn-sm" data-iftp-tutor-request-activation data-entity="<?php echo esc_attr( $entity ); ?>"<?php disabled( $requested ); ?>>
							<?php echo $requested ? esc_html__( 'Requested', 'ifthenpay-payments-for-tutorlms' ) : esc_html__( 'Request Activation', 'ifthenpay-payments-for-tutorlms' ); ?>
						</button>
					</span>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * The Connect modal, in Tutor's own modal markup. admin.js owns its open/closed state.
	 */
	private function render_connect_modal(): void {
		?>
		<div class="tutor-modal iftp-tutor-modal" id="iftp-tutor-connect-modal" role="dialog" aria-modal="true" aria-labelledby="iftp-tutor-connect-title" hidden>
			<div class="tutor-modal-overlay" data-iftp-tutor-close-connect></div>
			<div class="tutor-modal-window tutor-modal-window-md">
				<div class="tutor-modal-content tutor-modal-content-white">
					<div class="tutor-modal-header">
						<h3 class="tutor-modal-title iftp-tutor-modal__title" id="iftp-tutor-connect-title">
							<img class="iftp-tutor-modal__icon" src="<?php echo esc_url( IFTP_TUTOR_URL . 'assets/img/icon-normal.svg' ); ?>" alt="" width="28" height="28" />
							<?php esc_html_e( 'Connect to ifthenpay', 'ifthenpay-payments-for-tutorlms' ); ?>
						</h3>
						<button type="button" class="tutor-iconic-btn" data-iftp-tutor-close-connect aria-label="<?php esc_attr_e( 'Close', 'ifthenpay-payments-for-tutorlms' ); ?>">
							<span class="tutor-icon-times" aria-hidden="true"></span>
						</button>
					</div>
					<div class="tutor-modal-body">
						<label class="iftp-tutor-panel__title" for="iftp-tutor-backoffice-key"><?php esc_html_e( 'Backoffice Key', 'ifthenpay-payments-for-tutorlms' ); ?></label>
						<input type="text" id="iftp-tutor-backoffice-key" class="tutor-form-control iftp-tutor-panel__input" placeholder="0000-0000-0000-0000" autocomplete="off" spellcheck="false" aria-describedby="iftp-tutor-backoffice-key-help iftp-tutor-connect-message" />
						<span id="iftp-tutor-backoffice-key-help" class="iftp-tutor-panel__hint"><?php esc_html_e( 'The 16-digit key from your ifthenpay Backoffice account.', 'ifthenpay-payments-for-tutorlms' ); ?></span>
						<p id="iftp-tutor-connect-message" class="iftp-tutor-modal__message" role="alert"></p>
					</div>
					<div class="tutor-modal-footer">
						<button type="button" class="tutor-btn tutor-btn-ghost" data-iftp-tutor-close-connect><?php esc_html_e( 'Cancel', 'ifthenpay-payments-for-tutorlms' ); ?></button>
						<button type="button" class="tutor-btn tutor-btn-primary" id="iftp-tutor-connect-submit"><?php esc_html_e( 'Connect', 'ifthenpay-payments-for-tutorlms' ); ?></button>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}
