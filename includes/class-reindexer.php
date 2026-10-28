<?php
/**
 * Batched full reindex. State lives in an option; each step indexes one batch and schedules the next
 * (Action Scheduler when available, WP-Cron otherwise). WP-CLI drives steps in a loop.
 *
 * The reindex never wipes the index first: every document of a run carries the run id, and when the run
 * finishes, documents of this index with a different run id are deleted (so search stays complete meanwhile).
 *
 * @package WarpSearch
 */

defined( 'ABSPATH' ) || exit;

class WarpSearch_WC_Reindexer {
	const STATE_OPTION = 'warpsearch_wc_reindex';
	const STEP_HOOK    = 'warpsearch_wc_reindex_step';
	const STALE_PAGE   = 50; // Same default as the Umbraco provider's DeleteLookupPageSize.

	public static function init() {
		add_action( self::STEP_HOOK, array( __CLASS__, 'step' ) );
	}

	public static function state() {
		$s = get_option( self::STATE_OPTION, array() );
		return is_array( $s ) ? $s : array();
	}

	public static function is_running() {
		$s = self::state();
		return isset( $s['status'] ) && 'running' === $s['status'];
	}

	public static function count_products() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off count for progress; must be current, so uncached.
		return (int) $wpdb->get_var( "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish'" );
	}

	/** Start a run and schedule its first step (unless $schedule is false, for WP-CLI). */
	public static function start( $schedule = true ) {
		$state = array(
			'status'  => 'running',
			'run'     => gmdate( 'YmdHis' ) . wp_generate_password( 4, false ),
			'last_id' => 0,
			'total'   => self::count_products(),
			'done'    => 0,
			'failed'  => 0,
			'started' => time(),
			'message' => '',
		);
		update_option( self::STATE_OPTION, $state, false );
		if ( $schedule ) {
			self::schedule( 0 );
		}
		return $state;
	}

	public static function cancel() {
		$state = self::state();
		if ( $state ) {
			$state['status'] = 'cancelled';
			update_option( self::STATE_OPTION, $state, false );
		}
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::STEP_HOOK );
		}
		wp_clear_scheduled_hook( self::STEP_HOOK );
	}

	private static function schedule( $delay ) {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + $delay, self::STEP_HOOK, array(), 'warpsearch-wc' );
		} else {
			wp_schedule_single_event( time() + max( 1, $delay ), self::STEP_HOOK );
		}
	}

	/**
	 * Process one batch.
	 *
	 * @return array The updated state; status is 'running', 'completed' or 'failed'.
	 */
	public static function step() {
		global $wpdb;
		$state = self::state();
		if ( empty( $state['status'] ) || 'running' !== $state['status'] ) {
			return $state;
		}
		$size = (int) WarpSearch_WC_Settings::get( 'batch_size' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- keyset pagination over product ids for a batch job; caching would skip new products.
		$ids  = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish' AND ID > %d ORDER BY ID ASC LIMIT %d", $state['last_id'], $size ) );

		if ( ! $ids ) {
			return self::finish( $state );
		}
		$builder = WarpSearch_WC_Indexer::builder();
		$docs    = array();
		foreach ( $ids as $id ) {
			$data = WarpSearch_WC_Product_Extractor::extract( (int) $id );
			if ( $data && WarpSearch_WC_Product_Extractor::is_indexable( $data ) ) {
				$docs[ $builder->document_id( $id ) ] = $builder->build( $data, $state['run'] );
			}
		}
		$res = WarpSearch_WC_Indexer::write( $docs, array() );
		if ( is_wp_error( $res ) ) {
			$state['status']  = 'failed';
			$state['message'] = $res->get_error_message();
			update_option( self::STATE_OPTION, $state, false );
			return $state;
		}
		$state['last_id'] = (int) end( $ids );
		$state['done']   += count( $ids );
		update_option( self::STATE_OPTION, $state, false );
		self::schedule( 1 );
		return $state;
	}

	private static function finish( array $state ) {
		$client = WarpSearch_WC_Client::for_writing();
		// The run marker must be searchable before querying older generations. Otherwise cleanup
		// can find an old version of an ID and delete the freshly indexed replacement.
		$refresh = $client->refresh();
		if ( is_wp_error( $refresh ) ) {
			$state['status'] = 'failed';
			$state['message'] = 'Refresh failed; stale cleanup was not run.';
			update_option( self::STATE_OPTION, $state, false );
			return $state;
		}
		$qb     = new WarpSearch_WC_Query_Builder( new WarpSearch_WC_Field_Names(), WarpSearch_WC_Settings::get( 'index_alias' ) );
		$seen   = array();
		while ( true ) {
			$res = $client->search( $qb->stale( $state['run'], self::STALE_PAGE ) );
			if ( is_wp_error( $res ) ) {
				$state['status']  = 'failed';
				$state['message'] = 'Stale cleanup failed: ' . $res->get_error_message();
				update_option( self::STATE_OPTION, $state, false );
				return $state;
			}
			$ids = array();
			foreach ( isset( $res['hits']['hits'] ) ? $res['hits']['hits'] : array() as $hit ) {
				if ( isset( $hit['_id'] ) ) {
					$ids[] = $hit['_id'];
				}
			}
			// Stop when nothing is left or deletes do not become visible (avoids looping forever).
			if ( ! $ids ) {
				break;
			}
			if ( ! array_diff( $ids, $seen ) ) {
				$state['status'] = 'failed';
				$state['message'] = 'Stale cleanup did not make progress. Retry the reindex.';
				update_option( self::STATE_OPTION, $state, false );
				return $state;
			}
			$del = $client->delete_documents( $ids, true );
			if ( is_wp_error( $del ) ) {
				$state['status']  = 'failed';
				$state['message'] = 'Stale cleanup failed: ' . $del->get_error_message();
				update_option( self::STATE_OPTION, $state, false );
				return $state;
			}
			$seen = array_merge( $seen, $ids );
		}
		$state['status']   = 'completed';
		$state['finished'] = time();
		update_option( self::STATE_OPTION, $state, false );
		return $state;
	}
}
