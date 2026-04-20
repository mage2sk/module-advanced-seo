# Changelog

All notable changes to this extension are documented here. The format
is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.0.6]

### Fixed
- **Category pages 500'd when a structured-data provider touched the product
  collection first.** `SaleEventProvider::deriveSaleDateRange()` and
  `ProductListProvider::buildListItems()` both iterated the shared category /
  layer product collection without forcing `DISTINCT` and without a
  try/catch around the load. On multi-source / shared-catalog setups, the
  default stock + category-product joins produce duplicate `entity_id` rows,
  which trip the collection's "Item with the same ID already exists" guard
  and 500 the whole page before `ListProduct` ever renders. Both providers
  now force `distinct(true)` on the select and load via `getItems()` inside
  a `try/catch`, silently skipping their structured-data contribution when
  the collection cannot be materialised, so the page always renders.

## [1.0.5]

### Performance
- **Removed the redundant `RemoveNativeOgPlugin` `afterToHtml` plugin.**
  It was declared on `Magento\Framework\View\Element\AbstractBlock`, so it
  fired for every block on every frontend page (400–1500 invocations per
  category render). After the 1.0.2 pattern fix, the observer on
  `layout_generate_blocks_after` reliably removes all native OG blocks from
  the layout, so the plugin's "safety net" role is obsolete. Deleting it
  removes the per-block overhead entirely. Native OG suppression behaviour
  is unchanged.

## [1.0.4]

### Fixed
- **GA4 `view_item_list` no longer 500s the category page.** When the layer
  product collection contained duplicate `entity_id` rows (stock joins on
  multi-source / shared catalog setups), iterating it raised
  `Item with the same ID already exists` from inside the GA4 block,
  bringing the entire category render down with a 500. The block now
  forces the load via `getItems()` inside a try/catch and silently skips
  the GA4 event when the underlying collection cannot be materialised,
  so the page always renders.

## [1.0.3]

### Fixed — Product feed generation
- **Stock filter no longer crashes feed generation.** The `joinField()` on
  `cataloginventory_stock_item` in `ProfileBasedFeedBuilder` could produce
  multiple rows per product (multi-source / shared catalog setups), tripping
  the collection's "Item with the same ID already exists" guard and aborting
  the feed run. Switched to a raw `getSelect()->joinLeft()` that preserves
  primary-key uniqueness.
- **`<g:shipping>` is now valid nested XML.** `XmlFeedWriter` previously
  emitted shipping as flat text (`<g:shipping>IN:::0 INR</g:shipping>`),
  which Google rejects. The writer now splits the `COUNTRY:::PRICE` payload
  on `:::` and emits proper `<g:country>` / `<g:price>` children.
- **`sale_price_effective_date` now matches Google's spec.** Format changed
  from `Y-m-d\TH:i:sO` to `Y-m-d\TH:iO` (no seconds), and partial ranges
  (`from/`, `/to`) are no longer emitted — Google rejects both. The element
  is now written only when both dates exist.
- **`<g:identifier_exists>` is always emitted.** Previously only the `false`
  case was written; now `true` is written whenever a brand, GTIN, or MPN is
  present. Removes Merchant Center "missing identifier" warnings for items
  that actually have brand/MPN.
- **Brand falls back to `panth_seo/structured_data/default_brand`.** When a
  product has no value for the configured brand attribute (commonly
  `manufacturer`), the feed now uses the store-level default brand instead
  of leaving the field blank.
- **`<g:google_product_category>` is always emitted.** Falls back to
  `Apparel & Accessories > Jewelry` when no attribute is configured or the
  product attribute is empty, so the field is never silently omitted.

## [1.0.2]

### Fixed
- OG block detection no longer false-matches `catalog.*` blocks. The `'og.'`
  pattern in `RemoveNativeOgObserver` and `RemoveNativeOgPlugin` was matched
  with `str_contains`, so any layout block whose name contains the substring
  `"og."` (every `catalog.*` block — `catalog.leftnav`,
  `catalog.navigation.state`, `catalog.list.item.addto`,
  `catalog.list.item.wishlist`, `catalog.compare.sidebar`, etc.) was silently
  removed from the layout and blanked in `toHtml()` on every frontend
  category render. The pattern is now matched as a prefix (`str_starts_with`)
  while `'opengraph'` continues to match as a substring. Restores layered
  navigation, add-to-cart toolbar, wishlist, and compare blocks on category
  pages. Search-result pages are unaffected (the substring is `"ogs"`, not
  `"og."`, so they were never matched).

## [1.0.1]

### Changed
- Documentation tidy-up; no functional changes.

## [1.0.0] — Initial release

### Added — Meta templates & resolution
- **Smarty-lite token engine** with `{name}`, `{price}`, `{sku}`,
  `{category}`, `{store}`, `{attribute:X}`, `{description}` tokens
  for product, category, and CMS page meta titles and descriptions.
- **Token registry** (`Panth\AdvancedSEO\Model\Meta\TokenRegistry`)
  allowing third-party modules to register custom tokens.
- **Per-entity override** fields on product, category, and CMS edit
  pages — overrides always take precedence over templates.
- **Bulk editor** admin grid for viewing and editing resolved meta
  across hundreds of entities at once.
- **Resolved meta indexer** (`panth_seo_resolved_meta`) with mview
  support for incremental reindexing.
- **Resolved meta cache** layer to avoid redundant resolution on
  every page load.

### Added — Canonical URL resolver
- **Automatic canonical resolution** with query-parameter stripping,
  pagination awareness, and layered-navigation handling.
- Configurable per store view.

### Added — Robots & LLM bot control
- **Dynamic robots.txt** served from database via a dedicated
  controller (`Panth\AdvancedSEO\Controller\Robots\Index`).
