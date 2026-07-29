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

delete_option( 'dfxcaaw_settings' );
delete_option( 'dfxcaaw_db_version' );

/*
 * The aggregates table arrives with milestone 8; its removal belongs here, and
 * under the same opt-in guard.
 */
