<?php
/**
 * Plugin Name:       WarpSearch for WooCommerce
 * Plugin URI:        https://warpsearch.io
 * Description:       Index WooCommerce products in WarpSearch and answer product searches from it.
 * Version:           0.1.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            WarpSearch
 * Author URI:        https://warpsearch.io
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       warpsearch-for-woocommerce
 * WC requires at least: 8.0
 * WC tested up to:   11.2
 *
 * @package WarpSearch
 */

defined( 'ABSPATH' ) || exit;

define( 'WARPSEARCH_WC_VERSION', '0.1.0' );
define( 'WARPSEARCH_WC_FILE', __FILE__ );
define( 'WARPSEARCH_WC_DIR', plugin_dir_path( __FILE__ ) );
define( 'WARPSEARCH_WC_URL', plugin_dir_url( __FILE__ ) );

require_once WARPSEARCH_WC_DIR . 'includes/class-field-names.php';
require_once WARPSEARCH_WC_DIR . 'includes/class-document-builder.php';
require_once WARPSEARCH_WC_DIR . 'includes/class-query-builder.php';
require_once WARPSEARCH_WC_DIR . 'includes/class-settings.php';
require_once WARPSEARCH_WC_DIR . 'includes/class-client.php';
require_once WARPSEARCH_WC_DIR . 'includes/class-product-extractor.php';
require_once WARPSEARCH_WC_DIR . 'includes/class-indexer.php';
require_once WARPSEARCH_WC_DIR . 'includes/class-reindexer.php';
require_once WARPSEARCH_WC_DIR . 'includes/class-search.php';
require_once WARPSEARCH_WC_DIR . 'includes/class-admin.php';
require_once WARPSEARCH_WC_DIR . 'includes/class-plugin.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once WARPSEARCH_WC_DIR . 'includes/class-cli.php';
}

add_action( 'plugins_loaded', array( 'WarpSearch_WC_Plugin', 'init' ) );
// Products are read through the WooCommerce CRUD API and orders are never touched.
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WARPSEARCH_WC_FILE, true );
	}
} );
register_deactivation_hook( __FILE__, array( 'WarpSearch_WC_Plugin', 'deactivate' ) );
