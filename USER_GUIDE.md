# Panth Advanced SEO — User Guide

This guide walks a Magento store administrator through every screen
and setting of the Panth Advanced SEO extension. No coding required.

---

## Table of contents

1.  [Installation](#1-installation)
2.  [Verifying the extension is active](#2-verifying-the-extension-is-active)
3.  [Configuration overview](#3-configuration-overview)
4.  [Meta templates](#4-meta-templates)
5.  [Canonical URLs](#5-canonical-urls)
6.  [Robots & LLM bot control](#6-robots--llm-bot-control)
7.  [Hreflang](#7-hreflang)
8.  [Redirects](#8-redirects)
9.  [Structured data (JSON-LD)](#9-structured-data-json-ld)
10. [Social meta — OpenGraph & Twitter](#10-social-meta--opengraph--twitter)
11. [SEO rules engine](#11-seo-rules-engine)
12. [SEO scoring & audit](#12-seo-scoring--audit)
13. [AI content generation](#13-ai-content-generation)
14. [Sitemaps — XML & HTML](#14-sitemaps--xml--html)
15. [Image SEO](#15-image-seo)
16. [Cross-linking & internal link suggestions](#16-cross-linking--internal-link-suggestions)
17. [Layered navigation / filter URL control](#17-layered-navigation--filter-url-control)
18. [404 management](#18-404-management)
19. [IndexNow & Search Console](#19-indexnow--search-console)
20. [llms.txt](#20-llmstxt)
21. [Product feeds](#21-product-feeds)
22. [Analytics integration](#22-analytics-integration)
23. [CLI reference](#23-cli-reference)
24. [Troubleshooting](#24-troubleshooting)

---

## 1. Installation

### Composer (recommended)

```bash
composer require mage2kishan/module-advanced-seo
bin/magento module:enable Panth_Core Panth_AdvancedSEO
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f
bin/magento indexer:reindex panth_seo_resolved_meta panth_seo_hreflang
bin/magento cache:flush
```

### Manual zip

1. Download the extension package zip from the Adobe Commerce Marketplace
2. Extract to `app/code/Panth/AdvancedSEO`
3. Make sure `app/code/Panth/Core` is also present (required dependency)
4. Run the same `module:enable ... cache:flush` commands above

### Confirm

```bash
bin/magento module:status Panth_AdvancedSEO
# Module is enabled
```

---

## 2. Verifying the extension is active

After installation, these things should be true:

- **Admin sidebar** — a new "Advanced SEO" section appears under
  the "Panth Infotech" top-level menu.
- **Configuration** — `Stores > Configuration > Panth Extensions > Advanced SEO`
  is available with all sub-sections.
- **Indexers** — `bin/magento indexer:status` lists
  `panth_seo_resolved_meta` and `panth_seo_hreflang`.

---

## 3. Configuration overview

All settings live under
`Stores > Configuration > Panth Extensions > Advanced SEO`. The
configuration is divided into the following groups:

| Group                  | What it controls                                          |
|------------------------|-----------------------------------------------------------|
| General                | Enable/disable, default robots tag, trailing-slash policy |
| Meta Templates         | Default templates for products, categories, CMS pages     |
| Canonical              | Query-strip rules, pagination, layered-nav handling       |
| Robots & LLM Policy   | Global robots.txt rules, per-bot allow/deny               |
| Hreflang               | Enable, default x-default store, auto-binding             |
| Redirects              | Enable regex matching, 503 maintenance mode               |
| Structured Data        | Toggle each JSON-LD type, Organization defaults           |
| Social Meta            | OpenGraph & Twitter card defaults, fallback images        |
| Rules Engine           | Enable/disable automatic rule evaluation                  |
| Scoring & Audit        | Score thresholds, enabled checks, queue consumer          |
| AI Generation          | Adapter (OpenAI / Claude / None), API keys, budget        |
| Sitemaps               | Shard size, image inclusion, hreflang extension           |
| Image SEO              | Alt-text template, lazy-load attributes, vision adapter   |
| Cross-Linking          | Enable suggestions, max links per page, PageRank decay    |
| Filter URLs            | Noindex/nofollow rules for layered navigation params      |
| 404 Management         | Cluster threshold, auto-suggest redirects                 |
| IndexNow               | Enable, API key, endpoints                                |
| llms.txt               | Enable, custom directives                                 |
| Product Feeds          | Enable, feed format, included attributes                  |
| Analytics              | GA4 / Matomo integration, event tracking                  |

Each group can be configured at the **Default**, **Website**, or
**Store View** scope.

---

## 4. Meta templates

Meta templates let you generate SEO titles and descriptions
automatically using Smarty-lite variable tokens.

### Available tokens

| Token            | Resolves to                              | Scope               |
|------------------|------------------------------------------|----------------------|
| `{name}`         | Product / category / CMS page name       | All                  |
| `{price}`        | Final price with currency symbol         | Product              |
| `{sku}`          | Product SKU                              | Product              |
| `{category}`     | Primary category name                    | Product              |
| `{store}`        | Current store name                       | All                  |
| `{attribute:X}`  | Any product attribute by code            | Product              |
| `{description}`  | Short description (truncated)            | Product / CMS        |

### Creating a template

1. Go to **Panth Infotech > Advanced SEO > Meta Templates**
2. Click **Add New Template**
3. Choose the entity type (Product, Category, or CMS Page)
4. Enter the title pattern, e.g., `Buy {name} - Only {price} | {store}`
5. Enter the description pattern
6. Set priority (lower number = higher priority)
7. Save and reindex: `bin/magento indexer:reindex panth_seo_resolved_meta`

### Per-entity overrides

Any product, category, or CMS page can override its template-generated
meta by filling in the "SEO Override" fields on the entity edit page.
Overrides always win over templates.

### Bulk editor

The **Bulk Editor** screen (`Panth Infotech > Advanced SEO > Bulk Editor`)
lets you view and edit resolved meta titles and descriptions for
hundreds of entities in a spreadsheet-like grid.

---

## 5. Canonical URLs

The canonical resolver automatically determines the correct canonical
URL for every page on your store.

### Configuration

- **Strip query parameters** — removes UTM tags, session IDs, and
  other tracking parameters from canonical URLs.
- **Pagination handling** — choose whether paginated category pages
  point their canonical to page 1 or to themselves.
- **Layered navigation** — when enabled, filtered pages point their
  canonical back to the unfiltered category.

The resolver is layered-nav aware: it understands Magento's native
filterable attributes and strips filter parameters from the canonical.

---

## 6. Robots & LLM bot control

### robots.txt

The module replaces Magento's static `robots.txt` with a dynamic,
database-driven version served by `Panth\AdvancedSEO\Controller\Robots\Index`.

- Add or remove `Disallow` / `Allow` directives per store view
- Reference your XML sitemap URL automatically

### Per-LLM-bot policy

Control access for AI crawlers individually:

| Bot              | Default | Notes                           |
|------------------|---------|---------------------------------|
| GPTBot           | Deny    | OpenAI web crawler              |
| ClaudeBot        | Deny    | Anthropic web crawler           |
| Google-Extended  | Allow   | Google AI training crawler      |
| CCBot            | Deny    | Common Crawl                    |
| PerplexityBot    | Deny    | Perplexity AI                   |
| Bytespider       | Deny    | ByteDance / TikTok              |

Toggle each bot between Allow and Deny from the admin configuration
or from the dedicated **Robots & LLM Policy** grid.

### Per-page robots meta

Use the SEO rules engine (see section 11) to apply `noindex`, `nofollow`,
or custom robots directives to specific pages or page groups.

---

## 7. Hreflang

Hreflang tags tell search engines which language/region version of a
page to show to users in different locales.

### Setting up hreflang groups

1. Go to **Panth Infotech > Advanced SEO > Hreflang Groups**
2. Create a group (e.g., "English Sites")
3. Add members: map each store view to its locale code
   (e.g., `en-us`, `en-gb`, `fr-fr`)
4. Designate one store view as `x-default`

### Auto-binding

When **Auto-Bind** is enabled, the module automatically matches
products and categories across store views by SKU / URL key and
creates hreflang links without manual mapping.

### Reciprocity validation

The module validates that hreflang tags are reciprocal — if Store A
points to Store B, Store B must point back to Store A. Broken
reciprocity is flagged in the audit dashboard.

### Indexer

Hreflang mappings are materialized by the `panth_seo_hreflang`
indexer. After changing groups or members, reindex:

```bash
bin/magento indexer:reindex panth_seo_hreflang
```

---

## 8. Redirects

### Redirect types

- **301 Permanent** — standard permanent redirect
- **302 Found** — temporary redirect
- **503 Maintenance** — returns a 503 status with a Retry-After header

### Creating redirects

1. Go to **Panth Infotech > Advanced SEO > Redirects**
2. Click **Add Redirect**
3. Enter the source path (literal or regex)
4. Enter the target URL
5. Choose the redirect type
6. Save

### Regex support

Enable regex matching in configuration. Source paths starting with
`^` are treated as PCRE patterns:

```
Source: ^/old-category/(.*)$
Target: /new-category/$1
Type:   301
```

### Loop detection

The `Panth\AdvancedSEO\Model\Redirect\Loop` class detects redirect
chains and loops before saving. If a loop is detected, the redirect
is rejected with an error message.

### Bulk import

```bash
bin/magento panth:seo:redirect-import --file=redirects.csv
```

CSV format: `source_path,target_url,redirect_type,store_id`

---

## 9. Structured data (JSON-LD)

The module injects JSON-LD structured data into the `<head>` of
every page. Each provider can be toggled on or off independently.

### Available providers

| Provider       | Schema type         | Pages                  |
|----------------|---------------------|------------------------|
| Product        | `schema.org/Product`| Product detail pages   |
| Breadcrumb     | `BreadcrumbList`    | All pages              |
| Organization   | `Organization`      | Homepage / all pages   |
| WebSite        | `WebSite`           | Homepage               |
| FAQPage        | `FAQPage`           | Pages with FAQ blocks  |
| Article        | `Article`           | CMS / blog pages       |
| Video          | `VideoObject`       | Pages with video       |

### Product structured data

Automatically includes:

- Name, description, SKU, brand
- Price, currency, availability
- Aggregate rating (if reviews exist)
- Product images
- GTIN / MPN (if attributes are mapped)

### Validation

The `Panth\AdvancedSEO\Model\StructuredData\Validator` validates
generated JSON-LD against schema.org requirements and flags errors
in the audit dashboard.

### Configuration

Under `Structured Data`:

- Enable/disable each provider
- Set Organization name, logo URL, social profile URLs
- Map product attributes to schema.org properties (brand, GTIN, etc.)

---

## 10. Social meta — OpenGraph & Twitter

### OpenGraph tags

The module generates `og:title`, `og:description`, `og:image`,
`og:url`, `og:type`, and `og:site_name` tags for every page.

### Twitter Card tags

Generates `twitter:card`, `twitter:title`, `twitter:description`,
`twitter:image`, and `twitter:site` tags.

### Configuration

- **Default OG image** — fallback image when no page-specific image
  is available
- **Twitter card type** — `summary` or `summary_large_image`
- **Twitter @handle** — your brand's Twitter username
- **Facebook App ID** — optional, for Facebook Insights

### Per-entity overrides

Products, categories, and CMS pages can have custom OG title,
description, and image set on their edit pages.

---

## 11. SEO rules engine

The rules engine lets you create conditional SEO rules that
automatically apply actions to matching pages.

### Conditions

Rules use a condition-combine tree (similar to Magento's catalog
price rules):

- **Entity type** — product, category, CMS page
- **Attribute conditions** — any product/category attribute
- **Stock status** — in stock / out of stock
- **Price range** — min/max price
- **Category membership** — belongs to category X
- **URL pattern** — URL contains / matches regex

### Actions

| Action     | What it does                                           |
|------------|--------------------------------------------------------|
| Template   | Apply a specific meta template to matching pages       |
| Canonical  | Override canonical URL for matching pages              |
| Noindex    | Set robots to noindex,nofollow for matching pages      |

### Priority

Rules are evaluated in priority order (lower number = higher
priority). The first matching rule wins.

### Examples

- "All out-of-stock products should be noindexed"
- "Products in category 'Sale' use the sale meta template"
- "Products under $10 get a special title template"

---

## 12. SEO scoring & audit

### SEO score

Every product, category, and CMS page receives an SEO score from
0 to 100 based on the following checks:

| Check         | What it measures                                    | Weight |
|---------------|-----------------------------------------------------|--------|
| Length         | Title 50-60 chars, description 150-160 chars        | 20     |
| Duplicate      | No duplicate titles/descriptions across the store   | 25     |
| Readability   | Flesch reading ease, sentence length                 | 15     |
| Entity        | Structured data completeness                         | 20     |
| Keyword       | Focus keyword presence in title, desc, H1, URL      | 20     |

### Audit dashboard

The **SEO Dashboard** (`Panth Infotech > Advanced SEO > Dashboard`)
shows:

- Overall store SEO health score
- Distribution of page scores (excellent / good / needs work / poor)
- Top issues to fix (sorted by impact)
- Score trends over time

### Audit CLI

```bash
bin/magento panth:seo:audit
```

Runs all checks and outputs a summary to the terminal.

### Queue-based scoring

Scoring runs asynchronously via the `panth.seo.score` message queue.
The `ScoreConsumer` processes entities in the background. To trigger
a full rescore:

```bash
bin/magento queue:consumers:start panth.seo.score.consumer
```

---

## 13. AI content generation

### Supported adapters

| Adapter | Provider  | Notes                                 |
|---------|-----------|---------------------------------------|
| OpenAI  | OpenAI    | GPT-4o, GPT-4, GPT-3.5-turbo         |
| Claude  | Anthropic | Claude 3.5 Sonnet, Claude 3 Opus     |
| Null    | None      | Disabled — no API calls               |

### Configuration

Under `AI Generation`:

- **Adapter** — choose OpenAI, Claude, or Null
- **API Key** — your provider's API key
- **Model** — specific model to use
- **Monthly budget** — maximum spend per calendar month (USD)
- **Cache TTL** — how long to cache generated content (hours)

### How it works

1. Select entities in the Bulk Editor or template screen
2. Click **Generate with AI**
3. The module sends entity data (name, attributes, category) to the
   chosen AI provider
4. Generated meta title and description are returned and saved
5. Results are cached to avoid redundant API calls

### Budget control

The module tracks API spend per month. When the budget is exhausted,
generation requests are queued and held until the next billing cycle
or until the budget is increased.

### Generation jobs

View all generation jobs (pending, completed, failed) in
**Panth Infotech > Advanced SEO > AI Generation Jobs**.

---

## 14. Sitemaps — XML & HTML

### XML sitemaps

The module extends Magento's native sitemap with:

- **Sharding** — large sitemaps are split into multiple files
  (configurable entries per file, default 50,000)
- **Image extension** — product images are included as
  `<image:image>` tags
- **Hreflang extension** — hreflang alternate URLs are included
  as `<xhtml:link>` tags
- **Delta tracking** — the `DeltaTracker` only regenerates entries
  for entities that changed since the last generation

### HTML sitemap

An HTML sitemap page is available at `/panth-seo-sitemap.html`
(configurable URL). It lists all indexable products, categories,
and CMS pages grouped by type.

The HTML sitemap:

- Respects noindex rules (excluded pages are not listed)
- Supports pagination for large catalogs
- Uses a clean, accessible layout compatible with Hyva and Luma

### Configuration

- **Entries per shard** — maximum URLs per XML sitemap file
- **Include images** — yes/no
- **Include hreflang** — yes/no
- **HTML sitemap URL** — custom URL key
- **HTML sitemap — show products** — yes/no
- **HTML sitemap — show categories** — yes/no
- **HTML sitemap — show CMS pages** — yes/no

---

## 15. Image SEO

### Alt-text templates

Define templates for auto-generating image alt text:

```
{name} - {attribute:color} - {store}
```

This generates alt text like "Blue Widget - Blue - My Store".

### Vision adapter

The optional vision adapter (requires AI generation to be enabled)
can analyze product images and generate descriptive alt text using
computer vision.

- Adapter: `Panth\AdvancedSEO\Model\ImageSeo\VisionAdapterInterface`
- Default: `NullVisionAdapter` (disabled)

### Lazy loading

The module can add `loading="lazy"` and `decoding="async"` attributes
to product images below the fold automatically.

---

## 16. Cross-linking & internal link suggestions

### How it works

The module builds an internal link graph of your store and calculates
a simplified PageRank score for each page.

- `Panth\AdvancedSEO\Model\InternalLinking\Graph` — builds the link graph
- `Panth\AdvancedSEO\Model\InternalLinking\PageRank` — calculates scores
- `Panth\AdvancedSEO\Model\InternalLinking\Suggester` — suggests links

### Related links block

The `RelatedLinks` ViewModel renders contextual internal links on
product and category pages. Links are chosen based on:

- Textual relevance (shared keywords / categories)
- PageRank — preference for linking to high-authority pages
- Reciprocity — avoid one-way link clusters

### Configuration

- **Enable suggestions** — yes/no
- **Max links per page** — default 5
- **PageRank decay factor** — default 0.85
- **Exclude categories** — category IDs to exclude from suggestions

### CLI

```bash
bin/magento panth:seo:pagerank
```

Recalculates PageRank scores for all indexable pages.

---

## 17. Layered navigation / filter URL control

Control how search engines handle filtered category pages.

### Options

- **Noindex filtered pages** — add `noindex,follow` to all pages
  with active layered navigation filters
- **Nofollow filter links** — add `rel="nofollow"` to filter links
  in the sidebar
- **Canonical to parent** — filtered pages point their canonical
  back to the unfiltered category
- **Allowed filters** — whitelist specific filters that should
  remain indexable (e.g., brand pages)

---

## 18. 404 management

### 404 logging

Every 404 hit is logged to `panth_seo_notfound_log` with:

- Request URL
- Referer
- User agent
- Timestamp
- Hit count (deduplicated)

### Clustering

The module clusters similar 404 URLs together to identify patterns.
For example, `/old-product-1`, `/old-product-2`, `/old-product-3`
are grouped so you can create a single regex redirect.

### Auto-suggest

The `SuggestionEngine` analyzes 404 URLs and suggests the most
likely redirect target based on URL similarity and existing content.

### 404 grid

View all 404s in **Panth Infotech > Advanced SEO > 404 Log**.
From the grid you can:

- See hit counts and last-seen dates
- Create redirects directly from a 404 entry
- Accept or dismiss auto-suggested redirects
- Bulk-create redirects for clustered URLs

---

## 19. IndexNow & Search Console

### IndexNow

IndexNow is a protocol that lets you notify search engines (Bing,
Yandex, Seznam, Naver) instantly when content changes.

**Configuration:**

- **Enable IndexNow** — yes/no
- **API key** — your IndexNow API key (auto-generated if blank)
- **Endpoints** — which search engines to notify

When enabled, the module automatically pings IndexNow whenever a
product, category, or CMS page is saved.

### Search Console

Optional integration with Google Search Console API:

- Pull crawl error data into the admin dashboard
- Cross-reference 404 log with Search Console errors
- Monitor indexing coverage from within Magento

---

## 20. llms.txt

The `llms.txt` standard allows website owners to provide structured
information to large language models about their site.

### Configuration

- **Enable llms.txt** — serves a `/llms.txt` endpoint
- **Custom directives** — add custom key-value pairs
- **Auto-generate** — automatically include store name, description,
  product count, and category tree

The `Panth\AdvancedSEO\Model\LlmsTxt\Builder` generates the file
content dynamically.

---

## 21. Product feeds

### Supported formats

- Google Shopping (XML)
- Facebook Catalog (CSV / XML)
- Generic CSV export

### Configuration

- **Enable feeds** — yes/no
- **Feed format** — Google Shopping / Facebook / CSV
- **Included attributes** — select which product attributes to export
- **Filter by category** — only include products from specific categories
- **Filter by stock** — exclude out-of-stock products
- **Feed URL** — custom URL where the feed is accessible

### Generation

Feeds can be generated:

- On a cron schedule (configurable frequency)
- Manually from the admin panel
- Via CLI: `bin/magento panth:seo:generate-feed`

---

## 22. Analytics integration

### Supported platforms

- **Google Analytics 4 (GA4)** — enhanced ecommerce events
- **Matomo** — server-side tracking

### SEO event tracking

The module can fire custom analytics events for:

- Internal link clicks (cross-linking suggestions)
- Structured data impressions
- Search result click-through (when integrated with Search Console)

### Configuration

- **Enable analytics** — yes/no
- **Platform** — GA4 / Matomo
- **Tracking ID** — your GA4 measurement ID or Matomo site ID

---

## 23. CLI reference

| Command                                  | Description                              |
|------------------------------------------|------------------------------------------|
| `panth:seo:audit`                        | Run full SEO audit                       |
| `panth:seo:generate-meta`               | Generate meta via AI for entities         |
| `panth:seo:redirect-import --file=X`    | Import redirects from CSV                 |
| `panth:seo:pagerank`                     | Recalculate PageRank scores               |
| `panth:seo:generate-feed`               | Generate product feeds                    |
| `indexer:reindex panth_seo_resolved_meta`| Reindex resolved meta                    |
| `indexer:reindex panth_seo_hreflang`     | Reindex hreflang mappings                |

---

## 24. Troubleshooting

### Meta titles/descriptions not updating

1. Reindex: `bin/magento indexer:reindex panth_seo_resolved_meta`
2. Flush cache: `bin/magento cache:flush`
3. Check for per-entity overrides that may take precedence

### Hreflang tags not appearing

1. Verify hreflang groups have members assigned
2. Reindex: `bin/magento indexer:reindex panth_seo_hreflang`
3. Check reciprocity validation in the audit dashboard

### AI generation not working

1. Verify the API key is correct in configuration
2. Check the monthly budget has not been exhausted
3. Review generation job status in the admin grid
4. Check `var/log/system.log` for API errors

### Structured data validation errors

1. Check Google's Rich Results Test with your page URL
2. Review the audit dashboard for flagged issues
3. Ensure required attributes (brand, GTIN) are mapped

### 404 log not recording

1. Ensure the module's frontend route is registered
2. Check that Magento's `noroute` action is not being intercepted
   by another module

---

## Support

For all questions, bug reports, or feature requests:

- **Email:** kishansavaliyakb@gmail.com
- **Website:** https://kishansavaliya.com
- **WhatsApp:** +91 84012 70422