- **Per-LLM-bot allow/deny** for GPTBot, ClaudeBot,
  Google-Extended, CCBot, PerplexityBot, and Bytespider.
- **Default robots policy** installed via data patch
  (`Setup\Patch\Data\InstallDefaultRobotsPolicy`).

### Added — Hreflang
- **Hreflang group management** with locale-to-store-view mapping
  and x-default designation.
- **Auto-binder** (`Panth\AdvancedSEO\Model\Hreflang\AutoBinder`)
  that matches entities across store views by SKU / URL key.
- **Reciprocity validation** flagging broken hreflang pairs.
- **Hreflang indexer** (`panth_seo_hreflang`) with mview support.

### Added — Redirects & 404 management
- **Redirect matcher** supporting literal and PCRE regex source paths
  with 301, 302, and 503 (maintenance) redirect types.
- **Loop detection** (`Panth\AdvancedSEO\Model\Redirect\Loop`)
  preventing redirect chains and loops.
- **Bulk CSV import** via CLI
  (`bin/magento panth:seo:redirect-import --file=X`).
- **404 logging** with deduplication, hit counting, and referer
  tracking.
- **404 clustering** grouping similar missing URLs for bulk redirect
  creation.
- **Suggestion engine** (`Panth\AdvancedSEO\Model\Redirect\SuggestionEngine`)
  recommending redirect targets based on URL similarity.

### Added — Structured data (JSON-LD)
- **Six providers** out of the box: Product, Breadcrumb, Organization,
  WebSite, FAQPage, Article, plus a Video provider.
- **Structured data validator** checking generated JSON-LD against
  schema.org requirements.
- Server-rendered output injected via layout XML — no frontend JS.

### Added — Social meta (OpenGraph & Twitter)
- Automatic `og:*` and `twitter:*` tag generation for all pages.
- Per-entity override fields for OG title, description, and image.
- Configurable default images, Twitter card type, and Facebook App ID.

### Added — SEO rules engine
- **Condition-combine tree** matching on entity type, attributes,
  stock status, price range, category membership, and URL patterns.
- **Three action types**: Template (apply meta template), Canonical
  (override canonical), and Noindex (set robots directive).
- Priority-based evaluation — first matching rule wins.

### Added — SEO scoring & audit
- **Five check types**: Length, Duplicate, Readability, Entity
  (structured data completeness), and Keyword presence.
- **0-100 score** per entity with weighted scoring.
- **Audit dashboard** showing store-wide SEO health, issue
  distribution, and trend tracking.
- **Queue-based scoring** via `panth.seo.score` message queue
  with async consumer.
- **Embedding index** for semantic duplicate detection.
- **CLI audit** command: `bin/magento panth:seo:audit`.

### Added — AI content generation
- **Three adapters**: OpenAI (GPT-4o/4/3.5), Claude (Sonnet/Opus),
  and Null (disabled).
- **Monthly budget control** with spend tracking.
- **Result caching** with configurable TTL.
- **Generation job tracking** grid in admin.
- **CLI generation**: `bin/magento panth:seo:generate-meta`.

### Added — Sitemaps (XML & HTML)
- **Sharded XML sitemaps** with configurable entries per file.
- **Image extension** including product images as `<image:image>`.
- **Hreflang extension** including alternate URLs as `<xhtml:link>`.
- **Delta tracker** for incremental sitemap regeneration.
- **HTML sitemap** page with pagination, respecting noindex rules.

### Added — Image SEO
- **Alt-text templates** using the same token engine as meta templates.
- **Vision adapter interface** for AI-powered alt-text generation.
- Null adapter shipped as default (disabled).

### Added — Cross-linking & internal links
- **Internal link graph** builder.
- **PageRank calculator** with configurable decay factor.
- **Link suggester** recommending contextual internal links.
- **RelatedLinks ViewModel** rendering link suggestions on frontend.
- **CLI**: `bin/magento panth:seo:pagerank`.

### Added — Filter URL control
- Noindex/nofollow rules for layered navigation filtered pages.
- Canonical-to-parent for filtered URLs.
- Whitelist for indexable filter combinations (e.g., brand pages).

### Added — IndexNow & Search Console
- **IndexNow integration** pinging Bing, Yandex, Seznam, and Naver
  on content save.
- Optional Google Search Console API integration for crawl error
  monitoring.

### Added — llms.txt
- **Dynamic `/llms.txt` endpoint** built by
  `Panth\AdvancedSEO\Model\LlmsTxt\Builder`.
- Auto-generated store information with custom directive support.

### Added — Product feeds
- Google Shopping XML, Facebook Catalog, and generic CSV formats.
- Attribute selection, category filtering, stock filtering.
- Cron-based and CLI generation.

### Added — Analytics integration
- GA4 and Matomo support for SEO event tracking.
- Internal link click tracking, structured data impression events.

### Added — Admin UI
- Dashboard, Templates grid, Rules grid, Bulk Editor, Redirects grid,
  Hreflang Groups, Sitemap settings, Robots/LLM policy, Audit,
  AI Settings, 404 Log, Generation Jobs.
- SERP preview JS component (`view/adminhtml/web/js/serp-preview.js`).

### Compatibility
- Magento Open Source / Commerce / Cloud 2.4.4 - 2.4.8
- PHP 8.1, 8.2, 8.3, 8.4
- Hyva theme — fully compatible (no jQuery, server-rendered output)
- Luma theme — fully compatible without modification

---

## Support

For all questions, bug reports, or feature requests:

- **Email:** kishansavaliyakb@gmail.com
- **Website:** https://kishansavaliya.com
- **WhatsApp:** +91 84012 70422
