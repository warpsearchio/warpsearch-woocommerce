<?php
/**
 * Builds Elasticsearch Query DSL bodies for WarpSearch's ES-compatible search API
 * (same shapes as the Umbraco provider's SearchRequestBuilder). No WordPress dependency.
 *
 * @package WarpSearch
 */

defined( 'ABSPATH' ) || exit;

class WarpSearch_WC_Query_Builder {
	/** @var WarpSearch_WC_Field_Names */
	private $names;
	private $index;
	private $boosts;

	public function __construct( WarpSearch_WC_Field_Names $names, $index_alias, array $boosts = array( 'r1' => 6, 'r2' => 4, 'r3' => 2 ) ) {
		$this->names  = $names;
		$this->index  = strtolower( $index_alias );
		$this->boosts = $boosts;
	}

	/**
	 * @param array $args query, categories[], in_stock_only, min_price, max_price, sort, from, size, facets (bool), attributes[slug => values[]]
	 */
	public function search( array $args ) {
		$args    = array_merge(
			array(
				'query'         => '',
				'categories'    => array(),
				'attributes'    => array(),
				'in_stock_only' => false,
				'min_price'     => null,
				'max_price'     => null,
				'sort'          => 'relevance',
				'from'          => 0,
				'size'          => 12,
				'facets'        => true,
			),
			$args
		);
		$filter  = array(
			array( 'terms' => array( WarpSearch_WC_Field_Names::F_INDEX => array( $this->index ) ) ),
			// Hidden products are never indexed as searchable.
			array( 'terms' => array( WarpSearch_WC_Field_Names::F_VISIBILITY => array( 'visible', 'search' ) ) ),
		);
		$f_kw    = WarpSearch_WC_Field_Names::KEYWORDS;
		$post    = array();
		if ( $args['categories'] ) {
			$post[] = array( 'terms' => array( WarpSearch_WC_Field_Names::field( 'category_slug', $f_kw ) => array_values( $args['categories'] ) ) );
		}
		foreach ( $args['attributes'] as $slug => $values ) {
			if ( $values ) {
				$post[] = array( 'terms' => array( WarpSearch_WC_Field_Names::field( 'attr_' . $slug, $f_kw ) => array_values( $values ) ) );
			}
		}
		if ( $args['in_stock_only'] ) {
			$filter[] = array( 'terms' => array( WarpSearch_WC_Field_Names::field( 'in_stock', WarpSearch_WC_Field_Names::INTEGERS ) => array( 1 ) ) );
		}
		if ( null !== $args['min_price'] || null !== $args['max_price'] ) {
			$range = array();
			if ( null !== $args['min_price'] ) {
				$range['gte'] = (float) $args['min_price'];
			}
			if ( null !== $args['max_price'] ) {
				$range['lte'] = (float) $args['max_price'];
			}
			$filter[] = array( 'range' => array( WarpSearch_WC_Field_Names::field( 'price', WarpSearch_WC_Field_Names::DECIMALS ) => $range ) );
		}

		$bool = array( 'filter' => $filter );
		if ( '' !== trim( (string) $args['query'] ) ) {
			$bool['must'] = array( $this->full_text( $args['query'] ) );
		}
		$body = array(
			'query'            => array( 'bool' => $bool ),
			'from'             => max( 0, (int) $args['from'] ),
			'size'             => max( 1, (int) $args['size'] ),
			'track_total_hits' => true,
			'_source'          => array( WarpSearch_WC_Field_Names::F_ID ),
			'sort'             => $this->sort( $args['sort'], '' !== trim( (string) $args['query'] ) ),
		);
		if ( $post ) {
			$body['post_filter'] = array( 'bool' => array( 'filter' => $post ) );
		}
		if ( $args['facets'] ) {
			$body['aggs'] = array(
				'categories' => array( 'terms' => array( 'field' => WarpSearch_WC_Field_Names::field( 'category', $f_kw ), 'size' => 20 ) ),
			);
		}
		return $body;
	}

	/** Free text: all words must match, the last as a prefix, across the "all texts" tiers. */
	public function full_text( $query ) {
		$should = array( $this->match( $this->names->all_texts( 'tx' ), $query, 1.0 ) );
		foreach ( array( 'r1', 'r2', 'r3' ) as $tier ) {
			$should[] = $this->match( $this->names->all_texts( $tier ), $query, (float) $this->boosts[ $tier ] );
		}
		return array( 'bool' => array( 'should' => $should, 'minimum_should_match' => 1 ) );
	}

	/** Query that finds documents of this index left over from earlier reindex runs. */
	public function stale( $run, $size ) {
		return array(
			'query'            => array(
				'bool' => array(
					'filter'   => array( array( 'terms' => array( WarpSearch_WC_Field_Names::F_INDEX => array( $this->index ) ) ) ),
					'must_not' => array( array( 'terms' => array( WarpSearch_WC_Field_Names::F_RUN => array( $run ) ) ) ),
				),
			),
			'size'             => (int) $size,
			'_source'          => false,
			'track_total_hits' => false,
		);
	}

	private function sort( $sort, $has_query ) {
		$field = function ( $name, $kind ) {
			return WarpSearch_WC_Field_Names::field( $name, $kind );
		};
		switch ( $sort ) {
			case 'price_asc':
				return array( array( $field( 'price', WarpSearch_WC_Field_Names::DECIMALS ) => array( 'order' => 'asc' ) ) );
			case 'price_desc':
				return array( array( $field( 'price', WarpSearch_WC_Field_Names::DECIMALS ) => array( 'order' => 'desc' ) ) );
			case 'newest':
				return array( array( $field( 'date', WarpSearch_WC_Field_Names::DATES ) => array( 'order' => 'desc' ) ) );
			case 'title':
				return array( array( $field( 'title', WarpSearch_WC_Field_Names::TEXT_SORT ) => array( 'order' => 'asc' ) ) );
			case 'popularity':
				return array( array( $field( 'total_sales', WarpSearch_WC_Field_Names::INTEGERS ) => array( 'order' => 'desc' ) ) );
			default:
				return array( array( '_score' => array( 'order' => 'desc' ) ) );
		}
	}

	private function match( $field, $query, $boost ) {
		return array( 'match_bool_prefix' => array( $field => array( 'query' => $query, 'operator' => 'and', 'boost' => $boost ) ) );
	}
}
