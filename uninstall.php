<?php
/**
 * Uninstall: remove the option and (by default) the cached avatar files.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'wla_settings' );
wp_clear_scheduled_hook( 'wla_weekly_refresh' );

// Cached avatars are always removed on uninstall: they are regenerable
// copies of public Gravatars, and keeping them would serve stale images
// with no plugin left to refresh them.
$upload = wp_upload_dir();
if ( ! empty( $upload['basedir'] ) ) {
	$dir = trailingslashit( $upload['basedir'] ) . 'local-avatars';
	if ( is_dir( $dir ) ) {
		$files = glob( trailingslashit( $dir ) . '*.jpg' );
		if ( is_array( $files ) ) {
			foreach ( $files as $file ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
}
