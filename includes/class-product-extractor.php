<?php
/**
 * Reads a WooCommerce product into a plain array (title, description, SKU, price, categories, attributes,
 * images, stock, variations). Needs WooCommerce; everything after this is WordPress-independent.
 *
 * @package WarpSearch
 */

defined( 'ABSPATH' ) || exit;

class WarpSearch_WC_Product_Extractor {

	/**
	 * @param WC_Product|int $product Product (variations resolve to their parent).
	 * @return array|null Null when the product does not exist.
	 */
	public static function extract( $product ) {
		$product = is_numeric( $product ) ? wc_get_product( $product ) : $product;
		if ( $product && $product->is_type( 'variation' ) ) {
			$product = wc_get_product( $product->get_parent_id() );
		}
		if ( ! $product ) {
			return null;
		}
		$id = $product->get_id();

		$variation_skus = array();
		$prices         = array();
		$attributes     = array();
		$variations     = array();
		$in_stock       = $product->is_in_stock();

		if ( $product->is_type( 'variable' ) ) {
			$any_in_stock = false;
			foreach ( $product->get_children() as $child_id ) {
				$child = wc_get_product( $child_id );
				if ( ! $child || 'publish' !== $child->get_status() ) {
					continue;
				}
				if ( $child->get_sku() ) {
					$variation_skus[] = $child->get_sku();
				}
				if ( '' !== $child->get_price() ) {
					$prices[] = (float) $child->get_price();
				}
				$any_in_stock = $any_in_stock || $child->is_in_stock();
				foreach ( $child->get_attributes() as $tax => $value ) {
					if ( '' === $value ) {
						continue;
					}
					$label = taxonomy_exists( $tax ) ? self::term_name( $tax, $value ) : $value;
					$attributes[ self::attr_slug( $tax ) ][] = $label;
				}
				$variations[] = array(
					'id'             => $child_id,
					'sku'            => $child->get_sku(),
					'price'          => $child->get_price(),
					'stock_status'   => $child->get_stock_status(),
					'stock_quantity' => $child->get_stock_quantity(),
					'attributes'     => $child->get_attributes(),
				);
			}
			$in_stock = $any_in_stock;
		} elseif ( '' !== $product->get_price() ) {
			$prices[] = (float) $product->get_price();
		}

		foreach ( $product->get_attributes() as $attr ) {
			if ( ! ( $attr instanceof WC_Product_Attribute ) ) {
				continue;
			}
			$slug = self::attr_slug( $attr->get_name() );
			if ( $attr->is_taxonomy() ) {
				$terms = wc_get_product_terms( $id, $attr->get_name(), array( 'fields' => 'names' ) );
			} else {
				$terms = $attr->get_options();
			}
			foreach ( (array) $terms as $t ) {
				$attributes[ $slug ][] = (string) $t;
			}
		}
		$attribute_values = array();
		foreach ( $attributes as $slug => $values ) {
			$attributes[ $slug ] = array_values( array_unique( $values ) );
			$attribute_values    = array_merge( $attribute_values, $attributes[ $slug ] );
		}

		$cat_terms = get_the_terms( $id, 'product_cat' );
		$tag_terms = get_the_terms( $id, 'product_tag' );
		$brands    = taxonomy_exists( 'product_brand' ) ? get_the_terms( $id, 'product_brand' ) : array();

		$image_ids = array_filter( array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids() ) );
		$images    = array();
		foreach ( $image_ids as $image_id ) {
			$url = wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' );
			if ( $url ) {
				$images[] = $url;
			}
		}

		$created = $product->get_date_created();

		$data = array(
			'id'                 => $id,
			'name'               => wp_strip_all_tags( $product->get_name() ),
			'description'        => self::text( $product->get_description() ),
			'short_description'  => self::text( $product->get_short_description() ),
			'sku'                => (string) $product->get_sku(),
			'variation_skus'     => array_values( array_unique( $variation_skus ) ),
			'type'               => $product->get_type(),
			'prices'             => $prices,
			'on_sale'            => $product->is_on_sale(),
			'featured'           => $product->is_featured(),
			'categories'         => self::term_list( $cat_terms, 'name' ),
			'category_slugs'     => self::term_list( $cat_terms, 'slug' ),
			'tags'               => self::term_list( $tag_terms, 'name' ),
			'brands'             => self::term_list( $brands, 'name' ),
			'attributes'         => $attributes,
			'attribute_values'   => $attribute_values,
			'images'             => $images,
			'in_stock'           => (bool) $in_stock,
			'stock_status'       => $product->get_stock_status(),
			'stock_quantity'     => $product->managing_stock() ? $product->get_stock_quantity() : null,
			'variations'         => $variations,
			'menu_order'         => $product->get_menu_order(),
			'total_sales'        => (int) $product->get_total_sales(),
			'date_created'       => $created ? $created->getTimestamp() : 0,
			'catalog_visibility' => $product->get_catalog_visibility(),
			'status'             => $product->get_status(),
		);
		/**
		 * Filter the extracted product before it becomes a WarpSearch document.
		 * Return an array with 'status' other than 'publish' to keep a product out of the index.
		 */
		return apply_filters( 'warpsearch_wc_extracted_product', $data, $product );
	}

	/** Only published, non-password-protected products are indexed. */
	public static function is_indexable( $data ) {
		return is_array( $data ) && 'publish' === $data['status'] && 'hidden' !== $data['catalog_visibility'] && ! post_password_required( $data['id'] );
	}

	private static function attr_slug( $name ) {
		return preg_replace( '/^pa_/', '', sanitize_title( str_replace( 'attribute_', '', $name ) ) );
	}

	private static function term_name( $taxonomy, $slug ) {
		$term = get_term_by( 'slug', $slug, $taxonomy );
		return $term ? $term->name : $slug;
	}

	private static function term_list( $terms, $field ) {
		if ( ! is_array( $terms ) ) {
			return array();
		}
		return array_values( array_map( function ( $t ) use ( $field ) { return $t->$field; }, $terms ) );
	}

	private static function text( $html ) {
		return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( strip_shortcodes( (string) $html ) ) ) );
	}
}
