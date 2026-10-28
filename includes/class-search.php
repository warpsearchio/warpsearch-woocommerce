<?php
/**
 * Search integration: REST proxy (keeps the search key on the server), shortcode, block and the optional
 * replacement of the default WooCommerce product search.
 *
 * @package WarpSearch
 */

defined( 'ABSPATH' ) || exit;

class WarpSearch_WC_Search {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
		add_shortcode( 'warpsearch_products', array( __CLASS__, 'shortcode' ) );
		add_action( 'init', array( __CLASS__, 'register_block' ) );
		add_action( 'init', array( __CLASS__, 'register_assets' ) ); // Block themes render content before wp_enqueue_scripts.
		if ( WarpSearch_WC_Settings::get( 'replace_search' ) ) {
			add_filter( 'posts_pre_query', array( __CLASS__, 'replace_main_search' ), 10, 2 );
		}
	}

	public static function query_builder() {
		$s = WarpSearch_WC_Settings::all();
		return new WarpSearch_WC_Query_Builder( new WarpSearch_WC_Field_Names( (bool) $s['english_analysis'] ), $s['index_alias'] );
	}

	/** @return array|WP_Error array( 'ids' => int[], 'total' => int, 'categories' => array( name => count ) ) */
	public static function run( array $args ) {
		if ( '' === (string) WarpSearch_WC_Settings::get( 'search_key' ) && '' === (string) WarpSearch_WC_Settings::get( 'writer_key' ) ) {
			return new WP_Error( 'warpsearch_no_key', 'No search key' );
		}
		$res = WarpSearch_WC_Client::for_searching()->search( self::query_builder()->search( $args ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$ids = array();
		foreach ( isset( $res['hits']['hits'] ) ? $res['hits']['hits'] : array() as $hit ) {
			if ( isset( $hit['_source'][ WarpSearch_WC_Field_Names::F_ID ] ) ) {
				$ids[] = (int) $hit['_source'][ WarpSearch_WC_Field_Names::F_ID ];
			}
		}
		$cats = array();
		foreach ( isset( $res['aggregations']['categories']['buckets'] ) ? $res['aggregations']['categories']['buckets'] : array() as $b ) {
			$cats[ (string) $b['key'] ] = (int) $b['doc_count'];
		}
		return array(
			'ids'        => $ids,
			'total'      => (int) ( isset( $res['hits']['total']['value'] ) ? $res['hits']['total']['value'] : count( $ids ) ),
			'categories' => $cats,
		);
	}

	/* ---------- default search replacement ---------- */

	public static function replace_main_search( $posts, $query ) {
		if ( null !== $posts || is_admin() || ! $query->is_main_query() || ! $query->is_search() ) {
			return $posts;
		}
		$types = (array) $query->get( 'post_type' );
		if ( ! in_array( 'product', $types, true ) ) {
			return $posts;
		}
		$per   = max( 1, (int) $query->get( 'posts_per_page' ) );
		$paged = max( 1, (int) $query->get( 'paged' ) );
		$res   = self::run( array( 'query' => (string) $query->get( 's' ), 'from' => ( $paged - 1 ) * $per, 'size' => $per, 'facets' => false ) );
		if ( is_wp_error( $res ) ) {
			WarpSearch_WC_Indexer::log( 'Search failed, falling back to WooCommerce search: ' . $res->get_error_message() );
			return $posts; // Fall back to the default WordPress query.
		}
		$out = array();
		foreach ( $res['ids'] as $id ) {
			$post = get_post( $id );
			$product = wc_get_product( $id );
			if ( $post && 'publish' === $post->post_status && $product && $product->is_visible() && ! post_password_required( $post ) ) {
				$out[] = $post;
			}
		}
		$query->found_posts   = $res['total'];
		$query->max_num_pages = (int) ceil( $res['total'] / $per );
		return $out;
	}

	/* ---------- REST ---------- */

	public static function register_route() {
		register_rest_route(
			'warpsearch/v1',
			'/search',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'rest_search' ),
				'args'                => array(
					'q'        => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'page'     => array( 'type' => 'integer', 'default' => 1, 'sanitize_callback' => 'absint' ),
					'sort'     => array( 'type' => 'string', 'default' => 'relevance', 'sanitize_callback' => 'sanitize_key' ),
					'category' => array( 'type' => 'array', 'default' => array() ),
					'in_stock' => array( 'type' => 'boolean', 'default' => false ),
				),
			)
		);
	}

	public static function rest_search( WP_REST_Request $req ) {
		$per  = (int) WarpSearch_WC_Settings::get( 'results_per_page' );
		$page = max( 1, (int) $req['page'] );
		$cats = array_map( 'sanitize_title', (array) $req['category'] );
		$sort = in_array( $req['sort'], array( 'relevance', 'price_asc', 'price_desc', 'newest', 'title', 'popularity' ), true ) ? $req['sort'] : 'relevance';
		$res  = self::run(
			array(
				'query'         => (string) $req['q'],
				'categories'    => $cats,
				'in_stock_only' => (bool) $req['in_stock'],
				'sort'          => $sort,
				'from'          => ( $page - 1 ) * $per,
				'size'          => $per,
			)
		);
		if ( is_wp_error( $res ) ) {
			return new WP_REST_Response( array( 'error' => 'search_unavailable' ), 502 );
		}
		return new WP_REST_Response(
			array(
				'total'      => $res['total'],
				'page'       => $page,
				'pages'      => (int) ceil( $res['total'] / $per ),
				'html'       => self::render_cards( $res['ids'] ),
				'categories' => $res['categories'],
			)
		);
	}

	/** Cards are hydrated from WordPress (the index only returns ids), so prices, images and visibility are always current. */
	public static function render_cards( array $ids ) {
		ob_start();
		foreach ( $ids as $id ) {
			$p = wc_get_product( $id );
			if ( ! $p || 'publish' !== $p->get_status() || ! $p->is_visible() || post_password_required( $id ) ) {
				continue;
			}
			printf(
				'<li class="warpsearch-card"><a href="%1$s">%2$s<span class="warpsearch-title">%3$s</span></a><span class="warpsearch-price">%4$s</span>%5$s</li>',
				esc_url( $p->get_permalink() ),
				wp_kses_post( $p->get_image( 'woocommerce_thumbnail' ) ),
				esc_html( $p->get_name() ),
				wp_kses_post( $p->get_price_html() ),
				$p->is_in_stock() ? '' : '<span class="warpsearch-oos">' . esc_html__( 'Out of stock', 'warpsearch-for-woocommerce' ) . '</span>'
			);
		}
		return ob_get_clean();
	}

	/* ---------- front end ---------- */

	public static function register_assets() {
		wp_register_style( 'warpsearch-wc', WARPSEARCH_WC_URL . 'assets/search.css', array(), WARPSEARCH_WC_VERSION );
		wp_register_script( 'warpsearch-wc', WARPSEARCH_WC_URL . 'assets/search.js', array(), WARPSEARCH_WC_VERSION, true );
	}

	public static function register_block() {
		register_block_type(
			WARPSEARCH_WC_DIR . 'blocks/search',
			array( 'render_callback' => array( __CLASS__, 'render_block' ) )
		);
	}

	public static function render_block( $attributes = array() ) {
		return self::render_widget( is_array( $attributes ) ? $attributes : array() );
	}

	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'placeholder' => '', 'sort' => 'true' ), $atts, 'warpsearch_products' );
		return self::render_widget( $atts );
	}

	private static function render_widget( array $a ) {
		wp_enqueue_style( 'warpsearch-wc' );
		wp_enqueue_script( 'warpsearch-wc' );
		wp_localize_script(
			'warpsearch-wc',
			'WarpSearchWC',
			array(
				'endpoint'    => esc_url_raw( rest_url( 'warpsearch/v1/search' ) ),
				/* translators: %d: number of results */
				'results'     => __( '%d results', 'warpsearch-for-woocommerce' ),
				'unavailable' => __( 'Search is unavailable right now.', 'warpsearch-for-woocommerce' ),
			)
		);
		$placeholder = ! empty( $a['placeholder'] ) ? $a['placeholder'] : __( 'Search products', 'warpsearch-for-woocommerce' );
		$initial     = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		ob_start();
		?>
		<div class="warpsearch-widget" data-warpsearch>
			<input type="search" class="warpsearch-input" value="<?php echo esc_attr( $initial ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>" aria-label="<?php echo esc_attr( $placeholder ); ?>" />
			<select class="warpsearch-sort" aria-label="<?php esc_attr_e( 'Sort', 'warpsearch-for-woocommerce' ); ?>">
				<option value="relevance"><?php esc_html_e( 'Relevance', 'warpsearch-for-woocommerce' ); ?></option>
				<option value="price_asc"><?php esc_html_e( 'Price: low to high', 'warpsearch-for-woocommerce' ); ?></option>
				<option value="price_desc"><?php esc_html_e( 'Price: high to low', 'warpsearch-for-woocommerce' ); ?></option>
				<option value="newest"><?php esc_html_e( 'Newest', 'warpsearch-for-woocommerce' ); ?></option>
			</select>
			<div class="warpsearch-status" aria-live="polite"></div>
			<ul class="warpsearch-results products"></ul>
			<button type="button" class="warpsearch-more" hidden><?php esc_html_e( 'Load more', 'warpsearch-for-woocommerce' ); ?></button>
		</div>
		<?php
		return ob_get_clean();
	}
}
