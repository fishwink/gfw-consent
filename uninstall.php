<?php
/**
 * Uninstall — runs only on explicit "Delete" in WP admin.
 * Drops option rows and the consent log table.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'gfw_consent_settings' );
delete_option( 'gfw_consent_services' );
delete_option( 'gfw_consent_last_scan' );

global $wpdb;
$table = $wpdb->prefix . 'gfw_consent_log';
$wpdb->query( "DROP TABLE IF EXISTS $table" );

wp_clear_scheduled_hook( 'gfw_consent_daily_scan' );
