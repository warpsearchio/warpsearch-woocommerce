<?php
/**
 * Minimal WarpSearch HTTP client; endpoints are the ones the Umbraco provider uses.
 *
 * @package WarpSearch
 */

defined( 'ABSPATH' ) || exit;

class WarpSearch_WC_Client {
	private $endpoint;
	private $collection;
	private $key;
	/** Visitor-facing searches retry once and quickly; writes (background, CLI) wait out longer outages. */
	private $attempts = 4;
	private $max_wait = 10;
	private $timeout  = 30;

	/** @var callable Sleep function (seconds); replaceable in tests. */
	public static $sleep = 'sleep';

	/** Waits (seconds) before resubmitting bulk items that failed transiently (429/5xx, e.g. warming_up): about a minute. */
	const ITEM_RETRY_WAITS = array( 2, 4, 8, 15, 15, 15 );

	public function __construct( $endpoint, $collection, $key ) {
		$this->endpoint   = untrailingslashit( $endpoint );
		$this->collection = $collection;
		$this->key        = $key;
	}

	public static function for_writing() {
		return new self( WarpSearch_WC_Settings::get( 'endpoint' ), WarpSearch_WC_Settings::get( 'collection' ), WarpSearch_WC_Settings::get( 'writer_key' ) );
	}

	public static function for_searching() {
		$key = WarpSearch_WC_Settings::get( 'search_key' );
		$client           = new self( WarpSearch_WC_Settings::get( 'endpoint' ), WarpSearch_WC_Settings::get( 'collection' ), $key ? $key : WarpSearch_WC_Settings::get( 'writer_key' ) );
		$client->attempts = 2;
		$client->max_wait = 1;
		$client->timeout  = 10;
		return $client;
	}

	private function coll_path() {
		return '/v1/collections/' . rawurlencode( $this->collection );
	}

	private function es_path() {
		return '/compat/es/' . rawurlencode( $this->collection );
	}

