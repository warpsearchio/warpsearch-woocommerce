<?php
use PHPUnit\Framework\TestCase;

// HTTP contract tests, without loading WordPress. The live test below runs the same client in WP.
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;
		public function __construct( $code, $message ) { $this->code = $code; $this->message = $message; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
	function is_wp_error( $v ) { return $v instanceof WP_Error; }
	function untrailingslashit( $v ) { return rtrim( $v, '/' ); }
	function __( $v, $domain = '' ) { return $v; }
	function get_option( $name, $default = array() ) { return $GLOBALS['ws_test_options'][ $name ] ?? $default; }
	function wp_json_encode( $v ) { return json_encode( $v ); }
	function wp_remote_request( $url, $args ) { return call_user_func( $GLOBALS['ws_test_http'], $url, $args ); }
	function wp_remote_retrieve_response_code( $res ) { return $res['code']; }
	function wp_remote_retrieve_header( $res, $name ) { return $res['headers'][ $name ] ?? ''; }
	function wp_remote_retrieve_body( $res ) { return $res['body']; }
}
if ( ! defined( 'WARPSEARCH_WC_VERSION' ) ) { define( 'WARPSEARCH_WC_VERSION', 'test' ); }
require_once __DIR__ . '/../includes/class-settings.php';
require_once __DIR__ . '/../includes/class-client.php';

class ClientTest extends TestCase {
	protected function setUp(): void {
		WarpSearch_WC_Client::$sleep = function () {};
		$GLOBALS['ws_test_options'] = array( WarpSearch_WC_Settings::OPTION => array( 'writer_key' => 'writer-only', 'collection' => 'test', 'batch_size' => 2 ) );
	}
	public function test_search_key_falls_back_to_server_held_writer() {
		$GLOBALS['ws_test_http'] = function ( $url, $args ) {
			$this->assertSame( 'Bearer writer-only', $args['headers']['Authorization'] );
			$this->assertSame( 0, $args['redirection'] );
			return array( 'code' => 200, 'body' => '{"hits":{"hits":[]}}' );
		};
		$this->assertArrayHasKey( 'hits', WarpSearch_WC_Client::for_searching()->search( array() ) );
	}
	public function test_mixed_bulk_preserves_ids_and_splits_on_size_limit() {
		$seen = array();
		$GLOBALS['ws_test_http'] = function ( $url, $args ) use ( &$seen ) {
			$this->assertStringEndsWith( "\n", $args['body'] );
			$lines = array_map( 'json_decode', explode( "\n", trim( $args['body'] ) ) );
			if ( count( $lines ) > 2 ) { return array( 'code' => 413, 'body' => '{}' ); }
			foreach ( $lines as $line ) {
				if ( isset( $line->index ) ) { $seen[] = 'index:' . $line->index->_id; }
				if ( isset( $line->delete ) ) { $seen[] = 'delete:' . $line->delete->_id; }
			}
			return array( 'code' => 200, 'body' => '{"errors":false}' );
		};
		$r = WarpSearch_WC_Client::for_writing()->bulk( array( 'a' => array( 'title_s' => 'A' ), 'b' => array( 'title_s' => 'B' ) ), array( 'c' ) );
		$this->assertSame( array(), $r );
		$this->assertSame( array( 'index:a', 'index:b', 'delete:c' ), $seen );
	}
	public function test_success_status_with_invalid_json_is_failure() {
		$GLOBALS['ws_test_http'] = function () { return array( 'code' => 200, 'body' => '<html>upstream error</html>' ); };
		$this->assertSame( 'warpsearch_invalid_response', WarpSearch_WC_Client::for_writing()->bulk( array( 'a' => array( 'title_s' => 'A' ) ) )->get_error_code() );
	}
	public function test_bulk_item_failures_are_returned() {
		$GLOBALS['ws_test_http'] = function () { return array( 'code' => 200, 'body' => '{"errors":true,"items":[{"index":{"_id":"a","error":{"type":"bad_field","reason":"invalid"}}}]}' ); };
		$r = WarpSearch_WC_Client::for_writing()->bulk( array( 'a' => array( 'title_s' => 'A' ) ) );
		$this->assertSame( 'a', $r[0]['id'] );
		$this->assertSame( 'bad_field', $r[0]['type'] );
	}
	public function test_transient_bulk_items_are_resubmitted_alone() {
		$calls = array();
		$GLOBALS['ws_test_http'] = function ( $url, $args ) use ( &$calls ) {
			$calls[] = substr_count( $args['body'], '"_id"' );
			if ( 1 === count( $calls ) ) {
				return array( 'code' => 200, 'body' => '{"errors":true,"items":[{"index":{"_id":"a","status":503,"error":{"type":"warming_up","reason":"retry"}}},{"index":{"_id":"b","status":400,"error":{"type":"bad_field","reason":"invalid"}}}]}' );
			}
			return array( 'code' => 200, 'body' => '{"errors":false,"items":[{"index":{"_id":"a","status":201}}]}' );
		};
		$r = WarpSearch_WC_Client::for_writing()->bulk( array( 'a' => array( 'title_s' => 'A' ), 'b' => array( 'title_s' => 'B' ) ) );
		$this->assertSame( array( 2, 1 ), $calls );
		$this->assertCount( 1, $r );
		$this->assertSame( 'b', $r[0]['id'] );
	}
	public function test_bulk_items_that_stay_unavailable_are_an_error() {
		$GLOBALS['ws_test_http'] = function () { return array( 'code' => 200, 'body' => '{"errors":true,"items":[{"index":{"_id":"a","status":503,"error":{"type":"warming_up","reason":"retry"}}}]}' ); };
		$this->assertSame( 'warpsearch_unavailable', WarpSearch_WC_Client::for_writing()->bulk( array( 'a' => array( 'title_s' => 'A' ) ) )->get_error_code() );
	}
}
