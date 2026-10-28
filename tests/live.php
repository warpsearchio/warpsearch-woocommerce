<?php
/** Run only on a disposable WordPress/WooCommerce installation: wp eval-file tests/live.php. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'WARPSEARCH_TEST_SITE' ) ) {
	throw new RuntimeException( 'Requires WP-CLI and WARPSEARCH_TEST_SITE=1 on a disposable site.' );
}
$key = getenv( 'WARPSEARCH_API_KEY' );
$collection = getenv( 'WARPSEARCH_TEST_COLLECTION' );
if ( ! $key || ! preg_match( '/^test_wc_[a-z0-9_]+$/', $collection ) ) {
	throw new RuntimeException( 'Set WARPSEARCH_API_KEY and a dedicated WARPSEARCH_TEST_COLLECTION beginning test_wc_.' );
}
$endpoint = untrailingslashit( getenv( 'WARPSEARCH_TEST_ENDPOINT' ) ? getenv( 'WARPSEARCH_TEST_ENDPOINT' ) : 'https://api.warpsearch.io' );
$created = false;
$ids = array();
$old_settings = get_option( WarpSearch_WC_Settings::OPTION, array() );
$check = function ( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } };
$native = function ( $method, $suffix = '', $body = null ) use ( $key, $endpoint, $collection ) {
	$args = array( 'method' => $method, 'timeout' => 60, 'redirection' => 0, 'headers' => array( 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json' ) );
	if ( null !== $body ) { $args['body'] = wp_json_encode( $body ); }
	return wp_remote_request( $endpoint . '/v1/collections/' . $collection . $suffix, $args );
};
try {
	$r = $native( 'GET' );
	$check( ! is_wp_error( $r ) && 404 === wp_remote_retrieve_response_code( $r ), 'Test collection must not already exist; use a new name.' );
	$r = $native( 'PUT', '', array( 'description' => 'Disposable WooCommerce integration verification' ) );
	$check( ! is_wp_error( $r ) && wp_remote_retrieve_response_code( $r ) < 300, 'Could not create test collection; the test key needs editor/admin permissions.' );
	$created = true;
	update_option( WarpSearch_WC_Settings::OPTION, array_merge( WarpSearch_WC_Settings::defaults(), array( 'endpoint' => $endpoint, 'collection' => $collection, 'writer_key' => $key, 'search_key' => '', 'batch_size' => 1 ) ), false );
	$p = new WC_Product_Simple();
	$p->set_name( 'WarpVerification Trail Shoe' ); $p->set_regular_price( '49.50' ); $p->set_status( 'publish' ); $p->set_catalog_visibility( 'visible' );
	$ids[] = $p->save();
	$parent = new WC_Product_Variable(); $parent->set_name( 'WarpVerification Jacket' ); $parent->set_status( 'publish' ); $ids[] = $parent->save();
	$variant = new WC_Product_Variation(); $variant->set_parent_id( $parent->get_id() ); $variant->set_regular_price( '89' ); $variant->set_sku( 'WARP-VERIFY-VARIANT' ); $variant->set_status( 'publish' ); $ids[] = $variant->save();
	WC_Product_Variable::sync( $parent->get_id() );
	WarpSearch_WC_Indexer::flush();
	$r = WarpSearch_WC_Client::for_writing()->refresh(); $check( ! is_wp_error( $r ), 'Initial refresh failed' );
	$r = WarpSearch_WC_Search::run( array( 'query' => 'WarpVerification Trail' ) );
	$check( ! is_wp_error( $r ) && in_array( $p->get_id(), $r['ids'], true ), 'Indexed product was not found using writer-key fallback' );
	$r = WarpSearch_WC_Search::run( array( 'query' => 'WARP-VERIFY-VARIANT' ) );
	$check( ! is_wp_error( $r ) && in_array( $parent->get_id(), $r['ids'], true ), 'Variation SKU did not resolve to its parent' );
	$p->set_regular_price( '39' ); $p->set_stock_status( 'outofstock' ); $p->save(); WarpSearch_WC_Indexer::flush(); WarpSearch_WC_Client::for_writing()->refresh();
	$r = WarpSearch_WC_Search::run( array( 'query' => 'WarpVerification Trail', 'in_stock_only' => true ) );
	$check( ! is_wp_error( $r ) && ! in_array( $p->get_id(), $r['ids'], true ), 'Stock update did not remove the product from in-stock results' );
	$stale_id = WarpSearch_WC_Indexer::builder()->document_id( 999999999 );
	$stale = array( 'wc_index_s' => WarpSearch_WC_Settings::get( 'index_alias' ), 'wc_id_s' => '999999999', 'wc_run_s' => 'obsolete' );
	$r = WarpSearch_WC_Client::for_writing()->bulk( array( $stale_id => $stale ) );
	$check( ! is_wp_error( $r ) && ! $r, 'Could not seed stale-document fixture' );
	WarpSearch_WC_Reindexer::start( false );
	do { $state = WarpSearch_WC_Reindexer::step(); } while ( 'running' === $state['status'] );
	$check( 'completed' === $state['status'], 'Rebuild failed: ' . $state['message'] );
	$r = WarpSearch_WC_Client::for_searching()->search( array( 'query' => array( 'terms' => array( 'wc_id_s' => array( '999999999' ) ) ) ) );
	$check( ! is_wp_error( $r ) && empty( $r['hits']['hits'] ), 'Rebuild did not remove stale documents' );
	$req = new WP_REST_Request( 'GET', '/warpsearch/v1/search' );
	$req->set_query_params( array( 'q' => 'WarpVerification Jacket', 'page' => 1, 'sort' => 'relevance', 'category' => array(), 'in_stock' => false ) );
	$response = WarpSearch_WC_Search::rest_search( $req );
	$check( 200 === $response->get_status() && false !== strpos( $response->get_data()['html'], 'WarpVerification Jacket' ), 'REST search did not hydrate product cards' );
	$p->set_status( 'draft' ); $p->save(); WarpSearch_WC_Indexer::flush(); WarpSearch_WC_Client::for_writing()->refresh();
	$r = WarpSearch_WC_Search::run( array( 'query' => 'WarpVerification Trail' ) );
	$check( ! is_wp_error( $r ) && ! in_array( $p->get_id(), $r['ids'], true ), 'Unpublished product remained searchable' );
	WP_CLI::success( 'WooCommerce live verification passed: initial sync, variation SKU, stock update, rebuild, stale cleanup, REST hydration, unpublish, writer-key fallback.' );
} finally {
	WarpSearch_WC_Reindexer::cancel();
	foreach ( array_reverse( $ids ) as $id ) { wp_delete_post( $id, true ); }
	WarpSearch_WC_Indexer::flush();
	update_option( WarpSearch_WC_Settings::OPTION, $old_settings, false );
	if ( $created ) {
		$r = $native( 'DELETE' );
		if ( is_wp_error( $r ) || wp_remote_retrieve_response_code( $r ) >= 300 ) { WP_CLI::warning( 'Test collection cleanup failed; delete ' . $collection . ' in the console.' ); }
	}
}
