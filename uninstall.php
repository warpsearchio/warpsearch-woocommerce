<?php
/**
 * Removes plugin data. Documents already in WarpSearch are left alone (delete the collection in the console).
 *
 * @package WarpSearch
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$warpsearch_wc_delete_site_data = function () {
	delete_option( 'warpsearch_wc_settings' );
	delete_option( 'warpsearch_wc_reindex' );
	wp_clear_scheduled_hook( 'warpsearch_wc_reindex_step' );
	wp_clear_scheduled_hook( 'warpsearch_wc_sync_products' );
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'warpsearch_wc_reindex_step' );
		as_unschedule_all_actions( 'warpsearch_wc_sync_products' );
	}
	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_warpsearch\_wc\_notice\_%' OR option_name LIKE '\_transient\_timeout\_warpsearch\_wc\_notice\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
};

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $warpsearch_wc_blog_id ) {
		switch_to_blog( $warpsearch_wc_blog_id );
		$warpsearch_wc_delete_site_data();
		restore_current_blog();
	}
} else {
	$warpsearch_wc_delete_site_data();
}
