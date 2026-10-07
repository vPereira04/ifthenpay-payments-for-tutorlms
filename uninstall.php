<?php
/**
 * Removes this plugin's own options and caches (the per-method activation cooldowns expire on their own). Tutor's payment settings and its orders
 * (including their ifthenpay meta and timeline entries) stay, since they're Tutor's records.
 *
 * @package Ifthenpay\TutorLMS
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'iftp_tutor_backoffice_key' );
delete_option( 'iftp_tutor_config' );
delete_option( 'iftp_tutor_anti_phishing_keys' );
delete_option( 'iftp_tutor_callback_hash' );
delete_transient( 'iftp_tutor_gateway_rows' );
delete_transient( 'iftp_tutor_methods_catalog' );
delete_transient( 'iftp_tutor_callback_retry_lock' );
