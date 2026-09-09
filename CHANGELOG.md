# Changelog

All notable changes to this extension are documented here. The format
is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.3.20] - 2026-09-09

### Fixed
- **Pages owned by another module carried two canonical tags.** Modules that register their own canonical on the page config, such as the blog listing, post, category, tag, author and search pages, plus dynamic form and FAQ pages, ended up with their tag and this module's tag on the same page. Two conflicting canonicals are usually ignored altogether by search engines, so those pages had no effective canonical at all. The view model already carried a `hasCanonicalInPageConfig()` check for exactly this, but nothing ever called it. It is now consulted first, and the owning module's tag is left alone. The suppression is reported as `already_in_page_config` in the debug log. Product, category, CMS and home pages are unaffected.

## [1.3.19] - 2026-09-09

### Fixed
- **The resolved-meta indexer never wrote a debug line.** `etc/di.xml` bound its config argument as `config`, but the constructor parameter is `$seoConfig`. Magento matches di.xml arguments by parameter name, so the binding was ignored and the nullable argument stayed `null`, which made the indexer's `debug()` return before doing anything. With the name corrected a full reindex now writes the trace it was always meant to. Same trap as the canonical view model in 1.3.18, one level up.

### Added
- A unit test walks every `<argument>` in the module's `di.xml` files and fails if its name is not a real constructor parameter of the target class, so a binding can no longer be silently ignored.

## [1.3.18] - 2026-09-09

### Added
- **A suppressed canonical now says why.** When no `<link rel="canonical">` is emitted, the reason is written to the module debug log as `panth_seo: canonical.suppressed` with a `decision` key. Until now an intentional suppression and a thrown exception were byte-for-byte identical from outside, and because this module also suppresses Magento's native canonical tag, a page could end up with no canonical from any source and no trace anywhere. Decisions reported: `noindex_page`, `ignored_path`, `noroute_noindex`, `canonical_disabled`, `no_url_built`, `build_failed`, `exception` and `is_enabled_threw`. An exception also carries its message, class, file and line, and the request URI.
- The reasons are reported from both places that can decide it: `Model\Canonical\Resolver`, which is what runs for a product, category or CMS page, and `ViewModel\Canonical`, which runs for everything else. The resolver previously logged only its successful decisions.
- Logging is behind the existing **Debug Logging** setting, so nothing is written on a production store with debug off. Emitted canonicals are unchanged in every case; this release adds no output and removes none.

### Fixed
- `ViewModel\Canonical` discarded every exception, so a failure anywhere inside it, a bad store, a broken custom-canonical row, a repository error, silently became "no canonical on this page". The exception is now logged before the empty string is returned. The same applied to `isEnabled()`, which turned a config read failure into canonicals being off sitewide with no trace.
- `etc/di.xml` binds the debug logger into `ViewModel\Canonical`. Magento DI passes `null` for a nullable optional argument unless it is bound explicitly, so without this entry the new logging would never fire.

## [1.3.17] - 2026-09-09

Replaces 1.3.16, which was withdrawn. The code is identical. The sample strings
used by the new unit tests have been replaced with neutral catalogue text.

### Fixed
- **Meta titles and descriptions no longer cut a word in half.** Truncation kept a hard character count, so a title could end `... with Vacu...`. It now steps back to the last space, and only when that space keeps at least 60 per cent of the available room, so a long unbroken string still truncates instead of collapsing to a fragment. Trailing spaces and punctuation are trimmed before the ellipsis.
- **Multibyte meta was truncated by bytes and could be cut to a third of its length.** After the character-length check passed, a second byte-length check ran on the same value, so a 47 character Devanagari title with a 60 character limit was cut to 24 characters, and the cut could land inside a character and emit invalid UTF-8. The byte-length path is now only used when the mbstring extension is missing.
- The store name suffix path in the head plugin uses the same word-boundary rule, and the finished title still fits inside the configured title length.
- Google Merchant and custom feed descriptions use the same rule, so a feed description no longer ends mid-word.

### Changed
- The truncation logic lived in five places and has moved into one `Model\Text\Truncator` service, injected where it is needed, so the call sites cannot drift apart.
- `Plugin\PageConfig\HeadPlugin` builds the title through a `composeTitle` method instead of inline branching.

### Added
- Unit tests for the truncator and the head plugin title: word boundaries, an unbroken 200 character token, Devanagari, accented Latin and Japanese input, values under the limit, and a check that the title plus store name always fits the configured limit.

