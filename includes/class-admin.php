<?php
/**
 * Settings page (WooCommerce > WarpSearch) with connection test and reindex controls.
 *
 * @package WarpSearch
 */

defined( 'ABSPATH' ) || exit;

class WarpSearch_WC_Admin {
	const SLUG = 'warpsearch-wc';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_warpsearch_wc_reindex', array( __CLASS__, 'handle_reindex' ) );
		add_action( 'admin_post_warpsearch_wc_cancel', array( __CLASS__, 'handle_cancel' ) );
		add_action( 'admin_post_warpsearch_wc_test', array( __CLASS__, 'handle_test' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WARPSEARCH_WC_FILE ), function ( $links ) {
			array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Settings', 'warpsearch-for-woocommerce' ) . '</a>' );
			return $links;
		} );
	}

	public static function menu() {
		add_submenu_page( 'woocommerce', 'WarpSearch', 'WarpSearch', 'manage_woocommerce', self::SLUG, array( __CLASS__, 'render' ) );
	}

	public static function register() {
		register_setting( 'warpsearch_wc', WarpSearch_WC_Settings::OPTION, array( 'sanitize_callback' => array( 'WarpSearch_WC_Settings', 'sanitize' ) ) );
		// options.php requires manage_options unless the option page says otherwise; shop managers own this page.
		add_filter( 'option_page_capability_warpsearch_wc', function () {
			return 'manage_woocommerce';
		} );
	}

	private static function redirect( $notice, $type = 'success' ) {
		set_transient( 'warpsearch_wc_notice_' . get_current_user_id(), array( $type, $notice ), 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'warpsearch-for-woocommerce' ), 403 );
		}
		check_admin_referer( $action );
	}

	public static function handle_reindex() {
		self::guard( 'warpsearch_wc_reindex' );
		if ( '' === (string) WarpSearch_WC_Settings::get( 'writer_key' ) ) {
			self::redirect( __( 'Set a writer key first.', 'warpsearch-for-woocommerce' ), 'error' );
		}
		WarpSearch_WC_Reindexer::start( true );
		self::redirect( __( 'Reindex started. It runs in the background in batches.', 'warpsearch-for-woocommerce' ) );
	}

	public static function handle_cancel() {
		self::guard( 'warpsearch_wc_cancel' );
		WarpSearch_WC_Reindexer::cancel();
		self::redirect( __( 'Reindex cancelled.', 'warpsearch-for-woocommerce' ) );
	}

	public static function handle_test() {
		self::guard( 'warpsearch_wc_test' );
		$info = WarpSearch_WC_Client::for_writing()->get_collection();
		if ( is_wp_error( $info ) ) {
			if ( 'warpsearch_not_found' === $info->get_error_code() ) {
				self::redirect( __( 'Collection not found, or the writer key has no role on it. Create the collection in the WarpSearch console first.', 'warpsearch-for-woocommerce' ), 'error' );
			}
			self::redirect( $info->get_error_message(), 'error' );
		}
		if ( ! in_array( 'write', isset( $info['permissions'] ) ? (array) $info['permissions'] : array(), true ) ) {
			self::redirect( __( 'The key cannot write to this collection. Use a key with the writer role.', 'warpsearch-for-woocommerce' ), 'error' );
		}
		self::redirect( __( 'Connected: the collection exists and the writer key can write.', 'warpsearch-for-woocommerce' ) );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$s     = WarpSearch_WC_Settings::all();
		$state = WarpSearch_WC_Reindexer::state();
		$name  = WarpSearch_WC_Settings::OPTION;
		$notice = get_transient( 'warpsearch_wc_notice_' . get_current_user_id() );
		if ( $notice ) {
			delete_transient( 'warpsearch_wc_notice_' . get_current_user_id() );
			printf( '<div class="notice notice-%s"><p>%s</p></div>', esc_attr( $notice[0] ), esc_html( $notice[1] ) );
		}
		echo '<div class="wrap"><h1>WarpSearch</h1><form method="post" action="options.php">';
		settings_fields( 'warpsearch_wc' );
		echo '<table class="form-table">';
		self::row( __( 'Endpoint', 'warpsearch-for-woocommerce' ), '<input type="url" class="regular-text" name="' . $name . '[endpoint]" value="' . esc_attr( $s['endpoint'] ) . '">' );
		self::row( __( 'Collection', 'warpsearch-for-woocommerce' ), '<input type="text" class="regular-text" name="' . $name . '[collection]" value="' . esc_attr( $s['collection'] ) . '"><p class="description">' . esc_html__( 'Create it in the WarpSearch console first; the plugin never creates collections.', 'warpsearch-for-woocommerce' ) . '</p>' );
		self::row( __( 'Index name', 'warpsearch-for-woocommerce' ), '<input type="text" class="regular-text" name="' . $name . '[index_alias]" value="' . esc_attr( $s['index_alias'] ) . '"><p class="description">' . esc_html__( 'Documents are tagged with this name so several sites or indexes can share one collection. Use a different name per site.', 'warpsearch-for-woocommerce' ) . '</p>' );
		foreach ( array( 'writer_key' => __( 'Writer key', 'warpsearch-for-woocommerce' ), 'search_key' => __( 'Search key', 'warpsearch-for-woocommerce' ) ) as $k => $label ) {
			$has = '' !== (string) $s[ $k ];
			self::row( $label, '<input type="password" autocomplete="new-password" class="regular-text" name="' . $name . '[' . $k . ']" value="" placeholder="' . esc_attr( $has ? __( 'Saved (leave blank to keep)', 'warpsearch-for-woocommerce' ) : '' ) . '">' . ( $has ? ' <label><input type="checkbox" name="' . $name . '[clear_' . $k . ']" value="1"> ' . esc_html__( 'Remove', 'warpsearch-for-woocommerce' ) . '</label>' : '' ) );
		}
		self::row( __( 'Text analysis', 'warpsearch-for-woocommerce' ), '<label><input type="checkbox" name="' . $name . '[english_analysis]" value="1" ' . checked( $s['english_analysis'], 1, false ) . '> ' . esc_html__( 'English (accent folding and stemming). Reindex after changing.', 'warpsearch-for-woocommerce' ) . '</label>' );
		self::row( __( 'Automatic indexing', 'warpsearch-for-woocommerce' ), '<label><input type="checkbox" name="' . $name . '[auto_index]" value="1" ' . checked( $s['auto_index'], 1, false ) . '> ' . esc_html__( 'Update the index when products change', 'warpsearch-for-woocommerce' ) . '</label>' );
		self::row( __( 'Replace store search', 'warpsearch-for-woocommerce' ), '<label><input type="checkbox" name="' . $name . '[replace_search]" value="1" ' . checked( $s['replace_search'], 1, false ) . '> ' . esc_html__( 'Answer the default WooCommerce product search from WarpSearch (falls back to WooCommerce on errors)', 'warpsearch-for-woocommerce' ) . '</label>' );
		self::row( __( 'Reindex batch size', 'warpsearch-for-woocommerce' ), '<input type="number" min="1" max="500" name="' . $name . '[batch_size]" value="' . esc_attr( $s['batch_size'] ) . '"><p class="description">' . esc_html__( 'Keep within your plan\'s documents per request.', 'warpsearch-for-woocommerce' ) . '</p>' );
		self::row( __( 'Results per page', 'warpsearch-for-woocommerce' ), '<input type="number" min="1" max="50" name="' . $name . '[results_per_page]" value="' . esc_attr( $s['results_per_page'] ) . '">' );
		echo '</table>';
		submit_button();
		echo '</form><hr><h2>' . esc_html__( 'Connection and indexing', 'warpsearch-for-woocommerce' ) . '</h2>';

		self::post_button( 'warpsearch_wc_test', __( 'Test connection', 'warpsearch-for-woocommerce' ), false );
		if ( WarpSearch_WC_Reindexer::is_running() ) {
			printf( '<p>%s</p>', esc_html( sprintf( /* translators: 1: done 2: total */ __( 'Reindex running: %1$d of %2$d products.', 'warpsearch-for-woocommerce' ), $state['done'], $state['total'] ) ) );
			self::post_button( 'warpsearch_wc_cancel', __( 'Cancel reindex', 'warpsearch-for-woocommerce' ), false );
		} else {
			self::post_button( 'warpsearch_wc_reindex', __( 'Reindex all products', 'warpsearch-for-woocommerce' ), true );
			if ( ! empty( $state['status'] ) ) {
				printf( '<p>%s</p>', esc_html( sprintf( /* translators: 1: status 2: count 3: message */ __( 'Last reindex: %1$s, %2$d products. %3$s', 'warpsearch-for-woocommerce' ), $state['status'], $state['done'], $state['message'] ) ) );
			}
		}
		echo '<p class="description">' . esc_html__( 'Shortcode: [warpsearch_products]. Block: "WarpSearch Product Search". CLI: wp warpsearch reindex.', 'warpsearch-for-woocommerce' ) . '</p></div>';
	}

	private static function row( $label, $html ) {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $html . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts above.
	}

	private static function post_button( $action, $label, $primary ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:1em">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		wp_nonce_field( $action );
		submit_button( $label, $primary ? 'primary' : 'secondary', 'submit', false );
		echo '</form>';
	}
}
