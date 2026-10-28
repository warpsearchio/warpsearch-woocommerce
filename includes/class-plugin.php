<?php
/**
 * Bootstrap.
 *
 * @package WarpSearch
 */

defined( 'ABSPATH' ) || exit;

class WarpSearch_WC_Plugin {
	public static function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', function () {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'WarpSearch for WooCommerce requires WooCommerce.', 'warpsearch-for-woocommerce' ) . '</p></div>';
			} );
			return;
		}
		WarpSearch_WC_Admin::init();
		WarpSearch_WC_Indexer::register_hooks();
		WarpSearch_WC_Reindexer::init();
		WarpSearch_WC_Search::init();
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'warpsearch', 'WarpSearch_WC_CLI' );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( WarpSearch_WC_Reindexer::STEP_HOOK );
		wp_clear_scheduled_hook( WarpSearch_WC_Indexer::RETRY_HOOK );
	}
}
