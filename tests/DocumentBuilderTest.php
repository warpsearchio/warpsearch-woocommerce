<?php
use PHPUnit\Framework\TestCase;

class DocumentBuilderTest extends TestCase {
	private function product() {
		return array(
			'id' => 42, 'name' => 'Trail Shoe', 'description' => 'Grippy sole', 'short_description' => 'Light',
			'sku' => 'TS-1', 'variation_skus' => array( 'TS-1-S', 'TS-1-M' ), 'type' => 'variable',
			'prices' => array( 59.5, 49.0, 59.50 ), 'on_sale' => true, 'featured' => false,
			'categories' => array( 'Shoes' ), 'category_slugs' => array( 'shoes' ), 'tags' => array( 'run' ), 'brands' => array(),
			'attributes' => array( 'color' => array( 'Red', 'Blue' ) ), 'attribute_values' => array( 'Red', 'Blue' ),
			'in_stock' => true, 'stock_status' => 'instock', 'stock_quantity' => null, 'menu_order' => 0, 'total_sales' => 3,
			'date_created' => 1700000000, 'catalog_visibility' => 'visible',
		);
	}

	public function test_field_names_follow_umbraco_convention() {
		$n = new WarpSearch_WC_Field_Names();
		$this->assertSame( 'f_title_r1_un', $n->text( 'title', 'r1' ) );
		$this->assertSame( 'all_r1_un', $n->all_texts( 'r1' ) );
		$this->assertSame( 'f_my_field_kw_ss', WarpSearch_WC_Field_Names::field( 'my-field', 'kw_ss' ) );
		$this->assertSame( 'f_title_r1_t', ( new WarpSearch_WC_Field_Names( true ) )->text( 'title', 'r1' ) );
	}

	public function test_long_names_are_capped_and_keep_suffix() {
		$f = WarpSearch_WC_Field_Names::field( str_repeat( 'a', 300 ), 'kw_ss' );
		$this->assertLessThanOrEqual( 127, strlen( $f ) );
		$this->assertStringEndsWith( '_kw_ss', $f );
	}

	public function test_document_contents() {
		$b   = new WarpSearch_WC_Document_Builder( new WarpSearch_WC_Field_Names(), 'WooCommerce' );
		$doc = $b->build( $this->product(), 'run1' );
		$this->assertSame( 'woocommerce.42', $b->document_id( 42 ) );
		$this->assertSame( 'woocommerce', $doc['wc_index_s'] );
		$this->assertSame( 'run1', $doc['wc_run_s'] );
		$this->assertSame( array( 49.0, 59.5 ), $doc['f_price_dc_ds'] );
		$this->assertSame( array( '49', '59.5' ), $doc['f_price_dk_ss'] );
		$this->assertSame( array( 'TS-1', 'TS-1-S', 'TS-1-M' ), $doc['f_sku_kw_ss'] );
		$this->assertSame( array( 'Red', 'Blue' ), $doc['f_attr_color_kw_ss'] );
		$this->assertSame( array( 1 ), $doc['f_in_stock_in_is'] );
		$this->assertSame( "Trail Shoe\nTS-1\nTS-1-S\nTS-1-M", $doc['all_r1_un'] );
		$this->assertSame( 'trail shoe', $doc['f_title_so_s'] );
		$this->assertSame( array( '2023-11-14T22:13:20.000Z' ), $doc['f_date_dt_dts'] );
		$this->assertArrayNotHasKey( 'f_stock_quantity_in_is', $doc );
	}

	public function test_no_run_field_for_incremental() {
		$b = new WarpSearch_WC_Document_Builder( new WarpSearch_WC_Field_Names(), 'woocommerce' );
		$this->assertArrayNotHasKey( 'wc_run_s', $b->build( $this->product() ) );
	}

	public function test_search_query_shape() {
		$q    = new WarpSearch_WC_Query_Builder( new WarpSearch_WC_Field_Names(), 'woocommerce' );
		$body = $q->search( array( 'query' => 'trail sh', 'categories' => array( 'shoes' ), 'in_stock_only' => true, 'min_price' => 10, 'sort' => 'price_asc', 'from' => 24, 'size' => 12 ) );
		$this->assertSame( 24, $body['from'] );
		$this->assertSame( array( array( 'f_price_dc_ds' => array( 'order' => 'asc' ) ) ), $body['sort'] );
		$should = $body['query']['bool']['must'][0]['bool']['should'];
		$this->assertCount( 4, $should );
		$this->assertSame( 'trail sh', $should[1]['match_bool_prefix']['all_r1_un']['query'] );
		$this->assertSame( 6.0, $should[1]['match_bool_prefix']['all_r1_un']['boost'] );
		$this->assertSame( array( 'shoes' ), $body['post_filter']['bool']['filter'][0]['terms']['f_category_slug_kw_ss'] );
		$this->assertSame( array( 'wc_index_s' => array( 'woocommerce' ) ), $body['query']['bool']['filter'][0]['terms'] );
	}

	public function test_empty_query_has_no_must() {
		$q = new WarpSearch_WC_Query_Builder( new WarpSearch_WC_Field_Names(), 'woocommerce' );
		$this->assertArrayNotHasKey( 'must', $q->search( array() )['query']['bool'] );
	}

	public function test_stale_query_excludes_current_run() {
		$q = new WarpSearch_WC_Query_Builder( new WarpSearch_WC_Field_Names(), 'woocommerce' );
		$b = $q->stale( 'r9', 50 );
		$this->assertSame( array( 'wc_run_s' => array( 'r9' ) ), $b['query']['bool']['must_not'][0]['terms'] );
	}
}