## [1.3.15] - 2026-08-18

### Fixed
- **404 (no-route) pages are no longer indexable.** The 404 page previously rendered `robots index,follow` (from the shipped CMS meta template applied to the no-route page) together with a self-referencing canonical pointing at the requested 4XX URL, which SEO crawlers flag as "Canonical points to 4XX". The no-route page now defaults to `robots noindex,follow`, and the existing "Disable Canonical for NOINDEX Pages" guard drops the canonical tag on it. A layout override in the theme could not fix this because the module's CMS metadata plugin overrides layout-declared robots.

### Added
- New setting Stores > Configuration > Panth > Advanced SEO > Meta Tags > **Noindex 404 (No-Route) Page** (`panth_seo/meta/noindex_noroute`, default Yes). Turning it off restores the previous 404 behaviour.

## [1.3.14]

### Changed
- Replaced typographic characters (em dashes, curly quotes, ellipsis) with plain ASCII punctuation. No functional changes.
- Meta title and description truncation now appends "..." instead of the single-character ellipsis; truncated output still fits within the configured maximum length.

## [1.3.13]

### Changed
- Code cleanup: removed redundant inline comments and docblocks from the PHP source. No functional changes.

## [1.3.12] - 2026-06-18

### Changed

- README rewritten to match the Panth Infotech documentation standard: gold-template structure, Quick Answer block, full configuration table sourced from system.xml, companion modules table, and updated SEO keywords.

## [1.3.11] - 2026-06-14

### Fixed

- **SEO scores are now persisted instead of silently failing.** `Model/Score/Scorer.php` wrote a non-existent `updated_at` column to `panth_seo_score`, so every persist threw `SQLSTATE[42S22] Unknown column 'updated_at'`. The exception was caught and logged, so the storefront kept working, but no score row was ever written and `var/log/system.log` filled with one error per entity × store on every recompute. The persist now uses the schema's `computed_at` column, referenced via the `SeoScoreInterface` constants so the column names cannot drift again.
- **Meta-embedding (duplicate-content) storage no longer fails on every score.** `Model/Score/EmbeddingIndex.php` wrote `dims`/`updated_at` to `panth_seo_meta_embedding` and omitted the required `field`/`hash` columns, none of which matched the schema (`dimensions`, `field`, `hash`, `created_at`). Every `DuplicateCheck` run logged `Unknown column 'dims'`. The writer now matches the schema, keying one combined title+description vector per entity under a stable `field`, so near-duplicate detection works and the log stays clean.

## [1.3.9] - 2026-05-13

### Fixed

- **Frontend route `frontName` no longer collides with other extensions on the same project.** `etc/frontend/routes.xml` previously declared `frontName="seo"`, a generic value that any other installed SEO extension is likely to claim. Magento merges `routes.xml` across all modules and the XSD enforces uniqueness on `frontName`, so the duplicate caused `Element 'route': Duplicate key-sequence ['seo']` and 500'd every storefront request after both modules loaded. The route id `panth_seo` is unchanged - only the public URL prefix moves.

### Changed

- The Google Merchant feed URL moves from `/seo/feed/google` to `/panth_seo/feed/google`. Two admin-config comments (`etc/adminhtml/system.xml`) and one controller docblock (`Controller/Feed/Google.php`) were updated to match.

### Migration

Update the URL in any external integration that fetches the feed - typically the Merchant Center "Add a primary feed" URL, monitoring probes, and external test scripts - from `/seo/feed/google` to `/panth_seo/feed/google`.

## [1.2.0] - 2026-04-21

### BREAKING - XML Sitemap extracted

The XML Sitemap feature has been extracted into a dedicated Packagist
module:

- **XML Sitemap** -> `mage2kishan/module-xml-sitemap`
  Sharded XML sitemap generator with per-store profile CRUD, 7 entity-
  type contributors (product, category, CMS page, landing page, blog,
  video, additional links), hreflang + image + video tags, auto-split
  at configurable threshold, gzip compression, XSL stylesheet, search-
  engine ping, delta tracking, async shard queue, cron, CLI.
  Table names preserved (`panth_seo_sitemap_profile`,
  `panth_seo_sitemap_shard`) - zero data migration required.

### Removed

