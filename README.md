# WarpSearch for WooCommerce

Fast, relevant **WooCommerce product search** with as-you-type results, filters and facets,
powered by [WarpSearch](https://warpsearch.io/), the fast, affordable serverless search service.
No Elasticsearch, OpenSearch or Solr server to run: your catalog is indexed in WarpSearch and
searches are answered from there.

- **Better relevance** across titles, SKUs (including variation SKUs), categories, tags,
  attributes and descriptions, with titles and SKUs weighted highest
- **Instant search**: the "WarpSearch Product Search" block and `[warpsearch_products]` shortcode
  show results as shoppers type, with sorting and paging; the REST route also filters by
  category and stock and returns category facet counts
- **Drop-in replacement** for the default WooCommerce product search (optional), with automatic
  fallback to WooCommerce search if the service is unreachable
- **Always in sync**: products are re-indexed when they are saved, trashed, unpublished or their
  stock changes; failed writes are retried through Action Scheduler
- **Batched full reindex** from the admin screen or `wp warpsearch reindex`, without taking
  search offline
- **Private by design**: searches go through your own site's REST route, so API keys never reach
  visitors' browsers and visitors' IP addresses are not sent to WarpSearch
- **Current data**: results are hydrated from WooCommerce, so prices, images and visibility are
  always live
- Compatible with **HPOS** (high-performance order storage) and block themes

Docs: [warpsearch.io/docs/integration-woocommerce](https://warpsearch.io/docs/integration-woocommerce) ·
Plans: [warpsearch.io/use-cases/ecommerce](https://warpsearch.io/use-cases/ecommerce) ·
Support: [hello@warpsearch.io](mailto:hello@warpsearch.io)

## Requirements

WordPress 6.2+ (the block needs 7.0+; the shortcode works everywhere), WooCommerce 8.0+, PHP 7.4+,
and a [WarpSearch account](https://warpsearch.io/). The free plan is enough to start.

## Installation

1. In the [WarpSearch console](https://warpsearch.io/console/), create a collection (for example
   `myshop_prod`, no mapping needed) and an API key with the **writer** role on it. Optionally
   create a separate key for searching.
2. Install the plugin from the WordPress plugin directory (search for "WarpSearch"), or upload the
   release zip under **Plugins > Add New > Upload**, and activate it.
3. Open **WooCommerce > WarpSearch**, enter the collection and key, and click **Test connection**.
4. Click **Reindex all products**.
5. Add the **WarpSearch Product Search** block or `[warpsearch_products]` to a page, or enable
   **Replace store search**.

WP-CLI: `wp warpsearch reindex`, `wp warpsearch index <product-id>...`, `wp warpsearch status`.

Several sites can share one collection: give each a different **Index name**.

## How it works

- One document per product, id `{index_name}.{product_id}`. Variations are folded into the parent:
  their SKUs, prices (min/max) and attribute values are searchable on the parent.
- Writes use the WarpSearch Elasticsearch-compatible `_bulk` endpoint, batched at the end of the
  request. Rate limits, 5xx responses and timeouts are retried with backoff, honoring
  `Retry-After`; items a cold collection rejects (`warming_up`) are resubmitted.
- A rebuild never wipes the index first. Each run stamps documents with a run id, and when it
  finishes, documents from older runs are removed.
- Searches are Elasticsearch query DSL (`match_bool_prefix` over relevance tiers, `terms`,
  `range`, `post_filter` and terms aggregations). The index returns product ids; the plugin renders
  current WooCommerce data.
- Uninstalling removes the plugin's settings and scheduled actions. Indexed documents stay in your
  WarpSearch collection.

### Data sent to WarpSearch

Product data (names, descriptions, SKUs, prices, categories, tags, brands, attributes, stock,
visibility, sale and featured flags, sales count, dates) when products change or are reindexed,
and the search terms, filters and sort order visitors use. Nothing is sent until a key is
configured. See the [terms](https://warpsearch.io/terms) and [privacy policy](https://warpsearch.io/privacy).

## Code layout

| Path | Purpose |
|---|---|
| `includes/class-field-names.php`, `class-document-builder.php`, `class-query-builder.php` | Field naming, document and query building (pure PHP, unit tested) |
| `includes/class-product-extractor.php` | WooCommerce product to array |
| `includes/class-indexer.php` | Hooks, end-of-request bulk writes, retries |
| `includes/class-reindexer.php`, `class-cli.php` | Batched rebuild, WP-CLI commands |
| `includes/class-search.php` | REST route `/wp-json/warpsearch/v1/search`, shortcode, block, search replacement |
| `includes/class-admin.php`, `class-settings.php` | **WooCommerce > WarpSearch** settings |
| `includes/class-client.php` | HTTP client |

## Development and tests

`bash tests/verify.sh` (PHP 7.4+ and Composer) lints the sources and runs PHPUnit; WordPress is
not needed for that step.

The live check runs on a **disposable** WordPress with WooCommerce, this plugin and WP-CLI. Export
`WARPSEARCH_API_KEY` in the environment (never on the command line), set `WARPSEARCH_TEST_SITE=1`,
a new `WARPSEARCH_TEST_COLLECTION=test_wc_<suffix>` and optionally `WARPSEARCH_TEST_ENDPOINT`, then
run `bash tests/verify.sh`. It creates its own collection and products, checks initial indexing,
variation SKU search, stock changes, rebuild and unpublish, and removes everything afterwards.
Never run it against a production database.

Tested with WordPress 7.1, WooCommerce 11.2 (HPOS on), PHP 8.3 and the Twenty Twenty-Five block
theme; Plugin Check reports no errors.

## License

GPL-2.0-or-later, see [LICENSE](LICENSE). WarpSearch by BigData Boutique: [warpsearch.io](https://warpsearch.io/).
