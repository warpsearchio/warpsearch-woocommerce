<?php
/**
 * Turns a plain product array (see WarpSearch_WC_Product_Extractor) into a WarpSearch document.
 * No WordPress dependency, so it is unit tested without WordPress.
 *
 * @package WarpSearch
 */

defined( 'ABSPATH' ) || exit;

class WarpSearch_WC_Document_Builder {
	/** @var WarpSearch_WC_Field_Names */
	private $names;
	/** @var string */
	private $index;

	public function __construct( WarpSearch_WC_Field_Names $names, $index_alias ) {
		$this->names = $names;
		$this->index = strtolower( $index_alias );
	}

	public function document_id( $product_id ) {
		return $this->index . '.' . (int) $product_id;
	}

	/**
	 * @param array  $p   Extracted product.
	 * @param string $run Reindex run id ('' for incremental updates).
	 * @return array Document (id is not part of it).
	 */
	public function build( array $p, $run = '' ) {
		$n   = $this->names;
		$doc = array(
			WarpSearch_WC_Field_Names::F_INDEX      => $this->index,
			WarpSearch_WC_Field_Names::F_ID         => (string) $p['id'],
			WarpSearch_WC_Field_Names::F_VISIBILITY => array( (string) $p['catalog_visibility'] ),
		);
		if ( '' !== $run ) {
			$doc[ WarpSearch_WC_Field_Names::F_RUN ] = $run;
		}

		// Relevance tiers: r1 title/sku, r2 short description/categories/brands, r3 attributes/tags/variation text, tx description.
		$tiers = array(
			'r1' => array_merge( array( $p['name'] ), $this->flat( array( $p['sku'] ), $p['variation_skus'] ) ),
			'r2' => array_merge( array( $p['short_description'] ), $p['categories'], $p['brands'] ),
			'r3' => array_merge( $p['tags'], $p['attribute_values'] ),
			'tx' => array( $p['description'] ),
		);
		foreach ( $tiers as $relevance => $texts ) {
			$text = $this->join_text( $texts );
			if ( '' !== $text ) {
				$doc[ $n->all_texts( $relevance ) ] = $text;
			}
		}
		$this->put_text( $doc, 'title', 'r1', array( $p['name'] ) );
		$this->put_text( $doc, 'sku', 'r1', $this->flat( array( $p['sku'] ), $p['variation_skus'] ) );
		$this->put_text( $doc, 'short_description', 'r2', array( $p['short_description'] ) );
		$this->put_text( $doc, 'description', 'tx', array( $p['description'] ) );
		$this->put_text( $doc, 'category', 'r2', $p['categories'] );

		if ( '' !== trim( (string) $p['name'] ) ) {
			$doc[ WarpSearch_WC_Field_Names::field( 'title', WarpSearch_WC_Field_Names::TEXT_SORT ) ] = strtolower( (string) $p['name'] );
		}

		$this->put_kw( $doc, 'sku', $this->flat( array( $p['sku'] ), $p['variation_skus'] ) );
		$this->put_kw( $doc, 'category', $p['categories'] );
		$this->put_kw( $doc, 'category_slug', $p['category_slugs'] );
		$this->put_kw( $doc, 'tag', $p['tags'] );
		$this->put_kw( $doc, 'brand', $p['brands'] );
		$this->put_kw( $doc, 'type', array( $p['type'] ) );
		$this->put_kw( $doc, 'stock_status', array( $p['stock_status'] ) );
		foreach ( $p['attributes'] as $slug => $values ) {
			$this->put_kw( $doc, 'attr_' . $slug, $values );
		}

		$prices = array_values( array_unique( array_map( 'floatval', $p['prices'] ) ) );
		if ( $prices ) {
			sort( $prices );
			$doc[ WarpSearch_WC_Field_Names::field( 'price', WarpSearch_WC_Field_Names::DECIMALS ) ]         = $prices;
			$doc[ WarpSearch_WC_Field_Names::field( 'price', WarpSearch_WC_Field_Names::DECIMAL_KEYWORDS ) ] = array_map( array( __CLASS__, 'format_decimal' ), $prices );
		}
		$this->put_int( $doc, 'in_stock', $p['in_stock'] ? 1 : 0 );
		$this->put_int( $doc, 'on_sale', $p['on_sale'] ? 1 : 0 );
		$this->put_int( $doc, 'featured', $p['featured'] ? 1 : 0 );
		$this->put_int( $doc, 'menu_order', (int) $p['menu_order'] );
		$this->put_int( $doc, 'total_sales', (int) $p['total_sales'] );
		if ( null !== $p['stock_quantity'] ) {
			$this->put_int( $doc, 'stock_quantity', (int) $p['stock_quantity'] );
		}
		if ( ! empty( $p['date_created'] ) ) {
			$doc[ WarpSearch_WC_Field_Names::field( 'date', WarpSearch_WC_Field_Names::DATES ) ] = array( self::format_date( $p['date_created'] ) );
		}
		return $doc;
	}

	public static function format_decimal( $value ) {
		$s = rtrim( rtrim( number_format( (float) $value, 6, '.', '' ), '0' ), '.' );
		return '' === $s ? '0' : $s;
	}

	/** UTC ISO-8601 with milliseconds, like the Umbraco provider. */
	public static function format_date( $timestamp ) {
		return gmdate( 'Y-m-d\TH:i:s', (int) $timestamp ) . '.000Z';
	}

	private function flat( array $a, array $b ) {
		return array_merge( $a, $b );
	}

	private function clean( array $texts ) {
		$out = array();
		foreach ( $texts as $t ) {
			$t = trim( (string) $t );
			if ( '' !== $t ) {
				$out[] = $t;
			}
		}
		return $out;
	}

	private function join_text( array $texts ) {
		return implode( "\n", $this->clean( $texts ) );
	}

	private function put_text( array &$doc, $name, $relevance, array $texts ) {
		$text = $this->join_text( $texts );
		if ( '' !== $text ) {
			$doc[ $this->names->text( $name, $relevance ) ] = $text;
		}
	}

	private function put_kw( array &$doc, $name, array $values ) {
		$values = array_values( array_unique( $this->clean( $values ) ) );
		if ( $values ) {
			$doc[ WarpSearch_WC_Field_Names::field( $name, WarpSearch_WC_Field_Names::KEYWORDS ) ] = $values;
		}
	}

	private function put_int( array &$doc, $name, $value ) {
		$doc[ WarpSearch_WC_Field_Names::field( $name, WarpSearch_WC_Field_Names::INTEGERS ) ] = array( (int) $value );
	}
}