- `Controller/Adminhtml/Sitemap/*`, `Controller/Sitemap/Index`
- `Model/Sitemap/*`, `Model/SitemapProfile`, `Model/ResourceModel/SitemapProfile*`
- `Model/Queue/SitemapShardConsumer`
- `Model/Config/Source/SitemapChangefreq`
- `Block/Adminhtml/Sitemap*`, `Ui/Component/Form/DataProvider/SitemapProfileFormDataProvider`, `Ui/Component/Listing/Column/SitemapActions`
- `Plugin/Sitemap/{CategoryFormSitemapPlugin,ProductFormSitemapPlugin}`
- `Cron/SitemapRebuild`, `Console/Command/SitemapGenerateCommand`
- `Setup/Patch/Data/{AddDefaultSitemapProfile,AddSitemapExclusionAttributes}`
- `Setup/Patch/Schema/AddSitemapProfileTable`
- `Api/{SitemapBuilderInterface,SitemapContributorInterface}`
- System-config group "Sitemaps" under Panth Infotech -> SEO
- Admin menu item "Sitemaps"
- DB tables declaration (tables owned by sibling module now)
- Cron job `panth_seo_sitemap_rebuild`
- AMQP topic `panth_seo.sitemap_shard`
- CLI command `panth:seo:sitemap:generate` (now provided by the sibling module)

### Migration notes

- Install `mage2kishan/module-xml-sitemap` to restore functionality:
  ```
  composer require mage2kishan/module-xml-sitemap
  bin/magento module:enable Panth_XmlSitemap
  bin/magento setup:upgrade
  bin/magento setup:di:compile
  ```
