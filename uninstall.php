<?php
/**
 * Uninstall routine.
 *
 * Nothing is removed unless the user explicitly opted in through the plugin
 * settings (§14). Analytics data is expensive to rebuild, and a deactivation
 * is not consent to destroy it.
 *
 * @package DFX\CouponAAW
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$dfxcaaw_settings = get_option( 'dfxcaaw_settings' );

if ( ! is_array( $dfxcaaw_settings ) || empty( $dfxcaaw_settings['delete_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;

$dfxcaaw_table = $wpdb->prefix . 'dfxcaaw_coupon_stats';
$dfxcaaw_sql   = $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $dfxcaaw_table );

if ( is_string( $dfxcaaw_sql ) ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query( $dfxcaaw_sql );
}

delete_option( 'dfxcaaw_settings' );
delete_option( 'dfxcaaw_db_version' );
