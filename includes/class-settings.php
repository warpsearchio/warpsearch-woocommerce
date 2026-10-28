<?php
/**
 * Settings storage and registration.
 *
 * @package WarpSearch
 */

defined( 'ABSPATH' ) || exit;

class WarpSearch_WC_Settings {
	const OPTION = 'warpsearch_wc_settings';

	public static function defaults() {
		return array(
			'endpoint'         => 'https://api.warpsearch.io',
			'collection'       => 'woocommerce',
			'writer_key'       => '',
			'search_key'       => '',
			'index_alias'      => 'woocommerce',
			'english_analysis' => 0,
			'auto_index'       => 1,
			'replace_search'   => 0,
			'batch_size'       => 100,
			'results_per_page' => 12,
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/** Collection name as WarpSearch requires it: ^[a-z][a-z0-9_]*$ (same normalisation as the Umbraco provider). */
	public static function normalize_collection( $name ) {
		$n = trim( preg_replace( '/[^a-z0-9]+/', '_', strtolower( (string) $name ) ), '_' );
		if ( '' === $n || ! ctype_lower( $n[0] ) ) {
			$n = 'c_' . $n;
		}
		return $n;
	}

	public static function sanitize( $input ) {
		$old = self::all();
		$in  = is_array( $input ) ? $input : array();
		$out = array();

		$endpoint        = isset( $in['endpoint'] ) ? esc_url_raw( trim( $in['endpoint'] ) ) : '';
		$out['endpoint'] = untrailingslashit( '' !== $endpoint ? $endpoint : $old['endpoint'] );

		$out['collection']  = self::normalize_collection( isset( $in['collection'] ) ? $in['collection'] : $old['collection'] );
		$out['index_alias'] = self::normalize_collection( isset( $in['index_alias'] ) && '' !== $in['index_alias'] ? $in['index_alias'] : $old['index_alias'] );

		// Blank key fields keep the stored key, so the keys never have to be echoed into the form.
		foreach ( array( 'writer_key', 'search_key' ) as $k ) {
			$v         = isset( $in[ $k ] ) ? trim( sanitize_text_field( $in[ $k ] ) ) : '';
			$out[ $k ] = '' !== $v ? $v : $old[ $k ];
			if ( ! empty( $in[ 'clear_' . $k ] ) ) {
				$out[ $k ] = '';
			}
		}
		foreach ( array( 'english_analysis', 'auto_index', 'replace_search' ) as $k ) {
			$out[ $k ] = empty( $in[ $k ] ) ? 0 : 1;
		}
		$out['batch_size']       = min( 500, max( 1, isset( $in['batch_size'] ) ? (int) $in['batch_size'] : $old['batch_size'] ) );
		$out['results_per_page'] = min( 50, max( 1, isset( $in['results_per_page'] ) ? (int) $in['results_per_page'] : $old['results_per_page'] ) );
		return $out;
	}
}