- DB tables preserved; all existing sitemap profiles keep working.
- `/panth-sitemap.xml` URL remains unchanged (url_rewrite updated by the new module's patch).

## [1.1.0] - 2026-04-21

### BREAKING - feature split

Panth_AdvancedSEO has been refactored into a family of focused modules. Five
feature areas have been extracted into dedicated Packagist modules. Installing
only `mage2kishan/module-advanced-seo` after upgrading to 1.1.0 will remove
the following features; reinstall the corresponding sibling module to restore
each.

- **Cross-Links** -> `mage2kishan/module-crosslinks`
  Auto keyword -> internal-link replacement in CMS / product / category HTML.
  Same table name (`panth_seo_crosslink`) - zero data migration required.

- **Redirects & 404s** -> `mage2kishan/module-redirects`
  301/302/303/307/308/410/451 redirects, 404 log with clustering, CSV
  import/export, homepage-alias canonicaliser, lowercase + trailing-slash
  normalisers, expiry cron, loop detector, XHR guard. Table names preserved
  (`panth_seo_redirect`, `panth_seo_404_log`, `panth_seo_404_cluster`).

- **Robots & LLM Bots** -> `mage2kishan/module-robots-seo`
  Dedicated `/robots.txt` endpoint, `X-Robots-Tag` HTTP response header,
  per-entity `<meta name="robots">` pipeline, 14 LLM / AI crawler toggles
  (GPTBot, ClaudeBot, PerplexityBot, Google-Extended, Bytespider, CCBot,
  Applebot-Extended, Meta-ExternalAgent, Amazonbot, Cohere-AI, ...). Table
  name preserved (`panth_seo_robots_policy`).

- **AI Meta Generation** -> `mage2kishan/module-pagebuilder-ai`
  OpenAI + Claude adapter factory, monthly token budget, response cache,
  async job queue, AI Prompts CRUD, AI Knowledge Base with seeded reference
  content, "Generate with AI" button injection on product / category / CMS
  Page / FAQ / Testimonial / Banner / Dynamic Form admin surfaces. Table
  names preserved (`panth_seo_ai_prompt`, `panth_seo_ai_knowledge`,
  `panth_seo_ai_usage`, `panth_seo_ai_cache`, `panth_seo_generation_job`).

- **HTML Sitemap** -> `mage2kishan/module-html-sitemap`
  Frontend `/sitemap` HTML page with categories, products, CMS pages, stores,
  custom links. Custom router rewriting `/sitemap` -> module controller.

### Removed

- ~70 files - controllers, models, blocks, plugins, UI components,
  layouts, templates, setup patches belonging exclusively to the five
  extracted features.
- System-config groups under `Stores -> Configuration -> Panth Infotech ->
  SEO`: Auto Cross-Links, Redirects, Robots & LLM Bots, AI Meta Generation,
  HTML Sitemap. Each is now exposed by its own module's config section.
- 9 LLM bot toggles + robots.txt custom-body override (moved to Panth_RobotsSeo).
- DB tables `panth_seo_crosslink`, `panth_seo_redirect`, `panth_seo_404_log`,
  `panth_seo_404_cluster`, `panth_seo_robots_policy`, `panth_seo_ai_prompt`,
  `panth_seo_ai_knowledge`, `panth_seo_ai_usage`, `panth_seo_ai_cache`,
  `panth_seo_generation_job` - Magento will NOT drop existing rows because
  the sibling module re-declares each table byte-identically.
- AI-approval columns (`ai_generated`, `ai_approved`) on `panth_seo_override`
  - Override usability no longer gates on AI approval. Panth_PageBuilderAi
  owns the AI review workflow separately.

### Changed

- Shared `Helper/Config.php`: removed ~40 deprecated constants + accessor
  methods for the five extracted features.
- `Plugin/PageConfig/HeadPlugin.php`: the "always set robots from config
  default" branch is removed. Per-entity `robots` column still feeds the
  `<meta name="robots">` tag via `Model/Meta/Resolver`. Install
  Panth_RobotsSeo to restore per-store default, noindex-paths, and
  advanced directives (`max-image-preview`, `max-snippet`).
- Admin dashboard (`Block/Adminhtml/Dashboard.php` + `dashboard.phtml`):
  dropped Active Crosslinks, Active Redirects, Recent 404s, AI Generation
  Status cards.
- `Plugin/Admin/{Product,Category,CmsPage}SeoFieldsPlugin.php`: "Generate
  with AI" button + prompt selector + image upload removed. Install
  Panth_PageBuilderAi to restore - it plugs into the same fieldsets via
  its own DI.

### Migration notes

- No database migration is required. Every table kept its pre-split name
  and shape; the sibling modules declare the same schemas.
- Composer users: after `composer update mage2kishan/module-advanced-seo`,
  run `composer require mage2kishan/module-<feature>` for each sibling you
  want, then `bin/magento setup:upgrade && setup:di:compile && cache:flush`.
- Admin config values saved under `panth_seo/*` paths for the extracted
  features are ignored by AdvancedSEO 1.1.0. The sibling modules expose
  their own config paths; re-save settings under the new paths.

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
  fired for every block on every frontend page (400-1500 invocations per
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

### Fixed - Product feed generation
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
  (`from/`, `/to`) are no longer emitted - Google rejects both. The element
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
  `"og."` (every `catalog.*` block - `catalog.leftnav`,
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

## [1.0.0] - Initial release

### Added - Meta templates & resolution
- **Smarty-lite token engine** with `{name}`, `{price}`, `{sku}`,
  `{category}`, `{store}`, `{attribute:X}`, `{description}` tokens
  for product, category, and CMS page meta titles and descriptions.
- **Token registry** (`Panth\AdvancedSEO\Model\Meta\TokenRegistry`)
  allowing third-party modules to register custom tokens.
- **Per-entity override** fields on product, category, and CMS edit
  pages - overrides always take precedence over templates.
- **Bulk editor** admin grid for viewing and editing resolved meta
  across hundreds of entities at once.
- **Resolved meta indexer** (`panth_seo_resolved_meta`) with mview
  support for incremental reindexing.
- **Resolved meta cache** layer to avoid redundant resolution on
  every page load.

### Added - Canonical URL resolver
- **Automatic canonical resolution** with query-parameter stripping,
  pagination awareness, and layered-navigation handling.
- Configurable per store view.

### Added - Robots & LLM bot control
- **Dynamic robots.txt** served from database via a dedicated
  controller (`Panth\AdvancedSEO\Controller\Robots\Index`).
- **Per-LLM-bot allow/deny** for GPTBot, ClaudeBot,
  Google-Extended, CCBot, PerplexityBot, and Bytespider.
- **Default robots policy** installed via data patch
  (`Setup\Patch\Data\InstallDefaultRobotsPolicy`).

### Added - Hreflang
- **Hreflang group management** with locale-to-store-view mapping
  and x-default designation.
- **Auto-binder** (`Panth\AdvancedSEO\Model\Hreflang\AutoBinder`)
  that matches entities across store views by SKU / URL key.
- **Reciprocity validation** flagging broken hreflang pairs.
- **Hreflang indexer** (`panth_seo_hreflang`) with mview support.

### Added - Redirects & 404 management
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

### Added - Structured data (JSON-LD)
- **Six providers** out of the box: Product, Breadcrumb, Organization,
  WebSite, FAQPage, Article, plus a Video provider.
- **Structured data validator** checking generated JSON-LD against
  schema.org requirements.
- Server-rendered output injected via layout XML - no frontend JS.

### Added - Social meta (OpenGraph & Twitter)
- Automatic `og:*` and `twitter:*` tag generation for all pages.
- Per-entity override fields for OG title, description, and image.
- Configurable default images, Twitter card type, and Facebook App ID.

### Added - SEO rules engine
- **Condition-combine tree** matching on entity type, attributes,
  stock status, price range, category membership, and URL patterns.
- **Three action types**: Template (apply meta template), Canonical
  (override canonical), and Noindex (set robots directive).
- Priority-based evaluation - first matching rule wins.

### Added - SEO scoring & audit
- **Five check types**: Length, Duplicate, Readability, Entity
  (structured data completeness), and Keyword presence.
- **0-100 score** per entity with weighted scoring.
- **Audit dashboard** showing store-wide SEO health, issue
  distribution, and trend tracking.
- **Queue-based scoring** via `panth.seo.score` message queue
  with async consumer.
- **Embedding index** for semantic duplicate detection.
- **CLI audit** command: `bin/magento panth:seo:audit`.

### Added - AI content generation
- **Three adapters**: OpenAI (GPT-4o/4/3.5), Claude (Sonnet/Opus),
  and Null (disabled).
- **Monthly budget control** with spend tracking.
- **Result caching** with configurable TTL.
- **Generation job tracking** grid in admin.
- **CLI generation**: `bin/magento panth:seo:generate-meta`.

### Added - Sitemaps (XML & HTML)
- **Sharded XML sitemaps** with configurable entries per file.
- **Image extension** including product images as `<image:image>`.
- **Hreflang extension** including alternate URLs as `<xhtml:link>`.
- **Delta tracker** for incremental sitemap regeneration.
- **HTML sitemap** page with pagination, respecting noindex rules.

### Added - Image SEO
- **Alt-text templates** using the same token engine as meta templates.
- **Vision adapter interface** for AI-powered alt-text generation.
- Null adapter shipped as default (disabled).

### Added - Cross-linking & internal links
- **Internal link graph** builder.
- **PageRank calculator** with configurable decay factor.
- **Link suggester** recommending contextual internal links.
- **RelatedLinks ViewModel** rendering link suggestions on frontend.
- **CLI**: `bin/magento panth:seo:pagerank`.

### Added - Filter URL control
- Noindex/nofollow rules for layered navigation filtered pages.
- Canonical-to-parent for filtered URLs.
- Whitelist for indexable filter combinations (e.g., brand pages).

### Added - IndexNow & Search Console
- **IndexNow integration** pinging Bing, Yandex, Seznam, and Naver
  on content save.
- Optional Google Search Console API integration for crawl error
  monitoring.

### Added - llms.txt
- **Dynamic `/llms.txt` endpoint** built by
  `Panth\AdvancedSEO\Model\LlmsTxt\Builder`.
- Auto-generated store information with custom directive support.

### Added - Product feeds
- Google Shopping XML, Facebook Catalog, and generic CSV formats.
- Attribute selection, category filtering, stock filtering.
- Cron-based and CLI generation.

### Added - Analytics integration
- GA4 and Matomo support for SEO event tracking.
- Internal link click tracking, structured data impression events.

### Added - Admin UI
- Dashboard, Templates grid, Rules grid, Bulk Editor, Redirects grid,
  Hreflang Groups, Sitemap settings, Robots/LLM policy, Audit,
  AI Settings, 404 Log, Generation Jobs.
- SERP preview JS component (`view/adminhtml/web/js/serp-preview.js`).

### Compatibility
- Magento Open Source / Commerce / Cloud 2.4.4 - 2.4.8
- PHP 8.1, 8.2, 8.3, 8.4
- Hyva theme - fully compatible (no jQuery, server-rendered output)
- Luma theme - fully compatible without modification

---

## Support

For all questions, bug reports, or feature requests:

- **Email:** kishansavaliyakb@gmail.com
- **Website:** https://kishansavaliya.com
- **WhatsApp:** +91 84012 70422
