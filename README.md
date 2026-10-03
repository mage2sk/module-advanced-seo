# Magento 2 Advanced SEO

Panth Advanced SEO (`Panth_AdvancedSEO`) is the core module of the Panth SEO suite for Magento 2. It handles meta titles and descriptions (templates, rules, per-entity overrides, bulk editing), canonical URLs, robots meta, pagination meta, SEO scoring, duplicate meta detection, a crawl audit, product feeds, Google Analytics 4, Google Search Console verification and the Google Indexing API. It is intended for merchants, SEO staff and developers who manage SEO across products, categories and CMS pages. Storefront output is server-rendered through layout XML, blocks and view models, and the module ships a `view/frontend/hyva.xml` compatibility marker, so it works on Hyva and Luma themes.

Other SEO features (structured data, social meta, hreflang, filter URLs, sitemaps, redirects, robots.txt, llms.txt and more) are separate packages that this module lists as Composer suggestions; see [Included packages](#included-packages).

Product page: [Advanced SEO Extension for Magento 2](https://kishansavaliya.com/magento-2-advanced-seo.html)

## Features

### Meta templates and tokens
- Meta templates per entity type (product, category, CMS) and store (a template is only used for entities that match its conditions), with patterns for meta title, meta description, meta keywords, SEO name, OG title, OG description, OG image, Twitter card type, robots and URL key, plus priority, conditions and an active flag.
- Token syntax `{{token}}`, with `{{attribute:code}}` for any attribute and filters `|truncate:N`, `|title`, `|strip`, `|default:value`, `|upper` and `|lower`.
- Registered tokens: `name`, `price`, `sku`, `category`, `store`, `attribute`, `page`, `description`, `short_description`, `filter`, `url_key`, `seo_name`, `category_seo_name`, `brand`, `search_query`, `landing`, `random`.
- Three default templates are installed (Default Product Template, Default Category Template, Default CMS Template).
- Admin template grid with preview, apply, CSV export and mass delete. Templates with cron auto-apply enabled are applied by cron and written to the resolved meta table.
- Keyword search on the Templates (name, meta title, entity type, URL key template), Rules (name, entity type), Custom Canonicals (target URL, entity types), Feeds (name, file name, feed type) and Crawl Results (URL, meta title, canonical) grids.
- Title and description length limits, optional store name suffix, optional removal of Magento's native title prefix/suffix, and a configurable page-number label on paginated listings.
- CMS pages whose identifier starts with `landing-`, or whose layout update contains the `landing_page` handle, are treated as landing pages. Category listings filtered by the brand attribute configured in Panth Structured Data are treated as brand pages.
- Search result pages get their own meta and `noindex,follow`. The 404 page gets `noindex,follow` and no canonical (configurable).

### SEO rules engine
- Rules per entity type and store with priority, active flag and "stop processing subsequent rules". Rules are evaluated against the entity on every page view, so a new or changed rule applies without a reindex.
- Conditions: nested all/any groups, attribute comparisons (`==`, `!=`, `>`, `>=`, `<`, `<=`, `contains`, `ncontains`, `in`, `nin`, including `category_ids`) and stock status.
- Actions: apply a meta template, set a canonical URL, or set noindex.

### Per-entity SEO fields in admin
- Product form: Meta Robots, Custom Canonical URL, OG Title, OG Description, OG Image URL, plus an "SEO Canonical" section.
- Category form: Meta Robots, OG Title, OG Description, OG Image URL, canonical settings. The "Exclude from XML Sitemap" field comes from mage2kishan/module-xml-sitemap.
- CMS page form: Meta Robots, Hreflang Identifier, canonical settings.
- SERP preview and SEO score widget on product and category edit forms.
- EAV attributes added: `seo_name` (product and category), `meta_robots` (product and category) and `layered_navigation_canonical` (product attribute option with values Use Global Setting, Base Category URL, Filtered Page URL, Set NOINDEX).

### Bulk Meta Editor and Missing Meta Report
- Admin grid with product, category and CMS page tabs and inline editing of meta title and meta description, with a store view scope switcher.
- Missing Meta Report listing products and categories without meta title or description, with the same store view switcher. The grid is paged on the server (50 rows per page by default, 20 to 200 selectable) and Export CSV downloads every matching row.

### Canonical URLs
- Emits `<link rel="canonical">` and a `Link: <url>; rel="canonical"` HTTP header on full HTML storefront pages. The header is read from the canonical tag of the rendered page head; JSON, AJAX and other non-HTML responses are left untouched, and an existing `Link` header (for example preconnect hints) is kept.
- Query string stripping (all, or a list of parameter names), lowercase host, trailing slash handling (separately for the homepage), page-1 canonical for paginated pages, parent canonical for simple products used in configurable, grouped or bundle products, cross-domain canonical store, ignored paths, and no canonical on noindex pages.
- Product canonical type: without category path, shortest category path or longest category path. Optional shortest category URL path.
- Custom Canonicals grid: map a source product, category or CMS page to a target URL or entity per store.
- Sets Magento's `catalog/seo/category_canonical_tag` and `catalog/seo/product_canonical_tag` defaults to 0 and suppresses the native canonical at runtime, so only one canonical tag is output.
- `rel="prev"` and `rel="next"` links on paginated category and search result listings. The page count comes from the rendered product list (collection size and the current limit), so `rel="next"` is only printed when a next page exists, and no links are printed for a page number beyond the last page.

### URL keys
- Optional URL key generation from a template for new products (and optionally on every save of existing products).
- Regenerates a product's URL rewrites when its visibility changes, and deletes them when it becomes Not Visible Individually.
- Admin action and CLI command to regenerate catalog URL rewrites.

### SEO scoring, audit and duplicates
- Per-entity score 0 to 100 with grade A (90+), B (80+), C (70+), D (60+) or F. Weighted checks: length (2.0), duplicate (2.5), readability (1.5), entity (2.0), keyword (2.0) and an "AI overview" content check (1.5). All checks run locally.
- Scores are computed on save (directly or through the message queue) and refreshed by cron.
- Weekly duplicate scan of resolved meta using SHA-256 content hashes.
- SEO Audit page with low-scoring entities, near-duplicate meta and the last crawl summary.
- Crawl audit that fetches storefront pages and records status code, title, description, canonical, robots and issues (missing title, missing description, title too long, description too long, missing canonical, duplicate titles, noindex pages, redirects, redirect chains, fetch errors). Run Crawl in admin queues the crawl and a dedicated cron group runs it, with live progress and a Stop Crawl button. Results appear in the Crawl Results grid.
- Internal link graph with PageRank and related-link suggestions (`panth:seo:pagerank`).
- Deleting a product, category or CMS page removes its score, embedding, resolved meta and link-graph rows; a daily cron job and `panth:seo:prune` remove any left behind.

### Storefront SEO toolbar
- Optional on-page diagnostics toolbar for allowed IPs (or developer mode only when no IPs are set), with copy canonical/title, view source and links to Google Rich Results Test, opengraph.xyz and the Twitter card validator. Full page cache is disabled for responses that show the toolbar.

### Product feeds
- Feed profiles (Google Shopping, Facebook Product Catalog, Custom XML, Custom CSV) with XML (RSS 2.0) or CSV output, optional ZIP or GZIP compression, field mapping (product attribute, static value, template token or parent attribute), category and attribute set filters, stock/status/visibility filters, currency, UTM parameters and optional FTP or SFTP delivery with a connection test. Feed file names may only contain letters, digits, dots, dashes and underscores and must end in `.xml`, `.csv`, `.txt` or `.tsv`. A default "Google Shopping Feed" profile is installed for store ID 1.
- Legacy Google Merchant feed per store built from the Google Merchant Feed settings.
- Feeds are served at `/panth_seo/feed/google` (legacy feed) and `/panth_seo/feed/google?id=<feed id>` (profile feed) when the Google Merchant feed is enabled.

### Google Analytics 4 and Search Console
- GA4 `gtag.js` snippet with `view_item` and `view_item_list` events, plus a global helper for `add_to_cart`.
- `google-site-verification` meta tag.
- Google Indexing API submission of saved product, category and CMS page URLs.

### Performance
- `panth_seo_resolved_meta` indexer (with mview subscriptions on products, categories, stock items and CMS pages) that precomputes resolved meta.
- Optional asynchronous scoring through the `panth_seo.score_entity` queue.
- Optional `Last-Modified` and `ETag` headers on product and category pages, with `304 Not Modified` responses.
- Optional Speculation Rules JSON: prerender for `.html` links and prefetch for other links, both with moderate eagerness, excluding checkout, customer, wishlist and cart paths.

## Site Audit (Semrush-style checks)

Every crawl (Run Crawl, the scheduled audit, `panth:seo:crawl` and `panth:seo:audit --format=...`) also runs the Site Audit checks on the crawled pages. The checks use the store's own crawl and need no external API. Each issue has a code, a severity (error, warning or notice), a category (crawlability, content, meta, structured_data, links, performance, international, ai_search), the affected URL, the offending element and a "How to fix" text.

How it works:

- Each HTML page is parsed once with DOMDocument; every check receives the parsed page (title, meta, H1s, canonical, robots, hreflang, links, images, JSON-LD with `@graph` flattened, scripts, styles, text size and landmarks).
- Internal images, CSS, JS and linked files are HEAD-checked once per URL. External links are HEAD-checked with GET as fallback, 8 requests in parallel, 5 s timeout and one retry, capped by Max External Checks per Crawl. 403, 405, 429 and 999 answers from linkedin.com, x.com, twitter.com, facebook.com, instagram.com, medium.com, wa.me and whatsapp.com are unverifiable, not broken.
- robots.txt, llms.txt, the XML sitemap (at most Sitemap Sample URLs per sub-sitemap, reported as "sampled"), HSTS headers of the store host and linked subdomains, and a cache-busted fetch of the first pages for load time are read once per crawl.
- The crawler sends `User-Agent: PanthSeoAudit/<version> (+<store url>)`, waits at least 100 ms between requests, stops after 3 consecutive 5xx answers, never sends a session cookie and never crawls `/customer`, `/checkout`, `/sales`, `/wishlist`, `/newsletter`, `/admin*` or URLs with `___SID`.
- Checks are classes implementing `Panth\AdvancedSEO\Model\Audit\Check\PageCheckInterface` (per page) or `SiteCheckInterface` (whole crawl), registered in the `checks` argument of `Panth\AdvancedSEO\Model\Audit\SiteAuditor` in `etc/di.xml`. Another module can add a check with a `di.xml` item and describe its code through the `definitions` argument of `Panth\AdvancedSEO\Model\Audit\IssueCatalog`.
- Issues are saved per run in `panth_seo_audit_issue`; the last runs (Issue Runs to Keep) stay for the comparison. The crawl summary also stores the counts by severity.

| Code | Severity | Meaning | Fix |
|---|---|---|---|
| `structured_data_invalid` | Error | JSON-LD property the node's `@type` does not define (for example `Product.datePublished`), invalid JSON, missing `@context` or `@type`, or a Product without `offers`, `review` or `aggregateRating`. The allow-list is `etc/schema_properties.json`, generated from schema.org. | Remove the property at its source; opt-in fix Strip Invalid Schema Properties. |
| `broken_internal_image` | Error | An `<img>` `src`, `srcset` or `data-src` on the store host, or a linked image, returns 400 or more. Names the product or CMS page when the URL maps to one. | Fix the path or re-upload the image. |
| `relative_readme_link` | Error | `<a href="docs/x.png">` around a raw.githubusercontent.com image; the relative href resolves to the store and 404s. | Use absolute links; opt-in fix Rewrite Relative README Links. |
| `hreflang_conflict` | Error | Same locale twice with different URLs, no self-reference, two pages declaring each other as the same locale, missing return link, non-200 or non-canonical alternate, invalid locale code. | One member per locale per group; opt-in Hreflang Group Guard. |
| `duplicate_title` | Error | Indexable pages share a title (case and whitespace insensitive), grouped per title. | Give each page its own title or consolidate. |
| `possible_cannibalisation` | Notice | Pages with the same title whose H1s are near-identical (Jaccard 0.8 or more). | Merge with a 301 or differentiate the intent. |
| `slow_page` | Error | HTML load time above the threshold on a cache-busted fetch; TTFB and HTML size are reported separately. | Check full page cache, slow blocks and HTML size. |
| `large_html` | Warning | HTML larger than 300 KB. | Move inline CSS/JS/SVG into cached files. |
| `unminified_js_css` | Warning | Internal JS/CSS with a whitespace ratio over 15% or average line length under 120 while `dev/js/minify_files` or `dev/css/minify_files` is 0. | Enable minification together with a static content deploy. Hyva ships a built `styles.css`. |
| `nofollow_internal_link` | Warning | `rel="nofollow"` on a link to the store's own pages. | Remove nofollow from internal content links. |
| `nofollow_internal_link_allowed` | Notice | Nofollow on cart, checkout, account, wishlist, compare, login or layered-navigation links (expected). | None needed. |
| `broken_external_link` | Warning | External link returning 400 or more, or failing DNS/TLS; one row per linking page with the number of pages linking to it. | Update or remove the link. |
| `readme_anchor_link` | Warning | `github.com/<org>/<repo>/raw/main/#anchor`, which does not resolve. | Use `https://github.com/<org>/<repo>#anchor`. |
| `low_text_html_ratio` | Warning | Visible text under 10% of the HTML, with the largest inline style, script and SVG contributors. | Move inline assets out or add content. |
| `duplicate_h1_title` | Warning | The H1 equals the `<title>`. | Write a distinct title; opt-in SEO Title Wins Over H1. |
| `multiple_h1`, `missing_h1` | Warning | More than one H1, or none. | Keep exactly one H1. |
| `nofollow_external_link` | Notice | External nofollow links; `sponsored` and `ugc` links are not counted. | Informational. |
| `blocked_by_robots` | Notice (Warning for contact, about, service, pricing or FAQ pages) | Disallowed by robots.txt, noindex meta or `X-Robots-Tag`. Cart, checkout, customer and search pages are marked expected; a matching `panth_robots_seo/general/noindex_paths` rule is named. | Remove the rule for pages that should rank. |
| `query_param_noindex` | Notice | A URL with query parameters is `noindex,follow` (correct). | Informational. |
| `permanent_redirect_link` | Notice | Internal link to a URL that answers 301 or 308, with the linking page, the link and the final URL. | Link to the final URL; for CMS or URL-rewrite slugs use `$block->getUrl('', ['_direct' => 'slug'])`. |
| `trailing_slash_internal_link` | Notice | Internal link ending in `/` that 301s to the URL without it (typical of `$block->getUrl('slug')`). | Use `$block->getUrl('', ['_direct' => 'slug'])`. |
| `resource_as_page_link` | Notice | `<a href>` pointing to an image, PDF, CSS or JS file. | Link to a page instead. |
| `single_incoming_link` | Notice | Indexable page with exactly one internal incoming link in the crawl graph. | Link to it from more pages. |
| `orphan_in_sitemap` | Notice | Sitemap URL no crawled page links to (only when the crawl finished before the page limit). | Link to it or drop it from the sitemap. |
| `duplicate_trailing_slash` | Warning | A URL and its trailing-slash twin both return 200. | 301 one to the other. |
| `subdomain_no_hsts` | Notice | The store host or a linked subdomain answers without `Strict-Transport-Security`. | Send HSTS from the web server or CDN. |
| `empty_anchor_text` | Notice | Link without text, `aria-label`, `title` or image `alt`. | Give the link an accessible name. |
| `low_semantic_html` | Notice | No main, article, nav, header, footer or section, or over 60% of the text inside div/span only. | Use semantic elements. |
| `duplicate_main_landmark` | Notice | More than one `<main>` or `role="main"`. | Keep one. |
| `llms_txt` | Notice when missing, Warning when served | `/llms.txt` missing, not `text/plain`, over 500 KB, or a Priority URLs section listing URLs outside the store. | Serve a small text/plain file about this store. |
| `robots_ai_bots` | Warning (Notice for a missing `Content-Signal`) | robots.txt blocks GPTBot, OAI-SearchBot, ChatGPT-User, ClaudeBot, Claude-SearchBot, PerplexityBot, Google-Extended or Applebot-Extended. | Allow the crawlers you want to be cited by. |
| `robots_group_precedence` | Error | A named User-agent group with only Allow lines makes that bot ignore the `*` group; also a bot disallowed while `Content-Signal` says `ai-train=yes` (Warning). | Repeat the `*` rules and Content-Signal in every group, or drop Allow-only groups. |
| `ai_answer_readiness` | Notice | Indexable page without FAQ/HowTo blocks or a visible "last updated" date; free software typed as Product with shipping and return data. | Add Q&A blocks and dates; emit SoftwareApplication. |
| `thin_duplicate_pages` | Warning | Short indexable page whose main text is contained in another indexable page (hub). | Canonical or 301 to the hub. |
| `meta_cache_stale` | Warning | Rendered `<title>` differs from the resolved meta title. | `bin/magento cache:clean collections full_page`, reindex `panth_seo_resolved_meta`. |
| `link_to_disabled_entity` | Warning | Internal link to a disabled CMS page, product or category (302/404), naming the menu item, crosslink or footer config row. | Remove or repoint the source row. |

The legacy crawl checks (missing title and description, length limits, missing canonical, 404, 5xx, fetch errors, redirects and redirect chains) are reported with the same structure; their summary keys are unchanged.

Admin: the **SEO Audit** page shows the issues of the last run in tabs for Errors, Warnings and Notices with category filters. Each issue type shows "N pages" and expands to the URL, element and detail of every row, has a "How to fix" tooltip, and a "Compare with previous run" column (new, fixed, unchanged). **Export CSV** downloads the whole run.

CLI: `bin/magento panth:seo:audit --store=1 --max-pages=500 --format=table|json|csv --severity=error` crawls the store, runs the checks and exits with code 1 when any error-severity issue exists (2 when the audit could not run), so it can gate CI. `--severity` limits the listing to that severity or worse; the exit code always considers every issue. `--no-save` skips saving. Without `--format`, `--max-pages` or `--severity` the command prints the resolved-meta JSON summary as before. Inside a container, set `PANTH_SEO_CRAWL_INTERNAL_HOST` (for example `http://nginx`) when the store URL is not reachable from PHP.

Opt-in fixes (Stores > Configuration > Panth Advanced SEO > Site Audit Fixes, all off by default):

- **Strip Invalid Schema Properties** (`panth_seo/audit_fixes/strip_invalid_schema_props`): removes properties not allowed for the node's `@type` from the Panth_StructuredData provider output.
- **Rewrite Relative README Links** (`panth_seo/audit_fixes/rewrite_relative_readme_links`): rewrites `href="docs/..."` and `href="./docs/..."` in product and CMS content, at render time, to the raw.githubusercontent.com base of the image inside the link.
- **Hreflang Group Guard** (`panth_seo/audit_fixes/hreflang_group_guard`): refuses to save a Panth_Hreflang group with two members for the same locale.
- **SEO Title Wins Over H1** (`panth_seo/audit_fixes/seo_title_wins_over_h1`): re-applies the resolved SEO title after the layout is built so a later title change to the H1 heading does not replace it; the H1 keeps the heading.

After fixing issues on a site behind a CDN, purge the CDN cache before the next crawl, or the audit still sees the old edge copies.

## Included packages

`composer.json` requires one other mage2kishan package:

| Package | What it provides |
|---|---|
| [mage2kishan/module-core](https://packagist.org/packages/mage2kishan/module-core) ([GitHub](https://github.com/mage2sk/module-core)) | `Panth_Core`: the "Panth Extensions" configuration tab and the "Panth Infotech" admin menu that this module's settings and menu items are placed under. Required. |

The following packages are listed under `suggest` in `composer.json`. They are not installed automatically; install the ones you need. Descriptions are taken from `composer.json`.

| Package | What it provides |
|---|---|
| [mage2kishan/module-crosslinks](https://packagist.org/packages/mage2kishan/module-crosslinks) ([GitHub](https://github.com/mage2sk/module-crosslinks)) | Keyword to URL cross-linking in CMS, product and category HTML. |
| [mage2kishan/module-filter-seo](https://packagist.org/packages/mage2kishan/module-filter-seo) ([GitHub](https://github.com/mage2sk/module-filter-seo)) | Path-based URLs and dynamic meta for layered navigation. |
| [mage2kishan/module-hreflang](https://packagist.org/packages/mage2kishan/module-hreflang) ([GitHub](https://github.com/mage2sk/module-hreflang)) | Hreflang link tags with admin CRUD. |
| [mage2kishan/module-html-sitemap](https://packagist.org/packages/mage2kishan/module-html-sitemap) ([GitHub](https://github.com/mage2sk/module-html-sitemap)) | HTML sitemap page at `/sitemap` with categories, products, CMS pages, stores and custom links. |
| [mage2kishan/module-image-seo](https://packagist.org/packages/mage2kishan/module-image-seo) ([GitHub](https://github.com/mage2sk/module-image-seo)) | Template-based alt and title text for product images. |
| [mage2kishan/module-index-now](https://packagist.org/packages/mage2kishan/module-index-now) ([GitHub](https://github.com/mage2sk/module-index-now)) | IndexNow notifications to Bing and Yandex on content change. |
| [mage2kishan/module-llms-txt](https://packagist.org/packages/mage2kishan/module-llms-txt) ([GitHub](https://github.com/mage2sk/module-llms-txt)) | Serves `/llms.txt` and `/llms-full.txt`. |
| [mage2kishan/module-pagebuilder-ai](https://packagist.org/packages/mage2kishan/module-pagebuilder-ai) ([GitHub](https://github.com/mage2sk/module-pagebuilder-ai)) | AI meta generation via OpenAI or Claude with prompts, knowledge base, async jobs and Generate-with-AI buttons on admin forms. |
| [mage2kishan/module-redirects](https://packagist.org/packages/mage2kishan/module-redirects) ([GitHub](https://github.com/mage2sk/module-redirects)) | 301/302/303/307/308/410/451 redirects, 404 log, CSV import/export and loop detector. |
| [mage2kishan/module-robots-seo](https://packagist.org/packages/mage2kishan/module-robots-seo) ([GitHub](https://github.com/mage2sk/module-robots-seo)) | `/robots.txt`, `X-Robots-Tag` header and an LLM-bot policy. |
| [mage2kishan/module-social-meta](https://packagist.org/packages/mage2kishan/module-social-meta) ([GitHub](https://github.com/mage2sk/module-social-meta)) | OpenGraph and Twitter Card head tags. |
| [mage2kishan/module-structured-data](https://packagist.org/packages/mage2kishan/module-structured-data) ([GitHub](https://github.com/mage2sk/module-structured-data)) | JSON-LD schemas (Product, Breadcrumb, Organization, FAQ, Video, Review, SaleEvent, ReturnPolicy and others). |
| [mage2kishan/module-xml-sitemap](https://packagist.org/packages/mage2kishan/module-xml-sitemap) ([GitHub](https://github.com/mage2sk/module-xml-sitemap)) | Sharded XML sitemap at `/panth-sitemap.xml` with profiles, hreflang, image and video tags, gzip and a cron shard queue. |

`hyva-themes/magento2-default-theme` is also suggested for Hyva frontend use.

Panth Advanced SEO itself does not output JSON-LD, OpenGraph/Twitter tags, hreflang links, filter URLs, sitemaps, redirects, robots.txt or llms.txt; those come from the packages above. It reads the brand attribute and default brand from the Panth Structured Data configuration (`panth_structured_data/structured_data/*`, with a fallback to `panth_seo/structured_data/*`), and its SEO Dashboard shows counts for filter rewrites, hreflang groups and sitemap profiles when those packages are installed.

## Compatibility

| Requirement | Supported |
|---|---|
| Magento Open Source | 2.4.4 to 2.4.8 |
| Adobe Commerce | 2.4.4 to 2.4.8 |
| PHP | 8.1, 8.2, 8.3, 8.4 |
| Themes | Hyva (via `view/frontend/hyva.xml`) and Luma |

Magento constraints in `composer.json`: `magento/framework ^103.0`, `magento/module-store ^101.1`, `magento/module-catalog ^104.0`, `magento/module-cms ^104.0`, `magento/module-url-rewrite ^102.0`, `magento/module-catalog-url-rewrite ^100.4`, `magento/module-sitemap ^100.4`, `magento/module-backend ^102.0`, `magento/module-ui ^101.2`, `magento/module-config ^101.2`, `magento/module-eav ^102.1`, `magento/module-configurable-product ^100.4`, `magento/module-grouped-product ^100.4`, `magento/module-bundle ^101.0`, `magento/module-catalog-inventory ^100.4`, `magento/module-directory ^100.4`.

## Requirements

- Magento Open Source or Adobe Commerce 2.4.4 to 2.4.8
- PHP 8.1 to 8.4
- `mage2kishan/module-core` (installed by Composer)
- Magento cron running (scoring, feeds, duplicate scan, cleanup and the crawl audit run from cron)
- For asynchronous scoring: the `panth_seo.score_entity.consumer` queue consumer (database connection)
- For the Google Indexing API: a Google Cloud service account with Indexing API access

## Installation

```bash
composer require mage2kishan/module-advanced-seo
bin/magento module:enable Panth_Core Panth_AdvancedSEO
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
bin/magento setup:static-content:deploy -f
```

`setup:di:compile` is only needed in production mode. `setup:static-content:deploy -f` deploys the admin CSS, JavaScript and templates under `view/adminhtml/web`.

Check the module status:

```bash
bin/magento module:status Panth_AdvancedSEO
```

If you install any of the suggested packages, enable their modules as well.

## Configuration

Admin path: **Stores > Configuration > Panth Extensions > Advanced SEO** (section `panth_seo`). The "Panth Extensions" tab is defined by `Panth_Core`. Settings can be set at default, website and store view scope unless noted.

### General

| Setting | Default | What it does |
|---|---|---|
| Enable Module (`panth_seo/general/enabled`) | Yes | Master switch for all Panth Advanced SEO features. |
| Debug Logging (`panth_seo/general/debug`) | No | Writes detailed logs to `var/log/panth_seo.log`. |

### Meta Tags

| Setting | Default | What it does |
|---|---|---|
| Use Meta Templates (`panth_seo/meta/use_templates`) | Yes | Generates meta title and description from templates when an entity has no custom value. |
| Meta Title Max Length (`panth_seo/meta/title_max_length`) | 60 | Maximum title length (20 to 200). |
| Meta Description Max Length (`panth_seo/meta/description_max_length`) | 160 | Maximum description length (50 to 320). |
| Append Store Name to Title (`panth_seo/meta/append_store_name`) | No | Appends " - Store Name" to meta titles. |
| Strip Native Title Prefix/Suffix (`panth_seo/meta/strip_title_prefix_suffix`) | No | Removes the title prefix/suffix set in Content > Design > HTML Head. |
| Enable SEO Name Token (`panth_seo/meta/seo_name_enabled`) | Yes | Enables the `{{seo_name}}` token. |
| Pagination Label Position (`panth_seo/meta/pagination_position`) | After Title | Before Title, After Title or Don't Add. |
| Pagination Format (`panth_seo/meta/pagination_format`) | `\| Page %p` | `%p` is replaced by the page number. |
| Force Template Over Existing Meta (`panth_seo/meta/force_template_over_existing`) | No | Templates override manually set meta on products and categories. Shown when Use Meta Templates is Yes. |
| Noindex 404 (No-Route) Page (`panth_seo/meta/noindex_noroute`) | Yes | The 404 page gets `noindex,follow` and no canonical tag. |

### Canonical URLs

| Setting | Default | What it does |
|---|---|---|
| Emit Canonical Tag (`panth_seo/canonical/enabled`) | Yes | Outputs a canonical link tag. |
| Strip Query String (`panth_seo/canonical/strip_query`) | Yes | Removes all query parameters from canonical URLs. |
| Lowercase Host (`panth_seo/canonical/lowercase_host`) | Yes | Lowercases the host part. |
| Remove Trailing Slash (`panth_seo/canonical/remove_trailing_slash`) | Yes | Removes trailing slashes (homepage handled separately). |
| Paginated Pages Canonical to Page 1 (`panth_seo/canonical/paginated_canonical_to_first`) | No | Yes: paginated pages point to page 1. No: each page keeps a self-referencing canonical with `?p=N`, also when Strip Query Parameters is on. |
| Associated Product Canonical to Parent (`panth_seo/canonical/associated_product_canonical`) | Yes | Simple products used in configurable, grouped or bundle products use the parent URL. |
| Cross-Domain Canonical Store (`panth_seo/canonical/cross_domain_store`) | None (0) | Store view whose domain is used for canonical URLs. None keeps the domain of the current store; saving the page without changes keeps the stored value. |
| Ignore Pages (one URL path per line) (`panth_seo/canonical/canonical_ignore_pages`) | empty | Paths that get no canonical tag. |
| Disable Canonical for NOINDEX Pages (`panth_seo/canonical/disable_canonical_for_noindex`) | Yes | No canonical on noindex pages. |
| Product Canonical URL Type (`panth_seo/canonical/product_canonical_type`) | Product URL without category path | Without category path, shortest category path or longest/deepest category path. |
| Use Short Category URL for Canonical (`panth_seo/canonical/use_short_category_url`) | No | Uses the shortest path for category canonicals. |
| Trailing Slash on Homepage (`panth_seo/canonical/trailing_slash_homepage`) | No Change | Add Trailing Slash, Remove Trailing Slash or No Change. |
| Strip Query Parameters (`panth_seo/canonical/strip_params`) | empty | Comma-separated parameter names to remove (for example `utm_source,gclid`). |

### URL Key Automation

| Setting | Default | What it does |
|---|---|---|
| Auto-Generate URL Key on Product Creation (`panth_seo/url/auto_url_key_enabled`) | No | New products without a URL key get one from the template. |
| URL Key Template (`panth_seo/url/url_key_template`) | `{{name}}` | Tokens `{{name}}`, `{{sku}}`, `{{attribute:code}}`; filters `\|truncate:N`, `\|lower`. Shown when auto-generation is Yes. |
| Also Apply to Existing Products on Save (`panth_seo/url/auto_url_key_for_existing`) | No | Re-applies the template on every save, overwriting the key. Shown when auto-generation is Yes. |

### Reports & Diagnostics

| Setting | Default | What it does |
|---|---|---|
| Enable Scheduled Crawl Audit (`panth_seo/reports/enable_crawl_audit`) | No | Runs the crawl audit daily by cron. Run Crawl and `panth:seo:crawl` work either way. |
| Crawl Depth (max pages) (`panth_seo/reports/crawl_depth`) | 100 | Maximum pages per crawl. An admin crawl started while cron is not running is capped at 25 pages. |
| Crawl Exclude Paths (one per line) (`panth_seo/reports/crawl_exclude_paths`) | Customer, checkout, wishlist, compare, catalogsearch, cdn-cgi, sales, newsletter, customer review, downloadable, multishipping, paypal, vault, captcha, sendfriend, oauth and connect paths | Paths the crawl does not follow; `*` wildcards supported. |
| Follow Filtered And Sorted URLs (`panth_seo/reports/crawl_follow_filtered`) | No | Also crawl URLs with a query string. |
| Verify TLS Certificate While Crawling (`panth_seo/reports/crawl_verify_tls`) | Yes | Turn off only for self-signed certificates on local or staging sites. |
| Enable SEO Toolbar (`panth_seo/reports/seo_toolbar_enabled`) | No | Shows the storefront SEO toolbar. |
| SEO Toolbar Allowed IPs (`panth_seo/reports/seo_toolbar_allowed_ips`) | empty | Comma-separated IPs or CIDR ranges, matched against the client address Magento resolves (the remote address, or the configured trusted proxy header). Empty means developer mode only; `*` allows everyone and disables full page cache site-wide. |

### Site Audit Checks

| Setting | Default | What it does |
|---|---|---|
| Check External Links (`panth_seo/audit/check_external_links`) | Yes | HEAD-checks external links found by the crawl (GET as fallback). |
| Max External Checks per Crawl (`panth_seo/audit/max_external_checks`) | 500 | Distinct external URLs checked per crawl; 0 skips them. |
| Sitemap Sample per Sub-Sitemap (`panth_seo/audit/sitemap_sample`) | 300 | URLs read from each sub-sitemap for the orphan check. |
| Slow Page Threshold (seconds) (`panth_seo/audit/slow_page_seconds`) | 3 | Load time above which `slow_page` is reported. |
| Cache-Busted Timing Sample (`panth_seo/audit/timing_sample`) | 50 | Pages fetched again with a cache-busting parameter to measure TTFB and load time. |
| Issue Runs to Keep (`panth_seo/audit/history_runs`) | 5 | Runs kept per store view for the comparison column. |

### Site Audit Fixes (opt-in)

| Setting | Default | What it does |
|---|---|---|
| Strip Invalid Schema Properties (`panth_seo/audit_fixes/strip_invalid_schema_props`) | No | Removes JSON-LD properties the node's `@type` does not define from the structured-data provider output. |
| Rewrite Relative README Links (`panth_seo/audit_fixes/rewrite_relative_readme_links`) | No | Rewrites relative `docs/...` links in product and CMS content to the raw.githubusercontent.com base of the linked image. |
| Hreflang Group Guard (`panth_seo/audit_fixes/hreflang_group_guard`) | No | Refuses a hreflang group with two members for the same locale. |
| SEO Title Wins Over H1 (`panth_seo/audit_fixes/seo_title_wins_over_h1`) | No | Keeps the resolved SEO title when a block or controller later sets the title to the H1 heading. |

### Google Merchant Feed

| Setting | Default | What it does |
|---|---|---|
| Enable Google Merchant Feed (`panth_seo/merchant_feed/enabled`) | No | Serves feeds at `/panth_seo/feed/google` and generates the legacy feed daily. |
| Include Out of Stock Products (`panth_seo/merchant_feed/include_out_of_stock`) | No | Includes out-of-stock products as "out of stock". |
| Default Product Condition (`panth_seo/merchant_feed/default_condition`) | New | New, Used or Refurbished. |
| Google Product Category Attribute (`panth_seo/merchant_feed/google_category_attribute`) | empty | Attribute code holding the Google product category. |
| Shipping Country (`panth_seo/merchant_feed/shipping_country`) | empty | Two-letter ISO code; empty omits the shipping element. |
| Shipping Price (`panth_seo/merchant_feed/shipping_price`) | empty | Flat shipping price; empty omits the shipping element. |

All fields except the first are shown only when the feed is enabled.

### Google Analytics 4

| Setting | Default | What it does |
|---|---|---|
| Enable GA4 (`panth_seo/analytics/ga4_enabled`) | No | Outputs the GA4 script. |
| Measurement ID (`panth_seo/analytics/ga4_measurement_id`) | empty | GA4 ID such as `G-XXXXXXXXXX`; only letters, digits, dash and underscore are accepted. |
| Enhanced Ecommerce Events (`panth_seo/analytics/ga4_enhanced_ecommerce`) | Yes | Fires `view_item` and `view_item_list` on product and category pages. |

### Google Search Console

| Setting | Default | What it does |
|---|---|---|
| Site Verification Code (`panth_seo/search_console/site_verification_code`) | empty | Outputs `<meta name="google-site-verification">`. |
| Enable Indexing API (`panth_seo/search_console/indexing_api_enabled`) | No | Submits saved product, category and CMS page URLs to the Google Indexing API, at most 200 submissions per UTC day across all processes (counter kept in the `flag` table). |
| Service Account JSON Key (`panth_seo/search_console/service_account_json`) | empty | Service account key JSON, stored encrypted. Default scope only. |

### Advanced

| Setting | Default | What it does |
|---|---|---|
| Async Indexing via Queue (`panth_seo/advanced/async_indexing`) | Yes | Scores saved entities through the message queue instead of during the save. Default scope only. |
| Use MView for Incremental Rebuild (`panth_seo/advanced/mview_enabled`) | Yes | Reindexes the saved entity in `panth_seo_resolved_meta` on save when the indexer is not in scheduled mode. Default scope only. |
| Enable Last-Modified HTTP Header (`panth_seo/advanced/last_modified_header`) | Yes | Sets `Last-Modified` and `ETag` on product and category pages. |
| Enable Speculation Rules API (`panth_seo/advanced/speculation_rules_enabled`) | No | Outputs Speculation Rules for prefetch and prerender. |

## Usage

### Admin pages

Under **Panth Infotech > Advanced SEO** in the admin menu: SEO Dashboard, Meta Templates, SEO Rules, Bulk Meta Editor, Custom Canonicals, Product Feeds, Missing Meta Report, SEO Audit, Crawl Results and Configuration.

- **Meta Templates**: create a template, pick entity type and store, enter patterns, optionally enable cron auto-apply, and use Apply to write it now.
- **SEO Rules**: build conditions and choose a template, canonical or noindex action.
- **Bulk Meta Editor**: switch entity type and store view, edit titles and descriptions inline, then save.
- **Custom Canonicals**: map a source entity to a target URL or entity.
- **Product Feeds**: manage profiles and field mappings, add the Google default fields, generate a feed and test FTP/SFTP delivery.
- **SEO Audit**: review low scores and duplicates, start or stop a crawl, and review the Site Audit issues of the last run (tabs per severity, category filters, "N pages" expansion, How to fix, comparison with the previous run, Export CSV). **Crawl Results** lists each crawled URL.

Actions that change data (delete a template, rule, custom canonical, feed profile or feed field, Apply a template, Generate a feed, Add Google Defaults, Test FTP Connection) are sent as POST requests with the admin form key. Opening their URLs with GET returns 404.

### Storefront output

With the module enabled, pages get the resolved meta title, description and robots, the canonical tag and `Link` header, rel prev/next on paginated listings and, when enabled, GA4, the verification tag, Speculation Rules, the `Last-Modified`/`ETag` headers and the SEO toolbar.

### Cron jobs

| Job | Schedule | Group | What it does |
|---|---|---|---|
| `panth_seo_score_recompute` | `0 * * * *` (hourly) | default | Scores up to 500 products and 100 categories per store, oldest scores first. |
| `panth_seo_duplicate_scan` | `0 3 * * 0` (Sundays 03:00) | default | Rebuilds `panth_seo_duplicate` from resolved meta hashes. |
| `panth_seo_bulk_template_apply` | `0 */2 * * *` (every 2 hours) | default | Applies active templates with cron auto-apply enabled. |
| `panth_seo_prune_orphans` | `25 2 * * *` (daily 02:25) | default | Removes SEO rows for deleted entities. |
| `panth_seo_google_feed` | `0 1 * * *` (daily 01:00) | default | Generates active feed profiles with cron enabled, then the legacy Google feed for stores without a profile feed. |
| `panth_seo_crawl_audit` | `0 3 * * *` (daily 03:00) | panth_advancedseo | Scheduled crawl audit when Enable Scheduled Crawl Audit is Yes. |
| `panth_seo_process_crawl_queue` | `* * * * *` (every minute) | panth_advancedseo | Runs crawls queued from the SEO Audit page. |

The `panth_advancedseo` cron group runs in a separate process.

### Console commands

| Command | Options | What it does |
|---|---|---|
| `panth:seo:audit` | `--store`/`-s` (default 0 = all), `--fail-on-missing`, `--fail-on-duplicate`, `--fail-on-score`, `--max-pages`, `--format` (table, json, csv), `--severity` (error, warning, notice), `--no-save` | Without `--format`/`--max-pages`/`--severity`: prints a JSON audit summary and exits non-zero when a threshold is reached. With them: crawls the store, runs the Site Audit checks and exits 1 when an error-severity issue exists. |
| `panth:seo:crawl` | `--store`/`-s`, `--limit`/`-l`, `--dry-run`, `--force`/`-f` | Runs the crawl audit. |
| `panth:seo:feed` | `--store`/`-s`, `--feed`/`-f` | Generates feeds for a store or one feed profile. |
| `panth:seo:pagerank` | `--store`/`-s`, `--limit`/`-l` (default 5) | Recomputes PageRank and related suggestions. |
| `panth:seo:prune` | `--dry-run`, `--include-authored` | Deletes SEO rows for deleted entities; `--include-authored` also deletes overrides and custom canonicals. |
| `panth:seo:regenerate-urls` | `--entity`/`-e` (product, category, all), `--store`/`-s`, `--ids`, `--id-range` | Regenerates catalog URL rewrites. |

### Feed output

Profile feeds are written to `pub/media/panth_seo/feeds/`. The legacy feed is written to `pub/media/panth_seo/google_feed_<store_code>.xml`.

### External services

- **Google Indexing API** (only when Enable Indexing API is Yes and a service account key is set): after a product, category or CMS page is saved, at the end of that request the module requests an OAuth token from `oauth2.googleapis.com` with a JWT signed by the service account key, then sends the entity URL with type `URL_UPDATED` to `indexing.googleapis.com`. See the [Indexing API quickstart](https://developers.google.com/search/apis/indexing-api/v3/quickstart).
- **External link checks** (only during a crawl, when Check External Links is Yes): the server sends HEAD requests (GET as fallback) with the `PanthSeoAudit` user agent to the external URLs linked from the crawled pages, up to Max External Checks per Crawl. No store data is sent. Each external host is resolved once; hosts that do not resolve or that resolve to a private or reserved address are reported as failed and not requested, and the request is pinned to the checked IP address so a second DNS answer cannot redirect it to an internal address. Redirects to private or reserved addresses are aborted.
- **Google Analytics 4** (only when Enable GA4 is Yes): the visitor's browser loads `gtag.js` from Google and sends page views and ecommerce events. See the [GA4 documentation](https://developers.google.com/analytics/devguides/collection/ga4).
- **FTP/SFTP** (only for feed profiles with delivery enabled): the generated feed file is uploaded to the configured host. Hosts that do not resolve to an IPv4 address, or that resolve to private or reserved addresses, are rejected, and the connection is made to the checked IP address. Test FTP Connection uses the values in the form and the saved password when the password field is empty. Delivery passwords are stored encrypted, are not sent back to the edit form, and are kept when the password field is left empty on save.
- **Crawl audit**: requests go to the store's own base URLs. The `PANTH_SEO_CRAWL_INTERNAL_HOST` environment variable can point the crawler to an internal host (for example inside containers).

No external AI service is called by this module; the scoring checks run locally.

## Developer Notes

- Module: `Panth_AdvancedSEO`; package `mage2kishan/module-advanced-seo`; namespace `Panth\AdvancedSEO`.
- Service contracts: `Api\MetaResolverInterface` (`Model\Meta\Resolver`), `Api\CanonicalResolverInterface` (`Model\Canonical\Resolver`), `Api\RuleEvaluatorInterface` (`Model\Rule\Engine`), `Api\SeoScorerInterface` (`Model\Score\Scorer`), `Api\CustomCanonicalRepositoryInterface`.
- Extension points via `di.xml`: add tokens to the `tokens` argument of `Model\Meta\TokenRegistry` (implement `Model\Meta\Token\TokenInterface`); add score checks and weights to the `checks` and `weights` arguments of `Model\Score\Scorer` (implement `Model\Score\CheckInterface`).
- Main plugins: `Magento\Framework\View\Page\Config` (pagination canonical, head meta, pagination meta, search result meta), `View\Result\Layout::renderResult` (rel prev/next, added after the product list is rendered), `Page\Title` (strip prefix/suffix), product/category/CMS metadata plugins, `Magento\Catalog\Helper\Category` and `Helper\Product` (native canonical suppression), `CategoryUrlPathGenerator` (short category URL), `Catalog\Model\Product` (visibility URL rewrites), frontend `Response\Http` (canonical `Link` header, `Last-Modified`) and `View\Result\Page` (SEO toolbar), plus admin form data provider and save plugins.
- Observers: product/category/CMS save, delete and category move (cache invalidation, reindex, scoring, cleanup), `catalog_product_save_before` (URL key), and Indexing API submission on save.
- Indexer `panth_seo_resolved_meta`; queue topic `panth_seo.score_entity`, consumer `panth_seo.score_entity.consumer`.
- Routes: admin `panth_seo`, frontend `panth_seo`.
- ACL: `Panth_AdvancedSEO::manage` with `::dashboard`, `::templates`, `::rules`, `::bulkeditor`, `::feeds`, `::custom_canonical`, `::reports`, `::missing_meta`, `::crawl_audit`, `::audit`, `::config`.
- Tables: `panth_seo_template`, `panth_seo_override`, `panth_seo_resolved`, `panth_seo_rule`, `panth_seo_score`, `panth_seo_meta_embedding`, `panth_seo_duplicate`, `panth_seo_custom_canonical`, `panth_seo_crawl_result`, `panth_seo_related`, `panth_seo_feed_profile`, `panth_seo_feed_field`. Also adds the `layered_navigation_canonical` column to `catalog_eav_attribute`.
- Log file: `var/log/panth_seo.log`.

## Uninstallation

```bash
bin/magento module:disable Panth_AdvancedSEO
composer remove mage2kishan/module-advanced-seo
bin/magento setup:upgrade
bin/magento cache:flush
```

The module has no uninstall script. The `panth_seo_*` tables, the `layered_navigation_canonical` column in `catalog_eav_attribute`, the EAV attributes `seo_name`, `meta_robots` and `layered_navigation_canonical`, the `panth_seo/*` values in `core_config_data` and generated feed files under `pub/media/panth_seo/` remain until removed manually. After removal, Magento's own defaults for `catalog/seo/category_canonical_tag` and `catalog/seo/product_canonical_tag` apply again.

## Support

- Product page: [Advanced SEO Extension for Magento 2](https://kishansavaliya.com/magento-2-advanced-seo.html)
- Contact: [kishansavaliya.com/contact](https://kishansavaliya.com/contact)
- Email: kishansavaliyakb@gmail.com
- Issues: [GitHub issues](https://github.com/mage2sk/module-advanced-seo/issues)

## Documentation

See [USER_GUIDE.md](USER_GUIDE.md).

## License

Commercial software license. See [LICENSE.txt](LICENSE.txt) in this repository.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Links

- Website: [kishansavaliya.com](https://kishansavaliya.com)
- All extensions catalogue: [kishansavaliya.com/magento-extensions.html](https://kishansavaliya.com/magento-extensions.html)
- GitHub: [mage2sk/module-advanced-seo](https://github.com/mage2sk/module-advanced-seo)
- Packagist: [mage2kishan/module-advanced-seo](https://packagist.org/packages/mage2kishan/module-advanced-seo)
