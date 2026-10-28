<?php
/**
 * WP-CLI commands.
 *
 * @package WarpSearch
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manage the WarpSearch product index.
 */
class WarpSearch_WC_CLI {

	/**
	 * Reindex all published products in batches, then remove documents left from earlier runs.
	 *
	 * ## EXAMPLES
	 *
	 *     wp warpsearch reindex
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function reindex( $args, $assoc_args ) {
		WarpSearch_WC_Reindexer::cancel();
		$state = WarpSearch_WC_Reindexer::start( false );
		$bar   = \WP_CLI\Utils\make_progress_bar( 'Indexing products', max( 1, $state['total'] ) );
		$last  = 0;
		do {
			$state = WarpSearch_WC_Reindexer::step();
			// step() schedules the next batch for the background runner; unschedule it, this loop does the work.
			if ( 'running' === $state['status'] ) {
				wp_clear_scheduled_hook( WarpSearch_WC_Reindexer::STEP_HOOK );
				if ( function_exists( 'as_unschedule_all_actions' ) ) {
					as_unschedule_all_actions( WarpSearch_WC_Reindexer::STEP_HOOK );
				}
			}
			for ( $i = $last; $i < $state['done']; $i++ ) {
				$bar->tick();
			}
			$last = $state['done'];
		} while ( 'running' === $state['status'] );
		$bar->finish();
		if ( 'completed' !== $state['status'] ) {
			WP_CLI::error( $state['message'] ? $state['message'] : 'Reindex ' . $state['status'] );
		}
		WP_CLI::success( sprintf( 'Indexed %d products.', $state['done'] ) );
	}

	/**
	 * Index (or remove, if not indexable) specific products.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : Product ids.
	 */
	public function index( $args ) {
		$res = WarpSearch_WC_Indexer::sync( array_map( 'intval', $args ) );
		if ( is_wp_error( $res ) ) {
			WP_CLI::error( $res->get_error_message() );
		}
		WP_CLI::success( 'Done.' );
	}

	/**
	 * Check the collection and the writer key.
	 */
	public function status() {
		$info = WarpSearch_WC_Client::for_writing()->get_collection();
		if ( is_wp_error( $info ) ) {
			WP_CLI::error( $info->get_error_message() );
		}
		$can = isset( $info['permissions'] ) && in_array( 'write', (array) $info['permissions'], true );
		WP_CLI::log( 'Collection reachable. Write permission: ' . ( $can ? 'yes' : 'NO' ) );
		WP_CLI::log( 'Reindex state: ' . wp_json_encode( WarpSearch_WC_Reindexer::state() ) );
	}
}
