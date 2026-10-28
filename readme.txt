=== WarpSearch for WooCommerce ===
Contributors: warpsearch
Tags: woocommerce, search, product search, instant search, ajax search
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fast, relevant WooCommerce product search with as-you-type results, powered by WarpSearch, the serverless search service.

== Description ==

[WarpSearch](https://warpsearch.io/) is a fast, affordable serverless search service. This plugin indexes your WooCommerce catalog in WarpSearch and answers product searches from it, so you get better relevance and instant results without running Elasticsearch, OpenSearch or Solr yourself.

= Features =

* **Better relevance**: titles and SKUs weighted highest, then categories, tags, attributes and descriptions. Variation SKUs and attributes are searchable on the parent product.
* **Instant search**: the "WarpSearch Product Search" block and the `[warpsearch_products]` shortcode show results as shoppers type, with sorting by relevance, price, newest, title or popularity.
* **Replace store search**: optionally answer the standard WooCommerce product search from WarpSearch, with automatic fallback to WooCommerce search if the service is unreachable.
* **Always in sync**: products are re-indexed when they are saved, trashed, deleted, unpublished or their stock changes. Failed writes are retried with Action Scheduler.
* **Full reindex without downtime**: batched from the admin screen or with `wp warpsearch reindex`; old documents are removed only after the new index is complete.
* **Live data**: results are rendered from WooCommerce, so prices, images and visibility are always current.
* **Private by design**: searches go through your own site, so API keys never reach visitors' browsers and visitors' IP addresses are not sent to WarpSearch.
* Compatible with HPOS and block themes. WP-CLI commands for reindexing and status.

The free WarpSearch plan is enough to start. See [plans for e-commerce](https://warpsearch.io/use-cases/ecommerce) and the [setup guide](https://warpsearch.io/docs/integration-woocommerce).

= External service =

This plugin relies on WarpSearch (https://warpsearch.io), a third-party hosted search service; a WarpSearch account and API key are required. Nothing is sent until you enter a key in WooCommerce > WarpSearch.

* What is sent: product data (names, descriptions, short descriptions, SKUs, prices, categories, tags, brands, attributes, stock, catalog visibility, featured and sale flags, sales count and creation date) when products are saved, changed or reindexed; and the search terms, filters and sort order that visitors enter, when they search. Searches are sent from your server, so visitors' IP addresses and browser details are not sent to WarpSearch.
* Where: the API endpoint configured in the settings (https://api.warpsearch.io by default).
* Terms of Service: https://warpsearch.io/terms
* Privacy Policy: https://warpsearch.io/privacy

== Installation ==

1. In the WarpSearch console create a collection and an API key with the writer role (and, optionally, a separate key for searching).
2. Install and activate the plugin (WooCommerce required).
3. Go to WooCommerce > WarpSearch, enter endpoint, collection and keys, then use "Test connection".
4. Click "Reindex all products".
5. Add the block or `[warpsearch_products]` to a page, or enable "Replace store search".

== Frequently Asked Questions ==

= Does the plugin create the collection? =
No. Create it once in the WarpSearch console. The writer key only needs write access.

= Can several sites share a collection? =
Yes, give each site a different "Index name"; documents are tagged with it.

= Where is the block? =
The "WarpSearch Product Search" block appears in the block inserter on WordPress 7.0 and later. On older versions use the `[warpsearch_products]` shortcode (for example in a Shortcode block).

= Do I need to run a search server? =
No. WarpSearch is a hosted service; the plugin only needs a WarpSearch account and an API key.

= Is the search key exposed to visitors? =
No. Searches are sent from your server through a REST route on your site.

= What happens on uninstall? =
Plugin settings and scheduled actions are removed. Documents stay in WarpSearch.

== Changelog ==

= 0.1.0 =
* First version.
