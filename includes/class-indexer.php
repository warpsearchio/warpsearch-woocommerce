<?php
/**
 * Incremental indexing: collects changed product ids during a request and writes them in one bulk at shutdown.
 *
 * @package WarpSearch
 */

defined( 'ABSPATH' ) || exit;

class WarpSearch_WC_Indexer {
	const RETRY_HOOK = 'warpsearch_wc_sync_products';

	/** @var int[] */
	private static $pending_index = array();
	/** @var int[] */
	private static $pending_delete = array();
	private static $registered = false;

	public static function builder() {
		$s = WarpSearch_WC_Settings::all();
		return new WarpSearch_WC_Document_Builder( new WarpSearch_WC_Field_Names( (bool) $s['english_analysis'] ), $s['index_alias'] );
	}

	public static function register_hooks() {
		if ( ! WarpSearch_WC_Settings::get( 'auto_index' ) ) {
			return;
		}
		add_action( 'woocommerce_new_product', array( __CLASS__, 'queue_index' ) );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'queue_index' ) );
		add_action( 'woocommerce_new_product_variation', array( __CLASS__, 'queue_index' ) );
		add_action( 'woocommerce_update_product_variation', array( __CLASS__, 'queue_index' ) );
		// Stock changes (orders, REST, bulk edit) do not always fire the update hooks.
		add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'queue_index_from_product' ) );
		add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'queue_index_from_product' ) );
		add_action( 'woocommerce_product_set_stock_status', array( __CLASS__, 'queue_index' ) );
		add_action( 'woocommerce_variation_set_stock_status', array( __CLASS__, 'queue_index' ) );
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition' ), 10, 3 );
		add_action( 'wp_trash_post', array( __CLASS__, 'queue_delete' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'queue_delete' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'queue_index' ) );
		add_action( self::RETRY_HOOK, array( __CLASS__, 'retry' ), 10, 2 );
	}

	private static function ensure_shutdown() {
		if ( ! self::$registered ) {
			self::$registered = true;
			add_action( 'shutdown', array( __CLASS__, 'flush' ) );
		}
	}

	private static function resolve( $id ) {
		$type = get_post_type( $id );
		if ( 'product_variation' === $type ) {
			return (int) wp_get_post_parent_id( $id );
		}
		return 'product' === $type ? (int) $id : 0;
	}

	public static function queue_index( $id ) {
		$id = self::resolve( $id );
		if ( $id ) {
			self::$pending_index[ $id ] = $id;
			unset( self::$pending_delete[ $id ] );
			self::ensure_shutdown();
		}
	}

	public static function queue_index_from_product( $product ) {
		if ( $product instanceof WC_Product ) {
			self::queue_index( $product->get_id() );
		}
	}

	public static function queue_delete( $id ) {
		if ( 'product' === get_post_type( $id ) ) {
			self::$pending_delete[ (int) $id ] = (int) $id;
			unset( self::$pending_index[ (int) $id ] );
			self::ensure_shutdown();
		} elseif ( 'product_variation' === get_post_type( $id ) ) {
			self::queue_index( $id ); // The parent document changes.
		}
	}

	public static function on_transition( $new, $old, $post ) {
		if ( 'product' === $post->post_type && 'publish' === $old && 'publish' !== $new ) {
			self::queue_delete( $post->ID );
		}
	}

	public static function flush() {
		$index  = array_values( self::$pending_index );
		$delete = array_values( self::$pending_delete );
		self::$pending_index = self::$pending_delete = array();
		if ( ! $index && ! $delete ) {
			return;
		}
		$result = self::sync( $index, $delete );
		if ( self::should_retry( $result ) ) {
			self::schedule_retry( $index, $delete );
		}
	}

	/**
	 * Write the given products now. Products that are not indexable (draft, hidden, gone) are deleted.
	 *
	 * @return true|WP_Error
	 */
	public static function sync( $index_ids, $delete_ids = array() ) {
		$builder = self::builder();
		// Writes during a full reindex carry its run id so the final stale cleanup keeps them.
		$state   = WarpSearch_WC_Reindexer::state();
		$run     = ( isset( $state['status'], $state['run'] ) && 'running' === $state['status'] ) ? $state['run'] : '';
		$docs    = array();
		$delete  = array();
		foreach ( (array) $delete_ids as $id ) {
			$delete[] = $builder->document_id( $id );
		}
		foreach ( (array) $index_ids as $id ) {
			$data = WarpSearch_WC_Product_Extractor::extract( (int) $id );
			if ( $data && WarpSearch_WC_Product_Extractor::is_indexable( $data ) ) {
				$docs[ $builder->document_id( $id ) ] = $builder->build( $data, $run );
			} else {
				$delete[] = $builder->document_id( $id );
			}
		}
		$res = self::write( $docs, $delete );
		return $res;
	}

	/** @return true|WP_Error */
	public static function write( array $docs, array $delete ) {
		if ( '' === (string) WarpSearch_WC_Settings::get( 'writer_key' ) ) {
			return new WP_Error( 'warpsearch_no_key', 'No writer key' );
		}
		$res = WarpSearch_WC_Client::for_writing()->bulk( $docs, $delete );
		if ( is_wp_error( $res ) ) {
			self::log( $res->get_error_message() );
			return $res;
		}
		if ( $res ) {
			self::log( 'Bulk write had failures: ' . wp_json_encode( array_slice( $res, 0, 5 ) ) );
			return new WP_Error( 'warpsearch_bulk_failed', 'Some documents failed to index' );
		}
		return true;
	}

	/** Without a writer key nothing can succeed; a full reindex catches up once the plugin is configured. */
	private static function should_retry( $result ) {
		return is_wp_error( $result ) && 'warpsearch_no_key' !== $result->get_error_code();
	}

	private static function schedule_retry( $index, $delete ) {
		$args = array( $index, $delete );
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + 300, self::RETRY_HOOK, $args, 'warpsearch-wc' );
		} else {
			wp_schedule_single_event( time() + 300, self::RETRY_HOOK, $args );
		}
	}

	public static function retry( $index, $delete = array() ) {
		// Re-read current state: a delayed delete must not remove a since-republished product.
		$ids = array_values( array_unique( array_merge( (array) $index, (array) $delete ) ) );
		if ( self::should_retry( self::sync( $ids ) ) ) {
			self::schedule_retry( $index, $delete );
		}
	}

	public static function log( $message ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( $message, array( 'source' => 'warpsearch' ) );
		}
	}
}