	/** @return array|WP_Error Decoded JSON ('' body gives array()); 404 on GET gives WP_Error code warpsearch_not_found. */
	private function request( $method, $path, $body = null, $content_type = 'application/json' ) {
		if ( '' === (string) $this->key ) {
			return new WP_Error( 'warpsearch_no_key', __( 'No WarpSearch API key is configured.', 'warpsearch-for-woocommerce' ) );
		}
		$args    = array(
			'method'     => $method,
			'redirection' => 0, // Never forward the bearer key to a redirect target.
			'timeout'    => $this->timeout,
			'user-agent' => 'WarpSearch-WooCommerce/' . WARPSEARCH_WC_VERSION,
			'headers'    => array(
				'Authorization' => 'Bearer ' . $this->key,
				'Content-Type'  => $content_type,
				'Accept'        => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = $body;
		}
		$attempts = 0;
		do {
			$res = wp_remote_request( $this->endpoint . $path, $args );
			++$attempts;
			if ( is_wp_error( $res ) ) {
				$retry = true;
				$wait  = 1;
			} else {
				$code  = (int) wp_remote_retrieve_response_code( $res );
				$retry = 429 === $code || $code >= 500;
				$wait  = max( 1, min( $this->max_wait, (int) wp_remote_retrieve_header( $res, 'retry-after' ) ) );
			}
			if ( $retry && $attempts < $this->attempts ) {
				call_user_func( self::$sleep, $wait * $attempts );
				continue;
			}
			break;
		} while ( true );

		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$raw  = wp_remote_retrieve_body( $res );
		$json = '' === $raw ? array() : json_decode( $raw, true );
		if ( 404 === $code ) {
			return new WP_Error( 'warpsearch_not_found', 'Not found (404)' );
		}
		if ( $code < 200 || $code >= 300 ) {
			// Native API: RFC 9457 problem details; ES API: {"error":{type,reason}}.
			$detail = $raw;
			if ( is_array( $json ) ) {
				if ( isset( $json['error']['reason'] ) ) {
					$detail = $json['error']['reason'];
				} elseif ( isset( $json['detail'] ) ) {
					$detail = $json['detail'];
				} elseif ( isset( $json['title'] ) ) {
					$detail = $json['title'];
				}
			}
			return new WP_Error( 'warpsearch_http_' . $code, sprintf( 'WarpSearch %s %s failed with %d: %s', $method, $path, $code, substr( (string) $detail, 0, 500 ) ) );
		}
		if ( '' !== $raw && ! is_array( $json ) ) {
			return new WP_Error( 'warpsearch_invalid_response', 'WarpSearch returned invalid JSON.' );
		}
		return is_array( $json ) ? $json : array();
	}

	/** GET /v1/collections/{c}. Returns array (with "permissions" containing "write") or WP_Error. */
	public function get_collection() {
		return $this->request( 'GET', $this->coll_path() );
	}

	/**
	 * Index and delete documents through the ES-compatible _bulk API.
	 *
	 * @param array $index_docs  id => document array.
	 * @param array $delete_ids  ids.
	 * @return array|WP_Error List of failures (each: id, type, reason); empty on full success.
	 */
	public function bulk( array $index_docs, array $delete_ids = array() ) {
		if ( ! $index_docs && ! $delete_ids ) {
			return array();
		}
		$count = count( $index_docs ) + count( $delete_ids );
		$limit = max( 1, (int) WarpSearch_WC_Settings::get( 'batch_size' ) );
		if ( $count > $limit ) {
			return $this->split_bulk( $index_docs, $delete_ids );
		}
		$waits    = self::ITEM_RETRY_WAITS;
		$failures = array();
		while ( true ) {
			$res = $this->send_bulk( $index_docs, $delete_ids );
			if ( is_wp_error( $res ) && 'warpsearch_http_413' === $res->get_error_code() && $count > 1 ) {
				return $this->split_bulk( $index_docs, $delete_ids );
			}
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			list( $permanent, $transient ) = $res;
			$failures = array_merge( $failures, $permanent );
			if ( ! $transient ) {
				return $failures;
			}
			// Items rejected with 429/5xx inside a 200 response (a cold or warming collection): resubmit only those.
			if ( ! $waits ) {
				return new WP_Error( 'warpsearch_unavailable', sprintf( 'WarpSearch did not accept %d documents in time: %s', count( $transient ), substr( (string) reset( $transient ), 0, 200 ) ) );
			}
			call_user_func( self::$sleep, array_shift( $waits ) );
			$index_docs = array_intersect_key( $index_docs, $transient );
			$delete_ids = array_values( array_filter( $delete_ids, function ( $id ) use ( $transient ) {
				return isset( $transient[ (string) $id ] );
			} ) );
			if ( ! $index_docs && ! $delete_ids ) {
				return $failures; // Unknown ids; nothing to resubmit.
			}
		}
	}

	/** @return array|WP_Error array( failures (id, type, reason), transient (id => reason) ). */
	private function send_bulk( array $index_docs, array $delete_ids ) {
		$lines = array();
		foreach ( $index_docs as $id => $doc ) {
			$lines[] = wp_json_encode( array( 'index' => array( '_id' => (string) $id ) ) );
			$lines[] = wp_json_encode( $doc );
		}
		foreach ( $delete_ids as $id ) {
			$lines[] = wp_json_encode( array( 'delete' => array( '_id' => (string) $id ) ) );
		}
		$res = $this->request( 'POST', $this->es_path() . '/_bulk', implode( "\n", $lines ) . "\n", 'application/x-ndjson' );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$failures  = array();
		$transient = array();
		if ( ! empty( $res['errors'] ) && isset( $res['items'] ) ) {
			foreach ( $res['items'] as $item ) {
				$item = is_array( $item ) ? reset( $item ) : null;
				if ( is_array( $item ) && isset( $item['error'] ) ) {
					$id     = isset( $item['_id'] ) ? $item['_id'] : null;
					$type   = isset( $item['error']['type'] ) ? $item['error']['type'] : null;
					$reason = isset( $item['error']['reason'] ) ? $item['error']['reason'] : null;
					$status = isset( $item['status'] ) ? (int) $item['status'] : 0;
					if ( null !== $id && ( 429 === $status || $status >= 500 ) ) {
						$transient[ (string) $id ] = $type . ': ' . $reason;
						continue;
					}
					$failures[] = array(
						'id'     => $id,
						'type'   => $type,
						'reason' => $reason,
					);
				}
			}
		}
		return array( $failures, $transient );
	}

	private function split_bulk( array $docs, array $deletes ) {
		$half = (int) ceil( ( count( $docs ) + count( $deletes ) ) / 2 );
		$doc_count = min( $half, count( $docs ) );
		$delete_count = $half - $doc_count;
		$a = $this->bulk( array_slice( $docs, 0, $doc_count, true ), array_slice( $deletes, 0, $delete_count ) );
		if ( is_wp_error( $a ) ) {
			return $a;
		}
		$b = $this->bulk( array_slice( $docs, $doc_count, null, true ), array_slice( $deletes, $delete_count ) );
		return is_wp_error( $b ) ? $b : array_merge( $a, $b );
	}

	public function refresh() {
		return $this->request( 'POST', $this->coll_path() . '/refresh' );
	}

	/** POST /v1/collections/{c}/documents/_delete {ids, commit}. */
	public function delete_documents( array $ids, $commit = true ) {
		return $this->request( 'POST', $this->coll_path() . '/documents/_delete', wp_json_encode( array( 'ids' => array_values( $ids ), 'commit' => (bool) $commit ) ) );
	}

	/** POST /compat/es/{c}/_search. */
	public function search( array $body ) {
		return $this->request( 'POST', $this->es_path() . '/_search', wp_json_encode( $body ) );
	}
}
